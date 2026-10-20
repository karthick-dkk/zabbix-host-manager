#!/usr/bin/env bash
# Builds the offline package dist/hostflow-<version>.tar.gz (module + widget + draw.io + DEPLOY.md)
# and its .sha256, from this checkout. The version is the one in manifest.json.
#   tools/package.sh
# Install it on a server without internet:  sudo ./install.sh --file hostflow-<version>.tar.gz
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(dirname "$here")"
v="$(grep -o '"version": *"[^"]*"' "$here/manifest.json" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"
[ -f "$here/assets/drawio/index.html" ] || "$here/tools/install-drawio.sh"

st="$(mktemp -d)"
trap 'rm -rf "$st"' EXIT
pkg="$st/hostflow-$v"
mkdir -p "$pkg/modules/hostflow" "$pkg/modules/hostflow_widget"
for f in manifest.json Module.php README.md; do cp "$here/$f" "$pkg/modules/hostflow/"; done
for d in actions lib views assets; do cp -R "$here/$d" "$pkg/modules/hostflow/"; done
cp -R "$root/hostflow-widget/." "$pkg/modules/hostflow_widget/"
cp "$here/DEPLOY.md" "$here/install.sh" "$pkg/"
find "$st" \( -name .DS_Store -o -name '._*' -o -name .gitignore \) -delete
command -v xattr >/dev/null 2>&1 && xattr -rc "$st" 2>/dev/null || true

mkdir -p "$root/dist"
out="$root/dist/hostflow-$v.tar.gz"
# No macOS metadata and root ownership, so GNU tar unpacks it cleanly.
if tar --version 2>/dev/null | grep -q bsdtar; then
	COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs --uid 0 --gid 0 --uname root --gname root -C "$st" -czf "$out" "hostflow-$v"
else
	tar --owner=0 --group=0 -C "$st" -czf "$out" "hostflow-$v"
fi
(cd "$root/dist" && { command -v sha256sum >/dev/null && sha256sum "hostflow-$v.tar.gz" || shasum -a 256 "hostflow-$v.tar.gz"; } > "hostflow-$v.tar.gz.sha256")
echo "✓ $out ($(du -h "$out" | awk '{print $1}'))"
cat "$out.sha256"
