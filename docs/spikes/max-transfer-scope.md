# Spike: is `MaxTransfer` scoped per job or per process?

PLAN.md §4.3 flags this as the one thing to confirm before Phase 3 builds the
budget ledger: when two rclone RC jobs run concurrently against the same
`rclone rcd` process, does a per-call `_config.MaxTransfer` cap apply to that
job alone, or to the whole process (i.e. does it see bytes moved by other
jobs too)?

**Answer: per job (stats group).** Confirmed empirically below, using the
bundled rclone v1.75.1 binary, entirely local (no cloud remotes involved, no
host details).

## Setup

Two local source directories, 5 files of 2 MiB each (10 MiB total) in each:

```
/tmp/rclone-spike/src_a  (10 MiB)
/tmp/rclone-spike/src_b  (10 MiB)
```

Started `rcd` with an empty local config, HTTP basic auth (not
`--rc-no-auth`):

```
rclone rcd --rc-addr=127.0.0.1:5572 --rc-user=spike --rc-pass=spikepass \
  --config=/tmp/rclone-spike/conf/rclone.conf \
  --log-file=/tmp/rclone-spike/rcd.log --log-level=INFO
```

Fired two concurrent async `sync/copy` calls, each with its own 3 MiB
`MaxTransfer` cap (well under each source's 10 MiB), back to back:

```
curl -s -u spike:spikepass -X POST -H Content-Type:application/json \
  -d @job_a.json http://127.0.0.1:5572/sync/copy
# job_a.json: {"srcFs":"/tmp/rclone-spike/src_a","dstFs":"/tmp/rclone-spike/dst_a",
#              "_async":true,"_config":{"MaxTransfer":3145728,"CutoffMode":"SOFT"}}
# -> {"executeId": "...", "jobid": 4}

curl -s -u spike:spikepass -X POST -H Content-Type:application/json \
  -d @job_b.json http://127.0.0.1:5572/sync/copy
# job_b.json: same shape, srcFs/dstFs pointed at _b
# -> {"executeId": "...", "jobid": 5}
```

## Result

```
job/status jobid=4: {"error":"max transfer limit reached as set by --max-transfer","finished":true,"group":"job/4","success":false}
job/status jobid=5: {"error":"max transfer limit reached as set by --max-transfer","finished":true,"group":"job/5","success":false}

core/stats group=job/4: {"bytes":8388608,"transfers":4,...}
core/stats group=job/5: {"bytes":8388608,"transfers":4,...}
core/stats (global, no group):  {"bytes":16777216,"transfers":8,...}

du -sh dst_a dst_b: 8.0M each
```

Both jobs independently transferred **~8 MiB each**, not a combined ~3 MiB.
Job B's own stats group has no knowledge of job A's bytes — if the cap were
enforced against a shared process total, job B would have started already
over budget (job A alone exceeded 3 MiB) and transferred nothing. It didn't:
it ran to its own independent cutoff. The global `core/stats` total (16 MiB)
is simply the sum of the two per-job groups, not a shared enforcement point.

**Conclusion: `MaxTransfer` (and by extension `CutoffMode`) passed via
per-call `_config` is scoped to that job's own stats group.** Two jobs
against different remotes, each with its own cap, do not interfere with each
other's budgets.

## Side finding: cap overshoot

Each job's cap of 3 MiB let ~8 MiB through before stopping. This is not a
per-job-vs-process-total effect — it's `--transfers=4` (the default)
copying up to 4 files in parallel per job; the cutoff is checked as each
transfer completes, not mid-transfer, so with four ~2 MiB files in flight at
once the job can overshoot by roughly `(transfers - 1) * avg file size`
before the next check trips it. The RC log shows this directly:

```
file2.bin: Copied (new)
NOTICE: max transfer limit reached as set by --max-transfer - stopping transfers
file4.bin: Copied (new)   <- already in flight when the cutoff fired
file3.bin: Copied (new)   <- already in flight when the cutoff fired
```

**Implication for Phase 3's budget ledger:** the daemon-side stop (PLAN.md
§4.3 "second line of defence") must not assume the per-call `MaxTransfer` cap
is exact — it can overshoot by up to `transfers × max file size` inside a
single job. The ledger's own periodic check (sampling `core/stats` for that
job's group) is still necessary and should not treat `MaxTransfer` alone as
sufficient to hit a byte-exact cap.
