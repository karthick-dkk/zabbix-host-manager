#!/usr/bin/env python3
"""Seed the Host Flow lab: import the SISA template, create client/role groups and hosts,
enable the module, and raise a few problems through trapper items.

  python3 hostflow/lab/seed.py            # seed (idempotent)
  python3 hostflow/lab/seed.py problems   # raise problems again
  python3 hostflow/lab/seed.py clear      # clear all lab problems
"""
import json, os, socket, struct, sys, time, urllib.request

URL = os.environ.get('ZBX_URL', 'http://localhost:18080/api_jsonrpc.php')
TEMPLATE_FILE = os.path.join(os.path.dirname(__file__), '..', '..', 'zbx_export_templates_ES_Elastic_http_linux.json')
TOKEN = None

def api(method, params=None):
    body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params or {}, 'id': 1}).encode()
    req = urllib.request.Request(URL, body, {'Content-Type': 'application/json-rpc'})
    if TOKEN and method not in ('user.login', 'apiinfo.version'):
        req.add_header('Authorization', 'Bearer ' + TOKEN)
    r = json.load(urllib.request.urlopen(req, timeout=120))
    if 'error' in r:
        raise RuntimeError(f"{method}: {r['error']['message']} {r['error'].get('data', '')}")
    return r['result']

def gid(name):
    g = api('hostgroup.get', {'filter': {'name': [name]}, 'output': ['groupid']})
    return g[0]['groupid'] if g else api('hostgroup.create', {'name': name})['groupids'][0]

def tid(name):
    t = api('template.get', {'filter': {'host': [name]}, 'output': ['templateid']})
    return t[0]['templateid'] if t else None

PLAN = {
    'ACME':   {'fwd': 3, 'parser': 4, 'vpn': 2, 'es': 6, 'eng': 3, 'aiml': 1, 'soar': 1, 'ui': 1},
    'GLOBEX': {'fwd': 2, 'parser': 2, 'vpn': 1, 'es': 3, 'eng': 2, 'aiml': 0, 'soar': 1, 'ui': 1},
}
ROLE_GROUP = {'fwd': 'Forwarders', 'parser': 'Parsers', 'vpn': 'VPN-Gateways', 'es': 'ESNodes', 'eng': 'Engines', 'aiml': 'AIML', 'soar': 'SOAR', 'ui': 'SIEM-UI'}
# problems to raise: host -> (severity 0-5, name)
PROBLEMS = {'acme-es-02': (5, 'ES node down'), 'acme-parser-02': (3, 'Parser queue high'), 'acme-fwd-03': (2, 'Forwarder lag'),
            'acme-eng-03': (4, 'Correlation engine stalled'), 'globex-es-01': (4, 'Disk space critically low')}

def send(items):
    """Zabbix sender protocol to the server trapper port."""
    payload = json.dumps({'request': 'sender data', 'data': [{'host': h, 'key': 'hf.lab.problem', 'value': str(v)} for h, v in items]}).encode()
    s = socket.create_connection(('localhost', 10051), timeout=10)
    s.sendall(b'ZBXD\x01' + struct.pack('<Q', len(payload)) + payload)
    resp = s.recv(1024)
    s.close()
    return resp[13:].decode(errors='replace')

def main():
    global TOKEN
    for _ in range(60):
        try:
            api('apiinfo.version'); break
        except Exception:
            time.sleep(3)
    TOKEN = api('user.login', {'username': 'Admin', 'password': 'zabbix'})
    mode = sys.argv[1] if len(sys.argv) > 1 else 'seed'
    if mode == 'clear':
        print(send([(h, 0) for h in PROBLEMS])); return
    if mode == 'problems':
        print(send([(h, 1) for h in PROBLEMS])); return

    if not tid('Elasticsearch Cluster by HTTP SISA'):
        src = open(TEMPLATE_FILE).read()
        rules = {k: {'createMissing': True, 'updateExisting': True} for k in
                 ['template_groups', 'templates', 'items', 'triggers', 'discoveryRules', 'graphs', 'valueMaps', 'templateDashboards']}
        rules['templateLinkage'] = {'createMissing': True}
        api('configuration.import', {'format': 'json', 'source': src, 'rules': rules})
        print('imported SISA templates')
    cluster_t, linux_t = tid('Elasticsearch Cluster by HTTP SISA'), tid('Linux by Zabbix agent -SISA')

    n = 10
    for client, roles in PLAN.items():
        cg = gid(client)
        for role, count in roles.items():
            rg = gid(ROLE_GROUP[role])
            for i in range(1, count + 1):
                n += 1
                name = f'{client.lower()}-{role}-{i:02d}'
                ip = f'10.20.{0 if client == "ACME" else 1}.{n}'
                if api('host.get', {'filter': {'host': [name]}, 'output': ['hostid']}):
                    continue
                h = {'host': name, 'groups': [{'groupid': cg}, {'groupid': rg}],
                     'interfaces': [{'type': 1, 'main': 1, 'useip': 1, 'ip': ip, 'dns': '', 'port': '10050'}],
                     'macros': [{'macro': '{$CLIENT}', 'value': client}, {'macro': '{$ROLE}', 'value': role}],
                     'tags': [{'tag': 'evp-client', 'value': client}]}
                if role in ('es', 'eng') and linux_t:
                    h['templates'] = [{'templateid': linux_t}]
                api('host.create', h)
        # cluster host
        cname = f'{client.lower()}-es-cluster'
        if not api('host.get', {'filter': {'host': [cname]}, 'output': ['hostid']}):
            api('host.create', {'host': cname, 'groups': [{'groupid': cg}],
                                'interfaces': [{'type': 1, 'main': 1, 'useip': 1, 'ip': f'10.20.9.{1 if client == "ACME" else 2}', 'dns': '', 'port': '10050'}],
                                'templates': [{'templateid': cluster_t}] if cluster_t else [],
                                'macros': [{'macro': '{$GRP.CLIENT}', 'value': client}]})
    print('hosts ready:', len(api('host.get', {'output': ['hostid'], 'search': {'host': '-'}})))

    # trapper item + trigger per problem host
    for host, (sev, title) in PROBLEMS.items():
        hh = api('host.get', {'filter': {'host': [host]}, 'output': ['hostid']})[0]
        if not api('item.get', {'hostids': [hh['hostid']], 'filter': {'key_': 'hf.lab.problem'}, 'output': ['itemid']}):
            api('item.create', {'hostid': hh['hostid'], 'name': 'Lab problem switch', 'key_': 'hf.lab.problem', 'type': 2, 'value_type': 3})
            api('trigger.create', {'description': title + ' on {HOST.NAME}', 'expression': f'last(/{host}/hf.lab.problem)>0', 'priority': sev})

    mods = api('module.get', {'filter': {'id': 'hostflow'}, 'output': ['moduleid', 'status']})
    if not mods:
        api('module.create', {'id': 'hostflow', 'relative_path': 'modules/hostflow', 'status': 1})
        print('module enabled')
    elif mods[0]['status'] != '1':
        api('module.update', {'moduleid': mods[0]['moduleid'], 'status': 1})
        print('module enabled')
    time.sleep(70)  # let the server load the new items into its configuration cache
    print('problems:', send([(h, 1) for h in PROBLEMS]))

if __name__ == '__main__':
    main()
