<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CControllerResponseData;
use Modules\HostFlow\Lib\CostSync;

class CostView extends Base {

	protected function checkPermissions(): bool {
		return parent::checkPermissions() && $this->canSeeCost();
	}

	protected function handle(): void {
		$r = new CControllerResponseData([
			'is_super' => $this->isSuperAdmin(),
			'stale' => CostSync::stale()
		]);
		$r->setTitle(_('Host Flow cost'));
		$this->setResponse($r);
	}
}
