# Changelog

All notable changes to this project will be documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit
or zero-padded, because Unraid's plugin manager compares versions with a
plain `strcmp`, not a semver-aware comparison — `1.0.10` would otherwise
sort *before* `1.0.9`.

## [Unreleased]

### Plan: 0.1.3 — remove superseded package files from flash on upgrade

Observed on the live host 2026-09-16: `/boot/config/plugins/godwit/` keeps
every `.txz` ever installed (~20MB each, rclone bundled in), because the
install block never deletes old ones — only `remove` wipes the directory.
Each upgrade leaves another ~20MB on the USB flash drive.

- `godwit.plg`'s install `<INLINE>`: after `upgradepkg --install-new`
  succeeds, loop over `&plgPATH;/&name;-*.txz` and delete every one except
  `&name;-&version;.txz`, gated on `[[ -f ]]`, safe under `set -e` when the
  glob matches nothing, and touching nothing else in that directory
  (`rclone.conf`, future config/token files).
- New `tests/run.php` coverage on the existing install-block harness:
  old `.txz` files plus current `.txz`, `rclone.conf` and a decoy file are
  left correctly (only the old `.txz` gone); empty glob still exits 0;
  `upgradepkg` failure deletes nothing and the block exits non-zero.

## [0.1.2] - 2026-09-16

### Fixed

Found by Dormouse 0.2.3's review, which hit the identical bug; confirmed by
reading `godwit.plg` at a565143. The install `<INLINE>` block runs under
`set -e`. At boot, `array-ready.sh` exits 1 by design (array not started,
cache not mounted yet) — that is the whole point of the check, and the exact
case the 0.1.1 "defer to the `started` event hook" branch exists to handle.
But the block called it as a bare statement (`bash .../array-ready.sh;
READY=$?`), so `set -e` aborted the install script the moment that bare
command returned non-zero, before the deferral branch it was meant to feed
ever ran — a boot-time plugin install therefore reported failure instead of
deferring cleanly.

- `godwit.plg`'s install block now captures the result with `if bash
  .../array-ready.sh; then READY=0; else READY=1; fi` instead of a bare
  statement + `$?`. XML well-formedness (no bare `&` in the INLINE block)
  confirmed still green by the existing `simplexml_load_file` test.
- Added `tests/run.php` coverage that extracts the real install `<INLINE>`
  block out of `godwit.plg`, substitutes its entities to a temp layout, and
  runs it under bash against recording stubs for `upgradepkg`, `removepkg`
  and `rc.godwit`, plus a stubbed `array-ready.sh`: asserts exit 0 and the
  deferral message when array-ready.sh exits 1 (and that `rc.godwit start`
  is never called), and the mirror case where array-ready.sh exits 0 and
  `rc.godwit start` is called. Proven to fail against the pre-fix block
  first (see handback).
- Scanned the rest of `godwit.plg` and `scripts/` for other bare commands
  under `set -e` with an expected non-zero exit — the remove block has no
  `set -e` at all, and every other guarded call in `scripts/*.sh` already
  wraps its fallible command in `if` or `|| true`. No other instances found.
- Checked, not changed: godwitd's mount guard (`godwit_is_mountpoint()` in
  `plugin/scripts/lib.php`) checks `/mnt/cache` itself via `mountpoint -q`,
  not a walk-up to the nearest existing mountpoint — Dormouse's 0.2.3
  walk-up regression (stopping at `/mnt`, a bind of rootfs on this host)
  does not apply here.

## [0.1.1] - 2026-09-15

### Fixed

0.1.0's `.plg` install step called `rc.godwit start` unconditionally, but
plugins install ~14s before `/mnt/cache` is mounted at boot (confirmed from
`/boot/logs/syslog`) — godwitd then created `appdata/godwit` and
`godwit.db` on the RAM rootfs, which the later cache mount silently covers,
losing all state at shutdown. The bug never actually fired on the live host
(last boot 2026-09-12 11:18; 0.1.0 was installed 2026-09-15 with the array
already up), but would have on the next reboot.

- `plugin/event/started` + `plugin/event/stopping_svcs` (executable,
  packaged) hook the real emhttpd lifecycle (confirmed via
  `/usr/local/sbin/emhttp_event` and sibling plugins file.activity/
  unbalanced/tips.and.tweaks) instead of relying on boot-time install order.
  `emhttp_event` gates `event/*` scripts on the **executable bit**
  (`[ -x $Dir/event/$1 ]`), not `-f` — a documented exception to CLAUDE.md
  non-negotiable 4, which is about our own `.plg`'s gating of scripts it
  invokes directly, not about emhttpd's own dispatcher.
- `plugin/scripts/array-ready.sh` (new): the install step starts the
  daemon only if the array is already `STARTED` (`var.ini`) *and*
  `/mnt/cache` is a real mountpoint; otherwise it defers to the `started`
  event hook.
- `godwitd` refuses to create/open the state dir unless `/mnt/cache` is a
  real mountpoint (defense in depth, overridable via `GODWIT_ASSUME_CACHE_MOUNTED`
  for tests — confirmed `mountpoint` is on emhttpd's own PATH
  `/bin:/sbin:/usr/bin:/usr/sbin` on the host, so the guard works from the
  `started` event hook's environment, not just an interactive shell's).
- `rc.godwit stop` escalates to SIGKILL (daemon + bundled rcd) if the
  graceful wait times out, and verifies nothing from the bundled rclone
  binary is left running.
- `godwit.plg`'s `launch`/page-menu pairing checked against Dormouse's
  proven, installed shape — confirmed already correct, no change.

### Fixed (found by code-diff-reviewer + advisor before this version shipped)

- `godwit.plg` was no longer well-formed XML: the install step's
  `&& bash ...` inside an `<INLINE>` block is a bare `&` outside an entity
  reference, which the plugin manager's XML parser rejects — `plugin
  install` would have failed on the host even though `release.yml`
  (sed/grep only) would have shipped it. Rewritten as nested `if`s
  (Dormouse has no `&&` anywhere in its INLINE blocks). Added an XML
  well-formedness test (`simplexml_load_file`) so this class can't regress
  silently again; added `simplexml, dom` to both workflows' PHP extensions.
- `godwitd`: `getenv('GODWIT_ASSUME_CACHE_MOUNTED') ?: null` collapsed the
  string `"0"` to `null` (PHP's `?:` treats `"0"` as falsy), silently
  falling through to the real mountpoint check instead of forcing the
  guard to fail — breaking the override for exactly the negative case it
  exists to test. Compared against `getenv()`'s `false`-on-unset sentinel
  explicitly instead. Test strengthened to point `GODWIT_CACHE_MOUNT_DIR`
  at `/` (a real mountpoint) so the override is proven to force failure
  despite the real check being true.
- `rc.godwit stop()`: the pidfile was removed before confirming a
  SIGKILL-escalated process (and its rcd child) were actually dead. Since
  `is_running()` and `restart` (`stop; start`, unconditional) key purely
  off pidfile presence, a failed kill would let a subsequent start spawn a
  second godwitd/rcd alongside the still-alive orphan. Moved the `rm -f`
  after both liveness checks.

### Verified on the live host (2026-09-15, upgrade → uninstall → reinstall → by-hand event hooks)

- Upgrade 0.1.0 → 0.1.1 installed and restarted cleanly (`rc.godwit stop`
  from the old install, then the new package's install step started it).
- `event/started` and `event/stopping_svcs` present and **executable**
  under `/usr/local/emhttp/plugins/godwit/event/`.
- Invoked `event/stopping_svcs` by hand: godwitd (pid) and the bundled rcd
  both went from 1 running process to 0, and `/var/run/godwit/rcd.sock`
  went from present to absent — no escalation needed (a clean SIGTERM
  stop). Then invoked `event/started` by hand: both came back to 1, socket
  present again.
- `array-ready.sh` read the live `var.ini`/mountpoint state as ready
  (exit 0) during both the upgrade and the reinstall, with `mdState="STARTED"`
  confirmed directly from `var.ini`.
- Uninstall (10/10 checks) and reinstall (all checks) both clean, same as
  0.1.0's checklist.
- `/usr/sbin/rclone` (md5 unchanged) and the Waseh plugin's files
  unchanged across the full upgrade/uninstall/reinstall cycle.
- The heartbeat db under `/mnt/cache/appdata/godwit/godwit.db` persisted
  (144 rows) across the whole cycle, as designed.
- `mountpoint` confirmed on `emhttpd`'s own PATH
  (`/bin:/sbin:/usr/bin:/usr/sbin`), so the cache-mount guard in godwitd
  actually works when invoked from the `started` event hook's environment,
  not just from an interactive shell.
- **Not verified — cannot be from this repo's allowed actions:** whether a
  `godwit` directory exists on the RAM rootfs underneath the live
  `/mnt/cache` mount. You cannot see beneath an active mount without a
  bind-mount, and mutating mounts is outside this phase's allowed host
  actions. Covered instead by the mountpoint check itself: `mountpoint
  /mnt/cache` reported "is a mountpoint" throughout this session, and both
  `array-ready.sh` and godwitd's own guard require exactly that condition
  before ever writing to the state dir — so nothing was written to the
  rootfs during any of the test cycles above, by construction, not by
  inspection of what's under the mount.

### Outstanding

**A real reboot or array-stop/start cycle is still outstanding.** Everything
above exercises the event hooks by hand and the install-time gate against
whatever the array's state happens to be *right now* (already started) —
none of it proves the fix survives an actual boot, where the ~14s gap
between plugin install and cache mount is the failure window this release
exists to close. Kieren needs to schedule a real reboot to close this out
fully; rebooting the host is not one of this phase's allowed automatic
actions.

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
