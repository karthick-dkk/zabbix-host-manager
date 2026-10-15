#!/bin/sh
# Generates the nginx config at container start from environment variables.
#   ZABBIX_URL         Zabbix frontend URL, e.g. http://192.168.64.13:8080/zabbix  (empty = no proxy)
#   ENABLE_TLS         true|false (default true). Self-signed cert is created if none is mounted.
#   PUBLIC_HTTPS_PORT  port users reach HTTPS on (used for the HTTP→HTTPS redirect, default 8443)
#   ALLOW_CIDRS        space-separated networks allowed to use the page, e.g. "10.0.0.0/8 192.168.0.0/16"
#   TLS_CN             common name for the generated certificate
set -eu

CONF=/etc/nginx/conf.d/default.conf
CERT_DIR=/etc/nginx/certs
ZABBIX_URL="${ZABBIX_URL:-}"
ZABBIX_URL="${ZABBIX_URL%/}"
ZABBIX_URL="${ZABBIX_URL%/api_jsonrpc.php}"
ENABLE_TLS="${ENABLE_TLS:-true}"
PUBLIC_HTTPS_PORT="${PUBLIC_HTTPS_PORT:-8443}"
ALLOW_CIDRS="${ALLOW_CIDRS:-}"

case "$ZABBIX_URL" in
  ""|http://*|https://*) ;;
  *) echo "40-zhm-config: ZABBIX_URL must start with http:// or https:// (got '$ZABBIX_URL')" >&2; exit 1 ;;
esac

if [ "$ENABLE_TLS" = "true" ] && [ ! -s "$CERT_DIR/tls.crt" ]; then
  echo "40-zhm-config: generating self-signed certificate (CN=${TLS_CN:-zabbix-host-manager})"
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -subj "/CN=${TLS_CN:-zabbix-host-manager}" \
    -addext "subjectAltName=DNS:${TLS_CN:-zabbix-host-manager},DNS:localhost,IP:127.0.0.1" \
    -keyout "$CERT_DIR/tls.key" -out "$CERT_DIR/tls.crt" >/dev/null 2>&1
fi

access=""
for c in $ALLOW_CIDRS; do access="${access}        allow ${c};
"; done
[ -n "$ALLOW_CIDRS" ] && access="${access}        allow 127.0.0.1;
        deny all;
"

if [ -n "$ZABBIX_URL" ]; then
  config_json="{\"zabbixProxy\":true,\"target\":\"${ZABBIX_URL}\"}"
  proxy="
    location = /api_jsonrpc.php {
${access}        limit_except POST OPTIONS { deny all; }
        proxy_pass ${ZABBIX_URL}/api_jsonrpc.php;
        proxy_http_version 1.1;
        proxy_set_header Host \$proxy_host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_ssl_server_name on;
        proxy_connect_timeout 10s;
        proxy_read_timeout 120s;
        client_max_body_size 16m;
    }"
else
  config_json='{"zabbixProxy":false}'
  proxy=""
fi

# nginx drops server-level add_header in any location that sets its own, so every location includes this file.
HDR=/etc/nginx/conf.d/zhm-headers.inc
cat > "$HDR" <<'EOF'
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options DENY always;
add_header Referrer-Policy no-referrer always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' http: https:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" always;
EOF
[ "$ENABLE_TLS" = "true" ] && echo 'add_header Strict-Transport-Security "max-age=31536000" always;' >> "$HDR"

app="
    server_tokens off;
    include ${HDR};
    root /usr/share/nginx/html;
    index index.html;

    location = /healthz { access_log off; default_type text/plain; return 200 'ok'; }
    location = /config.json { default_type application/json; add_header Cache-Control no-store; include ${HDR}; return 200 '${config_json}'; }
    location / {
${access}        try_files \$uri \$uri/ =404;
        include ${HDR};
        add_header Cache-Control \"no-cache\";
    }${proxy}
"

if [ "$ENABLE_TLS" = "true" ]; then
  cat > "$CONF" <<EOF
server {
    listen 8080;
    location = /healthz { access_log off; default_type text/plain; return 200 'ok'; }
    location / { return 301 https://\$host:${PUBLIC_HTTPS_PORT}\$request_uri; }
}
server {
    listen 8443 ssl;
    http2 on;
    ssl_certificate     ${CERT_DIR}/tls.crt;
    ssl_certificate_key ${CERT_DIR}/tls.key;
    ssl_protocols TLSv1.2 TLSv1.3;
${app}
}
EOF
else
  cat > "$CONF" <<EOF
server {
    listen 8080;
${app}
}
EOF
fi

echo "40-zhm-config: proxy=${ZABBIX_URL:-off} tls=${ENABLE_TLS} allow=${ALLOW_CIDRS:-any}"
