<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{AutoFlows, Config};

class SettingsUpdate extends Base {

	protected const ACCESS = USER_TYPE_SUPER_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['settings' => 'required|array']);
	}

	protected function handle(): void {
		Config::save($this->getInput('settings'));
		// Template and layout changes reach the automated client flows right away.
		$flows = ['updated' => [], 'created' => []];
		try {
			$flows = AutoFlows::ensure(true);
		}
		catch (\Throwable $e) {
			error_log('hostflow: client flows after settings save: '.$e->getMessage());
		}
		$this->json(['settings' => Config::forBrowser(), 'flows' => ['updated' => $flows['updated'], 'created' => $flows['created']]]);
	}
}
