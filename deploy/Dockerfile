# Zabbix Client Host Manager — static page + optional same-origin proxy to the Zabbix API.
# Runs as the unprivileged nginx user (uid 101); listens on 8080 (HTTP) and 8443 (HTTPS).
FROM nginxinc/nginx-unprivileged:1.27-alpine

USER root
RUN apk add --no-cache openssl \
 && rm -f /etc/nginx/conf.d/default.conf \
 && mkdir -p /etc/nginx/certs \
 && chown -R 101:101 /etc/nginx/certs /etc/nginx/conf.d

COPY --chown=101:101 host-manager.html  /usr/share/nginx/html/index.html
COPY --chown=101:101 macro-manager.html /usr/share/nginx/html/macro-manager.html
COPY --chmod=755 deploy/40-zhm-config.sh /docker-entrypoint.d/40-zhm-config.sh

USER 101
EXPOSE 8080 8443
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1
