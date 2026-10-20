<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{CostCache, CostModel, CostSync, ZabbixModel};

/** Cost per client → tier → host, the 12-month trend, and the AWS sync status. */
class CostData extends Base {

	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['client' => 'string']);
	}

	protected function checkPermissions(): bool {
		return parent::checkPermissions() && $this->canSeeCost();
	}

	protected function handle(): void {
		$clients = array_keys(ZabbixModel::clients());
		$pick = $this->getInput('client', '');
		$out = ['clients' => [], 'trend' => CostCache::trend(), 'stale' => CostSync::stale()];
		foreach ($clients as $client) {
			if ($pick !== '' && $client !== $pick) {
				continue;
			}
			$m = ZabbixModel::clientModel($client);
			$hosts = [];
			foreach (array_merge($m['hosts'], $m['cluster']) as $h) {
				$hosts[$h['hostid']] = [
					'hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'], 'ip' => $h['ip'],
					'groups' => array_column($h['hostgroups'], 'name'), 'tags' => $h['tags'] ?? [],
					'component' => $h['component'] ?? 'cluster', 'disabled' => (int) $h['status'] === 1
				];
			}
			$c = CostModel::forDiagram($hosts, [], $client);
			$rows = [];
			foreach ($hosts as $hid => $h) {
				$rows[] = ['hostid' => $hid, 'name' => $h['name'], 'ip' => $h['ip'], 'component' => $h['component']] + $c['hosts'][$hid];
			}
			$out['clients'][] = ['client' => $client, 'total' => $c['total'], 'components' => $c['components'],
				'hosts' => $rows, 'cost_only' => array_values($c['cost_only'])];
			$out['pricing_at'] = $c['pricing_at'];
			$out['aws_at'] = $c['aws_at'];
		}
		if ($this->isSuperAdmin()) {
			$aws = CostCache::aws();
			$out['aws'] = [
				'synced_at' => $aws['synced_at'] ?? null, 'instances' => $aws['instances'] ?? 0,
				'ambiguous' => $aws['ambiguous'] ?? [], 'moved' => $aws['moved'] ?? [],
				'unmatched' => $aws['unmatched'] ?? [], 'errors' => $aws['errors'] ?? []
			];
		}
		$this->json($out);
	}
}
