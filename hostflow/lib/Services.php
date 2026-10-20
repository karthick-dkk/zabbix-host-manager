<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use API;
use RuntimeException;

/**
 * Builds a Zabbix Services tree from a diagram, so Zabbix's own SLA reports cover the pipeline:
 *   Host Flow: <diagram>          (most critical of its tiers)
 *     <client> · <tier>           (status from its hosts)
 *       <host>                    (problem tag hostflow-host = <technical host name>)
 * Problems carry their host's tags, so the two host tags below are what link problems to services.
 * Preview first (apply=false): lists the tags it would add to hosts and the services it would create.
 */
class Services {

	public const TAG = 'hostflow';
	public const HOST_TAG = 'hostflow-host';
	public const DIAGRAM_TAG = 'hostflow-diagram';

	public static function build(string $diagramId, bool $apply): array {
		$meta = DiagramStore::meta($diagramId);
		if ($meta === null) {
			throw new RuntimeException('This diagram no longer exists.');
		}
		$cfg = Config::get();
		$client = $meta['client'] !== '' ? $meta['client'] : $meta['name'];
		$labels = array_column($meta['flow']['components'] ?? $cfg['components'], 'label', 'id');
		$by = Dependencies::hostsByComponent($meta, $cfg);
		$hostids = array_values(array_unique(array_merge(...array_values($by ?: [[]]))));
		$hosts = $hostids ? API::Host()->get(['output' => ['hostid', 'host', 'name'], 'hostids' => $hostids, 'selectTags' => ['tag', 'value'],
			'preservekeys' => true]) : [];

		$plan = ['root' => 'Host Flow: '.$meta['name'], 'tiers' => [], 'host_tags' => 0];
		$tagUpdates = [];
		foreach ($by as $comp => $ids) {
			$tierValue = $client.'/'.$comp;
			$tier = ['name' => $client.' · '.($labels[$comp] ?? $comp), 'tag' => $tierValue, 'hosts' => []];
			foreach ($ids as $hid) {
				if (!isset($hosts[$hid])) {
					continue;
				}
				$h = $hosts[$hid];
				$tier['hosts'][] = $h['name'];
				$want = [['tag' => self::TAG, 'value' => $tierValue], ['tag' => self::HOST_TAG, 'value' => $h['host']]];
				$tags = $h['tags'];
				$changed = false;
				foreach ($want as $w) {
					$found = false;
					foreach ($tags as &$t) {
						if ($t['tag'] === $w['tag']) {
							$found = true;
							if ($t['value'] !== $w['value']) {
								$t['value'] = $w['value'];
								$changed = true;
							}
						}
					}
					unset($t);
					if (!$found) {
						$tags[] = $w;
						$changed = true;
					}
				}
				if ($changed) {
					$tagUpdates[] = ['hostid' => $hid, 'tags' => $tags];
				}
			}
			$plan['tiers'][] = $tier;
		}
		$plan['host_tags'] = count($tagUpdates);
		if (!$apply) {
			return ['preview' => $plan];
		}

		foreach ($tagUpdates as $u) {
			if (API::Host()->update([$u]) === false) {
				throw new RuntimeException('Could not tag a host: '.implode(' ', array_column(get_and_clear_messages(), 'message')));
			}
		}
		$diagramTag = [['tag' => self::DIAGRAM_TAG, 'value' => $meta['id']]];
		$rootId = self::upsert(['name' => $plan['root'], 'algorithm' => ZBX_SERVICE_STATUS_CALC_MOST_CRITICAL_ONE, 'sortorder' => 0,
			'tags' => $diagramTag, 'problem_tags' => []]);
		foreach ($plan['tiers'] as $tier) {
			// Zabbix: a service with children takes its status from them and may not have problem tags.
			$tierId = self::upsert(['name' => $tier['name'], 'algorithm' => ZBX_SERVICE_STATUS_CALC_MOST_CRITICAL_ONE, 'sortorder' => 0,
				'tags' => $diagramTag, 'problem_tags' => [], 'parents' => [['serviceid' => $rootId]]]);
			foreach ($by as $comp => $ids) {
				if ($client.'/'.$comp !== $tier['tag']) {
					continue;
				}
				foreach ($ids as $hid) {
					if (isset($hosts[$hid])) {
						self::upsert(['name' => $hosts[$hid]['name'], 'algorithm' => ZBX_SERVICE_STATUS_CALC_MOST_CRITICAL_ONE, 'sortorder' => 0,
							'tags' => $diagramTag, 'problem_tags' => [['tag' => self::HOST_TAG, 'operator' => ZBX_SERVICE_PROBLEM_TAG_OPERATOR_EQUAL, 'value' => $hosts[$hid]['host']]],
							'parents' => [['serviceid' => $tierId]]]);
					}
				}
			}
		}
		$sla = API::Sla()->get(['output' => ['slaid'], 'filter' => ['name' => $plan['root']]]);
		if (!$sla) {
			$r = API::Sla()->create([[
				'name' => $plan['root'], 'slo' => 99.9, 'period' => ZBX_SLA_PERIOD_MONTHLY,
				'effective_date' => strtotime(gmdate('Y-m-01')), 'timezone' => 'UTC',
				'service_tags' => [['tag' => self::DIAGRAM_TAG, 'operator' => ZBX_SLA_SERVICE_TAG_OPERATOR_EQUAL, 'value' => $meta['id']]]
			]]);
			if ($r === false) {
				throw new RuntimeException('Services were created, but the SLA could not be: '.implode(' ', array_column(get_and_clear_messages(), 'message')));
			}
		}
		Audit::log('services.build', ['diagram.id' => $meta['id'], 'tiers' => count($plan['tiers']), 'hosts.tagged' => count($tagUpdates)]);

		return ['applied' => $plan];
	}

	private static function upsert(array $service): string {
		$existing = API::Service()->get(['output' => ['serviceid'], 'filter' => ['name' => $service['name']],
			'tags' => [['tag' => self::DIAGRAM_TAG, 'value' => $service['tags'][0]['value'], 'operator' => TAG_OPERATOR_EQUAL]]]);
		if ($existing) {
			$id = $existing[0]['serviceid'];
			$r = API::Service()->update([['serviceid' => $id] + $service]);
		}
		else {
			$r = API::Service()->create([$service]);
			$id = $r['serviceids'][0] ?? null;
		}
		if ($r === false || $id === null) {
			throw new RuntimeException('Could not save service "'.$service['name'].'": '.implode(' ', array_column(get_and_clear_messages(), 'message')));
		}

		return (string) $id;
	}
}
