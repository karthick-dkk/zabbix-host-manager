<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\Dependencies;

class DependencyApply extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['pairs' => 'required|array']);
	}

	protected function handle(): void {
		$this->json(Dependencies::apply($this->getInput('pairs')));
	}
}
