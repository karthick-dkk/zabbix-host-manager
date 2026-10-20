<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\AutoFlows;

/** Creates missing client flows and refreshes untouched ones (called by the main page for Admins). */
class AutoFlowsRun extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['force' => 'in 0,1', 'reset' => 'string']);
	}

	protected function handle(): void {
		if ($this->getInput('reset', '') !== '') {
			$meta = \Modules\HostFlow\Lib\DiagramStore::meta($this->getInput('reset'));
			if ($meta === null || !$this->canOpen($meta)) {
				$this->json(['error' => _('This diagram does not exist or you have no access to it.')]);
				return;
			}
			$this->json(['meta' => AutoFlows::reset($meta['id'])]);
			return;
		}
		$this->json(AutoFlows::ensure($this->getInput('force', '0') === '1'));
	}
}
