<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use API;

/**
 * The live state of every host on a diagram, in one batch:
 *   host.get  →  names, IPs, availability, status, maintenance, proxy
 *   problem.get + trigger.get  →  active problems mapped to hosts
 *   item.get  →  last values for link items (only when a diagram has them)
 * The number of API calls does not grow with the size of the diagram.
 */
class LiveState {

	public const TOP_PROBLEMS = 5;

	public static function now(array $hostids, array $itemids = []): array {
		$hostids = array_values(array_unique(array_filter(array_map('strval', $hostids), 'ctype_digit')));
		$hosts = self::hosts($hostids);

		$problems = [];
		if ($hosts) {
			$problems = API::Problem()->get([
				'output' => ['eventid', 'objectid', 'severity', 'name', 'acknowledged', 'clock', 'suppressed'],
				'hostids' => array_keys($hosts),
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'sortfield' => ['eventid'],
				'sortorder' => ZBX_SORT_DOWN
			]);
		}
		self::attachProblems($hosts, $problems);

		return [
			'at' => null,
			'hosts' => $hosts,
			'items' => $itemids ? self::items($itemids) : []
		];
	}

	/** State as it was at $ts (time travel): problems that were open at that moment. */
	public static function at(array $hostids, int $ts): array {
		$hostids = array_values(array_unique(array_filter(array_map('strval', $hostids), 'ctype_digit')));
		$hosts = self::hosts($hostids);
		$problems = [];
		if ($hosts) {
			$events = API::Event()->get([
				'output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'acknowledged', 'r_eventid'],
				'hostids' => array_keys($hosts),
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'value' => TRIGGER_VALUE_TRUE,
				'time_from' => $ts - 30 * SEC_PER_DAY,
				'time_till' => $ts,
				'sortfield' => ['eventid'],
				'sortorder' => ZBX_SORT_DOWN,
				'limit' => 5000
			]);
			$rids = array_values(array_filter(array_column($events, 'r_eventid'), static fn($r) => $r !== '0'));
			$recovered = [];
			if ($rids) {
				foreach (API::Event()->get(['output' => ['eventid', 'clock'], 'eventids' => $rids]) as $r) {
					$recovered[$r['eventid']] = (int) $r['clock'];
				}
			}
			foreach ($events as $e) {
				$rc = $e['r_eventid'] !== '0' ? ($recovered[$e['r_eventid']] ?? null) : null;
				if ($rc === null || $rc > $ts) {
					$e['suppressed'] = '0';
					$problems[] = $e;
				}
			}
		}
		self::attachProblems($hosts, $problems);

		return ['at' => $ts, 'hosts' => $hosts, 'items' => []];
	}

	private static function hosts(array $hostids): array {
		if (!$hostids) {
			return [];
		}
		$cfg = Config::get();
		$out = [];
		foreach (ZabbixModel::hostsWith(['hostids' => $hostids]) as $h) {
			$agent = null;
			foreach ($h['interfaces'] as $i) {
				if ((int) $i['type'] === INTERFACE_TYPE_AGENT && (int) $i['main'] === 1) {
					$agent = $i;
				}
			}
			$main = $agent ?? ($h['interfaces'][0] ?? null);
			$out[$h['hostid']] = [
				'hostid' => $h['hostid'],
				'name' => $h['name'],
				'host' => $h['host'],
				'ip' => $h['ip'],
				'port' => $main['port'] ?? '',
				'disabled' => (int) $h['status'] === HOST_STATUS_NOT_MONITORED,
				'maintenance' => (int) $h['maintenance_status'] === HOST_MAINTENANCE_STATUS_ON,
				// 0 unknown, 1 available, 2 unavailable (INTERFACE_AVAILABLE_*)
				'available' => $main !== null ? (int) $main['available'] : 0,
				'error' => $main['error'] ?? '',
				'proxyid' => (int) $h['monitored_by'] === ZBX_MONITORED_BY_PROXY ? $h['proxyid'] : null,
				'groups' => array_column($h['hostgroups'], 'name'),
				'tags' => $h['tags'] ?? [],
				'component' => ZabbixModel::componentFor($h, $cfg['components']),
				'problems' => [],
				'count' => 0,
				'unacked' => 0,
				'worst' => -1
			];
		}

		return $out;
	}

	private static function attachProblems(array &$hosts, array $problems): void {
		if (!$problems) {
			return;
		}
		$triggers = API::Trigger()->get([
			'output' => ['triggerid'],
			'triggerids' => array_values(array_unique(array_column($problems, 'objectid'))),
			'selectHosts' => ['hostid'],
			'preservekeys' => true
		]);
		foreach ($problems as $p) {
			foreach ($triggers[$p['objectid']]['hosts'] ?? [] as $th) {
				$hid = $th['hostid'];
				if (!isset($hosts[$hid])) {
					continue;
				}
				$h = &$hosts[$hid];
				$suppressed = (int) ($p['suppressed'] ?? 0) === 1;
				if (!$suppressed) {
					$h['count']++;
					$h['worst'] = max($h['worst'], (int) $p['severity']);
					if ((int) $p['acknowledged'] === 0) {
						$h['unacked']++;
					}
				}
				if (count($h['problems']) < self::TOP_PROBLEMS) {
					$h['problems'][] = [
						'eventid' => $p['eventid'],
						'name' => $p['name'],
						'severity' => (int) $p['severity'],
						'acknowledged' => (int) $p['acknowledged'] === 1,
						'suppressed' => $suppressed,
						'clock' => (int) $p['clock']
					];
				}
				unset($h);
			}
		}
	}

	private static function items(array $itemids): array {
		$itemids = array_values(array_unique(array_filter(array_map('strval', $itemids), 'ctype_digit')));
		if (!$itemids) {
			return [];
		}
		$out = [];
		foreach (API::Item()->get([
			'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type'],
			'itemids' => $itemids,
			'webitems' => true
		]) as $i) {
			$out[$i['itemid']] = [
				'itemid' => $i['itemid'],
				'name' => $i['name'],
				'key' => $i['key_'],
				'value' => $i['lastvalue'],
				'clock' => (int) $i['lastclock'],
				'units' => $i['units'],
				'numeric' => in_array((int) $i['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)
			];
		}

		return $out;
	}
}
