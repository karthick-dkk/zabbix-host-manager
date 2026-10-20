<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\Dependencies;

/** Proposes trigger dependencies from a diagram's data flow. Nothing is changed. */
class DependencySuggest extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['id' => 'required|string']);
	}

	protected function handle(): void {
		$this->json(['suggestions' => Dependencies::suggest($this->getInput('id'))]);
	}
}
