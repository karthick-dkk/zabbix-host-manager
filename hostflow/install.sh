#!/usr/bin/env bash
# Host Flow: install or upgrade the Zabbix 7.0 module and its dashboard widget.
#
# Online, latest release (run on the Zabbix web server):
#   curl -fsSL https://raw.githubusercontent.com/karthick-dkk/zabbix-host-manager/main/hostflow/install.sh | sudo bash -s -- --upgrade
#
# Usage: install.sh [action] [options]
#   (no action)        install the latest release, or move to it
#   --upgrade          install the latest release only if it is newer than the installed one
#   --check            show the installed and the latest version; change nothing
#   --rollback         put the module back as it was before the last install (from its backup)
#   --uninstall        remove the module folders (diagrams and settings are kept)
#
# Options:
#   --version X.Y.Z    that release instead of the latest
#   --file PATH        install from a package you downloaded (hostflow-X.Y.Z.tar.gz); no internet needed
#   --local            install from this source folder (for development)
#   --force            reinstall even when that version is already installed
#   --zabbix-dir DIR   the folder with zabbix.php (found automatically)
#   --data-dir DIR     diagrams and settings (default /var/lib/zabbix-hostflow)
#   --web-user USER    the user PHP runs as (found automatically)
#   --no-backup        do not back up the current module and data first
#   --keep N           backups to keep in /var/backups/hostflow (default 5)
#   --enable URL       afterwards, register and enable both modules through the Zabbix API at URL
#                      (e.g. http://localhost:8080/zabbix); needs HOSTFLOW_API_TOKEN (Super admin token)
#
# Nothing in the live module changes until the new version is unpacked and checked: the new folders
# are built next to the old ones and swapped in with a rename, and any failure puts the old ones back.
set -euo pipefail

REPO="${HOSTFLOW_REPO:-karthick-dkk/zabbix-host-manager}"
TAG_PREFIX="hostflow-v"
BACKUPS=/var/backups/hostflow

action=install; version=""; file=""; local_src=""; force=0; backup=1; keep=5; enable_url=""
zbx=""; data="/var/lib/zabbix-hostflow"; web_user=""

# How to run this again, for the hints we print: the file, or the one-liner when piped from curl.
if [ -f "${BASH_SOURCE[0]:-}" ]; then SELF="sudo bash ${BASH_SOURCE[0]}"
else SELF="curl -fsSL https://raw.githubusercontent.com/$REPO/main/hostflow/install.sh | sudo bash -s --"; fi

say() { printf '%s\n' "$*"; }
step() { printf '→ %s\n' "$*"; }
die() { printf '✗ %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
	case "$1" in
		--upgrade) action=upgrade; shift ;;
		--check) action=check; shift ;;
		--rollback) action=rollback; shift ;;
		--uninstall) action=uninstall; shift ;;
		--version) version="${2#v}"; shift 2 ;;
		--file) file="$2"; shift 2 ;;
		--local) local_src="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; shift ;;
		--force) force=1; shift ;;
		--zabbix-dir) zbx="$2"; shift 2 ;;
		--data-dir) data="$2"; shift 2 ;;
		--web-user) web_user="$2"; shift 2 ;;
		--no-backup) backup=0; shift ;;
		--keep) keep="$2"; shift 2 ;;
		--enable) enable_url="${2%/}"; shift 2 ;;
		--war) die "--war is no longer needed: use --file with a full package, or let the installer download draw.io." ;;
		-h|--help) sed -n '2,29p' "${BASH_SOURCE[0]:-$0}" 2>/dev/null | sed 's/^# \{0,1\}//' || true; exit 0 ;;
		*) die "Unknown option $1 (see --help)" ;;
	esac
done

# ------------------------------------------------------------------ environment

find_zabbix() {
	[ -n "$zbx" ] && { [ -f "$zbx/zabbix.php" ] || die "No zabbix.php in $zbx"; return; }
	for d in /usr/share/zabbix /usr/share/zabbix/ui /var/www/html/zabbix /usr/share/nginx/html/zabbix /var/www/zabbix; do
		[ -f "$d/zabbix.php" ] && { zbx="$d"; return; }
	done
	die "Zabbix frontend not found. Pass --zabbix-dir (the folder that contains zabbix.php)."
}

find_web_user() {
	[ -n "$web_user" ] && return
	# The user the PHP workers run as: PHP-FPM pool, Apache, then the usual names.
	web_user="$(ps -eo user=,args= 2>/dev/null | awk '/php-fpm: pool|apache2|httpd/ && $1 != "root" {print $1; exit}')"
	if [ -z "$web_user" ]; then
		for u in www-data apache nginx zabbix; do id "$u" >/dev/null 2>&1 && { web_user="$u"; break; }; done
	fi
	[ -n "$web_user" ] || die "Could not tell which user runs PHP. Pass --web-user."
}

installed_version() {
	[ -f "$zbx/modules/hostflow/manifest.json" ] || { echo ""; return; }
	grep -o '"version": *"[^"]*"' "$zbx/modules/hostflow/manifest.json" | head -1 | sed 's/.*"\([^"]*\)"$/\1/'
}

# Highest hostflow-vX.Y.Z tag of the repository.
latest_version() {
	curl -fsSL --max-time 20 "https://api.github.com/repos/$REPO/tags?per_page=100" \
		| grep -o "\"name\": *\"${TAG_PREFIX}[0-9][0-9.]*\"" | sed "s/.*\"${TAG_PREFIX}\([0-9.]*\)\"/\1/" \
		| sort -V | tail -1
}

newer() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -1)" = "$1" ]; }

need() { command -v "$1" >/dev/null 2>&1 || die "$1 is required. Install it and run again."; }

# ------------------------------------------------------------------ actions

do_check() {
	local cur latest
	cur="$(installed_version)"
	say "Zabbix frontend : $zbx"
	say "Installed       : ${cur:-not installed}"
	latest="$(latest_version || true)"
	say "Latest release  : ${latest:-unknown (no internet or no release yet)}"
	if [ -n "$cur" ] && [ -n "$latest" ] && newer "$latest" "$cur"; then
		say "Upgrade with    : $SELF --upgrade"
	fi
}

backup_now() {
	local cur="$1" stamp out
	[ "$backup" = 1 ] || return 0
	[ -d "$zbx/modules/hostflow" ] || return 0
	mkdir -p "$BACKUPS"
	chmod 0700 "$BACKUPS"
	stamp="$(date +%Y%m%d-%H%M%S)"
	out="$BACKUPS/hostflow-$stamp-${cur:-unknown}.tar.gz"
	step "Backing up the current module and data to $out"
	tar -czf "$out" -C / \
		"${zbx#/}/modules/hostflow" \
		$( [ -d "$zbx/modules/hostflow_widget" ] && echo "${zbx#/}/modules/hostflow_widget" ) \
		$( [ -d "$data" ] && echo "${data#/}" ) 2>/dev/null || die "Backup failed; nothing was changed."
	chmod 0600 "$out"
	# Keep the newest $keep backups.
	ls -1t "$BACKUPS"/hostflow-*.tar.gz 2>/dev/null | tail -n +"$((keep + 1))" | xargs -r rm -f
}

# Puts the module source (folder with manifest.json, actions, lib, views, assets) in $src and the widget in $wsrc.
fetch() {
	work="$(mktemp -d)"
	trap 'rm -rf "$work"' EXIT
	if [ -n "$local_src" ]; then
		src="$local_src"; wsrc="$(dirname "$local_src")/hostflow-widget"
	elif [ -n "$file" ]; then
		[ -f "$file" ] || die "No such file: $file"
		step "Unpacking $file"
		tar -xzf "$file" -C "$work"
		locate_source
	else
		need curl
		local tag="${TAG_PREFIX}${version}"
		step "Downloading Host Flow $version from github.com/$REPO"
		curl -fsSL --max-time 300 -o "$work/src.tar.gz" "https://codeload.github.com/$REPO/tar.gz/refs/tags/$tag" \
			|| die "Could not download $tag. Check the version and the internet connection."
		tar -xzf "$work/src.tar.gz" -C "$work"
		locate_source
	fi
	[ -f "$src/manifest.json" ] && grep -q '"id": *"hostflow"' "$src/manifest.json" || die "This is not a Host Flow package."
}

locate_source() {
	local m
	m="$(grep -l '"id": *"hostflow"' $(find "$work" -maxdepth 4 -name manifest.json) 2>/dev/null | head -1 || true)"
	[ -n "$m" ] || die "No Host Flow module found in the package."
	src="$(dirname "$m")"
	wsrc=""
	for w in "$(dirname "$src")/hostflow_widget" "$(dirname "$src")/hostflow-widget"; do
		[ -f "$w/manifest.json" ] && { wsrc="$w"; break; }
	done
}

# draw.io: in the package already, the same pinned version already installed, or downloaded and verified.
ensure_drawio() {
	[ -f "$src/assets/drawio/index.html" ] && return
	local want have
	[ -f "$src/tools/install-drawio.sh" ] || die "This package has no draw.io and no way to get it. Use a full package or the online install."
	want="$(grep -o '^DRAWIO_VERSION="[^"]*"' "$src/tools/install-drawio.sh" | cut -d'"' -f2)"
	have="$(cat "$zbx/modules/hostflow/assets/drawio/VERSION" 2>/dev/null || true)"
	if [ -n "$want" ] && [ "$want" = "$have" ]; then
		step "Reusing the installed draw.io v$have"
		mkdir -p "$src/assets"
		cp -R "$zbx/modules/hostflow/assets/drawio" "$src/assets/drawio"
		return
	fi
	[ -x "$src/tools/install-drawio.sh" ] || chmod +x "$src/tools/install-drawio.sh"
	"$src/tools/install-drawio.sh"
}

stage() {
	local name="$1" from="$2" to="$zbx/modules/.$1.new"
	rm -rf "$to"
	mkdir -p "$to"
	if [ "$name" = hostflow ]; then
		for f in manifest.json Module.php README.md DEPLOY.md; do [ -f "$from/$f" ] && cp "$from/$f" "$to/"; done
		for d in actions lib views assets; do cp -R "$from/$d" "$to/$d"; done
	else
		cp -R "$from/." "$to/"
	fi
	find "$to" \( -name .DS_Store -o -name '._*' -o -name .gitignore \) -delete
	chown -R root:root "$to"
	chmod -R u=rwX,go=rX "$to"
}

# Every PHP file must parse with this server's PHP before anything goes live.
lint() {
	command -v php >/dev/null 2>&1 || { say "  (php command not found; syntax check skipped)"; return; }
	local bad=0 f
	while IFS= read -r f; do
		php -l "$f" >/dev/null 2>&1 || { php -l "$f" >&2 || true; bad=1; }
	done < <(find "$zbx/modules/.hostflow.new" "$zbx/modules/.hostflow_widget.new" -name '*.php' 2>/dev/null)
	[ "$bad" = 0 ] || { rm -rf "$zbx/modules/.hostflow.new" "$zbx/modules/.hostflow_widget.new"; die "PHP syntax check failed; nothing was changed."; }
}

swap() {
	local m
	for m in hostflow hostflow_widget; do
		[ -d "$zbx/modules/.$m.new" ] || continue
		rm -rf "$zbx/modules/.$m.old"
		[ -d "$zbx/modules/$m" ] && mv "$zbx/modules/$m" "$zbx/modules/.$m.old"
		if ! mv "$zbx/modules/.$m.new" "$zbx/modules/$m"; then
			[ -d "$zbx/modules/.$m.old" ] && mv "$zbx/modules/.$m.old" "$zbx/modules/$m"
			die "Could not put $m in place; the previous version was restored."
		fi
	done
	rm -rf "$zbx/modules/.hostflow.old" "$zbx/modules/.hostflow_widget.old"
}

ensure_data() {
	mkdir -p "$data"
	chown "$web_user" "$data"
	chmod 0750 "$data"
	if [ "$data" != "/var/lib/zabbix-hostflow" ]; then
		say "  ! Set HOSTFLOW_DATA_DIR=$data for PHP (env[HOSTFLOW_DATA_DIR] in the PHP-FPM pool) and restart PHP."
	fi
}

api() {
	curl -fsS --max-time 20 -H 'Content-Type: application/json-rpc' -H "Authorization: Bearer $HOSTFLOW_API_TOKEN" \
		-d "{\"jsonrpc\":\"2.0\",\"method\":\"$1\",\"params\":$2,\"id\":1}" "$enable_url/api_jsonrpc.php"
}

# Register the modules if Zabbix has not seen them yet, and switch both on.
enable_modules() {
	[ -n "$enable_url" ] || return 0
	[ -n "${HOSTFLOW_API_TOKEN:-}" ] || die "--enable needs HOSTFLOW_API_TOKEN (an API token of a Super admin)."
	local m id path out
	for m in hostflow hostflow_widget; do
		[ -d "$zbx/modules/$m" ] || continue
		out="$(api module.get "{\"output\":[\"moduleid\",\"relative_path\",\"status\"],\"filter\":{\"relative_path\":[\"modules/$m\",\"$m\"]}}")" \
			|| die "The Zabbix API at $enable_url did not answer."
		echo "$out" | grep -q '"error"' && die "Zabbix API: $(echo "$out" | sed 's/.*"data":"\([^"]*\)".*/\1/')"
		id="$(echo "$out" | grep -o '"moduleid":"[0-9]*"' | head -1 | cut -d'"' -f4)"
		if [ -z "$id" ]; then
			out="$(api module.create "{\"id\":\"$m\",\"relative_path\":\"modules/$m\",\"status\":1}")"
			echo "$out" | grep -q '"error"' && die "Could not register $m: $out"
			step "Registered and enabled $m in Zabbix"
		else
			api module.update "{\"moduleid\":\"$id\",\"status\":1}" >/dev/null
			step "$m is enabled in Zabbix"
		fi
	done
}

do_install() {
	local cur
	cur="$(installed_version)"
	if [ -z "$file" ] && [ -z "$local_src" ]; then
		if [ -z "$version" ]; then
			need curl
			version="$(latest_version || true)"
			[ -n "$version" ] || die "Could not find the latest release on github.com/$REPO. Use --file for an offline install."
		fi
		if [ -n "$cur" ] && [ "$force" = 0 ]; then
			if [ "$cur" = "$version" ]; then say "✓ Host Flow $cur is installed and up to date."; return; fi
			if [ "$action" = upgrade ] && ! newer "$version" "$cur"; then say "✓ Host Flow $cur is installed; $version is not newer."; return; fi
		fi
	fi
	need tar
	step "Zabbix frontend: $zbx (PHP runs as $web_user)"
	[ -n "$cur" ] && step "Installed now: $cur"
	fetch
	ensure_drawio
	local newv
	newv="$(grep -o '"version": *"[^"]*"' "$src/manifest.json" | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"
	if [ -n "$cur" ] && [ "$cur" = "$newv" ] && [ "$force" = 0 ] && { [ -n "$file" ] || [ -n "$local_src" ]; }; then
		say "✓ Host Flow $cur is already installed (use --force to reinstall)."; return
	fi
	stage hostflow "$src"
	[ -n "$wsrc" ] && stage hostflow_widget "$wsrc"
	lint
	backup_now "$cur"
	swap
	ensure_data
	enable_modules
	say ""
	say "✓ Host Flow $newv is installed${cur:+ (was $cur)}."
	if [ -z "$cur" ] && [ -z "$enable_url" ]; then
		say "  Next: Zabbix → Administration → General → Modules → Scan directory, then enable Host Flow and the Host Flow widget."
	fi
	say "  Open Monitoring → Host Flow. Diagrams and settings in $data were kept."
	[ "$backup" = 1 ] && [ -n "$cur" ] && say "  Undo: $SELF --rollback"
	return 0
}

do_rollback() {
	local last
	last="$(ls -1t "$BACKUPS"/hostflow-*.tar.gz 2>/dev/null | head -1 || true)"
	[ -n "$last" ] || die "No backup found in $BACKUPS."
	step "Restoring the module from $last (diagrams and settings are not touched)"
	work="$(mktemp -d)"
	trap 'rm -rf "$work"' EXIT
	tar -xzf "$last" -C "$work"
	for m in hostflow hostflow_widget; do
		[ -d "$work/${zbx#/}/modules/$m" ] || continue
		rm -rf "$zbx/modules/.$m.new"
		mv "$work/${zbx#/}/modules/$m" "$zbx/modules/.$m.new"
	done
	swap
	say "✓ Host Flow $(installed_version) is back in place."
}

do_uninstall() {
	[ -d "$zbx/modules/hostflow" ] || { say "Host Flow is not installed."; return; }
	backup_now "$(installed_version)"
	rm -rf "$zbx/modules/hostflow" "$zbx/modules/hostflow_widget"
	say "✓ Module folders removed. Diagrams and settings are still in $data."
	say "  In Zabbix, Administration → General → Modules: Scan directory to drop the entries."
}

# ------------------------------------------------------------------ main

if [ "$action" != check ] && [ "$(id -u)" != 0 ]; then
	die "Run as root (sudo)."
fi
find_zabbix
case "$action" in
	check) do_check ;;
	rollback) do_rollback ;;
	uninstall) do_uninstall ;;
	*) find_web_user; do_install ;;
esac
