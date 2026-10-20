<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{Config, CostModel, DiagramStore, Generator, ZabbixModel};

/** Builds (or updates) a diagram from Zabbix. Nothing is saved: the editor shows it first. */
class DiagramGenerate extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated([
			'client' => 'required|string',
			'layout' => 'required|in '.implode(',', Generator::LAYOUTS),
			'collapse_over' => 'int32',
			'include_disabled' => 'in 0,1',
			'id' => 'string',
			'xml' => 'string'
		]);
	}

	protected function handle(): void {
		$cfg = Config::get();
		$model = ZabbixModel::clientModel($this->getInput('client'), $cfg);
		if ($model['client'] === null) {
			$this->json(['error' => _('No client group with that name. Check Settings → Client groups.')]);
			return;
		}
		$model['cost_only'] = CostModel::costOnlyFor($this->getInput('client'), $cfg);
		$gen = new Generator($cfg, [
			'collapse_over' => (int) $this->getInput('collapse_over', 0),
			'include_disabled' => $this->getInput('include_disabled', '1') === '1'
		]);

		$xml = $this->getInput('xml', '');
		if ($xml === '' && $this->getInput('id', '') !== '') {
			$meta = DiagramStore::meta($this->getInput('id'));
			if ($meta === null || !$this->canOpen($meta)) {
				$this->json(['error' => _('This diagram does not exist or you have no access to it.')]);
				return;
			}
			$xml = $this->viewableXml($meta)['xml'];
		}
		if ($xml !== '') {
			[$out, $added, $removed] = $gen->update($xml, $model, $this->getInput('layout'));
			$this->json(['xml' => $out, 'added' => $added, 'removed' => $removed, 'mode' => 'update']);
			return;
		}
		$this->json([
			'xml' => $gen->build($model, $this->getInput('layout')),
			'mode' => 'new',
			'hosts' => count($model['hosts']) + count($model['cluster'])
		]);
	}
}
