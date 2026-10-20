# Host Flow: deployment (Zabbix 7.0)

## Online install or upgrade (recommended)

Run on the Zabbix web server (the machine with `zabbix.php`):

```bash
# install, or upgrade to the latest release
curl -fsSL https://raw.githubusercontent.com/karthick-dkk/zabbix-host-manager/main/hostflow/install.sh | sudo bash -s -- --upgrade

# only look: installed version and latest release
curl -fsSL https://raw.githubusercontent.com/karthick-dkk/zabbix-host-manager/main/hostflow/install.sh | bash -s -- --check
```

What the installer does:

1. Finds the Zabbix frontend and the user PHP runs as.
2. Downloads the release (`hostflow-vX.Y.Z` tag) from GitHub. draw.io is reused when the installed copy is the pinned version, otherwise downloaded from the official draw.io release and checked against its SHA-256.
3. Builds the new module next to the old one and checks every PHP file with the server's PHP. If anything fails, the live module is untouched.
4. Backs up the current module and the data folder to `/var/backups/hostflow/` (the last 5 are kept).
5. Swaps the new folders in with a rename, so no page load ever sees a half-copied module.
6. Keeps diagrams and settings in `/var/lib/zabbix-hostflow`; settings from older versions are converted on first use.

| Command | Does |
|---|---|
| `install.sh --upgrade` | install the latest release if it is newer than the installed one |
| `install.sh` | install the latest release (also on a new server) |
| `install.sh --version 1.4.0` | install that release |
| `install.sh --check` | show installed and latest versions |
| `install.sh --rollback` | put the module back as it was before the last install |
| `install.sh --file hostflow-1.4.0.tar.gz` | offline install from a package (below) |
| `install.sh --uninstall` | remove the module folders (data is kept) |
| `--enable http://localhost/zabbix` | also register and enable the modules through the API; set `HOSTFLOW_API_TOKEN` to a Super admin API token |
| `--zabbix-dir`, `--data-dir`, `--web-user`, `--no-backup`, `--keep N`, `--force` | override what the installer finds, or reinstall |

Example with the API step:

```bash
curl -fsSL https://raw.githubusercontent.com/karthick-dkk/zabbix-host-manager/main/hostflow/install.sh -o install.sh
sudo HOSTFLOW_API_TOKEN=xxxxxxxx bash install.sh --upgrade --enable http://localhost:8080/zabbix
```

On a first install without `--enable`, finish in Zabbix: **Administration → General → Modules → Scan directory**, then enable **Host Flow** and the **Host Flow** widget.

## Offline package

Build it on a machine with internet (`hostflow/tools/package.sh` writes `dist/hostflow-X.Y.Z.tar.gz` and its `.sha256`), copy it to the server, then:

```bash
tar -xzf hostflow-1.4.0.tar.gz && cd hostflow-1.4.0
sudo ./install.sh --file ../hostflow-1.4.0.tar.gz
```

Or copy the folders by hand as below.

## Manual deployment

The package contains a ready-to-copy `modules/` folder:

```
modules/
├── hostflow/          the module (draw.io v31.4.6 already inside assets/drawio/)
└── hostflow_widget/   the dashboard widget (optional, needs hostflow next to it)
```

Keep both folder names exactly as they are: the widget loads code from `../hostflow/`.

## 1. Find the Zabbix frontend folder

It is the folder that contains `zabbix.php`:

| Install | Path |
|---|---|
| Debian / Ubuntu packages | `/usr/share/zabbix` (7.0: may be `/usr/share/zabbix/ui`) |
| RHEL / Rocky / Alma packages | `/usr/share/zabbix` |
| Official Docker image (`zabbix-web-*`) | `/usr/share/zabbix` inside the container |

```bash
ls /usr/share/zabbix/zabbix.php            # must exist
ZBX=/usr/share/zabbix                       # adjust if different
```

## 2. Copy the module

```bash
tar -xzf hostflow-1.4.0.tar.gz
sudo cp -R modules/hostflow modules/hostflow_widget "$ZBX/modules/"
sudo chmod -R a+rX "$ZBX/modules/hostflow" "$ZBX/modules/hostflow_widget"
```

## 3. Create the data folder

Diagrams, settings, cost caches and the audit log are stored here, outside the web root.
It must be writable by the PHP-FPM user (`www-data` on Debian/Ubuntu, `apache` on RHEL, `nginx` for nginx on RHEL):

```bash
sudo mkdir -p /var/lib/zabbix-hostflow
sudo chown www-data /var/lib/zabbix-hostflow     # your PHP-FPM user
sudo chmod 0750 /var/lib/zabbix-hostflow
```

To use another folder, set it for PHP-FPM and restart it:

```ini
; /etc/php/8.x/fpm/pool.d/zabbix.conf  (RHEL: /etc/php-fpm.d/zabbix.conf)
env[HOSTFLOW_DATA_DIR] = /data/hostflow
```

If SELinux is enforcing (RHEL family):

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-hostflow(/.*)?'
sudo restorecon -Rv /var/lib/zabbix-hostflow
```

## 4. Enable it in Zabbix

1. Log in as Super admin.
2. **Administration → General → Modules → Scan directory.**
3. Enable **Host Flow**, and **Host Flow** (widget) if you want the dashboard widget.
4. Open **Monitoring → Host Flow**.

## 5. First-time settings (Super admin)

**Monitoring → Host Flow → Settings**:

- **Client flows**: on by default. Opening Host Flow as Admin creates a flow for each client; switch it off or change the layout here.
- **Components**: map **VPN** and **UI** to your host groups (they have no default). Check that Forwarder, Parser, ES, Engine, AIML and SOAR match your groups.
- **Flow templates**: check the tiers and arrows of *SIEM pipeline* (default) and *S3 + ULM pipeline*. Map **ULM** (group `ULM` by default) and, for S3, add buckets under Cost.
- **Pick a template per client**: on the client's cluster host (the host with `{$GRP.CLIENT}`), add the macro `{$HOSTFLOW.TEMPLATE}` with the template id, e.g. `s3-ulm`. Clients without it use the default template. The client flow changes at the next check (Settings → Client flows, every 10 min by default).
- **Install "Open in Host Flow"** adds the menu entry to every host's context menu.
- **Cost** (optional): turn it on, add rules, and for AWS add an account (instance role, AssumeRole or access key) and press **Test connection**.

## 6. Who can do what

Users need **Host Flow** allowed in their user role (**Users → User roles → Access to UI elements / Modules**).

| Role | Can |
|---|---|
| User | Live view, wall, find, cost (when cost is on) |
| Admin, Super admin | Edit, generate, save, discard, delete, dependency suggestions, services; opening Host Flow creates missing client flows |
| Super admin | Settings, cost refresh, "Open in Host Flow" script |

Each user only sees the hosts their Zabbix permissions allow.

## Check that it works

- **Monitoring → Host Flow** as Admin: a flow appears on the left for each client, and the first one opens on the right with live IPs and problems.
- **Edit** opens the draw.io editor inside Zabbix with the Host Flow panel on the right; changes save by themselves (Auto-save).

## Troubleshooting

| Symptom | Fix |
|---|---|
| Host Flow not listed after Scan directory | The folder must be `$ZBX/modules/hostflow/` with `manifest.json` directly inside it. |
| "The data directory … is not writable by the web server user." | Step 3: owner must be the PHP-FPM user; check SELinux. |
| Editor stays blank / 403 for `assets/drawio/...` | Your nginx/Apache blocks some paths. Look in the browser's network tab for the blocked URL and allow `/modules/hostflow/assets/` in the web server config. |
| Widget diagram list says "No diagrams yet" although diagrams exist | The widget must sit next to the module: `$ZBX/modules/hostflow_widget` and `$ZBX/modules/hostflow`. Enable the **Host Flow** module too, not only the widget. |

## Upgrade

Copy the new `modules/hostflow` and `modules/hostflow_widget` over the old ones (replace the folders).
The data folder is kept. Then **Scan directory** again if the version changed.
From 1.0.0: diagrams keep working; the old numbered version files are replaced by one `diagram.drawio` the next time each diagram is saved.
From 1.0/1.1: your saved pipeline becomes the *SIEM pipeline* template (still the default), *S3 + ULM pipeline* and the ULM component are added, and client flows switch to the pipeline + hierarchy layout.

## Remove

Disable both modules in **Administration → General → Modules**, delete `$ZBX/modules/hostflow` and `$ZBX/modules/hostflow_widget`.
Delete `/var/lib/zabbix-hostflow` only if you no longer need the diagrams (back it up first).
