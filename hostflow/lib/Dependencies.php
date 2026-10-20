<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use API;
use RuntimeException;

/**
 * Suggests Zabbix trigger dependencies from a diagram's data flow: for every arrow A → B,
 * B's "down / unreachable" triggers depend on A's, so one root-cause alert replaces a storm.
 * Two-way arrows (ES ⇄ AIML) are skipped: a dependency loop is not allowed.
 * Nothing changes until the suggestions you tick are applied.
 */
class Dependencies {

	public const LIMIT = 500;

	public static function suggest(string $diagramId): array {
		$meta = DiagramStore::meta($diagramId);
		if ($meta === null) {
			throw new RuntimeException('This diagram no longer exists.');
		}
		$cfg = Config::get();
		$edges = $meta['flow']['edges'] ?? $cfg['flow'];
		$byComponent = self::hostsByComponent($meta, $cfg);
		$regex = '/'.str_replace('/', '\/', $cfg['dependency_trigger_regex']).'/i';

		$hostids = array_values(array_unique(array_merge(...array_values($byComponent ?: [[]]))));
		if (!$hostids) {
			return [];
		}
		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority'],
			'hostids' => $hostids,
			'selectHosts' => ['hostid', 'name'],
			'selectDependencies' => ['triggerid'],
			'expandDescription' => true,
			'preservekeys' => true
		]);
		$down = [];
		foreach ($triggers as $t) {
			if (preg_match($regex, $t['description'])) {
				$down[$t['hosts'][0]['hostid']][] = $t;
			}
		}

		$pairs = [];
		foreach ($edges as $e) {
			if (self::hasEdge($edges, $e['to'], $e['from'])) {
				continue;
			}
			foreach ($byComponent[$e['to']] ?? [] as $child) {
				foreach ($down[$child] ?? [] as $ct) {
					$have = array_column($ct['dependencies'], 'triggerid');
					foreach ($byComponent[$e['from']] ?? [] as $parent) {
						foreach ($down[$parent] ?? [] as $pt) {
							if (in_array($pt['triggerid'], $have, true) || $pt['triggerid'] === $ct['triggerid']) {
								continue;
							}
							$pairs[] = [
								'triggerid' => $ct['triggerid'], 'trigger' => $ct['description'], 'host' => $ct['hosts'][0]['name'],
								'depends_on' => $pt['triggerid'], 'parent_trigger' => $pt['description'], 'parent_host' => $pt['hosts'][0]['name'],
								'arrow' => $e['from'].' → '.$e['to']
							];
							if (count($pairs) >= self::LIMIT) {
								return $pairs;
							}
						}
					}
				}
			}
		}

		return $pairs;
	}

	/** $pairs: [['triggerid' => …, 'depends_on' => …], …]; existing dependencies are kept. */
	public static function apply(array $pairs): array {
		$add = [];
		foreach ($pairs as $p) {
			if (ctype_digit((string) ($p['triggerid'] ?? '')) && ctype_digit((string) ($p['depends_on'] ?? ''))) {
				$add[$p['triggerid']][$p['depends_on']] = true;
			}
		}
		if (!$add) {
			return ['updated' => 0, 'errors' => []];
		}
		$current = API::Trigger()->get(['output' => ['triggerid', 'description'], 'triggerids' => array_keys($add),
			'selectDependencies' => ['triggerid'], 'preservekeys' => true]);
		$updated = 0;
		$errors = [];
		foreach ($add as $tid => $deps) {
			if (!isset($current[$tid])) {
				continue;
			}
			$all = array_unique(array_merge(array_column($current[$tid]['dependencies'], 'triggerid'), array_map('strval', array_keys($deps))));
			$r = API::Trigger()->update([['triggerid' => (string) $tid, 'dependencies' => array_map(static fn($d) => ['triggerid' => $d], array_values($all))]]);
			if ($r === false) {
				$errors[] = $current[$tid]['description'].': '.implode(' ', array_column(get_and_clear_messages(), 'message'));
			}
			else {
				$updated++;
			}
		}
		Audit::log('dependencies.apply', ['triggers.updated' => $updated, 'errors' => count($errors)]);

		return ['updated' => $updated, 'errors' => $errors];
	}

	/** component id → hostids, from the shapes on the diagram (falling back to Settings matching). */
	public static function hostsByComponent(array $meta, array $cfg): array {
		$xml = (string) DiagramStore::xml($meta['id']);
		$by = [];
		$unknown = [];
		foreach (DrawioXml::objects($xml) as $o) {
			$hid = $o['attrs']['zbx_hostid'] ?? '';
			if (!ctype_digit($hid)) {
				continue;
			}
			$c = $o['attrs']['zbx_component'] ?? '';
			if ($c !== '') {
				$by[$c][] = $hid;
			}
			else {
				$unknown[] = $hid;
			}
		}
		if ($unknown) {
			foreach (ZabbixModel::hostsWith(['hostids' => $unknown]) as $h) {
				$c = ZabbixModel::componentFor($h, $cfg['components']);
				if ($c !== null) {
					$by[$c][] = $h['hostid'];
				}
			}
		}

		return array_map(static fn($ids) => array_values(array_unique($ids)), $by);
	}

	private static function hasEdge(array $edges, string $from, string $to): bool {
		foreach ($edges as $e) {
			if ($e['from'] === $from && $e['to'] === $to) {
				return true;
			}
		}

		return false;
	}
}
