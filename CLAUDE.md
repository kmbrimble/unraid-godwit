# Godwit — CLAUDE.md

The build plan, kept out of the repo, is the authority for this project.
`PLAN.md` at the repo root is gitignored (it names host shares and sizes) and
is never committed. This file is derived from it and from the repo as it
stands — if the two conflict, re-derive this file from the current repo
state, not from memory of an earlier plan.

**Phase 1 (scaffold + release pipeline) is built and verified on the host**
(v0.1.1, verified 2026-09-15: install, uninstall, reinstall, plus the
`event/started`/`event/stopping_svcs` hooks fired by hand, all via
`scripts/install-on-host.sh` / `scripts/uninstall-on-host.sh` and direct
ssh — see CHANGELOG.md for the full checklist). v0.1.3 additionally verified
2026-09-16: upgrading 0.1.2 → 0.1.3 via `scripts/install-on-host.sh` deletes
`godwit-0.1.1.txz` and `godwit-0.1.2.txz` from `/boot/config/plugins/godwit/`,
leaving exactly `godwit-0.1.3.txz` and an untouched `rclone.conf` (sha256
unchanged, still the empty-file hash), one `godwitd` and one bundled `rcd`
running, socket present, heartbeat advancing across the restart. A real
reboot/array-stop test is still outstanding — see "Deploy and verify" below.

**Phase 2 (Remotes) is built and verified on the host** (v0.2.1, verified
2026-09-17). The live `[gdrive]` remote (real Google Drive credentials,
created by hand before this phase) was backed up to a root-only `/tmp` file
and hashed before any host action. Verified: upgrading 0.1.3 → 0.2.0 → 0.2.1
via `scripts/install-on-host.sh`; the health check ran automatically on
daemon startup against the real `gdrive` remote and stored `status=ok,
total=5497558138880 (5 TiB), used=780484589 (~744 MiB)` in
`/mnt/cache/appdata/godwit/godwit.db`'s new `remotes_health` table; the
`remotes_list` web action (exercised via CLI-PHP, matching earlier phases)
returned that remote with no client_id/client_secret/token value present —
confirmed by grepping the JSON response for both secrets' prefixes (0
matches); uninstalling preserved `rclone.conf` with the exact same non-token
line content (`grep -v '^token' | sha256sum` matched before/after — a
mid-verification token refresh legitimately changed the full-file hash, not
a fault, see CHANGELOG 0.2.0/0.2.1) and `gdrive` still reported `ok` after
reinstalling; exactly one `godwitd` and one bundled `rcd` throughout, socket
present, heartbeat advancing. **0.2.1 exists because host verification
itself caught a bug 0.2.0's review passes missed**: rcd's generated
`--rc-user`/`--rc-pass` were visible on the process's command line via
`ps aux`/`/proc/PID/cmdline` to any other local process — fixed by moving
them to `RCLONE_RC_USER`/`RCLONE_RC_PASS` environment variables, and
reverified live (`ps aux` shows no credentials, `/proc/PID/environ` shows
`RCLONE_RC_USER` instead, health check against `gdrive` still succeeds).
The `/tmp` backup was deleted once the live file was confirmed intact.
**Not verified this phase:** live OneDrive remote creation (no OneDrive
token was available this session — the creation path is tested against
fixtures ground-truthed against the bundled rclone binary locally, see
CHANGELOG 0.2.0) and sending a real Unraid notification (not required by
this phase's scope). The real reboot/array-stop test from Phase 1 is still
outstanding — unchanged by this phase.

**0.2.2 (verified 2026-09-17)** fixed the Remotes page swallowing every
Test/Add/Reauth/Delete result (the background 30s remotes-list refresh was
clearing the action-message div milliseconds after a result appeared) and a
timed-out health check being recorded as `unchecked` instead of `error`.
Verified live via `scripts/install-on-host.sh 0.2.2`: upgrade 0.2.1 → 0.2.2
clean, exactly one `godwitd` and one bundled `rcd`, socket present,
heartbeat advancing. CLI-PHP `remotes_test` against the real `gdrive`
remote returned `{"result":{"status":"ok",...}}` and wrote `remote test
gdrive: ok` to `/var/log/godwit.log`. `remotes_add_onedrive` with an empty
name (plus a syntactically-valid token, so the name failure — not a
token failure — was the thing under test) returned `{"error":"name is
required"}`; with a non-clashing name and a garbage (non-JSON) token
returned `{"error":"no JSON object found in the pasted text — paste the
whole blob rclone authorize printed"}` — both logged, neither reached
`config/create`: `[gdrive]`'s non-token lines were byte-identical before
and after (`grep -v '^token' | sha256sum` match) and `rclone.conf` still
has only `[gdrive]`. The installed `Godwit.page` was grepped for
`godwitRunAction`/`godwitShowMessage`/`godwitActionPending` (21 matches).
`PRAGMA table_info(remotes_health)` on the live db shows the new
`fail_count` column, confirming the ALTER-TABLE migration ran against an
existing 0.2.1 table. **Not verified this phase:** a real Add against a
real OneDrive/Drive token reaching rcd (no live token available this
session, same gap as 0.2.0/0.2.1 — the extraction/validation/config-create
paths are otherwise covered by `tests/run.php` and by the CLI-PHP checks
above), and the notify rule's 2-consecutive-failure behavior against a
real transient rcd outage (covered by unit tests with an injected `$call`,
not exercised live). The 60s `operations/about` timeout (raised from 15s)
means godwitd's health-check loop can now block up to 60s per remote on a
genuinely wedged rcd — a known ceiling of this fix, not a regression, since
the loop already serializes remotes one at a time. The real reboot/
array-stop test from Phase 1 remains outstanding — unchanged by this
phase.
**Phase 3 (Google full backup, v0.3.0) is built and offline-verified, host
verification in progress as of 2026-09-17.** One job per share (Filing
Cabinet, Kieren, Teegan, Photos) syncing `/mnt/user/<share>` to
`gdrive:godwit/<share>` with `--backup-dir` versioning; Kieren excludes
`/TimeMachine/**` and `/Backup/BombVault/**` (D13/D14); a rolling-24h
700 GiB budget ledger; a default 22:00–06:00 250 Mbit/s window with a
"Run now" override (D15); one active job per remote, queued in array
order; version retention (30 days); a Jobs section on the settings page.

Verified offline (196/196 tests, `php tests/run.php`, 2026-09-17):
job-direction assertion (including the reversed-direction case), filter
compilation against the real bundled rclone binary (`lsf -R
--filter-from`), the full `_config`/`_filter` rc call chain proven against
a real local-backend `rcd` fixture (not just the filter file's contents —
the actual form-encoded JSON path every job takes), budget ledger maths,
window evaluation (midnight wrap, DST-free `Australia/Brisbane`), Mbit→
bytes/s conversion (`31250000B`, never `31.25M` — that's ~262 Mbit/s),
queue selection/ordering, retention purge path safety, and notification
rules. Two facts were ground-truthed against the bundled v1.75.1 binary
by grepping its exact error strings rather than assumed: rclone's
`--max-transfer` cutoff text is `"max transfer limit reached as set by
--max-transfer"` and its `--max-duration` cutoff text is `"max transfer
duration reached as set by --max-duration"` — `godwit_classify_job_outcome()`
matches on these literally, and both were exercised live against a real
rcd (not just asserted) in `tests/run.php`, including proving a
`--max-duration` cutoff leaves no partially-written file at the
destination.

**Two pre-push review rounds (6 passes total, `code-diff-reviewer`) plus
a focused 3-pass round on the fix commit found and fixed 5 issues**,
the most serious being a confirmed **blocking** bug: `godwit_select_next_jobs()`
had no concept of "already ran this session", so once Filing Cabinet
finished and dropped out of the daemon's in-memory active-job tracking,
the very next tick re-selected Filing Cabinet again (always first in
queue order) instead of advancing to Kieren — the live seed would have
looped re-syncing Filing Cabinet forever. Fixed with a `$sessionStartTs`
the daemon resets on every window/"Run now" open transition (and seeds
correctly from the window's real start on a mid-window restart) plus
`godwit_terminal_job_outcomes()`; regression tests reproduce the loop and
prove the fix. The other four: the budget-ledger trim shared a timer
variable with the pre-existing heartbeat trim and could never actually
run; a mid-loop `rcd` crash left in-flight jobs and the process-wide
bwlimit un-reconciled against the freshly restarted `rcd`; a bare `.`
share name bypassed the traversal guard; the "Run now" queue-drained
check wasn't passed the same session-awareness as the real selection.
`advisor` timed out once during the first review round and was used
normally thereafter; every finding was independently re-verified by
direct code reading (and, for the two cutoff strings, against the real
binary) before being fixed, not applied on trust.

**Host-verified 2026-09-17 22:28–22:42 AEST**, via
`scripts/install-on-host.sh 0.3.0` plus direct CLI-PHP calls through
`godwit_handle_job_action()`/`godwit_rc_call_params()` (the exact
functions the web page and daemon use, not a bypass):
- rclone.conf's non-token lines hashed to `c26f0adb…` before and after
  the upgrade — unchanged, and still only `[gdrive]`.
- The daemon's effective timezone logged as `Australia/Brisbane` on
  startup (`grep 'effective timezone' /var/log/godwit.log`) — the host's
  PHP defaults to UTC and has no `/etc/timezone`, so this is
  `godwit_resolve_timezone()`'s hardcoded fallback landing correctly, not
  an ini setting; confirmed the host's real clock (`date`) is AEST.
- Filing Cabinet ran automatically the moment the daemon started (the
  22:00–06:00 window was already open): completed in ~3m16s, 135,775,764
  bytes, 237 files, 0 errors. `operations/size` on
  `gdrive:godwit/Filing Cabinet` read back the identical
  `{"bytes":135775764,"count":237}`; a recursive `operations/list`
  (247 entries incl. directories) contained zero `.DS_Store`/`._*` names.
  A second sync of the same job transferred **0 bytes, 0 files** in 2.1s
  — confirmed idempotent.
- Kieren's exclusion filter, applied read-only via `operations/list`
  with `_filter.FilterFrom` against the real `/mnt/user/Kieren` tree (no
  upload, no `dry_run` job even started), returned zero entries under
  `TimeMachine/` or `Backup/BombVault/` — the filter file's actual
  content (`- /TimeMachine/**`, `- /Backup/BombVault/**`, plus the global
  Mac-junk excludes) was printed and matches `godwit_compile_filter_rules()`
  exactly.
- Kieren/Teegan/Photos enabled via the real `jobs_save` action, then
  `run_now` triggered via the real `run_now` action (both exactly as the
  page would post them). godwitd picked up the change within 30s and
  **the queue advanced correctly: Filing Cabinet → Kieren**, confirming
  the blocking queue-advancement bug is fixed live, not just in tests.
  Observed for ~5 minutes across three polls: bytes climbed
  535MB → 3.35GB → 7.33GB, `core/stats` speed held at 29.4–29.8 MiB/s
  (the 250 Mbit/s bwlimit, never exceeding it), and the gdrive ledger's
  `used_24h_bytes` tracked the same growth (671MB → 3.49GB → 7.46GB).
  **Left running** per D15 — Teegan and Photos will follow once Kieren's
  remote frees up, still inside tonight's session.
- `/var/run/godwit/rcd.log` is `-rw-r--r--` (0644, world-readable) and
  contains zero matches for `client_secret`/`refresh_token`/`access_token`.
- Exactly one `godwitd` and one bundled `rcd` process throughout.
- rclone.conf's non-token hash re-confirmed unchanged after the full
  sequence above (upgrade, two smoke-test syncs, the exclusion read, and
  ~7.5 GB of real Kieren upload): still `c26f0adb…`, still only `[gdrive]`.

**v0.4.0 (built and offline-verified, 2026-09-18) is a settings-page UX
rework plus one real bugfix, built while a live Google Drive seed
(job_runs id 2, "Kieren") was running on the host — host access this
session was scoped to read-only inspection (`ps`, `tail
/var/log/godwit.log`, `ssh` greps of the host's own `/usr/local/emhttp`
webGui files) specifically to avoid re-uploading budget mid-seed; no
install, no daemon/rcd restart, no mutating rc call was made.** Verified
offline: 210/210 PHP tests (`php tests/run.php`) plus 10/10 Node checks
(`node tests/windows_form_test.mjs`, now wired into the PHP suite).

- **UX**: Add Google Drive/Add OneDrive/the OneDrive drive picker are now
  jQuery UI `.dialog()` modals; Status/Remotes/Jobs/Windows and
  speed/Advanced are collapsible. Which stock unRAID mechanism and how it
  was verified: jQuery UI is confirmed loaded globally by reading the
  host's `/usr/local/emhttp/webGui/include/DefaultPageLayout.php` (line
  142: a plain synchronous `<script src=".../dynamix.js">` in `<head>`,
  before the page body) and confirming `dynamix.js` itself is the jQuery UI
  bundle (`grep 'V.ui.version' dynamix.js` → `"1.14.1"`), plus finding
  `.dialog({modal:true,...})` already used the same way on stock pages
  (`dynamix/DeviceInfo.page`, `dynamix.vm.manager/VMMachines.page`). No
  stock collapsible/accordion widget was found anywhere in
  `webGui`/`dynamix*` (`slideToggle`, `.collapse(`, `<details` all came up
  empty except one unrelated third-party plugin) — native
  `<details>/<summary>` is therefore a documented negative, not a stock
  mechanism, which is why it was chosen. The Windows and speed section
  became a generated form; day-index-to-weekday correctness is proven by a
  test that exercises `godwit_time_in_window()` itself across all 7
  indices, not just the label text.
- **Bugfix (issue #1, folded into this release)**: `godwitd`'s version
  retention purge had never actually deleted anything since Phase 3 shipped
  — `operations/list` was called without the `remote` param rclone
  requires, so rcd rejected every call and the candidate list was always
  empty. Found by the user reading `/var/run/godwit/rcd.log` during the
  live seed. Fixed via a new `godwit_list_versions_dirs()` helper with a
  tagged `dirs`/`absent`/`error` result, ground-truthed against the real
  bundled rclone v1.75.1 binary through a local-backend rcd fixture (the
  pre-fix call shape proven rejected, the fix proven to list real
  directories and correctly treat a never-run share as absent rather than
  an error).
- **Review**: two rounds of `code-diff-reviewer` (6 passes each, unattended
  MID band, score 7 both times). Round 1 (UX alone): 6× NO FINDINGS. Round
  2 (UX + the retention fix together): a real bug at 4/6 agreement —
  `godwitAdd()`'s success path immediately closed the dialog it had just
  written a confirmation message into, hiding it — fixed and pinned with a
  regression test proven red on the pre-fix code. See CHANGELOG.md 0.4.0
  for the full detail on both rounds.

**Not verified this release** (unchanged from prior phases, plus): no live
browser exercise of the new modals/collapsible sections/generated form —
this was a headless session with a live seed running, so all UI
verification is via source-shape regression tests and the reasoning above,
not a real click-through. The user should confirm this in a real browser
before/while installing. The real reboot/array-stop test from Phase 1
remains outstanding.

**0.4.1 (built and offline-verified 2026-09-18, host verification
pending — the user installs)** fixes spurious "run failed" alerts seen
live on Kieren/Teegan the night of 2026-09-17→18: a MaxTransfer/daily-cap
cutoff always leaves some destination directories unreached, and rclone's
own directory-modtime pass then fails on every one with "directory not
found" — `job/status`'s returned error is rclone's own `currentError()`
precedence (fatal, then plain, then no-retry), and the graceful budget
cutoff is the lowest of the three, so that masking error, not the real
cutoff, was what got classified and notified as a failure.
`godwit_build_sync_params()` now sets `NoUpdateDirModTime` (the rc
`_config` field name, ground-truthed against the bundled v1.75.1 binary's
`options/info` call, not guessed from the flag spelling) on every
sync/copy job. A `budget`/`window` outcome now sends a `normal`-importance
"stopped at the cap/window, resumes next window" notification instead of
an alert, via a new `godwit_cap_stop_notification()`; genuine transfer
errors riding along with a cutoff still surface, via a per-path error-
count baseline (the job's configured `Transfers` for either budget path —
rclone's own graceful MaxTransfer cutoff or godwitd's own ledger-
triggered `job/stop` — and 0 for MaxDuration's fatal cutoff) rather than
one hardcoded number. Getting this right took two rounds of self-review,
both catching a wrong assumption the automated review passes didn't
flag: first that a hardcoded ">1" was wrong (a clean ledger-triggered
stop at `Transfers=4` costs exactly 4 errors, not 1), then that "exactly
1" for rclone's own graceful cutoff was ALSO wrong — that number had only
been measured at `Transfers=1`; repeating the same config at
`Transfers=8` twenty times over found 1 through 4, since each transfer
worker independently discovers the cutoff. MaxDuration's fatal cutoff, by
contrast, held at exactly 0 across 10 repeats at `Transfers=8` — rclone's
fatal path never increments the error counter, unlike the graceful path.
Verified offline (218/218, `php tests/run.php`, stable across 11 repeat
runs) against real local-backend rcd fixtures for all three cutoff paths,
including a regression test reproducing the exact directory-skip shape
from the live incident and proving it now classifies `budget`, not
`error`. godwitd's own glue code that picks which baseline to pass
(`$outcome === 'window' ? 0 : $aj['transfers']`) is untested by
construction, matching godwitd's existing convention of testing only its
pure building blocks in lib.php — confirmed by deliberately reverting it
to a hardcoded value and finding the full suite stayed green. **Not
verified this release**: none of the above
against the live Google Drive remote or a real budget/window cutoff on
the host — the fix is offline/local-backend only pending the user's
install. The skipped-delete-phase side effect seen in the same host log
("not deleting files/directories as there were IO errors") was
investigated and found to be correct, unrelated rclone behaviour (the
delete phase is gated on any `currentError()`, including a clean cutoff
itself, not only the directory-modtime bug) — confirmed live with a
local-backend fixture that a truncated run still skips deletion even with
`NoUpdateDirModTime` set, and needs no fix.

See PLAN.md §5 for the remaining phases.

## Test command

```
php tests/run.php
```

(Requires `php-cli`, `php-sqlite3`, `php-curl`; `apt-get install -y php-cli
php-sqlite3 php-curl` if missing. `scripts/build-plugin.sh` additionally
needs `curl`, `unzip` and `xz-utils`. As of 0.4.0 this also requires `node`
(present by default on `ubuntu-latest` CI runners) — `tests/run.php` shells
out to `node tests/windows_form_test.mjs` and hard-fails, not skips, if
`node` is missing.)

## Deploy and verify

1. `scripts/install-on-host.sh [version] [--forced]` — installs or upgrades
   on the live host, waiting for the CDN copy of `godwit.plg` to converge on
   the released version and md5 first (raw.githubusercontent.com caches for
   up to 5 minutes), then checks: flash `.plg` version, package registration,
   installed tree, stock README, bundled rclone reports v1.75.1, `rc.godwit`
   status, exactly one `godwitd` and one `rcd` process (from the bundled
   binary), the rcd unix socket present, `rclone.conf` created 0600, that
   `event/started` and `event/stopping_svcs` are present **and executable**
   (`emhttp_event` gates on `-x`, not `-f` — see non-negotiable 4's
   exception), that `array-ready.sh` reads the live `var.ini`/mountpoint
   state as ready, the Plugins-tab row rendering through the host's own
   `ShowPlugins.php`, the settings-page status call reporting the rclone
   version, and that `/usr/sbin/rclone` and the Waseh plugin's files are
   byte-for-byte unchanged (md5 + mtime snapshot before and after).
   As of 0.1.1, also confirm by hand: invoking Godwit's own
   `event/stopping_svcs` then `event/started` stops and restarts the
   daemon and rcd cleanly (pgrep counts and socket presence before/after).
   **Not covered by any of this:** a real reboot or array-stop/start
   cycle — only the event hooks fired by hand and the install-time gate
   against whatever the array's state happens to be *right now*. A real
   reboot test is outstanding and needs to be scheduled deliberately
   (rebooting the host is not one of this phase's allowed automatic
   actions).
2. `scripts/uninstall-on-host.sh` — removes and asserts a clean revert: no
   flash `.plg`, no flash plugin dir (including `rclone.conf` — nothing to
   preserve yet, see the decision recorded in `godwit.plg`'s remove block
   and in CHANGELOG.md 0.1.0), no installed tree, no plugin-manager
   registration, no `godwitd` process, no `rcd` process, no unix socket, no
   `/var/run/godwit/` rundir, no pid file, no package entry. The heartbeat
   db under `/mnt/cache/appdata/godwit/` is deliberately preserved.
3. Watch the release workflow with `gh run list` / `gh run watch <id>
   --exit-status` after every push to `main`. Release is automatic on merge
   to `main` (`.github/workflows/release.yml`); only *then* run step 1.
4. Host access for this phase is scoped to installing, uninstalling and
   reinstalling Godwit itself, via `ssh -i /root/.ssh/unraid_secretsman
   root@192.168.0.10` and the plugin manager (`plugin install`/`plugin
   remove`) — no other plugin, container, share or config on that host.

## Non-negotiable constraints

Carried over from `unraid-dormouse`'s CLAUDE.md — hard-won there, and true of
any Unraid plugin built on `dynamix.plugin.manager`, not specific to that
project.

1. **Versions are compared with `strcmp`, not semver.** The plugin manager's
   `plugin` script does a plain string compare, so `1.0.10` sorts *older*
   than `1.0.9`. Keep every version component single-digit or zero-padded.
   Never cross from date-based to semantic versioning without
   `plugin install <url> forced`. The release workflow should refuse to
   publish a version that does not sort after the previous tag as a plain
   string, the same way Dormouse's `scripts/version-sorts-after.php` does.
2. **A `FILE` block with no `<MD5>`/`<SHA256>` is never overwritten.** A
   plain `<INLINE>` file is written once and skipped forever. Don't add a
   versionless md5 sidecar and re-check the download against it in a script
   — that fails every upgrade after the first with a spurious mismatch. The
   plugin manager verifies the `.txz` against the `<MD5>` on its own `FILE`
   block; that should be the only check.
3. **The Plugins tab description comes from the installed `README.md`.**
   `plugin/README.md` should stay in the stock shape: a bold one-line title,
   a blank line, one paragraph, no headings. Packaging the repo README puts
   an H1 into a shared table cell and blows out the row height.
4. **Exercise the uninstall path deliberately.** Every script a `.plg`
   `FILE` block invokes must have a shebang and be gated on `[[ -f script ]]`,
   never `[[ -x script ]]` — an executable-bit check silently skips a script
   that lost its bit in transit.
   **Exception: `plugin/event/*` hooks.** `emhttpd`'s own dispatcher
   (`/usr/local/sbin/emhttp_event` on the host) gates these on the
   executable bit (`[ -x $Dir/event/$1 ]`), not `-f` — that is Unraid's own
   code, not this repo's `.plg` gating convention, and cannot be changed.
   `build-plugin.sh` must `chmod +x` every `event/*` script; a lost bit
   there means the hook silently never fires (confirmed on 0.1.1's boot-
   order fix).
4a. **A bare `&` inside a `.plg` `<INLINE>` block breaks the XML parse.**
   The plugin manager parses `.plg` as XML with entity substitution
   (`&emhttp;`, `&name;`, etc.) — a literal `&&` (or any other bare `&`)
   is a well-formedness error that `plugin install` rejects on the host,
   even though `release.yml`'s sed/grep-only pipeline will still tag and
   publish it. Use nested `if`s instead of `&&` (plain `||`, with no `&`
   character, is fine — `bash ... stop || true` already relies on it
   elsewhere). `tests/run.php` parses the `.plg` with `simplexml_load_file`
   to catch this class before it ships (added after 0.1.1 nearly shipped
   one, caught by review before it reached a release).
5. **`upgradepkg` may leave both versions in `/var/log/packages/`.**
   Cosmetic (tmpfs, rebuilt at boot), not a bug.
6. **`gh release list` is not evidence about what is on the host.** Read the
   installed `.plg` on the host itself.

### WebGUI rules

- **Do not use multipart `FormData`/`fetch()`** — incompatible with the
  host's nginx `auth_request` subrequest setup. Plain urlencoded `$.post` is
  the only proven-working POST path in the Unraid webGui.
- **Do not add a custom CSRF check.** `local_prepend.php` consumes and unsets
  `$_POST['csrf_token']` before plugin code runs; a redundant check always
  403s.
- **Functions reachable from both CLI and web must not reference `STDOUT`,
  `STDERR` or `$argv`.** A CLI-SAPI test runner has these; the web SAPI
  doesn't, which makes this bug class structurally invisible to tests run
  only from the command line.

### Release requirements (every build)

- `.plg` published as a GitHub Release with the `.txz` attached and the real
  `<MD5>` written onto the FILE block.
- `CHANGELOG.md` updated (Keep a Changelog format).
- `README.md` (repo) and `plugin/README.md` (stock one-liner) both current.
- `CLAUDE.md` current — derived from the repo, never invented; cite
  verification method and date inline.
- Pre-push secrets scan: a broad pass across tracked files, then a targeted
  pass on new files for serials, credentials, IPs, keys.

## What this is

Offsite backup for Unraid shares, built as a GUI over rclone: full-share
backup to Google Drive, selective folder/file backup to OneDrive personal,
a per-remote rolling 24h upload budget, scheduled time windows with
configurable speed limits, and backup-only semantics (sync with
`--backup-dir` versioning, no two-way sync). See `PLAN.md` for the full
architecture, decisions and open questions — it is the authority and is not
committed.

## Scope notes

- This repo is for the plugin itself: the daemon, the settings pages, the
  packaging and release pipeline.
- It is not a general rclone wrapper — job types are deliberately limited to
  the two backends and two selection modes above.
- Google client ID/secret and OAuth tokens are configured per-install by the
  user through the settings page; none of that belongs in this repo.
