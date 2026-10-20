<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{Config, CostModel, DiagramStore, LiveState};

/** Worst problem, problem count and cost for every diagram, from one batched read. */
class DiagramSummary extends Base {

	protected const JSON = true;

	protected function handle(): void {
		$diagrams = array_values(array_filter(DiagramStore::list(), fn($m) => $this->canOpen($m)));
		$all = [];
		foreach ($diagrams as $d) {
			$all = array_merge($all, $d['hostids'] ?? []);
		}
		$hosts = LiveState::now(array_values(array_unique($all)))['hosts'];
		$cost = $this->canSeeCost() ? CostModel::forDiagram($hosts, [], '') : null;

		$out = [];
		foreach ($diagrams as $d) {
			$worst = -1;
			$problems = 0;
			$visible = 0;
			$monthly = 0.0;
			foreach ($d['hostids'] ?? [] as $hid) {
				if (!isset($hosts[$hid])) {
					continue;
				}
				$visible++;
				$worst = max($worst, $hosts[$hid]['worst']);
				$problems += $hosts[$hid]['count'];
				$monthly += $cost['hosts'][$hid]['monthly'] ?? 0;
			}
			$out[] = [
				'id' => $d['id'], 'name' => $d['name'], 'client' => $d['client'] ?? '',
				'hosts' => $visible, 'worst' => $worst, 'problems' => $problems,
				'monthly' => $cost !== null ? round($monthly, 2) : null,
				'layout' => $d['layout'] ?? '', 'auto' => !empty($d['auto']), 'synced_at' => $d['synced_at'] ?? 0,
				'origin' => $d['origin'] ?? (!empty($d['auto']) ? 'auto' : ''),
				'kind' => ($d['origin'] ?? '') === 'auto' || !empty($d['auto']) ? \Modules\HostFlow\Lib\AutoFlows::kindOf($d) : '',
				'template' => $d['flow']['template'] ?? '', 'template_name' => $d['flow']['name'] ?? '',
				'updated_by' => $d['updated_by'] ?? '', 'updated_at' => $d['updated_at'] ?? 0
			];
		}
		$this->json(['diagrams' => $out, 'can_edit' => $this->isAdmin(), 'cost' => $cost !== null,
			'auto_flows' => Config::get()['auto_flows']['enabled']]);
	}
}
