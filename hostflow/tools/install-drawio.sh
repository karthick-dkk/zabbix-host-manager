#!/usr/bin/env bash
# Installs the pinned draw.io web app into assets/drawio so it is served by Zabbix's own web
# server (same origin, no internet at run time). Run once after copying the module, and again
# after changing DRAWIO_VERSION.
#
#   tools/install-drawio.sh                 download from GitHub and verify the checksum
#   tools/install-drawio.sh /path/draw.war  use a war you already have (checksum still verified)
set -euo pipefail

DRAWIO_VERSION="31.4.6"
DRAWIO_SHA256="f7798104da17d7e9494ab348c3ba9b2a65640096bd54f704d7a0fa2fab283938"
URL="https://github.com/jgraph/drawio/releases/download/v${DRAWIO_VERSION}/draw.war"

here="$(cd "$(dirname "$0")/.." && pwd)"
dest="$here/assets/drawio"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

war="${1:-}"
if [ -z "$war" ]; then
  echo "→ Downloading draw.io v${DRAWIO_VERSION}…"
  curl -fsSL -o "$tmp/draw.war" "$URL"
  war="$tmp/draw.war"
fi

sum="$( (command -v sha256sum >/dev/null && sha256sum "$war" || shasum -a 256 "$war") | awk '{print $1}')"
if [ "$sum" != "$DRAWIO_SHA256" ]; then
  echo "✗ Checksum mismatch for $war" >&2
  echo "  expected $DRAWIO_SHA256" >&2
  echo "  got      $sum" >&2
  exit 1
fi

echo "→ Unpacking…"
mkdir -p "$tmp/x"
# A .war is a zip file: use whichever unzip tool the server has.
if command -v unzip >/dev/null 2>&1; then
  unzip -q "$war" -d "$tmp/x"
elif command -v bsdtar >/dev/null 2>&1; then
  bsdtar -xf "$war" -C "$tmp/x"
elif command -v python3 >/dev/null 2>&1; then
  python3 -m zipfile -e "$war" "$tmp/x"
elif command -v php >/dev/null 2>&1 && php -r 'exit(class_exists("ZipArchive") ? 0 : 1);'; then
  php -r '$z = new ZipArchive(); if ($z->open($argv[1]) !== true || !$z->extractTo($argv[2])) exit(1);' "$war" "$tmp/x"
else
  echo "✗ Need unzip, bsdtar or python3 to unpack draw.io (apt install unzip)." >&2
  exit 1
fi

# Not needed in the browser, or would reach out to cloud services.
rm -rf "$tmp/x/WEB-INF" "$tmp/x/META-INF" "$tmp/x/connect"
rm -f  "$tmp/x"/{service-worker.js,dropbox.html,github.html,gitlab.html,onedrive3.html,teams.html,monday-app-association.json}
rm -f  "$tmp/x"/workbox-*.js
find "$tmp/x" -name '*.map' -delete

# Zabbix's packaged nginx denies any URL containing app/, include/, local/, locale/ or vendor/.
blocked="$(cd "$tmp/x" && find . -type f | grep -E '/(app/|include/|local/|locale/|vendor/)|conf[^.]' || true)"
if [ -n "$blocked" ]; then
  echo "! These draw.io files would be blocked by Zabbix's nginx rules:" >&2
  echo "$blocked" | head -20 >&2
fi

echo "$DRAWIO_VERSION" > "$tmp/x/VERSION"
mkdir -p "$here/assets"
rm -rf "$dest.new"
mv "$tmp/x" "$dest.new"
rm -rf "$dest"
mv "$dest.new" "$dest"
echo "✓ draw.io v${DRAWIO_VERSION} installed in $dest ($(du -sh "$dest" | awk '{print $1}'))"
