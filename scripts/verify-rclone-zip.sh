#!/bin/bash
# Verify a downloaded rclone release zip against that release's SHA256SUMS
# file. Fails (exit 1) on a missing entry or a hash mismatch — the build must
# not package an unverified binary. Split out from build-plugin.sh so tests
# can feed it a bad SUMS file and a SUMS file missing the entry.
set -euo pipefail

SUMS_FILE="${1:?usage: verify-rclone-zip.sh <sums-file> <zip-file> <zip-name>}"
ZIP_FILE="${2:?usage: verify-rclone-zip.sh <sums-file> <zip-file> <zip-name>}"
ZIP_NAME="${3:?usage: verify-rclone-zip.sh <sums-file> <zip-file> <zip-name>}"

EXPECTED="$(grep -F "  $ZIP_NAME" "$SUMS_FILE" | awk '{print $1}' || true)"
if [[ -z "$EXPECTED" ]]; then
    echo "verify-rclone-zip: no entry for $ZIP_NAME in $SUMS_FILE" >&2
    exit 1
fi

ACTUAL="$(sha256sum "$ZIP_FILE" | awk '{print $1}')"
if [[ "$ACTUAL" != "$EXPECTED" ]]; then
    echo "verify-rclone-zip: sha256 mismatch for $ZIP_NAME (expected $EXPECTED, got $ACTUAL)" >&2
    exit 1
fi

echo "verify-rclone-zip: $ZIP_NAME sha256 OK ($ACTUAL)"
