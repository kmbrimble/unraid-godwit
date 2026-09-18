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

**v0.4.2 (built and offline-verified, 2026-09-18) is a defect fix plus a
wording fix, prompted by tonight's live budget behaviour: after last
night's seed used the full 700 GiB between 22:28 and 06:10, the ledger
trickles back at roughly the window's bwlimit rate, and 0.4.1's "start
whenever remaining > 0" restarted a resuming job on every trickle,
re-listing the whole share each time.**

- **Minimum budget threshold (Kieren's call: 20%).** A job whose LAST run
  ended by hitting the daily cap (`outcome === 'budget'`) — known
  outstanding work — is now held back from restarting until its remote's
  remaining rolling-24h budget is at least `GODWIT_MIN_BUDGET_FRACTION`
  (0.20, a named constant with the reasoning next to it in `lib.php`) of
  that remote's configured cap; 140 GiB at the live 700 GiB cap. A job
  whose last run *completed* cleanly is never gated — it has no known
  outstanding work and may just need a few MB for an incremental sync, so
  gating it too could stall it a whole night for nothing. Settable per
  remote via `settings.json`'s `budget_min_fractions` map (no UI, as
  scoped — `?? GODWIT_MIN_BUDGET_FRACTION` falls back cleanly for every
  remote that doesn't set one).
- **Gate placement matters and was gotten wrong once during review, then
  fixed before merge.** The first design gated inside the candidate-start
  loop, AFTER `godwit_select_next_jobs()` had already reserved the gated
  job's remote — which would have stalled every other job on that remote
  behind it, exactly the completed-job exception this feature exists to
  protect. Fixed by giving `godwit_select_next_jobs()` a new optional
  `$gatedJobNames` param: a gated job is skipped WITHOUT reserving its
  remote, so a sibling job on the same remote still gets picked the same
  tick. Every enabled job gated is a normal quiet state (tested): the
  queue selects nothing, does not spin, and does not error.
  `godwit_budget_reached_notification()` (the "budget reached" alert) no
  longer fires for a gated job — the cap-stop notification already
  covered it when the run first hit the cap. The "Run now" override does
  NOT bypass the threshold gate — a resuming job stays gated even under
  Run now, since the underlying constraint (Drive API call cost per
  restart) doesn't go away just because the user asked for an immediate
  run. godwitd logs the hold-back once per job per gated spell (not once
  per 15s tick, tracked via `$budgetGateLogged`), found by explicitly
  testing for a spin/deadlock, not assumed safe.
- **Human-readable run status.** The raw `outcome` string ("error (669.2
  GiB) at 18/09/2026, 05:41:51" for a run that behaved exactly as
  designed) is replaced by a new `godwit_job_status_label()`: `completed`
  reads as completed; `budget`/`window` read as a calm "paused — …,
  resumes HH:MM (X uploaded)" using the REAL configured window start
  (`godwit_next_window_start_label()`, midnight-wrap-safe, picks the
  earliest of multiple configured windows) rather than a hardcoded
  "22:00"; a job currently held back by the new threshold reads "waiting
  for daily cap to free up"; a genuine failure still plainly says `error`
  (throttled/auth-expired get a more specific "error — …" since those
  reasons are actually known; a bare `error` has no stored reason text to
  report beyond pointing at the log, by design — see below).
  `godwit_build_jobs_status()` computes this server-side into a new
  `status_text` field per job; `Godwit.page`'s JS now renders it verbatim
  instead of rebuilding a label from `last_run.outcome` client-side.
  `godwit_cap_stop_notification()` gained an optional 6th `$resumeLabel`
  param (backward compatible — every pre-0.4.2 5-arg call site and test
  still passes) so the notification embeds the same real resume time.
  Historical `job_runs` rows stored `error` by 0.3.0/0.4.0 — before
  outcome classification distinguished budget/window from a real failure
  — are indistinguishable from a genuine error here and are left as
  `error`; there is no stored error-text column to reclassify them from,
  and CLAUDE.md's standing rule is no guessed backfill. This is honest by
  construction: only rows actually classified `budget`/`window` (0.4.1+)
  get the calm wording; nothing pre-0.4.1 is silently reinterpreted.
- **A real bug found by the tests themselves, not by review.** The first
  implementation of both `godwit_next_window_start_label()` and
  `godwit_job_status_label()`'s "at HH:MM:SS" formatting used PHP's plain
  `date($format, $ts)`, which formats in the PROCESS's global default
  timezone, not the timezone of the `DateTimeImmutable $now` object the
  functions were handed — silently correct in production only because
  godwitd sets the process default at startup (and godwit-api.php, the
  web SAPI entry point, now does too — see below), but wrong the moment a
  caller (a test, or any future consumer) passes a `$now` in a different
  zone. Caught immediately by 3 of the new tests failing red with values
  exactly 10 hours off (AEST vs UTC): fixed by reconstructing the label
  timestamp through `DateTimeImmutable`/`setTimezone($now->getTimezone())`
  instead of the global-default-dependent `date()` function.
- **Timezone bootstrap gap closed.** `godwit_build_jobs_status()` is
  reachable from the web SAPI (`godwit-api.php`), which — unlike godwitd —
  never called `godwit_resolve_timezone()`; CLAUDE.md already records the
  host PHP defaults to UTC. Without fixing this, every "resumes HH:MM"
  label rendered by the settings page would have been computed against a
  UTC "now" and read hours off from the daemon's own (correctly
  timezoned) notifications. `godwit-api.php` now calls
  `date_default_timezone_set(godwit_resolve_timezone(...))` at bootstrap,
  mirroring godwitd's own startup line exactly.
- **Page phase label.** `Godwit.page`'s intro no longer says "Phase 3" —
  phases are a PLAN.md planning concept, not something a user should see.
  PLAN.md gets a note that the 0.4.x series is UX/defect work sitting
  between Phase 3 and Phase 4, so the version/phase mismatch doesn't
  confuse a future session (PLAN.md itself is gitignored, so this note
  lives only in the working tree, not in any commit).
- **Review**: 3 passes of `code-diff-reviewer` returned 3× NO FINDINGS
  (a known failure mode of that reviewer per its own skill doc, not
  treated as proof of correctness on its own). Escalation score was 5
  (OWN band: exposure 1, authority 1, data 1, reversibility 1, test gap 0,
  pattern divergence 0, module spread 1 — no counsel), so only `advisor`
  ran as a further check; it read the diff directly rather than relying
  solely on the 0-finding passes and found no blocking issue, but flagged
  process gaps (this section, the CHANGELOG conversion, and the tag/md5
  verification below) that are addressed here.

Verified offline (248/248, `php tests/run.php`; 218 pre-existing + 30 new,
6 genuinely red before the fix — 3 the timezone bug above, 2 test-authoring
bugs in the new tests themselves caught by running them, 1 consequential
from the timezone fix). **Not verified this release**: nothing against the
live Google Drive remote or a real trickle-budget restart scenario on the
host — this was built read-only against the host (no install, no
daemon/rcd restart, no mutating rc call; the host was left running
tonight's live seed the whole session) specifically because the user
installs this one personally before 22:00 AEST. Tonight's first start of
each of the four jobs will NOT be gated by the new threshold — their
current `last_run` rows are all legacy `error` (pre-0.4.1), not `budget`;
the gate only engages once a job's outcome is actually stored as `budget`,
which happens from tonight's first budget-triggered stop onward. No live
browser click-through of the updated status text or phase-free intro
paragraph — same gap as 0.4.0, unchanged by this release.

**v0.4.3 (built and offline-verified, 2026-09-18) fixes v0.4.2's threshold
gate and calm status wording being dead on arrival in production — found
by the user reading the live host, not inferred.** Evidence: `grep -c
"stopped mid-run" /var/log/godwit.log` on the host returned 0 — godwitd's
own mid-run ledger-based budget stop had never fired once. Root cause:
`MaxTransfer` is set to the remote's remaining budget at job start, so
rclone's own CAUTIOUS cutoff always trips *before* godwitd's own
`remaining <= 0` ledger check ever sees zero — `$stoppedForBudget` was
therefore always `false`. That left the cutoff's own error text as the
only signal `godwit_classify_job_outcome()` had, and that text is a
`NoRetryError`, the lowest-precedence kind `job/status`'s `currentError()`
returns — any genuine per-file error in the same run (every one of that
night's three budget-capped runs had one: Photos 63, Teegan 85, Kieren
508) always won and overwrote it. The docblock this function carried into
0.4.2 explicitly accepted this as "correct" (a masked cutoff "genuinely
needs attention"); live evidence proved that judgement wrong for the
actual case — a run that hit the cap AND had file errors is still a cap
stop, and both the v0.4.2 threshold gate and status wording (which both
key off `outcome === 'budget'`) need to see it as one.

- **Byte-proximity fallback**, ground-truthed locally against the bundled
  v1.75.1 binary before being trusted (never guessed): godwitd now carries
  the exact `MaxTransfer` it passed to each active job
  (`$activeJobs[$name]['max_transfer']`) through to
  `godwit_classify_job_outcome()`, which — only after every more specific
  textual signal (throttle, explicit cutoff/duration text, auth-expiry)
  has had a chance to match — falls back to `godwit_bytes_near_max_transfer()`:
  within 2% of the configured limit, either direction, counts as a budget
  stop. 2% was chosen from three repeated local measurements each way, not
  assumed: many small, fast-completing files let CAUTIOUS *overshoot* its
  own limit by up to +1.6% (several files slip past the cutoff check
  before it's re-evaluated); a bandwidth-throttled transfer of larger
  files (closer to the real Drive workload) *undershoots* by up to -1.6%
  instead (the check fires before starting a file that would exceed the
  limit). It cannot mistake a job that merely used a lot of a large budget
  for one that was actually cut off: that case has `$errorMsg === ''`, so
  `godwit_classify_job_outcome()` already returns `completed` before the
  byte check is ever reached.
- **The real error count is never hidden by the reclassification** — the
  bug report's explicit constraint. `godwit_job_status_label()` now
  appends "— N transfer errors also logged, see /var/log/godwit.log" to a
  budget/window pause whenever the stored error count exceeds the clean
  cutoff's own accounted baseline (the job's currently configured
  `Transfers` for a budget stop, 0 for a window stop — mirroring
  `godwit_cap_stop_notification()`'s existing baseline concept, since
  `job_runs` has no column recording what `Transfers` actually was during
  a historical run). A clean cutoff (errors at/below baseline) stays
  quiet; 63 real errors on a Transfers=4 job now surfaces as "59 transfer
  errors also logged" on the page itself, not just in a one-off
  notification that's already gone by the time someone looks.
- **Decided, not silently left as-is: the threshold gate stays scoped to
  `outcome === 'budget'` only.** The bug report asked directly whether a
  job whose last run ended `window` or `error` with outstanding work
  should also be gated. Traced through the mechanics rather than guessed:
  `error` is a terminal outcome (`godwit_terminal_job_outcomes()`), so a
  job that ends in `error` is never re-selected again this session
  regardless of gating — no trickle-restart risk exists for it, gated or
  not. `window` can only occur when `MaxDuration` was set, which only
  happens when a window was active and it is NOT a "Run now" override —
  meaning the window that produced it has, by definition, just closed, so
  the candidate-start loop will not attempt selection again until the
  window reopens (hours later) or Run now is triggered — again no 15s-tick
  trickle-restart risk. `budget` is the only outcome that is both
  non-terminal (stays eligible for re-selection within the same session)
  and can genuinely be re-picked on every tick while the gate that causes
  the trickle-restart problem (a live window or Run now) stays open.
  Gating only `budget` is therefore correct as built, not a gap left by
  oversight.
- **Review**: 3 passes of `code-diff-reviewer` returned 3× NO FINDINGS (a
  known failure mode of that reviewer, not proof of correctness on its
  own). Escalation score 5 — OWN band (exposure 1, authority 1, data 1,
  reversibility 1, test gap 0, pattern divergence 0, module spread 1) — no
  counsel. `advisor` read the diff directly and found 4 real issues, all
  fixed pre-merge: the status-text error suffix originally showed
  errors-minus-baseline instead of the raw count (disagreed with
  `godwit_cap_stop_notification()`'s own wording for the same run — fixed
  to show the raw count on both surfaces); this file and CHANGELOG.md
  0.4.3 originally pointed at each other for the evidence below instead of
  stating it directly (fixed); `godwit_bytes_near_max_transfer()`'s
  docblock claimed its ±1.6% figures lived in a committed test when they
  were ad-hoc local numbers (fixed, real figures now in CHANGELOG.md
  0.4.3); and the large-single-file undershoot ceiling below was initially
  undocumented (fixed).
- Verified offline: 260/260 (`php tests/run.php`; 218 pre-existing + 30
  from v0.4.2 + 12 new this release), stable across 3 repeat runs. Red
  baseline confirmed by reverting only `plugin/scripts/lib.php` and
  `plugin/scripts/godwitd` (keeping the new tests): 7 genuinely red — 3
  `godwit_bytes_near_max_transfer()` tests (undefined function), 1
  `godwit_classify_job_outcome()` masking-case test (`error` instead of
  `budget`), 3 status-text/`godwit_build_jobs_status()` suffix tests (no
  suffix without the new `$jobTransfers` param). Includes a real-rcd
  end-to-end test against the bundled v1.75.1 binary that reproduces the
  exact masking shape (~400 small files near a real MaxTransfer cutoff,
  plus a genuine destination-collision file error) and proves both halves
  live: without the byte fallback the run classifies `error` (reproducing
  the bug), with it (what godwitd now actually does) it classifies
  `budget` while the real error count still surfaces in the status label.
  **Correction, v0.4.4: this test never actually executed in the worktree
  that built this release** — see the v0.4.4 entry below for why, and for
  what "stable across 3 repeat runs" above should be read as instead.
- **Known ceiling, not fixed this release** (documented as a `ponytail:`
  comment on `godwit_bytes_near_max_transfer()` in lib.php): the 2%
  tolerance's undershoot bound is really "the size of the one file that
  didn't fit" — for most shares (many small files) that's comfortably
  under 2%, but Teegan's real single files run to 11.9GB and 134GB
  (recorded elsewhere in this file); against a ~140GiB gated-resume
  budget, a masked cutoff whose blocking file is one of those could
  undershoot by more than 2% and still misclassify as `error`, silently
  skipping the gate for that job. Upgrade path if this is ever observed
  live: rcd's own log carries a distinct cutoff NOTICE line independent of
  `currentError()`'s masking precedence — a definitive signal, but reading
  rcd's log (not just its rc API) is a bigger change than this release's
  scope. The tolerance was deliberately not widened to compensate — there
  is no upper bound on a single file's size, so no fixed percentage can
  fully close this gap, and widening it risks the exact false-positive
  this function exists to avoid.

**Not verified this release**: nothing against the live Google Drive
remote — this was built read-only against the host, per the user's
explicit instruction (they installed and are verifying v0.4.2 live; no
install, no daemon/rcd restart, no mutating rc call was made this
session). The user installs v0.4.3 by hand once released.

**v0.4.4 (2026-09-18) fixes v0.4.3's own new end-to-end test being red on
main — found by the user, not by CI or this session's own review.** The
user ran `php tests/run.php` from `/projects/unraid-godwit` at the
released v0.4.3 tag 5 times: all 5 failed, always the same test, always
the same shape (`bytes (0)`, `errorMsg = 'context canceled'`).

**Two separate problems, found by reproducing directly against the
bundled v1.75.1 binary (`rclone rcd` + raw `curl` rc calls) before
touching any code, per the user's explicit instruction to find the cause
rather than guess:**

1. **This session's original "260/260, stable across 3 repeats" claim in
   the v0.4.3 handback was worthless — the test never actually ran.**
   Every rcd end-to-end test in this suite (not just the new one) opens
   with `if (!is_file($zip)) { return; }`, and this project's `t()`
   helper counts a clean `return` as `PASS`. `build/` is populated only
   by `scripts/build-plugin.sh` and is gitignored — it does not exist in
   a fresh `git worktree`, so every rcd e2e test silently *skipped* (not
   ran, not passed) in the worktree v0.4.3 was built and "verified" in.
   The same is true of `.github/workflows/ci.yml`, which checks out fresh
   and never runs `build-plugin.sh` — these tests have never executed in
   CI, a pre-existing gap not introduced by this release. The only reason
   the user's run actually caught this is that `/projects/unraid-godwit`'s
   shared checkout happens to have a `build/` cached locally from earlier
   manual work. Not fixed this release (out of scope, recorded here so
   it isn't lost again — see "Test command" below): the tests now log an
   explicit "skipped — build/ not populated" line instead of silently
   returning, so a suspiciously-fast green run has a visible clue, but
   CI still does not exercise these tests at all.
2. **Once actually executed, the test itself had a real bug.** Its source
   tree split into two subdirectories — many small files in one, a single
   oversized file (5x the transferable total) in the other, to force the
   MaxTransfer cutoff. rclone's directory march has no guaranteed
   traversal order between the two — when it discovers the oversized file
   first, that one candidate alone already exceeds MaxTransfer, so
   CAUTIOUS's cutoff trips before the other directory is even listed,
   cancelling the whole sync context with zero bytes transferred and
   `errorMsg = "context canceled"`. Fixed by moving every file into a
   single flat directory of uniform sizes (no oversized outlier, no
   second directory to race against), with `MaxTransfer` set to 60% of
   the real transferable total — ground-truthed at 10/10 repeat runs
   (with `build/` actually populated and confirmed present before every
   run this time) landing within ~0.15% of `MaxTransfer`, the expected
   masking error text (`can't move object - incompatible remotes`, a
   genuine rclone error from the pre-created destination-directory
   collision — not the cutoff's own text) and exactly 2 errors, every
   time.

No production code changed — `godwit_bytes_near_max_transfer()`,
`godwit_classify_job_outcome()` and `godwit_job_status_label()` are
byte-identical to v0.4.3; only the test harness that exercises them
against a real rcd was rewritten.

Verified offline: 260/260 (`php tests/run.php`), with `build/` manually
populated and its presence confirmed before each of 10 repeat runs (this
release's own bug — an unpopulated `build/` silently skipping the very
test under test — is exactly the kind of thing that made the *previous*
release's "stable across repeats" claim meaningless; this time presence
was checked, not assumed). **Not verified this release**: nothing against
the live Google Drive remote or the host — the user is still verifying
v0.4.2/v0.4.3 live and explicitly asked for build+release only, no
install, no daemon/rcd restart, no mutating rc call.

**v0.5.0, Phase 4 (OneDrive selective backup) — built and offline-verified
2026-09-18, host verification pending — the user installs.** Host state
at the start of this phase (verified by the user just before handoff):
v0.4.4 installed and running, both remotes healthy, `kmonedrive` a real
personal OneDrive (1.005 TiB total / 135.6 GiB used / 893.4 GiB free,
`kmonedrive:godwit` not yet created), all four existing gdrive jobs
unchanged. Per the user's explicit instruction this phase was built and
released without installing anything or restarting `godwitd`/`rcd` — the
user was deliberately keeping the host clean for tonight's first live
exercise of the v0.4.4 budget fix.

- **Selective job type.** A job's `type` is now `'share'` (Phase 3,
  default) or `'selective'` (Phase 4): a browsable, lazy-loaded,
  tri-state checkbox tree over `/mnt/user/<share>` (Godwit.page → Jobs →
  Add job → Browse…, or "Browse (N)" on an existing selective job's row).
  Ticking a folder includes everything below it; an already-included
  folder's children can be un-ticked to exclude just that subtree — one
  level of override, matching PLAN.md §4.4's wording exactly (there is no
  way to re-include something nested under an excluded child; the
  two-list `included`/`excluded` model can't express that, and nothing in
  the plan asks for it).
- **Filter compilation.** `godwit_compile_selective_filter_rules()`
  branches `godwit_compile_filter_rules()` for `type === 'selective'`:
  excludes ordered before their including ancestor (rclone matches
  top-to-bottom, first rule wins, so the more specific rule must come
  first), global junk excludes still applied, a trailing `- **` since a
  selective job's default is exclude (a share job's is include). Every
  path is backslash-escaped for rclone's glob metacharacters
  (`godwit_escape_filter_pattern()`) before being written into a filter
  line — real filesystem names are not glob patterns. Both the ordering
  and the escaping are ground-truthed against the real bundled rclone
  v1.75.1 binary via `lsf -R` e2e tests, and the actual `sync/sync` data-
  safety claim (a selective job's filter narrows what sync reconciles, it
  does not widen `--backup-dir` sync's delete phase to everything outside
  the selection) is proven against a real rcd process, not just `lsf`.
- **Tree browsing and on-demand, cached sizing.** `godwit_tree_list()`
  (plain `scandir`, no recursion — a full walk would wake the pool, per
  PLAN.md §4.4) and `godwit_tree_node_size()` (SQLite-cached per
  share+path, computed via `du -sb` — the native tool for a recursive
  size total, never a hand-rolled PHP walker) back two new
  `godwit-api.php` actions, `tree_list`/`tree_size`. A selected single
  file over OneDrive personal's 250 GiB per-file ceiling carries a
  warning in the `tree_size` response. The tree dialog shows the running
  selection total against the target remote's free space (from whatever
  `remotes_list` last returned, not a fresh rc call), with a warning near
  either the remote's free space or a 2 TiB ceiling, per PLAN.md §4.4.
- **OneDrive's daily upload budget no longer silently inherits gdrive's
  700 GiB cap.** `godwit_budget_cap_bytes()` centralises the fallback:
  the D12 700 GiB default only for `gdrive` specifically, effectively
  unlimited (`PHP_INT_MAX`) for any other remote unless
  `settings.budget_caps` configures one — PLAN.md §4.3 says OneDrive's
  budget should be off by default. The budget bar now shows "no daily cap
  configured" instead of a meaningless "744.5 MiB / 8.0 EiB (0.0%)".
- **Retention-purge log noise fix, corrected mid-review (see below).** A
  brand-new remote with jobs on it logged `operations/list: directory not
  found` on every godwitd restart, from the daily retention purge listing
  a `godwit/_versions/<share>` path that had never been created (harmless
  — already handled as "absent", not an error — but needless noise). The
  retention loop now `operations/mkdir`s that exact path immediately
  before listing it.
- **Review: two rounds of `code-diff-reviewer` (6 passes each, unattended
  MID band, escalation score 8).** Round 1 was 6× `NO FINDINGS` — a known
  failure mode of that reviewer per its own skill doc, not evidence of
  clean code — so `advisor` read the diff directly and found 2 real
  blocking issues, both fixed before merge: (1) the first version of the
  log-noise fix mkdir'd `<remote>:godwit` from inside
  `godwit_run_health_check()` — one directory level too shallow to touch
  the path that actually produces the log line (the retention loop's own
  `operations/list` on `_versions/<share>`, not the health check), and
  reached from the page's "Test connection" action, silently turning a
  documented never-mutates health check into a write; (2)
  `godwit_tree_node_filter_line()` didn't escape rclone glob
  metacharacters, so a folder literally named `Photos [RAW]` would have
  compiled to a character-class glob and silently skipped the real
  folder — a silent-omission bug in a backup tool, fixed with
  `godwit_escape_filter_pattern()` and a dedicated `lsf -R` e2e test.
  `advisor` also flagged (addressed, non-blocking): the red-before-green
  baseline hadn't been shown for this phase's own tests (retroactively
  proven — reverting only `plugin/scripts/{lib.php,godwitd,godwit-api.php}`
  and `plugin/Godwit.page` while keeping the new tests gave 27 genuinely
  red, all now green); no real `sync/sync` e2e for a selective job
  (added, described above); root `README.md` hadn't been updated
  alongside `plugin/README.md` (fixed); PLAN.md §4.4's free-space display
  was initially missing (added). Round 2 (on the fix commit) was clean.
- Verified offline: 287/287 (`php tests/run.php`, `build/` populated and
  confirmed present, 0 `skipped --` lines) plus 19/19 Node checks
  (`node tests/windows_form_test.mjs`, extended with the tri-state
  toggle logic's own tests via a new `GODWIT_TREE_BEGIN`/`END` marker
  pair, same pattern as the existing windows-form extraction).

**Not verified this release**: nothing against the live host — no
install, no daemon/rcd restart, no mutating rc call, per the user's
explicit instruction (they are verifying v0.4.4's live budget fix
tonight and didn't want a large new feature confounding it). No real
OneDrive upload of a selective job (the sync/sync data-safety claim
above is proven against a real rcd with a local backend, exactly like
every other Phase 3 e2e test, not against `kmonedrive` itself), and no
live browser click-through of the tree dialog, Add-job dialog or the new
budget-bar wording. Two deliberately deferred UI gaps, not correctness
bugs: a folder with some but not all children ticked renders as a plain
checked checkbox, not an indeterminate one; a checkbox for a node nested
under an excluded folder is visually clickable but is a documented no-op
rather than being disabled. `godwit_du_bytes()` has no shell timeout
(`ponytail:` comment names the upgrade path) — a `du` against a huge,
cold share could in principle outlast the web request, leaving that
click's size uncached; the result is cached once it does succeed, so
this is a one-off cost, not a recurring one.

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

**Known gap (found 2026-09-18, v0.4.4): every real-rcd end-to-end test
silently SKIPS — not "passes" — unless `build/rclone-v1.75.1-linux-amd64.zip`
is present**, and each such test's own `t()` result reads as `PASS` either
way (a clean early `return` and a real green assertion look identical in
the suite's output). `build/` is gitignored and only populated by
`scripts/build-plugin.sh`, so it does **not** exist in a fresh `git
worktree` — meaning every `/feature` session's own "N/N passed, stable
across repeats" claim for anything touching these tests has been
unverified by construction unless that session separately ran
`build-plugin.sh` or copied a cached zip in first. It also does not exist
in `.github/workflows/ci.yml` (checks out fresh, never builds) — these
tests have **never run in CI**, on any release to date. To actually
exercise them locally: `bash scripts/build-plugin.sh <any-version>` first
(downloads and SHA256-verifies the real binary into `build/`), or copy an
already-cached `build/*.zip` from another checkout. As of v0.4.4 these
tests log an explicit "skipped — build/ ... not found" line instead of a
bare `return`, so a suspiciously test-count-light run is at least visible
in the output — this does not close the CI gap, which remains open.

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
