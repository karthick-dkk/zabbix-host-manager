<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

/** Items for linking a flow arrow to a value (EPS, traffic). */
class ItemSearch extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['hostid' => 'required|id', 'q' => 'string']);
	}

	protected function handle(): void {
		$opt = [
			'output' => ['itemid', 'name', 'key_', 'units', 'lastvalue'],
			'hostids' => [$this->getInput('hostid')],
			'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
			'sortfield' => 'name',
			'limit' => 100
		];
		if ($this->getInput('q', '') !== '') {
			$opt['search'] = ['name' => $this->getInput('q'), 'key_' => $this->getInput('q')];
			$opt['searchByAny'] = true;
		}
		$this->json(['items' => \API::Item()->get($opt)]);
	}
}
