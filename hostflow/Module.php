<?php declare(strict_types = 0);

namespace Modules\HostFlow;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

require_once __DIR__.'/lib/autoload.php';

class Module extends CModule {

	public function init(): void {
		$menu = APP::Component()->get('menu.main');
		$monitoring = $menu->find(_('Monitoring'));
		if ($monitoring === null) {
			return;
		}
		$monitoring->getSubMenu()->insertAfter(_('Maps'),
			(new CMenuItem(_('Host Flow')))
				->setAction('hostflow.list')
				->setAliases(['hostflow.view', 'hostflow.edit', 'hostflow.wall', 'hostflow.settings', 'hostflow.cost'])
		);
	}
}
