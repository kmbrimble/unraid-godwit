#!/bin/bash
# Decides whether it is safe to start godwitd right now: the array must
# already be STARTED (per var.ini) AND /mnt/cache must be a real mountpoint.
# godwit.plg's install step uses this to avoid starting godwitd during a
# boot-time install, which runs ~14s before the cache pool is mounted
# (confirmed from /boot/logs/syslog) — starting early would let godwitd
# create its state dir on the RAM rootfs, silently covered by the later
# cache mount. Exit 0 if safe to start now, 1 otherwise (the `started`
# event hook is what actually starts it in the boot case).
set -u

VARINI="${GODWIT_VARINI:-/var/local/emhttp/var.ini}"
CACHE_DIR="${GODWIT_CACHE_DIR:-/mnt/cache}"

array_started() {
    [[ -f "$VARINI" ]] && grep -q 'mdState="STARTED"' "$VARINI"
}

cache_mounted() {
    # GODWIT_TEST_CACHE_MOUNTED lets tests force this without a real mount.
    if [[ -n "${GODWIT_TEST_CACHE_MOUNTED:-}" ]]; then
        [[ "$GODWIT_TEST_CACHE_MOUNTED" == "1" ]]
        return
    fi
    mountpoint -q "$CACHE_DIR"
}

array_started && cache_mounted
