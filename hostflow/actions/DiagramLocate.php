<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CControllerResponseRedirect;
use CUrl;
use Modules\HostFlow\Lib\DiagramStore;

/** Target of the "Open in Host Flow" host menu entry. */
class DiagramLocate extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['hostid' => 'required|id']);
	}

	protected function handle(): void {
		$hostid = $this->getInput('hostid');
		$found = [];
		foreach (DiagramStore::list() as $d) {
			if (in_array($hostid, $d['hostids'] ?? [], true) && $this->canOpen($d)) {
				$found[] = $d;
			}
		}
		$url = (new CUrl('zabbix.php'));
		if (count($found) === 1) {
			$url->setArgument('action', 'hostflow.view')->setArgument('id', $found[0]['id'])->setArgument('focus', $hostid);
		}
		else {
			$url->setArgument('action', 'hostflow.list')->setArgument('hostid', $hostid);
		}
		$this->setResponse(new CControllerResponseRedirect($url));
	}
}
