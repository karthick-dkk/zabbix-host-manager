<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{Audit, Config, CostModel, DiagramStore, DrawioXml, LiveState, Seen, ZabbixModel};

/**
 * Live state for one diagram. One request per refresh; LiveState batches the Zabbix calls.
 *   at   unix time for time travel (state as it was then)
 */
class DiagramData extends Base {

	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['id' => 'required|string', 'at' => 'int32']);
	}

	protected function handle(): void {
		$meta = DiagramStore::meta($this->getInput('id'));
		if ($meta === null || !$this->canOpen($meta)) {
			$this->json(['error' => _('This diagram does not exist or you have no access to it.')]);
			return;
		}
		$xml = (string) DiagramStore::xml($meta['id']);
		$itemids = [];
		$costIds = [];
		foreach (DrawioXml::objects($xml) as $o) {
			foreach (['zbx_itemid', 'zbx_compare_itemid'] as $k) {
				if (ctype_digit($o['attrs'][$k] ?? '')) {
					$itemids[] = $o['attrs'][$k];
				}
			}
			if (($o['attrs']['zbx_cost_id'] ?? '') !== '') {
				$costIds[] = $o['attrs']['zbx_cost_id'];
			}
		}

		$at = $this->hasInput('at') ? (int) $this->getInput('at') : null;
		$state = $at !== null && $at < time() - 60
			? LiveState::at($meta['hostids'], $at)
			: LiveState::now($meta['hostids'], $itemids);

		$proxies = [];
		$pids = array_values(array_unique(array_filter(array_column($state['hosts'], 'proxyid'))));
		if ($pids) {
			foreach (ZabbixModel::proxies($pids) as $p) {
				$proxies[$p['proxyid']] = $p['name'];
			}
		}

		$out = [
			'at' => $state['at'],
			'refreshed' => time(),
			'hosts' => $state['hosts'],
			'items' => $state['items'],
			'proxies' => $proxies,
			'flow' => $meta['flow'] ?? Config::template()
		];

		if ($this->canSeeCost()) {
			$out['cost'] = CostModel::forDiagram($state['hosts'], array_unique($costIds), $meta['client'] ?? '');
		}

		$uid = Audit::userId();
		if ($uid !== null && $state['at'] === null) {
			$stored = Seen::load($uid, $meta['id']);
			if ($stored === null) {
				Seen::store($uid, $meta['id'], $state['hosts']);
			}
			$out['changes'] = Seen::diff($stored, $state['hosts']);
		}

		$this->json($out);
	}
}
