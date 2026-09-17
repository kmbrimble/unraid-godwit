#!/bin/bash
# Remove godwit from the live Unraid host over ssh and assert the revert was
# clean. As of 0.2.0, /boot/config/plugins/godwit/ (rclone.conf and any
# future godwit.cfg/jobs.json) is deliberately preserved — it can hold live
# OAuth credentials — so this checks the directory survives with rclone.conf
# still in it, while everything else godwit installed is gone. Any other
# leftover here is a packaging bug, not intended state, and this fails
# loudly on one.
set -euo pipefail

HOST="192.168.0.10"
SSH_KEY="/root/.ssh/unraid_secretsman"
SSH=(ssh -o StrictHostKeyChecking=no -i "$SSH_KEY" "root@$HOST")

echo "removing godwit from $HOST..."
"${SSH[@]}" "plugin remove godwit.plg"

echo "verifying clean revert..."
FAIL=0

check_absent() {
    local desc="$1" cond="$2"
    if "${SSH[@]}" "$cond"; then
        echo "  FAIL: $desc still present" >&2
        FAIL=1
    else
        echo "  OK: $desc absent"
    fi
}

check_absent "/boot/config/plugins/godwit.plg" "[[ -f /boot/config/plugins/godwit.plg ]]"
check_absent "/boot/config/plugins/godwit/*.txz (installed package files)" \
    "ls /boot/config/plugins/godwit/godwit-*.txz >/dev/null 2>&1"
check_absent "/usr/local/emhttp/plugins/godwit/" "[[ -d /usr/local/emhttp/plugins/godwit ]]"

if "${SSH[@]}" "[[ -f /boot/config/plugins/godwit/rclone.conf ]]"; then
    echo "  OK: /boot/config/plugins/godwit/rclone.conf preserved (0.2.0 onward — holds live OAuth credentials)"
else
    echo "  FAIL: /boot/config/plugins/godwit/rclone.conf missing — uninstall must preserve it as of 0.2.0" >&2
    FAIL=1
fi
check_absent "/var/log/plugins/godwit.plg" "[[ -f /var/log/plugins/godwit.plg ]]"

# Match the installed daemon's full absolute path, not the bare word
# "godwitd" — a bare `pgrep -f godwitd` could match unrelated long-lived
# process command lines that happen to quote the text. The absolute install
# path is specific enough, but pgrep -f also matches its OWN invoking shell
# (the ssh command's argv contains this same pattern text), so the single-
# character bracket stays on the last path segment to keep that literal
# argument from self-matching.
check_absent "godwitd process" "pgrep -f '/usr/local/emhttp/plugins/godwit/scripts/[g]odwitd'"

# rc.godwit's stop sends SIGTERM to godwitd, which terminates its own rcd
# child as part of its shutdown handler before godwitd exits — so this must
# be absent too, or Phase 1's rcd is left orphaned after every uninstall
# (and every plain `rc.godwit stop`).
check_absent "rcd process from the bundled binary" "pgrep -f '/usr/local/emhttp/plugins/godwit/bin/[r]clone rcd'"
check_absent "rcd unix socket" "[[ -S /var/run/godwit/rcd.sock ]]"
check_absent "/var/run/godwit/ rundir" "[[ -d /var/run/godwit ]]"
check_absent "/var/run/godwit.pid" "[[ -f /var/run/godwit.pid ]]"
check_absent "godwit entry in /var/log/packages/" "ls /var/log/packages/ | grep -q '^godwit-'"

if [[ "$FAIL" -ne 0 ]]; then
    echo "uninstall-on-host: leftover state found — packaging bug, fix before re-release" >&2
    exit 1
fi

# Deliberately NOT checked here: /mnt/cache/appdata/godwit/godwit.db. The
# plg's remove block only ever touches the flash config dir and the
# installed tree, never appdata — matching Dormouse, and giving Phase 2 a
# heartbeat history that survives a remove/reinstall cycle during
# development.
echo "uninstall-on-host: clean revert confirmed (heartbeat db under appdata is deliberately preserved)"
