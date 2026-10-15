# Zabbix Client Host Manager: Docker deploy

## One-click deploy

```bash
./deploy/deploy.sh
```

On the first run the script asks for five settings and saves them to `deploy/.env`. It then builds the image, starts the container, waits until it is healthy and checks that it can reach Zabbix. That check calls `apiinfo.version`, which needs no token and changes nothing.

To run it without prompts:

```bash
ZABBIX_URL=http://192.168.64.13:8080/zabbix ./deploy/deploy.sh
```

| Command | What it does |
|---|---|
| `./deploy/deploy.sh` | Build and start the container. Run it again after updating `host-manager.html`. |
| `./deploy/deploy.sh reconfig` | Ask for the settings again, then redeploy |
| `./deploy/deploy.sh logs` / `status` | Follow the logs, or show container status |
| `./deploy/deploy.sh stop` | Stop and remove the container. The certificate volume is kept. |
| `./deploy/deploy.sh remove` | Remove the container, the image and the certificate volume |

## Settings (`deploy/.env`)

| Variable | Default | Meaning |
|---|---|---|
| `ZABBIX_URL` | – | Zabbix frontend URL. When set, the container forwards `/api_jsonrpc.php` to it, so the browser only talks to this server and needs no cross-origin access. Leave it empty to call Zabbix directly from the browser. |
| `HTTP_PORT` | `8090` | HTTP port. Redirects to HTTPS when TLS is on. |
| `HTTPS_PORT` | `8443` | HTTPS port |
| `ENABLE_TLS` | `true` | A self-signed certificate is generated on first start and kept in the `certs` volume |
| `ZABBIX_TLS_VERIFY` | `true` | When `ZABBIX_URL` is `https://`, check the Zabbix certificate. Set it to `false` only for a self-signed Zabbix on a trusted network; a better option is to mount your CA and set `ZABBIX_CA_FILE`. |
| `ZABBIX_CA_FILE` | system CA bundle | CA bundle used to verify Zabbix, e.g. mount `./zabbix-ca.pem:/etc/nginx/zabbix-ca.pem:ro` and set this to `/etc/nginx/zabbix-ca.pem` |
| `ALLOW_CIDRS` | everyone | Networks allowed to use the page and the proxy, space separated, e.g. `"10.0.0.0/8 192.168.50.0/24"` |

## Using your own certificate

Replace the `certs` volume in `docker-compose.yml` with a folder that contains `tls.crt` and `tls.key`:

```yaml
    volumes:
      - ./certs:/etc/nginx/certs:ro
```

## Security notes

- The container runs as an unprivileged user with all Linux capabilities dropped. It sends standard security headers (CSP, X-Frame-Options, HSTS). The proxy accepts only `POST` requests.
- The page has no login of its own. Every action needs a Zabbix API token and runs with that token's Zabbix permissions. Limit who can reach the page with `ALLOW_CIDRS`.
- If you set `ZABBIX_URL` to plain `http://`, traffic between the container and Zabbix is unencrypted. Run the container close to Zabbix, or put HTTPS on the Zabbix frontend.
- "Remember permanently" keeps the API token in that browser's local storage. Use it only on your own device, with a token that has an expiry date.
