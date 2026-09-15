#!/bin/bash
# Install (or upgrade) godwit on the live Unraid host over ssh, then verify
# the install. Run from this container — the host has no way to reach
# GitHub Actions and this container is not on the same trust boundary as the
# host, so all verification happens here, over ssh, read-only aside from the
# `plugin install` call itself.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLG="godwit.plg"
HOST="192.168.0.10"
SSH_KEY="/root/.ssh/unraid_secretsman"
SSH=(ssh -o StrictHostKeyChecking=no -i "$SSH_KEY" "root@$HOST")

FORCED=""
VERSION=""
for arg in "$@"; do
    case "$arg" in
        --forced) FORCED="forced" ;;
        *) VERSION="$arg" ;;
    esac
done

VERSION="${VERSION:-$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' "$REPO_ROOT/$PLG")}"
PLG_URL="https://raw.githubusercontent.com/kmbrimble/unraid-godwit/main/$PLG"

# The release workflow commits the real md5 onto main AFTER building — wait
# for that commit to exist upstream, and pin to ITS md5, not merely "any
# non-placeholder md5". Waiting for the CDN to match a same-looking-but-wrong
# md5 from a stale, previously-released version would pass this check and
# then fail the plugin manager's own MD5 verification on install.
git -C "$REPO_ROOT" fetch -q origin main
EXPECTED_PLG="$(git -C "$REPO_ROOT" show "origin/main:$PLG")"
EXPECTED_VERSION="$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' <<<"$EXPECTED_PLG")"
EXPECTED_MD5="$(sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p' <<<"$EXPECTED_PLG")"

if [[ "$EXPECTED_VERSION" != "$VERSION" ]]; then
    echo "ERROR: origin/main has version $EXPECTED_VERSION, expected $VERSION — has the release workflow run yet?" >&2
    exit 1
fi
if [[ -z "$EXPECTED_MD5" || "$EXPECTED_MD5" == "00000000000000000000000000000000" ]]; then
    echo "ERROR: origin/main's godwit.plg still has the placeholder md5 — release workflow has not written the real one back yet" >&2
    exit 1
fi

echo "waiting for raw.githubusercontent.com to serve version $VERSION with md5 $EXPECTED_MD5..."
ATTEMPTS=40
for i in $(seq 1 "$ATTEMPTS"); do
    RAW="$(curl -fsSL -H 'Cache-Control: no-cache' "$PLG_URL" || true)"
    REMOTE_VERSION="$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' <<<"$RAW")"
    REMOTE_MD5="$(sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p' <<<"$RAW")"
    if [[ "$REMOTE_VERSION" == "$VERSION" && "$REMOTE_MD5" == "$EXPECTED_MD5" ]]; then
        echo "CDN serving $VERSION (md5 $REMOTE_MD5) after $i attempt(s)"
        break
    fi
    if [[ "$i" == "$ATTEMPTS" ]]; then
        echo "ERROR: CDN never converged on version $VERSION / md5 $EXPECTED_MD5 after $ATTEMPTS attempts" >&2
        exit 1
    fi
    sleep 15
done

INSTALLED_VERSION="$("${SSH[@]}" "sed -rn 's|^<!ENTITY version[[:space:]]+\"([^\"]+)\">.*|\1|p' /boot/config/plugins/$PLG 2>/dev/null || true")"

# The host has no python3 and this container has no php by default, but we
# installed php-cli here (see CLAUDE.md test command), so run the same guard
# scripts/version-sorts-after.php uses everywhere else — one home for the
# strcmp rule, matching dynamix.plugin.manager's `plugin` script.
if [[ -n "$INSTALLED_VERSION" && -z "$FORCED" ]]; then
    if ! php "$REPO_ROOT/scripts/version-sorts-after.php" "$INSTALLED_VERSION" "$VERSION"; then
        echo "ERROR: $VERSION does not sort after installed $INSTALLED_VERSION (strcmp). Use --forced to override." >&2
        exit 1
    fi
fi

# Snapshot the Waseh plugin and /usr/sbin/rclone before touching anything —
# godwit must never touch either (PLAN.md D10), and this is the only way to
# prove that after the fact. Fixed paths, not a name glob: godwit's own
# install lands at /usr/local/emhttp/plugins/godwit/bin/rclone, which a bare
# "*rclone*" glob over all plugin dirs would also match, guaranteeing a
# before/after mismatch that has nothing to do with the Waseh plugin.
snapshot_waseh_and_sbin() {
    "${SSH[@]}" "md5sum /usr/sbin/rclone 2>/dev/null; find /usr/local/emhttp/plugins/rclone /usr/local/emhttp/plugins/rclone-beta /boot/config/plugins/rclone* -type f 2>/dev/null | sort | xargs -r stat -c '%n %Y %s' 2>/dev/null"
}
echo "snapshotting /usr/sbin/rclone and the Waseh plugin's files..."
BEFORE_SNAPSHOT="$(snapshot_waseh_and_sbin)"

echo "installing $VERSION on $HOST..."
"${SSH[@]}" "plugin install '$PLG_URL' $FORCED"

echo "verifying install..."
FAIL=0

check() {
    local desc="$1"; shift
    if "${SSH[@]}" "$@"; then
        echo "  OK: $desc"
    else
        echo "  FAIL: $desc"
        FAIL=1
    fi
}

# (a) installed .plg version on the host
check "flash .plg reports version $VERSION" \
    "grep -q '<!ENTITY version *\"$VERSION\">' /boot/config/plugins/$PLG"
check "registered in /var/log/plugins/godwit.plg" \
    "[[ -f /var/log/plugins/godwit.plg ]]"
check "installed tree complete" \
    "[[ -f /usr/local/emhttp/plugins/godwit/README.md && -f /usr/local/emhttp/plugins/godwit/Godwit.page && -f /usr/local/emhttp/plugins/godwit/scripts/rc.godwit && -f /usr/local/emhttp/plugins/godwit/scripts/godwitd && -f /usr/local/emhttp/plugins/godwit/scripts/lib.php && -f /usr/local/emhttp/plugins/godwit/scripts/godwit-api.php ]]"
check "packaged README has no heading" \
    "! grep -q '^#' /usr/local/emhttp/plugins/godwit/README.md"

# (b) bundled rclone binary is the pinned version
check "bundled rclone reports v1.75.1" \
    "/usr/local/emhttp/plugins/godwit/bin/rclone version | head -1 | grep -q 'v1.75.1'"

check "rc.godwit reports running" \
    "bash /usr/local/emhttp/plugins/godwit/scripts/rc.godwit status"
check "pid file present" \
    "[[ -f /var/run/godwit.pid ]]"

# (c) godwitd and exactly one rcd running from the bundled binary, socket present
check "exactly one godwitd process" \
    "[[ \$(pgrep -fc '/usr/local/emhttp/plugins/godwit/scripts/[g]odwitd') == 1 ]]"
check "exactly one rcd process from the bundled binary" \
    "[[ \$(pgrep -fc '/usr/local/emhttp/plugins/godwit/bin/[r]clone rcd') == 1 ]]"
check "rcd unix socket present" \
    "[[ -S /var/run/godwit/rcd.sock ]]"
# /boot is a vfat mount — actual file mode is whatever the mount's
# fmask/dmask enforces, not necessarily 0600 (godwitd's chmod is
# best-effort there, see plugin/scripts/godwitd). Check existence, and
# report the real mode for the record rather than asserting a value that
# may just be a mount coincidence.
check "rclone.conf exists under /boot/config" \
    "[[ -f /boot/config/plugins/godwit/rclone.conf ]]"
"${SSH[@]}" "stat -c 'rclone.conf mode on this mount: %a' /boot/config/plugins/godwit/rclone.conf" || true

# (d) Plugins-tab row renders through the host's own ShowPlugins.php. This
# exact invocation is not proven by Dormouse (it did not have this check) —
# ShowPlugins.php may expect DOCUMENT_ROOT/cwd context that only exists
# under the webGui's own PHP-FPM process. cd into /usr/local/emhttp first,
# since ShowPlugins.php falls back to that as its root when DOCUMENT_ROOT is
# unset; note in the handback if this still doesn't render the row.
check "Plugins tab renders the godwit row via ShowPlugins.php" \
    "cd /usr/local/emhttp && php plugins/dynamix.plugin.manager/include/ShowPlugins.php 2>/dev/null | grep -qi godwit"

echo "waiting for the first heartbeat (daemon ticks every 15s)..."
sleep 20

# (e) settings page status call returns the rclone version. Try the same
# HTTP path the browser would use first, but the host's nginx auth_request
# setup likely rejects an unauthenticated loopback curl with a login page
# rather than JSON (unproven either way from this repo) — fall back to
# invoking the same script directly via the host's own PHP CLI, which reads
# real on-host state (the heartbeat db) exactly like the HTTP path does,
# just without going through nginx.
STATUS_JSON="$("${SSH[@]}" "curl -s -X POST http://localhost/plugins/godwit/scripts/godwit-api.php -d action=status" || true)"
if ! echo "$STATUS_JSON" | grep -q '"rclone_version":"v1.75.1"'; then
    echo "  HTTP status call did not report v1.75.1 ($STATUS_JSON) — falling back to CLI invocation"
    STATUS_JSON="$("${SSH[@]}" "php /usr/local/emhttp/plugins/godwit/scripts/godwit-api.php" || true)"
fi
if echo "$STATUS_JSON" | grep -q '"rclone_version":"v1.75.1"'; then
    echo "  OK: settings-page status call reports rclone v1.75.1 ($STATUS_JSON)"
else
    echo "  FAIL: settings-page status call did not report v1.75.1 via HTTP or CLI ($STATUS_JSON)"
    FAIL=1
fi

# Waseh / /usr/sbin/rclone unchanged
AFTER_SNAPSHOT="$(snapshot_waseh_and_sbin)"
if [[ "$BEFORE_SNAPSHOT" == "$AFTER_SNAPSHOT" ]]; then
    echo "  OK: /usr/sbin/rclone and Waseh plugin files unchanged"
else
    echo "  FAIL: /usr/sbin/rclone or Waseh plugin files changed"
    echo "    before: $BEFORE_SNAPSHOT"
    echo "    after:  $AFTER_SNAPSHOT"
    FAIL=1
fi

if [[ "$FAIL" -ne 0 ]]; then
    echo "install-on-host: one or more checks FAILED" >&2
    exit 1
fi

echo "install-on-host: all checks passed"
