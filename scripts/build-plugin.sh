#!/bin/bash
# Build dist/godwit-<version>.txz from plugin/, packaged at the path Unraid
# unpacks .txz files to: usr/local/emhttp/plugins/godwit/. Also downloads,
# SHA256-verifies and bundles the pinned rclone binary (PLAN.md D10) into
# the plugin's own bin/ dir — never /usr/sbin/rclone, never a Waseh path.
set -euo pipefail

VERSION="${1:?usage: build-plugin.sh <version>}"
NAME="godwit"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$REPO_ROOT/plugin"
OUT_DIR="$REPO_ROOT/dist"
BUILD_DIR="$REPO_ROOT/build"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# shellcheck disable=SC1091
source "$REPO_ROOT/scripts/rclone-pin.env"

mkdir -p "$BUILD_DIR"
ZIP_PATH="$BUILD_DIR/$RCLONE_ZIP_NAME"
SUMS_PATH="$BUILD_DIR/SHA256SUMS-$RCLONE_VERSION"

if [[ ! -f "$ZIP_PATH" ]]; then
    echo "downloading $RCLONE_ZIP_NAME..."
    curl -fsSL -o "$ZIP_PATH" "$RCLONE_ZIP_URL"
fi
if [[ ! -f "$SUMS_PATH" ]]; then
    echo "downloading SHA256SUMS for $RCLONE_VERSION..."
    curl -fsSL -o "$SUMS_PATH" "$RCLONE_SUMS_URL"
fi

bash "$REPO_ROOT/scripts/verify-rclone-zip.sh" "$SUMS_PATH" "$ZIP_PATH" "$RCLONE_ZIP_NAME"

RCLONE_EXTRACT_DIR="$WORK_DIR/rclone-extract"
mkdir -p "$RCLONE_EXTRACT_DIR"
unzip -q "$ZIP_PATH" -d "$RCLONE_EXTRACT_DIR"
RCLONE_BIN="$(find "$RCLONE_EXTRACT_DIR" -type f -name rclone)"

INSTALL_ROOT="$WORK_DIR/usr/local/emhttp/plugins/$NAME"
mkdir -p "$INSTALL_ROOT"

# Copy the installed tree as real files (no symlinks) — .page files must sit
# at the plugin's installed root, not a subdirectory, or Unraid's page
# loader will not find them.
cp -a "$PLUGIN_DIR/." "$INSTALL_ROOT/"

mkdir -p "$INSTALL_ROOT/bin"
cp "$RCLONE_BIN" "$INSTALL_ROOT/bin/rclone"
chmod +x "$INSTALL_ROOT/bin/rclone"
chmod +x "$INSTALL_ROOT/scripts/rc.godwit" "$INSTALL_ROOT/scripts/godwitd"

mkdir -p "$OUT_DIR"
TXZ="$OUT_DIR/$NAME-$VERSION.txz"
rm -f "$TXZ"

tar -C "$WORK_DIR" --owner=0 --group=0 -cJf "$TXZ" usr

MD5="$(md5sum "$TXZ" | awk '{print $1}')"

echo "built: $TXZ"
echo "md5: $MD5"
