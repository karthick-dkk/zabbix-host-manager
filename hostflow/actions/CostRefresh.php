<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\CostSync;

/** Runs the daily cost refresh now (Super admin, so it sees every host). */
class CostRefresh extends Base {

	protected const ACCESS = USER_TYPE_SUPER_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['only_if_stale' => 'in 0,1']);
	}

	protected function handle(): void {
		if ($this->getInput('only_if_stale', '0') === '1' && !CostSync::stale()) {
			$this->json(['skipped' => true]);
			return;
		}
		@set_time_limit(600);
		$this->json(['report' => (new CostSync())->run()]);
	}
}
