<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{DiagramStore, ZabbixModel};

/** Find a host by name or IP and the diagrams it is on. */
class DiagramFind extends Base {

	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['q' => 'required|string']);
	}

	protected function handle(): void {
		$diagrams = array_values(array_filter(DiagramStore::list(), fn($m) => $this->canOpen($m)));
		$out = [];
		foreach (ZabbixModel::search($this->getInput('q'), 30) as $h) {
			$on = [];
			foreach ($diagrams as $d) {
				if (in_array($h['hostid'], $d['hostids'] ?? [], true)) {
					$on[] = ['id' => $d['id'], 'name' => $d['name']];
				}
			}
			$out[] = $h + ['diagrams' => $on];
		}
		$this->json(['hosts' => $out]);
	}
}
