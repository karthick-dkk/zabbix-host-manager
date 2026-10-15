#!/usr/bin/env bash
# One-click deploy for the Zabbix Client Host Manager.
#   ./deploy.sh            build + start (asks for settings on first run, saved in deploy/.env)
#   ./deploy.sh reconfig   ask for the settings again, then redeploy
#   ./deploy.sh logs | status | stop | remove
# Non-interactive: ZABBIX_URL=http://zbx:8080/zabbix ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

die() { echo "✗ $*" >&2; exit 1; }
command -v docker >/dev/null || die "Docker is not installed."
docker info >/dev/null 2>&1 || die "Docker is not running. Start Docker and try again."
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is required (docker compose)."

case "${1:-up}" in
  logs)   exec docker compose logs -f ;;
  status) exec docker compose ps ;;
  stop)   exec docker compose down ;;
  remove) docker compose down -v --rmi local; exit 0 ;;
  reconfig) rm -f .env ;;
  up) ;;
  *) die "unknown command '$1' (use: up, reconfig, logs, status, stop, remove)" ;;
esac

ask() { # ask VAR "question" default
  local cur="${!1:-}"; [ -n "$cur" ] && return
  if [ -t 0 ]; then read -rp "$2 [${3}]: " cur; fi
  printf -v "$1" '%s' "${cur:-$3}"
}

if [ ! -f .env ]; then
  echo "First run: a few settings (saved to deploy/.env, change any time with ./deploy.sh reconfig)"
  ask ZABBIX_URL  "Zabbix frontend URL (empty = no proxy)" "http://192.168.64.13:8080/zabbix"
  ask HTTP_PORT   "HTTP port on this machine"  "8090"
  ask HTTPS_PORT  "HTTPS port on this machine" "8443"
  ask ENABLE_TLS  "Enable HTTPS (true/false)"  "true"
  ask ALLOW_CIDRS "Allowed client networks, space separated (empty = everyone)" ""
  cat > .env <<EOF
ZABBIX_URL=${ZABBIX_URL}
HTTP_PORT=${HTTP_PORT}
HTTPS_PORT=${HTTPS_PORT}
ENABLE_TLS=${ENABLE_TLS}
ALLOW_CIDRS="${ALLOW_CIDRS}"
TLS_CN=$(hostname 2>/dev/null || echo zabbix-host-manager)
EOF
fi
set -a; . ./.env; set +a

echo "→ Building and starting…"
docker compose up -d --build

echo -n "→ Waiting for the container to become healthy"
for _ in $(seq 1 30); do
  st=$(docker inspect -f '{{.State.Health.Status}}' zabbix-host-manager 2>/dev/null || echo starting)
  [ "$st" = healthy ] && break
  [ "$st" = unhealthy ] && { echo; docker compose logs --tail 30; die "container is unhealthy"; }
  echo -n "."; sleep 1
done
echo

if [ "${ENABLE_TLS}" = "true" ]; then base="https://localhost:${HTTPS_PORT}"; else base="http://localhost:${HTTP_PORT}"; fi
if [ -n "${ZABBIX_URL}" ]; then
  # apiinfo.version needs no token and changes nothing — just proves the proxy reaches Zabbix.
  v=$(curl -sk --max-time 10 -H 'Content-Type: application/json' \
      -d '{"jsonrpc":"2.0","method":"apiinfo.version","params":{},"id":1}' "${base}/api_jsonrpc.php" | sed -n 's/.*"result":"\([^"]*\)".*/\1/p')
  if [ -n "$v" ]; then echo "✓ Proxy reaches Zabbix ${v} at ${ZABBIX_URL}"
  else echo "⚠ Could not reach Zabbix through the proxy. Check ZABBIX_URL (${ZABBIX_URL}) is reachable from this machine."; fi
fi

ip=$( (ipconfig getifaddr en0 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}') | head -1 )
echo
echo "✓ Zabbix Client Host Manager is running"
echo "  Open:  ${base}/${ip:+   (from other machines: ${base/localhost/$ip}/)}"
[ "${ENABLE_TLS}" = "true" ] && echo "  The certificate is self-signed: accept the browser warning once, or mount your own tls.crt/tls.key (see README)."
echo "  Macro editor for template exports: ${base}/macro-manager.html"
