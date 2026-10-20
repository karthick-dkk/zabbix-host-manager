<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CControllerResponseData;
use CSettingsHelper;
use Modules\HostFlow\Lib\{Config, CostSync, DiagramStore};

/**
 * Main page: flows on the left, the selected flow live on the right.
 *   hostflow.list   no id needed (opens the last or first flow)
 *   hostflow.view   a given flow (also the target of links and "Open in Host Flow")
 *   hostflow.embed  one flow without the Zabbix menu (dashboard widget)
 */
class DiagramView extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['id' => 'string', 'focus' => 'id', 'kiosk' => 'in 0,1', 'hostid' => 'id', 'q' => 'string']);
	}

	protected function handle(): void {
		$meta = null;
		$id = $this->getInput('id', '');
		$kiosk = $this->getInput('kiosk', '0') === '1' || $this->getAction() === 'hostflow.embed';
		if ($id !== '' || $kiosk) {
			$meta = DiagramStore::meta($id);
			if ($meta === null || !$this->canOpen($meta)) {
				$this->setResponse(new \CControllerResponseRedirect((new \CUrl('zabbix.php'))->setArgument('action', 'hostflow.list')));
				\CMessageHelper::setErrorTitle(_('This diagram does not exist or you have no access to it.'));
				return;
			}
		}
		$colors = [];
		for ($s = 0; $s <= 5; $s++) {
			$colors[$s] = '#'.CSettingsHelper::get(constant('CSettingsHelper::SEVERITY_COLOR_'.$s));
		}
		$cfg = Config::get();
		$r = new CControllerResponseData([
			'meta' => $meta,
			'can_edit' => $this->isAdmin(),
			'is_super' => $this->isSuperAdmin(),
			'cost' => $this->canSeeCost(),
			'cost_stale' => $this->isSuperAdmin() && $this->canSeeCost() && CostSync::stale(),
			'focus' => $this->getInput('focus', ''),
			'hostid' => $this->getInput('hostid', ''),
			'q' => $this->getInput('q', ''),
			'kiosk' => $kiosk,
			'auto_flows' => $cfg['auto_flows']['enabled'],
			'layouts' => \Modules\HostFlow\Lib\Generator::LAYOUTS,
			'severity_colors' => $colors,
			'severity_names' => array_map(static fn($s) => \CSeverityHelper::getName($s), range(0, 5)),
			'refresh' => $cfg['refresh_sec']
		]);
		$r->setTitle($meta['name'] ?? _('Host Flow'));
		$this->setResponse($r);
	}
}
