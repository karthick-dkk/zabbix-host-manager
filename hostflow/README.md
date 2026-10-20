# Host Flow — editable draw.io diagrams inside Zabbix

A Zabbix 7.0 frontend module that adds **Monitoring → Host Flow**:

- **A flow for every client, automatically**: each client group gets a flow generated from Zabbix: its pipeline on top, the client hierarchy (client → host groups → hosts) below. It follows new and removed hosts, and template changes, until someone edits it.
- **Flow templates**: named pipelines in Settings, each with its tiers, a note per tier and its arrows. Built in: *SIEM pipeline* (default) and *S3 + ULM pipeline* (FWD → Parser → ES → UI, Parser → S3 → ULM → UI, Redis/Kafka on FWD and Parser). A client uses the template named by `{$HOSTFLOW.TEMPLATE}` on its cluster host, e.g. `{$HOSTFLOW.TEMPLATE}=s3-ulm`.
- **One screen**: the flows and the selected flow's details (hosts up, tiers, problems, cost) on the left, the flow itself live on the right, filling the window.
- **Per client**: Pipeline, Client hierarchy and Capacity tabs. Capacity shows CPU (vCPU, use), memory and disk (size, use) per host and tier, from the standard agent items; 80 % and above in red.
- **Download** a view with IPs and cost, or plain: PNG, SVG, the draw.io file, or a CSV host list. **Show / hide** any tier or host on a flow (per browser; drawing only).
- **Drawing**: role icons on every host, straight arrows, two-way pairs as one double-headed line, pass-through tiers such as VPN as a small icon on the line ("logs via VPN"), static tiers such as UI, and queues (Redis, Kafka or both) on tiers.
- **Edit** architecture diagrams with the full draw.io editor, bundled inside the module (no extra hosting, no internet). One Save button, Discard, and Auto-save (on by default, per browser).
- **Generate** a diagram from Zabbix: client group → host groups → hosts, your data-flow pipeline, or the monitoring path (server → proxies → hosts). **Update from Zabbix** adds new hosts and marks removed ones without touching what you drew.
- **Live view**: every linked shape shows the host's current IP, agent availability and problems, coloured with your Zabbix severity colours. Problem badges, pulsing unacknowledged High/Disaster, downstream tiers marked *impacted*, values on arrows (EPS/traffic) with a drop alert.
- **Time travel**, **find a host or IP**, **changes since your last visit**, **notes and runbooks** on shapes, **NOC wall**, **PNG/SVG export**, **dashboard widget**.
- **Zabbix integration**: suggest trigger dependencies from the flow, create a Services tree and SLA from a diagram, import an existing Zabbix map, "Open in Host Flow" in every host menu.
- **Cost (optional)**: monthly USD per host from Settings rules and the AWS Pricing API, tier and client totals, 12-month trend, CSV export. With AWS connected: hosts matched to EC2 by IP (ambiguous IPs listed, never guessed), billed cost from the Cost and Usage Report or Cost Explorer.

Everything reads Zabbix **as the logged-in user** through the API classes, so Zabbix permissions decide what each person sees. A live refresh is one request (3–4 API calls) whatever the size of the diagram.

## Install

```bash
# on the Zabbix web server: install, or upgrade to the latest release
curl -fsSL https://raw.githubusercontent.com/karthick-dkk/zabbix-host-manager/main/hostflow/install.sh | sudo bash -s -- --upgrade
```

Backups, rollback, offline packages and the API step are in [DEPLOY.md](DEPLOY.md).

Then **Administration → General → Modules → Scan directory**, enable **Host Flow** (and the **Host Flow** widget).
Offline servers: build a package with `tools/package.sh` and run `sudo ./install.sh --file hostflow-X.Y.Z.tar.gz`.

| Who | Can |
|---|---|
| Users (with module access in their role) | Live view, wall, find, cost (if visible to everyone) |
| Admin, Super admin | Edit, generate, save, discard, delete, dependency suggestions, services; opening Host Flow creates missing client flows |
| Super admin | Settings (components, flow, cost, AWS), cost refresh, "Open in Host Flow" script |

## Settings

- **Client groups**: a client is a host group, found from `{$GRP.CLIENT}` on hosts with the *Elasticsearch Cluster by HTTP SISA* template, plus any groups you list.
- **Components**: each tier matches host groups, a host tag or a template (first match wins). VPN and UI have no default: map them to your groups.
- **Flow templates**: the default *SIEM pipeline* is FWD → Parser → VPN → ES → UI, ES → Engine → UI, ES ⇄ AIML, SOAR → ES; *S3 + ULM pipeline* is FWD (Redis/Kafka) → Parser (Redis/Kafka) → ES → UI, Parser → S3 (raw log / enriched copy) → ULM → UI. Add, copy or change templates; set the macro on a client's cluster host to pick one. Edited diagrams keep the template they were drawn with.
- **Cost / AWS**: rules apply component → group → host; accounts use the instance role, AssumeRole (role ARN + external id) or an access key (stored encrypted, AES-256-GCM, key in the data directory).

## Data

`/var/lib/zabbix-hostflow/` (or `HOSTFLOW_DATA_DIR`): `diagrams/<id>/` (`diagram.drawio` + `meta.json`; one file per diagram, no version history), `settings.json`, `cost/` caches and daily snapshots, `users/` (last-visit snapshots), `audit/hostflow-audit.jsonl` (who changed what). Back it up with your normal file backups. It is outside the web root.

## Development

```bash
docker compose -f lab/docker-compose.yml up -d     # Zabbix 7.0 on http://localhost:18080 (Admin / zabbix)
python3 lab/seed.py                                # imports the SISA templates, creates ACME/GLOBEX hosts, enables the module, raises problems
docker run --rm -v "$PWD":/m -w /m php:8.3-cli sh -c 'php tests/lib.test.php && php tests/cost.test.php'
```
