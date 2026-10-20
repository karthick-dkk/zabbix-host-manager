<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\ZabbixModel;

class HostSearch extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['q' => 'required|string']);
	}

	protected function handle(): void {
		$this->json(['hosts' => ZabbixModel::search($this->getInput('q'), 50)]);
	}
}
