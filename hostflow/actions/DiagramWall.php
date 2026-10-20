<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CControllerResponseData;
use CSettingsHelper;
use Modules\HostFlow\Lib\{Config, DiagramStore};

/** NOC wall: full screen, cycles through the chosen diagrams. */
class DiagramWall extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['ids' => 'string', 'every' => 'int32']);
	}

	protected function handle(): void {
		$all = array_values(array_filter(DiagramStore::list(), fn($m) => $this->canOpen($m)));
		$ids = array_filter(explode(',', $this->getInput('ids', '')));
		$pick = $ids ? array_values(array_filter($all, static fn($m) => in_array($m['id'], $ids, true))) : $all;
		$colors = [];
		for ($s = 0; $s <= 5; $s++) {
			$colors[$s] = '#'.CSettingsHelper::get(constant('CSettingsHelper::SEVERITY_COLOR_'.$s));
		}
		$r = new CControllerResponseData([
			'diagrams' => array_map(static fn($m) => ['id' => $m['id'], 'name' => $m['name']], $pick),
			'all' => array_map(static fn($m) => ['id' => $m['id'], 'name' => $m['name']], $all),
			'every' => max(10, min(600, (int) $this->getInput('every', 30))),
			'severity_colors' => $colors,
			'severity_names' => array_map(static fn($s) => \CSeverityHelper::getName($s), range(0, 5)),
			'refresh' => Config::get()['refresh_sec']
		]);
		$r->setTitle(_('Host Flow wall'));
		$this->setResponse($r);
	}
}
