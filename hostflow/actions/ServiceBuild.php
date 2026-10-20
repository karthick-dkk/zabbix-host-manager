<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\Services;

/** Builds a Zabbix Services tree (client → tiers → hosts) from a diagram. apply=0 previews. */
class ServiceBuild extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['id' => 'required|string', 'apply' => 'in 0,1']);
	}

	protected function handle(): void {
		$this->json(Services::build($this->getInput('id'), $this->getInput('apply', '0') === '1'));
	}
}
