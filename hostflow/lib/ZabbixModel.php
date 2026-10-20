<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use API;

/**
 * Reads hosts, groups, templates and proxies through the Zabbix API classes, as the
 * logged-in user, so Zabbix permissions decide what each person can see.
 */
class ZabbixModel {

	private const HOST_OUTPUT = ['hostid', 'host', 'name', 'status', 'maintenance_status', 'monitored_by', 'proxyid', 'proxy_groupid'];
	private const IF_OUTPUT = ['type', 'ip', 'dns', 'useip', 'port', 'main', 'available', 'error'];

	/**
	 * Client groups: every value of the client macro ({$GRP.CLIENT}) found on a host, whatever
	 * template the host uses (when auto-detect is on), plus groups listed in Settings; only names
	 * that are existing host groups count.
	 * The client's cluster/master node is a host carrying the macro and linked to one of the cluster
	 * templates (Settings). The flow template comes from the template macro on such a host, else on
	 * any host carrying the client macro.
	 * Returns [name => ['groupid' => ..., 'name' => ..., 'cluster_hostids' => [...], 'template' => flow template id or '']].
	 */
	public static function clients(?array $cfg = null): array {
		$cfg = $cfg ?? Config::get();
		$names = array_fill_keys($cfg['client']['groups'], []);
		$templates = [];

		if ($cfg['client']['autodetect']) {
			$clusterTpl = array_map('strtolower', $cfg['client']['cluster_templates']);
			$hosts = self::macroHosts($cfg['client']['grp_macro']);
			// Cluster hosts first, so their template macro wins over other hosts of the client.
			usort($hosts, static fn($a, $b) => self::isCluster($b, $clusterTpl) <=> self::isCluster($a, $clusterTpl));
			foreach ($hosts as $h) {
				$v = trim((string) self::macro($h, $cfg['client']['grp_macro']));
				if ($v === '') {
					continue;
				}
				$names[$v] = $names[$v] ?? [];
				if (self::isCluster($h, $clusterTpl)) {
					$names[$v][] = $h['hostid'];
				}
				$t = strtolower(trim((string) self::macro($h, $cfg['template_macro'])));
				if ($t !== '' && !isset($templates[$v])) {
					$templates[$v] = $t;
				}
			}
		}
		if (!$names) {
			return [];
		}

		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'filter' => ['name' => array_map('strval', array_keys($names))]
		]);
		$out = [];
		foreach ($groups as $g) {
			$out[$g['name']] = ['groupid' => $g['groupid'], 'name' => $g['name'], 'cluster_hostids' => $names[$g['name']] ?? [],
				'template' => $templates[$g['name']] ?? ''];
		}
		ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

		return $out;
	}

	/** Hosts (not templates) that carry $macro themselves, with their macros and linked templates. */
	public static function macroHosts(string $macro): array {
		$ids = array_values(array_unique(array_column(API::UserMacro()->get([
			'output' => ['hostid'],
			'filter' => ['macro' => $macro]
		]), 'hostid')));
		if (!$ids) {
			return [];
		}

		return API::Host()->get([
			'output' => self::HOST_OUTPUT,
			'hostids' => $ids,
			'selectMacros' => ['macro', 'value'],
			'selectParentTemplates' => ['host', 'name'],
			'selectInterfaces' => self::IF_OUTPUT
		]);
	}

	private static function isCluster(array $host, array $clusterTemplatesLower): bool {
		foreach ($host['parentTemplates'] ?? [] as $t) {
			if (in_array(strtolower($t['host']), $clusterTemplatesLower, true) || in_array(strtolower($t['name']), $clusterTemplatesLower, true)) {
				return true;
			}
		}

		return false;
	}

	/** Hosts carrying the client macro and linked to a cluster template. */
	public static function clusterHosts(?array $cfg = null): array {
		$cfg = $cfg ?? Config::get();
		$clusterTpl = array_map('strtolower', $cfg['client']['cluster_templates']);

		return array_values(array_filter(self::macroHosts($cfg['client']['grp_macro']), static fn($h) => self::isCluster($h, $clusterTpl)));
	}

	/**
	 * Everything a client's diagram needs: its hosts (with component), its cluster host(s)
	 * and the proxies that monitor them.
	 */
	public static function clientModel(string $client, ?array $cfg = null): array {
		$cfg = $cfg ?? Config::get();
		$clients = self::clients($cfg);
		$info = $clients[$client] ?? null;

		$hosts = $info === null ? [] : self::hostsWith(['groupids' => [$info['groupid']]]);
		$clusterIds = $info['cluster_hostids'] ?? [];
		$cluster = [];
		$members = [];
		foreach ($hosts as $h) {
			if (in_array($h['hostid'], $clusterIds, true)) {
				$cluster[] = $h;
			}
			else {
				$h['component'] = self::componentFor($h, $cfg['components']);
				$members[] = $h;
			}
		}
		// A cluster host that isn't in the client group still belongs to the client.
		$missing = array_diff($clusterIds, array_column($cluster, 'hostid'));
		if ($missing) {
			foreach (self::hostsWith(['hostids' => array_values($missing)]) as $h) {
				$cluster[] = $h;
			}
		}

		return [
			'client' => $info,
			'flow' => Config::template($info['template'] ?? null, $cfg),
			'cluster' => $cluster,
			'hosts' => $members,
			'proxies' => self::proxies(array_unique(array_filter(array_column(array_merge($members, $cluster), 'proxyid'))))
		];
	}

	public static function hostsWith(array $filter): array {
		$hosts = API::Host()->get($filter + [
			'output' => self::HOST_OUTPUT,
			'selectHostGroups' => ['groupid', 'name'],
			'selectTags' => ['tag', 'value'],
			'selectParentTemplates' => ['templateid', 'host', 'name'],
			'selectInterfaces' => self::IF_OUTPUT,
			'sortfield' => 'name'
		]);
		foreach ($hosts as &$h) {
			$h['ip'] = self::mainAddress($h['interfaces'] ?? []);
		}
		unset($h);

		return $hosts;
	}

	/** First component (in Settings order) whose groups, tags or templates match the host. */
	public static function componentFor(array $host, array $components): ?string {
		$groups = array_column($host['hostgroups'] ?? [], 'name');
		$templates = array_merge(array_column($host['parentTemplates'] ?? [], 'host'), array_column($host['parentTemplates'] ?? [], 'name'));
		foreach ($components as $c) {
			if ($c['kind'] !== 'hosts') {
				continue;
			}
			$m = $c['match'];
			if (array_intersect($m['groups'], $groups) || array_intersect($m['templates'], $templates)) {
				return $c['id'];
			}
			foreach ($m['tags'] as $want) {
				foreach ($host['tags'] ?? [] as $t) {
					if ($t['tag'] === $want['tag'] && ($want['value'] === '' || $t['value'] === $want['value'])) {
						return $c['id'];
					}
				}
			}
		}

		return null;
	}

	/** Host search for the editor's "Link to host" panel and for Find (name, visible name or IP). */
	public static function search(string $q, int $limit = 50): array {
		$q = trim($q);
		if ($q === '') {
			return [];
		}
		$byName = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'],
			'search' => ['host' => $q, 'name' => $q],
			'searchByAny' => true,
			'selectInterfaces' => self::IF_OUTPUT,
			'selectHostGroups' => ['name'],
			'limit' => $limit,
			'sortfield' => 'name'
		]);
		$hostids = array_column($byName, 'hostid');
		if (preg_match('/^[0-9a-f:.]+$/i', $q)) {
			$ifs = API::HostInterface()->get(['output' => ['hostid'], 'search' => ['ip' => $q], 'limit' => $limit]);
			$extra = array_diff(array_unique(array_column($ifs, 'hostid')), $hostids);
			if ($extra) {
				$byName = array_merge($byName, API::Host()->get([
					'output' => ['hostid', 'host', 'name', 'status'],
					'hostids' => array_values($extra),
					'selectInterfaces' => self::IF_OUTPUT,
					'selectHostGroups' => ['name']
				]));
			}
		}
		$cfg = Config::get();
		$out = [];
		foreach (array_slice($byName, 0, $limit) as $h) {
			$out[] = [
				'hostid' => $h['hostid'],
				'name' => $h['name'],
				'host' => $h['host'],
				'ip' => self::mainAddress($h['interfaces'] ?? []),
				'groups' => array_column($h['hostgroups'] ?? [], 'name'),
				'disabled' => (int) $h['status'] === HOST_STATUS_NOT_MONITORED,
				'component' => self::componentFor($h + ['tags' => [], 'parentTemplates' => []], $cfg['components'])
			];
		}

		return $out;
	}

	public static function proxies(array $ids = []): array {
		$opt = ['output' => ['proxyid', 'name', 'address', 'lastaccess', 'operating_mode', 'state']];
		if ($ids) {
			$opt['proxyids'] = array_values($ids);
		}

		return API::Proxy()->get($opt);
	}

	public static function mainAddress(array $interfaces): string {
		$main = null;
		foreach ($interfaces as $i) {
			if ((int) $i['main'] === 1 && (int) $i['type'] === INTERFACE_TYPE_AGENT) {
				$main = $i;
				break;
			}
			if ((int) $i['main'] === 1 && $main === null) {
				$main = $i;
			}
		}
		$main = $main ?? ($interfaces[0] ?? null);
		if ($main === null) {
			return '';
		}

		return (int) $main['useip'] === INTERFACE_USE_DNS && $main['dns'] !== '' ? $main['dns'] : $main['ip'];
	}

	public static function macro(array $host, string $name): ?string {
		foreach ($host['macros'] ?? [] as $m) {
			if ($m['macro'] === $name) {
				// Secret macros come back without a value.
				return $m['value'] ?? null;
			}
		}

		return null;
	}
}
