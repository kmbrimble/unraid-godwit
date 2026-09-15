# Godwit

*"Flies your data a long way away."*

Offsite backup for Unraid, built as a GUI over [rclone](https://rclone.org/).
Named for the bar-tailed godwit, the bird that holds the record for the
longest non-stop flight of any species and lands in Australia every year.

**Status: not yet built.** This repo is scaffolding; Phase 1 (packaging and
the release pipeline) has not started.

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

(Not yet published — nothing has shipped.)

## Licence

GPL-2.0 — see [LICENSE](LICENSE).
