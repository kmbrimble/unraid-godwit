# Changelog

All notable changes to this project will be documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit
or zero-padded, because Unraid's plugin manager compares versions with a
plain `strcmp`, not a semver-aware comparison — `1.0.10` would otherwise
sort *before* `1.0.9`.

## [Unreleased]

### Fixed

Found live on the host during 0.2.0's own verification (host verification
catches things review can't — this one wasn't in any of the three review
passes, since none of them run `ps aux`): `godwit_rcd_argv()` put the
generated `--rc-user`/`--rc-pass` on rcd's command line. A process's argv
is visible to any other local process via `ps aux` or
`/proc/<pid>/cmdline`, so this printed the exact credentials godwitd just
generated to gate remote management to anyone on the host who could run
`ps`.

- Credentials now go in via `RCLONE_RC_USER`/`RCLONE_RC_PASS` environment
  variables instead (`godwit_rcd_env()`), passed to `proc_open()`'s `$env`
  argument merged onto the current process's environment (passing an
  `$env` array to `proc_open()` replaces the whole environment, not just
  adds to it, so this merges rather than stripping `PATH` etc. from the
  spawned rcd). Confirmed locally that the bundled rclone v1.75.1 honours
  these identically to the flags — same `rc/noop` behaviour, same 401
  without them — and that `ps aux` on the spawned process shows no
  credentials at all.
- `godwit_rcd_argv()`'s tests now assert the opposite of what they asserted
  in 0.2.0: that credentials never appear anywhere in argv. A new
  `godwit_rcd_env()` test covers the replacement.

## [0.2.0] - 2026-09-17

### Added — Phase 2: Remotes (PLAN.md §5 item 2)

A Remotes section on Settings → Godwit lists configured rclone remotes
(name, type, health status, quota) and can add a Google Drive or OneDrive
remote from a pasted `rclone authorize` token, re-authorise, test-connect
and delete one. `godwitd` health-checks every remote hourly (plus on
daemon startup) with a read-only `operations/about` call, stores the
result in SQLite, and sends an Unraid notification once per OK/failed
transition and once per 90% quota crossing. Still no jobs, no uploads —
Godwit makes no write calls to Google Drive or OneDrive in this phase.

- **Remote config now lives in rcd's memory, not just on disk**, so
  managing it has to go through rcd's own rc API rather than the bundled
  CLI (a CLI `--config` write would leave a running rcd blind to the
  change until its next restart, and hourly health checks would fail
  against a remote the page just "created"). `godwit_handle_remote_action()`
  in `plugin/scripts/lib.php` is the single entry point for every
  `remotes_*` action posted from the page.
- **Ground-truthed discovery mid-implementation:** rclone's rc auth gate
  is *not* exempted by unix-socket transport — only commands rclone
  itself marks NoAuth (`rc/noop`, `core/version`, the only two Phase 1's
  heartbeat ever called) skip it. `config/create`, `config/update`,
  `config/delete` and `operations/about` all 403 with "authentication
  must be set up on the rc server" against a unix listener with no
  credentials, which is what Phase 1 shipped (no `--rc-user`/`--rc-pass`
  on the unix socket, relying only on the socket directory's 0700
  permissions). Confirmed locally against the bundled rclone v1.75.1
  binary with a scratch `--config` file, no network. Fixed by generating
  `--rc-user`/`--rc-pass` for *every* listener now, unix included —
  `godwit_rcd_argv()`'s docblock has the full evidence. `godwitd` writes
  the current listener (address + credentials) to a new runtime-only file,
  `$GODWIT_RUNDIR/rc-credentials.json` (0600, same directory as the
  socket, same trust boundary), on every rcd (re)start; the web SAPI reads
  it back to build authenticated rc calls
  (`godwit_write_rc_credentials()` / `godwit_read_rc_credentials()`). This
  is a deliberate architecture change from Phase 1's "the web SAPI never
  needs rcd credentials" — Phase 2's remote management genuinely does.
  `--rc-no-auth` is still never used anywhere (unchanged non-negotiable).
- **Non-interactive remote creation**, ground-truthed against the bundled
  rclone v1.75.1 binary locally (scratch `--config` file, fake/syntactically-
  valid tokens, no network, no real credentials anywhere in this repo):
  - `config create <name> drive client_id=.. client_secret=.. scope=drive
    token=<json>` persists the remote section immediately even though it
    returns a non-empty `State` (a follow-up question); declining every
    follow-up (`result=false`: "replace the token?", "Shared Drive?") over
    rc's `config/update --continue` walks the state machine to `State ""`
    without changing anything already set.
  - Over the rc API, `config/create` needs `opt={"nonInteractive":true}`
    or it hangs (rcd has no stdin to prompt against), and `config/update
    --continue` needs a `parameters` key present (an empty object
    suffices) with `state`/`result` nested *inside* `opt` — confirmed by
    the rc framework's "Didn't find key parameters" 400 until that was
    added.
  - OneDrive's chain reaches a `choose_type_done` state that must be
    answered `"onedrive"` (not declined) — that answer makes rclone's own
    onedrive backend call Microsoft Graph (`/me/drives`, `/me/drive`)
    using the pasted access_token to resolve `drive_id`/`drive_type`
    itself, so Godwit never has to. Tested against a syntactically-invalid
    token, which surfaced as `HTTP 401 InvalidAuthenticationToken` on that
    exact call — the same shape is this feature's auth-expired
    classification, and the "unverified until a real OneDrive token
    exists" case flagged in the handback: rclone then asks to enter the
    drive ID manually, a fallback this phase doesn't support, so the walk
    raises instead of silently creating a broken remote.
  - rclone's own remote-name validation error text is mirrored exactly by
    `godwit_validate_remote_name()`.
- **No secret ever reaches the browser or a log line.** `config/dump` is
  filtered server-side to `name, type, has_client_secret, has_token,
  scope, drive_type` before it leaves `godwit_list_remotes()` —
  `client_id` is not just emptied, the key is absent entirely.
  `godwit_redact()` strips token/client_secret/client_id-shaped values
  from both the ini and JSON key=value/`"key":"value"` shapes before
  anything is logged.
- **Uninstall now preserves `&plgPATH;`** (`rclone.conf`, and any future
  `godwit.cfg`/`jobs.json`) instead of deleting it — see the "Deleting
  credentials" section of README.md for how to wipe it by hand. Only the
  installed `.txz` package files are removed from that directory on
  uninstall now. A new remove-block extraction harness in `tests/run.php`
  (`godwit_extract_remove_block()`/`godwit_run_remove_block()`, mirroring
  the existing install-block harness) proved the pre-fix block deleted a
  fixture `rclone.conf` (red baseline), then that the fix preserves it
  alongside a stale `.txz` cleanup.
- 33 new tests (69 total, up from 36): redaction, name/token validation,
  rc param builders, the drive/onedrive config-state walk (including the
  auth-expired fixture), `config/dump` secret-stripping, error
  classification, quota parsing (including the "unlimited" null-pct
  case), notify-once transition logic (OK↔auth-expired/error, 90% quota
  crossing with reset), the notify script wrapper (stubbed), the
  credentials file round-trip, and one end-to-end test against a real
  bundled rcd instance (add → list → delete) confirming no secret value
  ever appears in a `remotes_list` response.
- Reviewed via `code-diff-reviewer`'s three-pass pipeline against
  `origin/main..HEAD`. Two findings survived, both fixed with a regression
  test each:
  - **[A] agreement 2/3:** `godwit_walk_config_state()`'s 10-step cap could
    be exhausted while rclone's state machine was still non-terminal (no
    `Error`, `State` still set) and the function returned normally — both
    `remotes_add_*` and `remotes_reauth` only check for a thrown exception
    before reporting `{"ok": true}`, so a remote stuck mid-configuration
    could be reported as successfully added. Fixed: a non-empty `State`
    after the loop now throws, same as an `Error` already did.
  - **[B] agreement 1/3:** `godwit_redact()` was dead code — defined and
    unit-tested, but never called from any production log line, despite
    this changelog and its own docblock claiming otherwise. Fixed by
    moving `godwit_log()` into `lib.php` and redacting unconditionally
    inside it (so every future call site gets it for free, not just
    today's), and by redacting every rclone-sourced error string before
    it reaches a `remotes_*` JSON response or a notification description.
  - Both fixes also caught a real gap while testing: the inline
    `key=value` shape (a secret embedded mid-message, not on its own ini
    line) wasn't matched by the original regex — added a third redaction
    pattern for it.

## [0.1.3] - 2026-09-16

### Fixed

Observed on the live host 2026-09-16: `/boot/config/plugins/godwit/` held
both `godwit-0.1.1.txz` and `godwit-0.1.2.txz` (~20MB each, rclone bundled
in), because the install block never deleted old ones — only `remove`
wipes the whole directory. Every upgrade left another ~20MB on the USB
flash drive.

- `godwit.plg`'s install `<INLINE>`, after `upgradepkg --install-new`
  succeeds, now loops over `&plgPATH;/&name;-[0-9]*.txz` (the `[0-9]`
  restricts the match to the `&name;-<version>.txz` shape so a file like
  `godwit-notes.txz` is never touched) and deletes every one except the
  quoted `"&plgPATH;/&name;-&version;.txz"`, gated on `[[ -f ]]`, safe
  under `set -e` when the glob matches nothing, and never touching anything
  else in that directory (`rclone.conf`, future config/token files).
- New `tests/run.php` coverage on the existing install-block harness: old
  `.txz` files plus the current `.txz`, `rclone.conf`, a `.txt` decoy and a
  non-version-shaped `.txz` decoy are left correctly (only the two old
  version `.txz` files gone); an empty glob (no old versions present) still
  exits 0; an `upgradepkg` failure deletes nothing and the block exits
  non-zero. Only the first of these three is a genuine red-baseline test
  against the pre-fix block (the other two are regression guards that
  pass trivially against code that deletes nothing) — see handback.
- Reviewed via `code-diff-reviewer`'s three-pass pipeline (band OWN, score
  2): all three passes returned NO FINDINGS, which CLAUDE.md notes is a
  known failure mode of that reviewer, not evidence of clean code. `advisor`
  caught the real gaps instead: the original glob (`&name;-*.txz`) and
  unquoted `!=` comparison didn't actually enforce the "match exactly the
  `godwit-<version>.txz` shape" requirement, and the original decoy file
  didn't exercise it. Tightened as above.

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
