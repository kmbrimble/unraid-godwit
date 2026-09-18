# Godwit

*"Flies your data a long way away."*

Offsite backup for Unraid, built as a GUI over [rclone](https://rclone.org/).
Named for the bar-tailed godwit, the bird that holds the record for the
longest non-stop flight of any species and lands in Australia every year.

**Status: Phase 4 shipped.** The plugin installs, runs a supervised daemon
(`rc.godwit` / `godwitd`) that manages a single bundled `rclone rcd`, and
Settings → Godwit can add, test, re-authorise and delete Google Drive and
OneDrive remotes. Godwit backs up whole shares to Google Drive (one job per
share, `sync` with `--backup-dir` versioning, or `copy`-only) and now also
backs up hand-picked folders/files to OneDrive via a browsable, Unbalanced-
style tri-state tree — both job types share the same rolling-24h upload
budget, scheduled time windows with a speed limit, "Run now" override,
version retention, and status page showing per-job and per-remote progress.

## What it does

- **Google Drive:** full backup of whole shares.
- **OneDrive (personal):** selective backup of folders, subfolders or
  individual files, picked from an Unbalanced-style tree.
- **Backup only.** Every job is a one-way `rclone sync` with `--backup-dir`
  versioning — deleted or overwritten files are kept, not lost. There is no
  two-way sync anywhere in this project.
- A PHP daemon, `godwitd`, supervises a single `rclone rcd` instance and runs
  jobs with multi-threaded transfers.
- A rolling 24-hour upload budget is enforced per remote (750 GiB/day on
  Google Drive by default).
- Scheduled time windows apply a configurable upload speed limit, so backups
  can be throttled during the day and opened up overnight.

## Install

Add this URL in the Unraid Plugins tab ("Install Plugin"):

```
https://raw.githubusercontent.com/kmbrimble/unraid-godwit/main/godwit.plg
```

## Remotes

Settings → Godwit → Remotes lists configured rclone remotes with a health
status (OK / auth-expired / error / unchecked), last-checked time and
quota. Adding a remote needs a token pasted from `rclone authorize` run on
another machine with a browser — the exact command and a short how-to are
shown on the page itself for both Google Drive and OneDrive. No
client_id, client_secret or token value is ever shown back once saved,
only whether it's set.

## Deleting credentials

Uninstalling Godwit (removing the plugin) deliberately **preserves**
`/boot/config/plugins/godwit/rclone.conf` (and any future
`godwit.cfg`/`jobs.json` in that directory) so reinstalling doesn't require
re-authorising every remote from scratch. To wipe credentials completely,
after uninstalling, remove the directory by hand over SSH or the Unraid
terminal:

```
rm -rf /boot/config/plugins/godwit
```

## Test

```
php tests/run.php
```

## Licence

GPL-2.0 — see [LICENSE](LICENSE).
