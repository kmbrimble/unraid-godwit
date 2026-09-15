# Changelog

All notable changes to this project will be documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit
or zero-padded, because Unraid's plugin manager compares versions with a
plain `strcmp`, not a semver-aware comparison — `1.0.10` would otherwise
sort *before* `1.0.9`.

## [Unreleased]

## [0.1.0] - 2026-09-15 (verified on host 2026-09-15)

### Added

- Initial scaffold and release pipeline: `godwit.plg`, `plugin/README.md` in
  stock shape, `.github/workflows/ci.yml` (lint + `php tests/run.php`) and
  `release.yml` (cuts a GitHub Release on push to `main`, writes the real
  `<MD5>` back onto the FILE block after building).
- Bundled rclone (PLAN.md D10): `scripts/build-plugin.sh` downloads
  `rclone-v1.75.1-linux-amd64.zip`, verifies it against that release's
  `SHA256SUMS` via `scripts/verify-rclone-zip.sh`, and fails the build on a
  mismatch or missing entry. The pinned version and URLs live in
  `scripts/rclone-pin.env`. The binary is installed at
  `/usr/local/emhttp/plugins/godwit/bin/rclone` — `/usr/sbin/rclone` and the
  Waseh plugin's files are never touched.
- Daemon: `plugin/scripts/godwitd` (PHP) starts and supervises a single
  `rclone rcd` process using the bundled binary, restarting it with
  exponential backoff (capped at 60s) if it dies. Listens on a unix socket
  under `/var/run/godwit/` by default; falls back to `127.0.0.1` on an
  OS-assigned free port with generated `--rc-user`/`--rc-pass` (never
  written to flash) if the socket bind doesn't come up within ~2s.
  `--rc-no-auth` is never used. `--config` points at
  `/boot/config/plugins/godwit/rclone.conf` (created 0600, empty). State
  (heartbeat SQLite db, WAL mode) lives under
  `/mnt/cache/appdata/godwit/godwit.db`, never on flash. `plugin/scripts/rc.godwit`
  provides start/stop/status, matching Dormouse's pattern.
- Settings page (`Godwit.page`, Utilities → Godwit): read-only daemon/rcd
  status (running, bundled rclone version, listener, last heartbeat) via
  `godwit-api.php`, polled with urlencoded `$.post` (no custom CSRF check —
  `local_prepend.php` already consumes `csrf_token`). Start/Stop buttons
  invoke `rc.godwit` directly; the web endpoint never talks to rcd itself,
  so no rcd credentials need to live in the web SAPI.
- Spike (PLAN.md §4.3): confirmed empirically that rclone rc's per-call
  `_config.MaxTransfer` is scoped to that job's own stats group, not the
  process total — see `docs/spikes/max-transfer-scope.md`. Also recorded a
  side finding: the cutoff can overshoot by roughly
  `(transfers - 1) * avg file size` within a single job, which the Phase 3
  budget ledger's own periodic check needs to account for.
- Tests (`tests/run.php`, PHP CLI): version-sort rule, `godwit.plg`
  structure (MD5 tag, `-f` gating not `-x`), `plugin/README.md` stock shape,
  `rclone-pin.env` parsing and `verify-rclone-zip.sh`'s hash/missing-entry
  rejection, `godwit_rcd_argv()`'s never-`--rc-no-auth`/always-`--config`
  invariant, shebang presence on every script the `.plg`/`rc.godwit`
  invokes directly, and `rc.godwit`'s start/status/stop lifecycle against a
  stub daemon.

### Fixed (found by code-diff-reviewer + advisor before this version shipped)

- `godwitd` now backs off (1s doubling to 60s) when both the unix-socket and
  tcp-fallback rcd start attempts fail, instead of retrying every second
  forever.
- `godwitd`'s `chmod` on `rclone.conf` is best-effort — `/boot/config` is a
  vfat mount where `chmod` is a no-op, and the unsuppressed call logged a
  spurious warning on every daemon start.
- `godwit_rc_call()` degrades to `null` instead of a fatal error if the
  `curl` extension is absent (PLAN.md's confirmed host extension list is
  sqlite3/pcntl/posix — curl was assumed, not confirmed).
- `install-on-host.sh`'s Waseh/`/usr/sbin/rclone` snapshot used a
  `*rclone*` name glob that also matched godwit's own bundled binary,
  guaranteeing a spurious before/after mismatch; switched to fixed paths.
  The `rclone.conf` permission check no longer asserts an exact mode on a
  vfat mount (mode is mount-determined, not code-determined). The (e)
  status check now falls back to a CLI PHP invocation if the HTTP path is
  blocked by nginx's `auth_request`.

### Verified on the live host (2026-09-15, install → uninstall → reinstall)

- (a) flash `.plg` reports version 0.1.0.
- (b) bundled `/usr/local/emhttp/plugins/godwit/bin/rclone version` → v1.75.1.
- (c) exactly one `godwitd` and one `rcd` process from the bundled binary;
  `/var/run/godwit/rcd.sock` present.
- (d) the Plugins-tab row renders through the host's own `ShowPlugins.php`
  (`cd /usr/local/emhttp && php plugins/dynamix.plugin.manager/include/ShowPlugins.php`).
- (e) the settings-page status call reports `rclone_version":"v1.75.1"`. The
  plain HTTP path (`curl http://localhost/plugins/godwit/scripts/godwit-api.php`)
  returned nothing, as anticipated — the host's nginx `auth_request` blocks
  an unauthenticated loopback call; the CLI-PHP fallback
  (`php .../godwit-api.php` directly) is what actually produced the
  evidence, and is the one that will need proving through a real browser
  session with a valid `csrf_token` later.
- (f) uninstall left no flash `.plg`, no flash config dir, no installed
  tree, no plugin-manager registration, no `godwitd`/`rcd` process, no
  socket, no rundir, no pidfile, no package entry — all ten checks passed.
- (g) reinstall succeeded and repeated (a)-(e) cleanly.
- `/usr/sbin/rclone` (md5 unchanged) and the Waseh plugin's files
  (`rclone`/`rclone-beta` dirs, `rclone.plg`) were confirmed byte-for-byte
  unchanged before vs. after the full install/uninstall/reinstall cycle.
- The heartbeat db under `/mnt/cache/appdata/godwit/godwit.db` (WAL)
  persisted across the uninstall/reinstall cycle, as designed.
- Host's `/boot` mount has `fmask=0177`, which independently yields 0600 on
  new files — `rclone.conf` got 0600 in practice on this host, though the
  daemon's `chmod` there is still best-effort (not all vfat mounts share
  this fmask).
- Host's PHP has the `curl` extension (not in PLAN.md §2's confirmed list,
  now confirmed) — `godwit_rc_call()`'s no-curl fallback exists for
  portability but was not exercised live.

### Decisions made during this unattended session (not in PLAN.md)

- No `godwit.cfg` yet — Phase 1 has no user-configurable settings, so there
  is nothing to seed a placeholder config for (unlike Dormouse's Phase 1,
  which did have `watched_shares` etc. from day one).
- `rclone.conf` is removed on uninstall (matches Dormouse's flash config
  handling for Phase 1). It stays empty through Phase 1; once Phase 2 adds
  OAuth tokens to it, this should be revisited deliberately rather than
  inherited by default.
- The TCP fallback listener's port is chosen by asking the OS for a free
  port and releasing it immediately before rcd binds it (a small TOCTOU
  window) — acceptable because this path only exists as a fallback for a
  socket bind failure that did not occur during local testing on rclone
  v1.75.1.
