# Godwit — CLAUDE.md

The build plan, kept out of the repo, is the authority for this project.
`PLAN.md` at the repo root is gitignored (it names host shares and sizes) and
is never committed. This file is derived from it and from the repo as it
stands — if the two conflict, re-derive this file from the current repo
state, not from memory of an earlier plan.

**Phase 1 (scaffold + release pipeline) is built; host verification is
pending Step 8 of this session (see CHANGELOG.md).** See PLAN.md §5 for the
remaining phases.

## Test command

```
php tests/run.php
```

(Requires `php-cli`, `php-sqlite3`, `php-curl`; `apt-get install -y php-cli
php-sqlite3 php-curl` if missing. `scripts/build-plugin.sh` additionally
needs `curl`, `unzip` and `xz-utils`.)

## Deploy and verify

1. `scripts/install-on-host.sh [version] [--forced]` — installs or upgrades
   on the live host, waiting for the CDN copy of `godwit.plg` to converge on
   the released version and md5 first (raw.githubusercontent.com caches for
   up to 5 minutes), then checks: flash `.plg` version, package registration,
   installed tree, stock README, bundled rclone reports v1.75.1, `rc.godwit`
   status, exactly one `godwitd` and one `rcd` process (from the bundled
   binary), the rcd unix socket present, `rclone.conf` created 0600, the
   Plugins-tab row rendering through the host's own `ShowPlugins.php`, the
   settings-page status call reporting the rclone version, and that
   `/usr/sbin/rclone` and the Waseh plugin's files are byte-for-byte
   unchanged (md5 + mtime snapshot before and after).
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
