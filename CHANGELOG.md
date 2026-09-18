# Changelog

All notable changes to this project will be documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit
or zero-padded, because Unraid's plugin manager compares versions with a
plain `strcmp`, not a semver-aware comparison — `1.0.10` would otherwise
sort *before* `1.0.9`.

## [Unreleased]

## [0.5.0] - 2026-09-18

### Added

- **Phase 4: OneDrive selective backup.** Jobs can now be "selective"
  instead of whole-share: a browsable, lazy-loaded, tri-state checkbox
  tree over `/mnt/user/<share>` (Godwit.page: Jobs → Add job → Browse…,
  or the "Browse (N)" button on an existing selective job's row) lets you
  tick individual folders or files. Ticking a folder includes everything
  below it; an already-included folder's children can be un-ticked to
  exclude just that subtree — the same model PLAN.md §4.4 describes for
  the Unbalanced-style picker. The selection compiles to an rclone
  `--filter-from` file (`godwit_compile_selective_filter_rules()`):
  excludes ordered before their including ancestor so the more specific
  rule wins, global junk excludes still applied, and a trailing `- **`
  default-deny since a selective job's default (unlike a share job) is
  exclude, not include. Ground-truthed against the real bundled rclone
  v1.75.1 binary via a new rcd end-to-end test (`tests/run.php`), not
  assumed from rclone's documented idiom alone.
- **Tree browsing/backend**: `godwit_tree_list()` (plain `scandir`, no
  recursion — a full walk would wake the pool) and `godwit_tree_node_size()`
  (on-demand, SQLite-cached per share+path, computed via `du -sb` — the
  native tool for a recursive size total, not a hand-rolled PHP walker).
  A selected single file over OneDrive personal's 250 GiB per-file
  ceiling (`godwit_onedrive_max_file_bytes()`) carries a `warning` in the
  `tree_size` response. New `godwit-api.php` actions: `tree_list`,
  `tree_size`.
- `godwit_validate_selective_job()`: a selective job needs at least one
  included path, and every excluded path must sit under an included one
  (an exclusion with no included ancestor can never affect the compiled
  filter and is almost certainly a client bug) — enforced server-side in
  `jobs_save`, the same defence-in-depth pattern as the existing
  direction/overlap checks.
- The tree dialog now shows the target remote's free space alongside the
  running selection total (PLAN.md §4.4), with a warning once the
  selection approaches either the remote's free space or a 2 TiB ceiling,
  reusing whatever `remotes_list` last returned rather than an extra rc
  call of its own.
- **Data safety, proven against a real rcd in `sync` mode (not just
  `lsf`)**: a new end-to-end test pre-seeds the destination with content
  that sits outside a selective job's filter, runs a real `sync/sync`,
  and asserts that content survives — a selective job's filter narrows
  what sync reconciles, it does not widen `--backup-dir` sync's delete
  phase to everything outside the selection.
- Two selective jobs (or a selective and a share job) on the same
  share+remote are rejected by the existing overlap check, since a
  selective job's destination is still `remote:godwit/<share>` — the
  same path a share job on that share+remote would use. This is
  intentional and conservative, not a bug: it is the same "one
  destination, one job" invariant `godwit_validate_job_destinations()`
  already enforces for share jobs.

### Fixed

- **OneDrive's daily upload budget silently inherited Google Drive's 700
  GiB cap** for any remote with no explicit `budget_caps` entry — PLAN.md
  §4.3 says OneDrive's budget should be off (unlimited) by default. New
  `godwit_budget_cap_bytes()` centralises the fallback: the D12 700 GiB
  default only for `gdrive` specifically, effectively unlimited
  (`PHP_INT_MAX`) for any other remote unless configured. An explicit
  `settings.budget_caps` entry for any remote still always wins.
- **A brand-new remote logged `operations/list: directory not found`
  noise to rcd's own log on every godwitd restart**, from the daily
  version-retention purge listing a `godwit/_versions/<share>` path that
  had never been created — harmless (godwit's own code already treated
  it as "absent", not an error) but needless noise. The retention loop
  now `operations/mkdir`s that exact path (a no-op if it already exists)
  immediately before listing it, once a day, only for enabled jobs.
  **Correction from this release's own first review round**: the first
  version of this fix mkdir'd `<remote>:godwit` from inside
  `godwit_run_health_check()` instead — one directory level too shallow
  to touch the path that actually produced the log line, and reached from
  the page's "Test connection" action, which silently turned a
  documented never-mutates health check into a write. Caught by
  `advisor` reading the diff directly (6× `code-diff-reviewer` passes on
  this change were all `NO FINDINGS`, a known failure mode, not evidence
  of correctness) before merge, not live.
- **Selective-job filter compilation didn't escape rclone glob
  metacharacters** (`* ? [ ] { } \`) in real folder/file names — a
  folder literally named `Photos [RAW]` compiled to `+ /Photos [RAW]/**`,
  a character class that also matches `Photos R`/`Photos A`/`Photos W`
  and would silently skip the real folder, a silent-omission bug in a
  backup tool. `godwit_escape_filter_pattern()` now backslash-escapes the
  path before it's ever written into a filter line — ground-truthed
  against the real bundled binary with a dedicated `lsf -R` e2e test that
  proves both halves (the bracketed folder survives, the glob-only
  siblings that would prove a missing escape do not appear). Also found
  by the same pre-merge review round.

### Review

Escalation score 8 (unattended MID band: exposure 1, authority 1, data 1,
reversibility 1, test gap 2, pattern divergence 0, module spread 1) → 6
`code-diff-reviewer` passes, then `advisor`. All 6 were `NO FINDINGS` (a
known failure mode of that reviewer, not evidence of correctness on its
own) — `advisor` read the diff directly instead and found 2 real
blocking issues (the mkdir-wrong-path and glob-escaping bugs above) plus
several should-fix/non-blocking items, all addressed in a second commit.
That fix commit then went through a separate focused 3-pass
`code-diff-reviewer` round (3× `NO FINDINGS`) followed by a second
`advisor` call, which found two further real issues before this could
ship: `godwit.plg`'s own `<CHANGES>` entry (what the Plugins tab shows)
still described the reverted health-check mkdir instead of the actual
retention-purge fix, and — the more serious one — a remote with no
`settings.budget_caps` entry (OneDrive by default) resolves to
`PHP_INT_MAX` as its cap, and that value had never actually reached
`godwit_build_sync_params()` end-to-end: sending `PHP_INT_MAX` as
`MaxTransfer` relies on rclone's own JSON→float64→int64 round-trip
handling a value that isn't exactly representable as a float64, which is
not something to ship a flagship feature's upload path on without
verifying. Fixed by never sending it: `GODWIT_BUDGET_UNLIMITED` is a
named sentinel `godwit_remaining_budget()` now returns unchanged (not
cap-minus-used) for an unlimited cap, and `godwit_build_sync_params()`
omits the `MaxTransfer` key entirely when it sees the sentinel — rclone's
own default with no `MaxTransfer` set is already "no limit". Proven
end-to-end against a real rcd process (not just a unit test asserting
the key is absent) via the same selective-job `sync/sync` fixture used
for the data-safety proof above, now run with the sentinel instead of a
plain byte count.

### Not verified this release

Nothing against the live host — built and tested entirely offline
(`php tests/run.php`, `node tests/windows_form_test.mjs`), per the
user's explicit instruction not to install, and not to restart godwitd
or rcd, so tonight's first live exercise of the v0.4.4 budget fix isn't
confounded by a large new feature landing at the same time. In
particular: no real OneDrive upload of a selective job (the sync/sync
data-safety and unlimited-sentinel claims above are proven against a
real rcd with a local backend, exactly like every other Phase 3 e2e
test, not against `kmonedrive` itself), no live browser click-through of
the new tree dialog, Add-job dialog or budget-bar "unlimited" wording.

**One fact needs the user's own confirmation, not this session's
guess**: the "directory not found" log noise this release's retention-
purge fix targets. This repo's *only* `operations/list` caller is the
daily retention purge, over enabled jobs — today that's all four gdrive
jobs, so the four log lines are almost certainly
`gdrive:godwit/_versions/{Filing Cabinet,Kieren,Teegan,Photos}` (absent
until each share's first versioned overwrite), not `kmonedrive:godwit`
as originally described handing this phase off. Please check the exact
`fs=` in `rcd.log` after installing — if it really does say
`kmonedrive`, this fix's mkdir call won't silence it, since nothing in
this repo lists a bare `<remote>:godwit` path, and the actual cause
would need tracing on the host, not guessed at from here.

Known, deliberately deferred UI gaps (not correctness bugs): a folder
with some but not all children ticked renders as a plain checked
checkbox, not an indeterminate one (`cb.indeterminate = true` is a
one-line follow-up); a checkbox for a node nested under an excluded
folder is visually clickable but is a no-op (the two-list
include/exclude model can't express deeper re-inclusion, documented on
`godwitTreeToggle()`'s own comment) rather than being disabled.
`godwit_du_bytes()` has no shell timeout (`ponytail:` comment in
`lib.php` names the upgrade path) — a `du` against a huge cold share
could in principle outlast the web request, leaving that one click's
size uncached; the result is cached once it does succeed, so this is a
one-off click cost, not a recurring one.

## [0.4.4] - 2026-09-18

### Fixed

- **v0.4.3's own new end-to-end test was red on main — found by the user
  running `php tests/run.php` 5 times from `/projects/unraid-godwit`
  after the release, all 5 failing deterministically** (`259 passed, 1
  failed`, always the same test: `bytes (0) vs MaxTransfer (...)`,
  `errorMsg = 'context canceled'`).
  **Two separate problems, not one**, both found only by reproducing
  directly against the bundled v1.75.1 binary (not guessed):
  1. Every rcd end-to-end test in this suite (not just the new one) opens
     with `if (!is_file($zip)) { return; }`, and this project's `t()`
     helper counts a clean `return` as `PASS`. `build/` is gitignored
     (only populated by `scripts/build-plugin.sh`) and does not exist in
     a fresh `git worktree` — so this test, and every other rcd e2e test,
     silently *skipped* (not passed, not ran) in the worktree that wrote
     it, and its "260/260, stable across repeats" claim in the original
     v0.4.3 handback was worthless: the test that mattered never actually
     executed. It also means these tests never run in CI (`ci.yml`
     checks out fresh and never runs `build-plugin.sh`) — a pre-existing
     gap, not introduced by this release, but this is what let a broken
     e2e test ship. `/projects/unraid-godwit`'s shared checkout happens to
     have a locally cached `build/` from earlier manual work, which is
     why the user's run actually executed it and caught the bug.
  2. The test itself had a real bug once actually executed: it split its
     source tree into two subdirectories — many small files in one, a
     single oversized file (5x the total) in the other, to force the
     MaxTransfer cutoff. rclone's directory march does not guarantee it
     visits the first subdirectory before the second — when it discovers
     the oversized file first, that ONE candidate alone already exceeds
     MaxTransfer, so CAUTIOUS's cutoff trips before any file in the other
     directory is even listed, cancelling the whole sync context
     (`context canceled`) with zero bytes transferred. This reproduced
     nothing about the masking bug the test exists to prove.
  Fixed (2): moved every file into a SINGLE flat directory of
  uniform-sized files, with `MaxTransfer` set to 60% of the real
  transferable total (matching the measurements already cited for the 2%
  tolerance) — ground-truthed at 10/10 repeat runs (with `build/`
  actually populated this time, confirmed present before every run)
  landing within ~0.15% of `MaxTransfer`, with the expected masking error
  text (`can't move object - incompatible remotes` — a genuine rclone
  error from the pre-created destination-directory collision, not the
  cutoff's own text) and exactly 2 errors, every time. Not fixed (1): the
  gitignored-`build/`-means-silent-skip gap is a pre-existing, structural
  issue affecting every rcd e2e test in this suite, not something this
  release's scope covers — recorded in CLAUDE.md's Test command section
  so it isn't lost again, and this test now logs an explicit "skipped —
  build/ not populated" line instead of silently returning, so the next
  person who sees a suspiciously-fast green run has a clue.
- No production code changed this release — `godwit_bytes_near_max_transfer()`,
  `godwit_classify_job_outcome()` and `godwit_job_status_label()` are
  unchanged from v0.4.3; only the test harness that exercises them against
  a real rcd was rewritten.

## [0.4.3] - 2026-09-18

### Fixed

- **v0.4.2's minimum-budget-threshold gate and calm status wording were
  dead on arrival.** Verified against the live host: `grep -c "stopped
  mid-run" /var/log/godwit.log` returned 0 — godwitd's own mid-run
  ledger-based budget stop (the second line of defence) had never fired,
  and every budget-capped run that night (Photos, Teegan, Kieren) was
  still recorded as `error`, not `budget`. Root cause: `MaxTransfer` is
  set to the remaining budget at job start, so rclone's own CAUTIOUS
  cutoff always trips before godwitd's ledger-based `remaining <= 0`
  check ever sees zero — `$stoppedForBudget` was therefore always
  `false`, leaving the cutoff's own error text as the only signal, and
  that text is a `NoRetryError`, the lowest-precedence kind
  `job/status`'s `currentError()` can return. Any genuine per-file error
  occurring in the same run (every one of that night's three runs had
  one) always won and overwrote it before `godwit_classify_job_outcome()`
  ever saw it. `godwit_classify_job_outcome()` gained a byte-proximity
  fallback (`godwit_bytes_near_max_transfer()`, 2% tolerance, symmetric):
  godwitd now also carries the exact `MaxTransfer` it passed to each
  active job (`$activeJobs[$name]['max_transfer']`) and, when no more
  specific textual signal (throttle, explicit cutoff text, auth-expiry)
  matched, classifies as `budget` if the transferred bytes landed within
  2% of that limit either way. 2% is not a guess — ground-truthed via an
  ad-hoc local repro against the bundled v1.75.1 binary (3 repeats each
  direction, no committed test pins these exact figures — the timing is
  too environment-sensitive for CI; the committed end-to-end test instead
  proves the fallback fires on one concrete run without hardcoding a
  magnitude): CAUTIOUS mode can both **overshoot** its own limit — many
  small (~1KB), fast-completing files on local disk let several slip past
  the cutoff check before it's re-evaluated (+3878, +3996, +4063,
  +3878..+4942 bytes on a ~300KB MaxTransfer, i.e. up to +1.6%) — and
  **undershoot** it — a bandwidth-throttled (20 Mbit/s) transfer of larger
  (~2MB) files, closer to the real Drive workload, stops before starting a
  file that would exceed the limit (−1,953,219, −74,965, −54,957 bytes on
  a ~121MB MaxTransfer, i.e. up to −1.6%). 2% is a deliberately generous
  margin above both measured extremes.
  **Known ceiling, documented not fixed** (`ponytail:` comment on
  `godwit_bytes_near_max_transfer()`): the true undershoot bound is really
  "the size of the one file that didn't fit", not a fixed percentage —
  for Teegan's real single files (11.9GB, 134GB per CLAUDE.md), a masked
  cutoff whose blocking file is one of those could undershoot by more than
  2% of a ~140GiB gated-resume budget and still misclassify as `error`.
  Upgrade path if this bites: rcd's own log carries a distinct NOTICE line
  independent of `currentError()`'s precedence — a definitive signal, but
  reading rcd's log (not just its rc API) is a bigger change than this
  release's scope. The tolerance is not widened to compensate — there is
  no upper bound on a single file's size, so no fixed percentage can fully
  close this gap, and widening it risks exactly the false-positive this
  function exists to avoid.
- **The real error count is never hidden by the reclassification.**
  `godwit_job_status_label()` now appends "— N transfer errors also
  logged, see /var/log/godwit.log" to a budget/window pause when the
  stored error count exceeds the clean cutoff's own accounted baseline
  (the job's configured `Transfers` for a budget stop, 0 for a window
  stop — the same baseline `godwit_cap_stop_notification()` already used).
  Shows the raw stored count (not count-minus-baseline — the baseline is
  only an upper bound on the cutoff's own bookkeeping, not an exact figure
  to subtract), so this label always agrees with
  `godwit_cap_stop_notification()`'s own wording for the same run. A clean
  cutoff (errors at or below the baseline) stays quiet, matching 0.4.1's
  existing notification behaviour; a genuinely elevated count (63 on the
  real Photos run, 508 on Kieren's) now surfaces on the page itself, not
  just in a one-off notification that's already gone by the time someone
  looks.
- **Decided, not silently changed: the threshold gate stays scoped to
  `outcome === 'budget'` only** (not `window`/`error`/`interrupted` too).
  Traced through the session/terminal-outcome mechanics rather than
  guessed: `error` is a terminal outcome (`godwit_terminal_job_outcomes()`),
  so a job that ends in `error` is never re-selected again this session
  regardless of gating — no trickle-restart risk exists for it. `window`
  can only occur when `MaxDuration` was set, which only happens when a
  window was active and it's NOT a "Run now" override — meaning the
  window that caused it has, by definition, just closed, so the
  candidate-start loop won't attempt selection again until the window
  reopens (hours away) or Run now is triggered — again, no 15s-tick
  trickle-restart risk. `budget` is the only outcome that is both
  non-terminal (stays eligible within the same session) and can be
  immediately re-selected on every tick while the gate that actually
  causes the trickle-restart problem (a live window or Run now) is still
  open. Gating only `budget` is therefore correct as built, not a gap.

### Tests

260 total (218 pre-existing + 30 from v0.4.2 + 12 new this release,
stable across 3 repeat runs including the real-rcd end-to-end test). Red
baseline confirmed by temporarily reverting `plugin/scripts/lib.php` and
`plugin/scripts/godwitd` only (keeping the new tests): 7 genuinely red —
3 `godwit_bytes_near_max_transfer()` tests (undefined function), 1
`godwit_classify_job_outcome()` masking-case test (`error` instead of the
expected `budget`), 3 status-text/`godwit_build_jobs_status()` suffix
tests (no suffix without the new `$jobTransfers` param and baseline
logic). A new end-to-end test spins up a real bundled-v1.75.1 rcd, forces
a genuine per-file error via a destination name collision (a directory
pre-created where a file needs to go) alongside a real MaxTransfer cutoff
on ~400 small files, and proves both halves live: without the byte
fallback the run classifies `error` (reproducing the bug), and with it
(matching what godwitd now actually does) it classifies `budget` while
the real error count still surfaces in the status label. Stable across 3
repeat runs.

### Review

3 passes of `code-diff-reviewer`: 3× NO FINDINGS (a known failure mode of
that reviewer, not treated as proof of correctness on its own).
Escalation score 5 (OWN band: exposure 1, authority 1, data 1,
reversibility 1, test gap 0, pattern divergence 0, module spread 1 — no
counsel). `advisor` read the diff directly and found 4 real issues, all
fixed before release: the status-text error count originally subtracted
the baseline (disagreeing with the notification's raw count on the same
run — fixed to show the raw count on both surfaces); this CHANGELOG
originally pointed back to itself for the escalation score and red
evidence instead of stating them (fixed — see above and the Review
section); `godwit_bytes_near_max_transfer()`'s docblock originally
claimed the ±1.6% figures lived in `tests/run.php` when they were ad-hoc
local numbers (fixed — the actual figures are recorded above); and the
large-single-file undershoot ceiling (Teegan's 11.9GB/134GB files) was
initially undocumented (fixed — see the "Known ceiling" note above and
the `ponytail:` comment on `godwit_bytes_near_max_transfer()`).

## [0.4.2] - 2026-09-18

### Added

- **Minimum budget threshold before resuming a budget-stopped job.** A job
  whose last run ended because it hit its remote's daily cap (`outcome ===
  'budget'`) is not restarted until that remote's remaining rolling-24h
  budget is at least `GODWIT_MIN_BUDGET_FRACTION` (20%, Kieren's call) of
  its configured cap (140 GiB at the live 700 GiB cap) — otherwise a
  trickle-refilling budget after last night's full-cap night restarts the
  job every ~15s tick, each restart re-listing the whole share. A job
  whose last run *completed* cleanly is never gated (no known outstanding
  work; may just be a small incremental sync). Settable per remote via
  `settings.json`'s `budget_min_fractions` map (no UI, as scoped).
- New `status_text` field in `jobs_status`: a human-readable render of each
  job's last run, computed server-side by `godwit_job_status_label()`.

### Changed

- **Human-readable run status.** `completed`/`budget`/`window`/gated/
  `error`/`auth`/`throttled`/never-run now render as calm, accurate English
  instead of the raw outcome string — "error (669.2 GiB) at …" for a run
  that hit the daily cap exactly as designed read as alarming and was
  wrong. Uses the real configured window start time
  (`godwit_next_window_start_label()`), not a hardcoded "22:00", and is
  midnight-wrap-safe and multi-window-aware. `Godwit.page`'s JS renders
  `status_text` verbatim instead of rebuilding a label from
  `last_run.outcome` client-side. `godwit_cap_stop_notification()` gained
  an optional 6th `$resumeLabel` param (backward compatible with every
  existing 5-arg call/test) to embed the same real resume time in the
  notification text.
- `godwit_select_next_jobs()` gained a new optional `$gatedJobNames` param:
  a gated job is skipped WITHOUT reserving its remote, so the next enabled
  job on the same remote still gets a turn the same tick — a gate placed
  the ordinary way (after selection) would have stalled every job behind
  the gated one, the exact scenario the completed-job exception exists to
  avoid. Every enabled job gated is a normal quiet state: the queue
  selects nothing, does not spin, does not deadlock. godwitd logs the
  hold-back once per job per gated spell, not once per 15s tick.
  `godwit_budget_reached_notification()` no longer fires for a gated job —
  the cap-stop notification already covered it when the run first hit the
  cap. The "Run now" override does NOT bypass the threshold gate — a
  resuming job stays gated even under Run now.
- `Godwit.page`'s intro drops "Phase 3 —" (a PLAN.md planning concept, not
  a user concept) for a plain description of what the page does. PLAN.md
  (gitignored, not part of this or any commit) gets a note that 0.4.x is
  UX/defect work between Phase 3 and 4.
- `godwit-api.php` now calls `godwit_resolve_timezone()` at bootstrap,
  mirroring godwitd's own startup — closes a gap where the web SAPI's
  "resumes HH:MM" label would otherwise compute against PHP's UTC default
  instead of the daemon's real local clock.

### Fixed

- A real bug the new tests caught, not review: the first implementation of
  `godwit_next_window_start_label()`/`godwit_job_status_label()`'s time
  formatting used PHP's `date($format, $ts)`, which formats in the
  process's global default timezone rather than the timezone of the
  `DateTimeImmutable $now` object handed to the function — silently
  correct only because godwitd (and now godwit-api.php) happen to set the
  process default at startup, wrong for any other caller. Fixed by
  formatting through `DateTimeImmutable`/`setTimezone()` instead.

### Known gap

- Tonight's first start of each of the four jobs will NOT be gated by the
  new threshold — their current `last_run` rows are all legacy `error`
  (pre-0.4.1), not `budget`; the gate only engages once a job's outcome is
  actually stored as `budget`, from tonight's first budget-triggered stop
  onward. Historical `job_runs` rows stored `error` by 0.3.0/0.4.0 stay
  `error` in status_text too — no backfill/guessing, per CLAUDE.md; only
  rows classified from 0.4.1 onward get the calm budget/window wording.

## [0.4.1] - 2026-09-18

### Fixed

- **Spurious "run failed" alerts on a daily-cap stop.** Diagnosed from the
  live host's `job_runs`/`rcd.log` on the night of 2026-09-17→18: Kieren
  and Teegan each hit their MaxTransfer cutoff (derived from the 700 GiB
  rolling budget) partway through, and every one of the 591/85 logged
  errors was the same shape — `ERROR : <dir>: Failed to update directory
  timestamp or metadata: directory not found`. rclone stops **starting**
  new files at the cutoff, so a directory it never reached was never
  created at the destination; its end-of-sync directory-modtime pass then
  fails on every such directory. `job/status`'s returned error is rclone's
  `currentError()`, which resolves in fixed precedence — fatal error, then
  a plain error, then a "no-retry" error — and MaxTransfer's CAUTIOUS
  cutoff is a no-retry error (the *lowest* of the three), so the
  directory-modtime failures (plain errors) always won and overwrote the
  real "max transfer limit reached" text before it ever reached
  `godwit_classify_job_outcome()`. The classifier was never the bug: fed
  the masked text, `error` was the *correct* read of what it was given.
  Ground-truthed against the bundled rclone v1.75.1's `options/info` rc
  call (not guessed from the flag spelling): the fix sets `_config`'s
  `NoUpdateDirModTime` (the Go struct field name; `_config` takes
  `fs.ConfigInfo` field names, not `--flag` spellings) to `true` on every
  sync/copy job in `godwit_build_sync_params()` — directory timestamps have
  no value for an offsite backup, cost API calls, and can never succeed
  against a destination directory that doesn't exist yet. Proven end to
  end against a real local-backend rcd fixture (not just that the call is
  accepted): a MaxTransfer cutoff that skips an entire subdirectory now
  produces zero directory-modtime errors and the real, uncontaminated
  "max transfer limit reached as set by --max-transfer" text, which
  `godwit_classify_job_outcome()` correctly reads as `budget`.
- **A cap-stopped run now notifies as a stop, not a failure.** New
  `godwit_cap_stop_notification()` fires for a `budget`/`window` outcome
  ("Godwit: `<job>` stopped at the daily cap" / "...stopped for tonight's
  window") reporting how much transferred and that it resumes at the next
  window, at `normal` importance — replacing the silent-then-misfired
  "run failed" alert. **The classification rule, made explicit**: because
  MaxTransfer's cutoff is the lowest-precedence error kind (see above), a
  genuine transfer failure occurring in the same run already wins
  `job/status`'s returned text and is classified `error`, not `budget` —
  no separate error-count check was needed for that case. MaxDuration's
  window cutoff is the *opposite* precedence (fatal, the highest), so it
  cannot be masked by a directory-modtime-style error, but in principle
  could itself mask a genuine co-occurring failure's text.
  Accepted, and covered defensively, but **not with a single hardcoded
  threshold** — getting the right baseline took two rounds of ground-
  truthing, both caught by self-review before merge, not by the three
  automated review passes (which returned no findings on either round):
  round one assumed rclone's own graceful MaxTransfer cutoff always costs
  exactly 1 error — true, but only measured at `Transfers=1`. Repeating
  the same config at `Transfers=8` twenty times over found 1 through 4,
  never a fixed number: each transfer worker independently discovers the
  cutoff the next time it tries to start a file, and how many do so
  before the pipe drains is racy. The same is true, separately, of
  godwitd's own mid-run `job/stop` (the ledger guard firing before
  rclone's own MaxTransfer, §4.3) — proven live at `Transfers=4` costing
  exactly 4 "context canceled" errors on an otherwise clean stop, one per
  in-flight slot. A hardcoded "1" (or any other fixed number) would have
  made a multi-worker clean budget stop — the common case in practice,
  since the default job `Transfers` is 4 — report a false "N errors were
  also logged" on a perfectly clean run: the exact false-alarm class this
  release exists to fix, just reshaped. MaxDuration's fatal cutoff, by
  contrast, costs exactly 0 at any Transfers count up to 8 (repeated 10x)
  — rclone's fatal path never calls `fs.CountError`, unlike the graceful
  path. `godwit_cap_stop_notification()` now takes an explicit
  `$baselineErrors`: the job's configured `Transfers` for either budget
  path, 0 for window, and only a count *above* that baseline reads as
  "something beyond the cutoff itself also failed — check
  /var/log/godwit.log."
- **Skipped delete phase on a truncated run — investigated, not a bug.**
  The same host log showed `"not deleting files/directories as there were
  IO errors"` on both truncated jobs. Ground-truthed against rclone's
  `fs/sync/sync.go`: the delete phase is gated on `currentError() != nil`,
  and MaxTransfer's own cutoff **is** a `currentError()` (a no-retry
  error) independent of the directory-modtime bug — confirmed live with a
  local-backend fixture: even with `NoUpdateDirModTime` set, a cutoff sync
  still skips deletion and logs the same "not deleting" line, with zero
  other errors present. This is correct, conservative rclone behaviour
  (never reconcile deletions against a destination the sync didn't fully
  reach) and needs no fix — deletion resumes normally on any run that
  completes a full pass within budget. Not just conservative by policy: a
  `--ignore-errors`-style override would be unsafe here, not merely
  unwanted — `processError()` calls `s.inCancel()` on the graceful cutoff,
  and the destination-side march runs on that same cancelled context
  (`march.March{Ctx: s.inCtx, ...}`), so the destination listing is itself
  cut off mid-walk. Deleting against a directory listing that didn't
  finish would risk deleting things Godwit never actually got a chance to
  check for.
- **Known pre-existing sharp edge, not touched by this release**:
  `godwit_classify_job_outcome()`'s `$stoppedForBudget` flag short-
  circuits to `budget` *before* looking at `$errorMsg` at all — so if
  godwitd's own ledger-triggered stop happens to coincide with a real
  per-file error, that error's text is never even inspected, only its
  count (still stored in `job_runs` and checked against the `Transfers`
  baseline above). This is the same class of "a real error could in
  principle be masked" tradeoff written down for MaxDuration above, on a
  third path — accepted for the same reason: the error count is still
  visible, and out of scope for this release, which is about ending the
  specific false alarm recorded on the host, not auditing every
  precedence interaction.

## [0.4.0] - 2026-09-18

### Fixed

- **Retention purge never ran (issue #1).** `godwitd`'s version-retention
  purge called `operations/list` with only `fs`, but rclone's rc endpoint
  requires `fs` **and** `remote` (the path within that fs, `""` for the
  root) — rcd rejected every call outright with `Didn't find key "remote"
  in input`, so `$dirs` was always empty and the 30-day retention purge has
  never deleted a single version, silently, since Phase 3 shipped. Found by
  inspection of `/var/run/godwit/rcd.log` on the host during the first live
  Google seed (four identical errors per retention tick, one per enabled
  job). Fixed by extracting the call into `godwit_list_versions_dirs()`
  (`plugin/scripts/lib.php`), which always sends `remote => ''` and returns
  a tagged result: `dirs` on success, `absent => true` when the `_versions`
  directory doesn't exist yet (normal before a share's first versioned
  overwrite — ground-truthed against the real bundled rclone binary as
  `error in ListJSON: directory not found`, so this case stays quiet), or
  `error` on anything else, which `godwitd` now logs instead of silently
  treating as "nothing to purge". Ground-truthed against the real bundled
  rclone v1.75.1 binary via a local-backend rcd fixture: the exact pre-fix
  call shape is proven rejected (red), then the fixed call lists real
  on-disk date directories and correctly reports a never-run share as
  absent rather than an error (green). Five additional unit tests exercise
  the tagged-result branches with an injected `$call`, and a source-shape
  test confirms `godwitd`'s retention block both calls the new helper and
  logs on `error`. This touches `plugin/scripts/godwitd`, outside this
  release's original front-end-only scope — called out here because it
  fixes a real, host-observed bug rather than a UX change.

### Changed

Settings page UX rework (front-end/PHP entry points only — **no change to
the job engine, budget ledger or scheduling semantics**).

- **Modals.** "Add Google Drive remote" and "Add OneDrive remote" now open
  a jQuery UI dialog (`.dialog({modal:true,...})` — confirmed as the stock
  unRAID 7.3.1 webGUI convention by reading the host's
  `/usr/local/emhttp/webGui/include/DefaultPageLayout.php` (loads
  `jquery.ui.css` globally) and finding `.dialog(...)` already used the
  same way on stock pages such as `dynamix/DeviceInfo.page` and
  `dynamix.vm.manager/VMMachines.page`; SweetAlert (`swal()`) is also
  stock but jQuery UI dialog was the closer fit for a form-with-fields
  modal) instead of inline page furniture. Each dialog keeps its own
  message div (`#drive-add-message` / `#onedrive-add-message`); both error
  and success leave the dialog open so the message can actually be read (a
  6-pass review at 4/6 agreement caught a first draft that auto-closed the
  dialog immediately after writing the success message into it, hiding it
  instantly — exactly the swallowed-result class 0.2.2 fixed for the
  page-wide message div; a source-shape regression test now pins this).
  The 0.2.2 persistent-message behaviour is preserved, just scoped
  per-dialog instead of to the page-wide `#godwit-remotes-message`, which
  still serves Test/Reauth/Delete. The OneDrive drive picker (0.2.4) is now the same kind of
  dialog, with the existing radio list (suggested drive preselected) plus
  a new Cancel button; jQuery UI moves dialog content in the DOM but never
  changes element ids, so `godwitAdd()`/`godwitRunAction()` needed no
  rewrite.
- **Collapsible sections.** Status, Remotes, Jobs, Windows & speed and
  Advanced are now native `<details>/<summary>` sections — grepping the
  installed stock webGui/dynamix pages for an accordion/collapse widget
  (`slideToggle`, `.collapse(`, `<details`) turned up nothing beyond one
  third-party plugin, so this is a documented native-HTML choice, not a
  stock unRAID mechanism. Status and Jobs default open; Remotes, Windows &
  speed and Advanced default closed.
- **Windows & speed form.** The raw JSON textarea is replaced by a
  generated form: one row per window with seven day-of-week checkboxes
  (labelled from `godwitDayLabels = ['Sun','Mon',...,'Sat']`, index 0 =
  Sunday, matching `godwit_time_in_window()`'s use of PHP's `w` — proven
  by a test that checks all 7 index→weekday pairings via
  `godwit_time_in_window()` itself, not just the label text), a start/end
  half-hourly dropdown (00:00–23:30, 48 slots), and a Mbit/s speed field.
  Add-window/Remove-window buttons. `start == end` ("all day") remains
  reachable and is labelled in the UI, not hidden as if a mistake. A time
  outside the half-hourly grid (from a pre-existing hand-edited raw JSON
  window) is added as an extra select option rather than silently snapped
  to the nearest slot. The line-profile dropdown and its 80%-of-upstream
  warning are now driven by the form's in-memory rows instead of parsing
  the old textarea. The raw JSON is still editable as an escape hatch in
  the collapsed Advanced section (`#godwit-windows-json` +
  "Apply JSON to form"), kept in sync with the form on every form change;
  Save always serializes from the form, never from the textarea directly.
- **Server-side validation added at the `settings_save` trust boundary**
  (`godwit_handle_job_action()` in `plugin/scripts/lib.php`): each window
  now needs at least one day, `days` entries must be integers 0-6,
  `start`/`end` must match `HH:MM` (00:00-23:59), and `limit_mbit` must be
  a positive number — mirroring the client-side checks so a malformed
  direct API call can't reach the daemon's schedule loop either.

### Tests

- `tests/windows_form_test.mjs` (new, run via `node`, wired into
  `php tests/run.php` as its one documented test command): extracts the
  dependency-free windows-form functions from `plugin/Godwit.page` between
  `GODWIT_WINDOWS_FORM_BEGIN`/`END` markers and proves the default
  midnight-wrap window round-trips through the form's row⇄window
  conversion unchanged (including exact JSON key order, which is what
  actually keeps a save byte-identical), that `start == end` round-trips
  without being rejected or coerced, that an off-grid time is preserved
  rather than snapped to the half-hourly grid, that days are sorted on the
  way out, and each `godwitValidateRow()` rejection path. All 9 failed
  (module/markers not found) before the markers and functions existed; a
  10th case (a non-array `days` throwing before validation can reject it)
  was added after the review round below caught the "Apply JSON to form"
  handler skipping that check.
- `tests/run.php`: a new test proves `days[] = [N]` is active on the exact
  weekday the settings page's day label at index N claims, for all 7
  indices, via `godwit_time_in_window()` itself (not just checking the
  label text) — this is what a mislabelled checkbox would silently get
  wrong. Four new tests on `godwit_handle_job_action('settings_save', …)`
  cover the new validation (empty `days`, non-positive/non-numeric
  `limit_mbit`, malformed `start`/`end`) and a `start == end` acceptance
  case, plus a byte-identical round-trip test that posts the exact payload
  shape the form's JS produces for an untouched default window and
  compares the stored `windows` JSON bytes before/after. Verified red
  against pre-change `lib.php` (`git stash` the validation, re-run): the
  empty-`days`, non-positive-`limit_mbit` and malformed-`start`/`end`
  tests failed as expected (3 failed, 199 passed); the byte-identical
  round trip already passed pre-change (nothing in the old code corrupted
  that case — its value is as a regression guard, not new-behaviour proof).
  The day-label test failed pre-change because `plugin/Godwit.page` had no
  `godwitDayLabels` array yet. 202/202 PHP tests pass at this point, plus
  the 10 Node checks above (see the "Review" note below for the further
  tests added on top of this baseline).

### Review

- Two rounds of `code-diff-reviewer` (6 passes each, unattended — MID band
  both times, score 7, counsel offered-but-skipped per the unattended
  default). Round 1 (UX rework alone): 6× NO FINDINGS. Round 2 (UX rework +
  the issue #1 fix together): a real bug at 4/6 agreement — `godwitAdd()`'s
  success path closed the just-opened dialog immediately after writing the
  "Added X — checking connection…" confirmation into it, hiding the message
  before it could be read (the same swallowed-result class 0.2.2 fixed for
  the page-wide message div, reintroduced per-dialog). Fixed by not
  auto-closing on success, and pinned with a source-shape regression test
  proven red against the pre-fix code (`git stash`, 209 passed/1 failed,
  then 210/0 after the fix). A second, single-pass (1/6) finding asked
  whether jQuery UI's JS (not just its CSS) is actually loaded before this
  page's script runs — resolved by host evidence, not by argument: the
  host's `dynamix.js` (a plain synchronous `<script>` in `<head>`, per
  `DefaultPageLayout.php:142`, before the page body) is itself the jQuery
  UI bundle (`V.ui.version="1.14.1"` grepped directly from it), so
  `$.fn.dialog` exists before this page's inline script runs. 210 PHP tests
  + 10 Node checks pass after both fixes.

## [0.3.0] - 2026-09-17

### Added

Phase 3 (PLAN.md §5 item 3): Google full backup. **This is the first
release that writes to Google Drive.**

- **Jobs.** One Google job per share (Filing Cabinet, Kieren, Teegan,
  Photos), persisted in `jobs.json` under the flash config dir (preserved
  by uninstall, same as `rclone.conf`). Source is always
  `/mnt/user/<share>`, destination always `<remote>:godwit/<share>` — a
  hard runtime assertion (`godwit_assert_job_direction()`) refuses to build
  an rc call for any other shape, so a reversed direction is structurally
  impossible, not just avoided by convention. Mode is `sync` with
  `--backup-dir <remote>:godwit/_versions/<share>/<date>` by default (a
  `copy`-only alternative is available per job), with a `--max-delete`
  guard (default 1000). Kieren excludes `/TimeMachine/**` (live sparsebundle,
  D13) and `/Backup/BombVault/**` (contains Godwit's own `rclone.conf` and
  the SecretsMan store — must never sit unencrypted in Drive, D14); global
  excludes (`.DS_Store`, `._*`, `.Trashes/**`, `.Recycle.Bin/**`,
  `Thumbs.db`) apply to every job. Saving an overlapping pair of
  destinations, or one that would sit inside `godwit/_versions`, is
  rejected server-side before it ever reaches `jobs.json`.
- **Runner.** `godwitd` starts at most one `sync/sync`/`sync/copy` `_async`
  rc job per remote, in queue order (Filing Cabinet, Kieren, Teegan,
  Photos — small first), with `Transfers`/`MaxDelete`/`CutoffMode=CAUTIOUS`/
  `MaxTransfer` set per call and `RCLONE_DRIVE_CHUNK_SIZE=64M` /
  `RCLONE_DRIVE_STOP_ON_UPLOAD_LIMIT=true` set once in rcd's own
  environment (backend options, not `_config` keys). Every run is recorded
  in SQLite (start/end, bytes, files, errors, outcome). A godwitd/rcd
  restart always loses whatever rc job was in flight — the run row is
  closed as `interrupted` on startup and picked back up by the normal
  selection pass; sync is idempotent, so this is a plain requeue, not a
  special case.
- **Daily budget (§4.3).** A rolling-24h per-remote ledger, fed from
  `core/stats` deltas every tick — the real guard, independent of
  `MaxTransfer` (confirmed in Phase 1's spike to be able to overshoot by up
  to transfers × average file size). Default cap 700 GiB for `gdrive`
  (D12). A job that finishes after `--drive-stop-on-upload-limit` fires is
  recorded as `throttled` and the remote is blocked for 24h.
- **Windows and speed (§4.5, D12).** Default window every day 22:00–06:00
  at 250 Mbit/s, applied via `core/bwlimit` only when the desired rate
  changes. Mbit/s is converted to an explicit byte count with rclone's `B`
  suffix, never its `M` (MiB, not Mbit) suffix — `31.25M` would actually be
  ~262 Mbit/s, a silent 5% overshoot. Outside every window, no new job
  starts; a job already running got `MaxDuration` set to the seconds left
  in its window at start time (`CutoffMode=CAUTIOUS`), so rclone itself
  stops picking up new files at the boundary and lets an in-flight transfer
  finish rather than hard-killing it (a 134 GB file at 250 Mbit/s takes
  ~1.3h — Drive's resumable uploads don't survive an rclone restart, so
  this matters). **"Run now" (D15)** starts the queue immediately
  regardless of the window, still honouring the speed limit and budget,
  and clears itself once the queue drains. Line-profile presets
  (`1000/400`, `500/50`) warn on the Jobs page when the window's limit is
  at or above 80% of the profile's upstream.
- **Status page.** A Jobs section: per-job enabled/mode/transfers/excludes
  (editable, saved server-side with the same direction/overlap validation
  as the daemon), running state with live bytes/files/ETA, last-run outcome,
  and queue position; per-remote 24h budget bar and throttled-until; a
  windows/profile editor with the 80% warning; Run now / Pause / Resume-all.
- **Version retention.** `godwit/_versions/<share>/<date>` directories older
  than 30 days (configurable) are purged once a day via `operations/purge`
  — every path is rebuilt and re-validated by
  `godwit_assert_purge_path()`/`godwit_purge_call_params()` immediately
  before the call (must match exactly
  `godwit/_versions/<share>/YYYY-MM-DD`, whole date directories only),
  never a hand-built string.
- **Notifications**, using the existing notify-once logic: a run ending in
  error, budget reached (once per remote per day), throttled (once on
  entering the state), and a share's first full seed completing (once ever
  per job).

### Tests

170 new offline tests (178 total): job builder + hard direction assertion
(including the reversed-direction case), filter compilation verified
against the real bundled rclone binary (`lsf -R --filter-from`, proving
both the anchored Kieren excludes and the unanchored Mac-junk excludes),
budget ledger maths (rolling window, boundary, overshoot), window
evaluation (midnight wrap, weekday-restricted windows, DST-free
`Australia/Brisbane`), Mbit→bytes/s conversion, line-profile warning,
one-job-per-remote queue selection and ordering, requeue-after-restart,
retention purge path safety (including deliberately malformed inputs),
notification rules, and the new `jobs_save`/`settings_save`/`jobs_status`
web actions (including a caught bug where a partial `settings_save` would
have reset the budget cap back to default, and a caught bug in the queue-
position calculation).

## [0.2.4] - 2026-09-17

### Fixed

Kieren's live 0.2.3 run against a real OneDrive personal account hit two
issues:

1. An expired-token config-walk failure: `Failed to query available
   drives: /me/drives: Get "https://graph.microsoft.com/v1.0/me/drives":
   couldn't fetch token: Post "": unsupported protocol scheme ""`.
   **Root cause is an upstream rclone bug**, verified against rclone
   v1.75.1 `backend/onedrive/onedrive.go`: `Config()` (starting line 629)
   calls `makeOauthConfig(ctx, opt)` only for the initial `conf.State ==
   ""` case (line 639); every subsequent config-wizard state instead calls
   `oauthutil.NewClient(ctx, name, m, oauthConfig)` (line 648) against the
   package-global `oauthConfig`, whose `TokenURL`/`AuthURL` are never
   populated outside `makeOauthConfig()`. If the pasted token's access part
   has already expired, this call's own attempt to refresh it posts to `""`
   and fails with exactly the error above, instead of refreshing. A token
   pasted within its lifetime (about an hour after `rclone authorize`)
   never needs a refresh and is unaffected.
2. 0.2.3's "cannot choose automatically" ambiguity error, hit because his
   account has six personal-labelled OneDrive drives (`AEEE102E-…`,
   `Bundles_b896e2bb…`, `ODCMetadataArchive`, `OneDrive`, `2974e5ed-…`,
   `C022FB8E-…`) — the "uniquely personal" heuristic added in 0.2.3 can
   never disambiguate more than one.

Fix:
1. **Drive picker.** When OneDrive's `config_driveid` Examples list has
   more than one entry and no `drive_id` was supplied, the walker now
   throws `GodwitDriveChoiceNeeded` (carrying the offered `{id, label}`
   choices and a `suggested` id — the drive named exactly `OneDrive`, if
   any) instead of guessing. `godwit_handle_remote_action()` catches it,
   rolls back the half-created remote via the same `config/delete` pattern
   already used for other walk failures, and returns
   `{choose_drive: [...], suggested}`. `Godwit.page` renders those as a
   radio list under the OneDrive form (pre-selecting `suggested`) with a
   "Use this drive" button that resubmits the same add (keeping the
   name/token/client fields, same as any other failed add) plus
   `drive_id`. The walker answers `config_driveid` with that value only if
   it matches one of the offered Examples, otherwise it fails with a clear
   error. The 0.2.3 "uniquely personal" heuristic is gone — a picker makes
   it unnecessary, and it could never have resolved Kieren's account
   anyway. A single-drive account still auto-selects, unchanged.
2. **Token expiry pre-check.** Before calling rcd for an add or reauth,
   `godwit_token_expiry_error()` parses the pasted token's `expiry` field
   (RFC3339Nano, as `rclone authorize` emits — e.g.
   `2026-09-17T21:43:12.6543211+10:00`, not just a bare `...Z`) and refuses
   an already-expired (or <5min-from-expiry) token with a friendly,
   provider-appropriate message ("Run `rclone authorize \"onedrive\"`
   again…") before ever reaching `config/create`/`config/update`. A
   missing or unparseable `expiry` is not blocked. `godwit_classify_walk_error()`
   classifies rclone's `unsupported protocol scheme` text (case 1 above)
   into the same friendly message, in case the token expires mid-walk
   instead of before it starts. Both are logged as `failed validation:`.

`godwit_handle_remote_action()` now takes an optional injected `$rcCall`
(defaulting to `godwit_rc_call_params`, same DI pattern as
`godwit_walk_config_state()`'s `$call` and `godwit_check_remote_about()`'s
`$call`) so the add branch's `config/create`/`config/update`/`config/delete`
calls can be driven by a fixture in tests without a real rcd or network.

Tests (`tests/run.php`): the two-drives-one-personal fixture now proves the
picker is offered instead of an auto-pick; a walker-level and a full-stack
`godwit_handle_remote_action()` test for the picker response and its
rollback; resubmission with a valid and an unknown `drive_id`; single-drive
auto-select unchanged; `godwit_token_expiry_error()` for expired,
soon-to-expire, plenty-of-time, missing, and unparseable `expiry`, including
one using rclone's real RFC3339Nano-with-offset shape (not just the bare
`...Z` shape used elsewhere in this file); `godwit_classify_walk_error()`
for the `unsupported protocol scheme` text and an unrelated message passed
through unchanged; a page-source regression guard for the picker markup.

## [0.2.3] - 2026-09-17

### Fixed

Kieren reported adding a real OneDrive remote failing with `Failed to query
root for drive "false": HTTP error 400 ... "ObjectHandle is Invalid"`.

Root cause: `godwit_walk_config_state()` (`plugin/scripts/lib.php`) answered
every rclone config-wizard question with a blanket `"false"`, except
`choose_type_done` → `"onedrive"`. OneDrive's drive-selection question
(`config_driveid`, state `driveid_final`) must be answered with one of the
real drive IDs rclone offered (`Option.Examples[].Value`), not `"false"` —
sending `"false"` made rclone `GET /drives/false/root` and 400. Verified
against rclone v1.75.1 source (`backend/onedrive/onedrive.go`,
`fs/backend_config.go`, `fs/registry.go`, `lib/oauthutil/oauthutil.go`).

Fix: the walker now answers each question by its `Option.Name` instead of
guessing:
- `config_refresh_token`, `config_change_team_drive` → `false` (unchanged —
  Google Drive's live add path is untouched)
- `config_type` → `onedrive` (unchanged)
- `config_driveid` (OneDrive only) → picked from `Option.Examples`: the only
  one if there's one, the one *uniquely* whose Help contains `(personal)` if
  several (two personal-labelled drives is still ambiguous, not a silent
  pick of the first), otherwise a clear error naming the drives (no IDs)
  rather than guessing
- `config_drive_ok` → `true` (was previously declined, which would have
  looped the wizard back to `choose_type` had a drive question ever
  succeeded before this fix)
- any other question: the Option's own `DefaultStr` if it has one,
  otherwise a clear error naming the question instead of guessing `false`
- the chosen drive's label is now included in the `remote add ... ok` log
  line and in the action's JSON response (`drive`), which `Godwit.page`
  shows in the success message

Also: backend/rclone-reported failures (a bad token, a 400 from Graph) are
now logged as `failed:` rather than `failed validation:` — the latter is
reserved for Godwit's own input checks (name, token shape, client
id/secret).

Tests (`tests/run.php`): a fixture reproducing the exact reported sequence
and error text (proven to fail against the pre-fix walker — see PR/commit
for the red run), one-drive, two-drives-one-personal, two-drives-ambiguous
(clear ID-free error), `config_drive_ok` answered `true`, an unrecognised
question with and without a `DefaultStr`, and a step-cap regression test
rewritten to use a recognised-but-never-terminating question (the old
version relied on the removed blanket-`"false"` behaviour). The existing
Google Drive and original OneDrive fixtures are unchanged and still pass.

## [0.2.2] - 2026-09-17

### Fixed

Kieren reported Test/Add doing "nothing". Root cause: `godwitRemoteAction()`
always calls `godwitRemotesRefresh()`, whose success handler unconditionally
clears `#godwit-remotes-message`, wiping any result/error milliseconds after
it's shown. Separately, timed-out health checks were misclassified as
`unchecked` instead of `error`.

Fix, in `plugin/Godwit.page` and `plugin/scripts/lib.php`:
- Split "user action" postbacks (message persists, shows pending/result/error
  state, has a `.fail()` handler) from "background refresh" postbacks (silent,
  never touches the action message div). Message div moved directly under the
  remotes table.
- Test/Add/Reauth/Delete buttons disable during the call and show a pending
  label; Add clears its token/secret fields only on success.
- Name inputs get real default values instead of placeholders.
- `godwit_extract_token_json()` tolerates the `rclone authorize` paste wrapper
  and surrounding whitespace before validating access_token/refresh_token.
- `godwit_rc_call_params()` takes a timeout + optional `$meta` out-param so a
  curl timeout is distinguishable from other transport failures;
  `godwit_check_remote_about()` now always returns `error` (with "timed out
  after Ns") on a failed check — `unchecked` is reserved for "never checked",
  set by `remotes_list` for a remote with no health row yet. Timeout raised
  to 60s for `operations/about` and the config walk.
- `godwit_health_notifications()` gains a consecutive-failure counter:
  ok→error notifies only on the 2nd consecutive failure (one rcd hiccup
  shouldn't page anyone), auth-expired still notifies immediately.
- `godwit_handle_remote_action()` logs every on-demand test/add/reauth/delete
  (redacted) to `/var/log/godwit.log`, and touches the existing (previously
  unwired) `check-now` marker after a successful add so the new remote gets
  checked within a second instead of waiting up to an hour.
- `godwit_log()` now strips control characters (not just secret-shaped text)
  from every message before writing it — found by this feature's own review
  pipeline: a remote name reaches `godwit_log()` before
  `godwit_validate_remote_name()` gets a chance to reject it in a couple of
  branches, so an unsanitised name containing a newline plus a fake
  `[timestamp] ...` prefix could forge what looked like a second, distinct
  log line in `/var/log/godwit.log`.
- The Add form now checks the remote name before parsing the pasted token,
  so a blank name is reported as the actual problem instead of being
  hidden behind an unrelated token error.

Tests added to `tests/run.php` for: token extraction (incl. the exact
`--->`/`<---End paste` wrapper), timeout classification (via dependency
injection, same pattern as `godwit_walk_config_state`), the notify rule, the
new log lines, and the log-injection fix. Two source-shape checks (not
behaviour tests — the page JS has no execution harness) guard against this
exact regression recurring: the background remotes-list refresh must never
reference `#godwit-remotes-message`, and the message div must sit between
the remotes table and the Add forms. Also verified by reading the installed
page source on the host post-deploy.

## [0.2.1] - 2026-09-17

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
