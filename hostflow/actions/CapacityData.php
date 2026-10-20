<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{Capacity, DiagramStore, LiveState};

/** CPU, memory and disk per host of a flow (Capacity tab). Read as the logged-in user. */
class CapacityData extends Base {

	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['id' => 'required|string']);
	}

	protected function handle(): void {
		$meta = DiagramStore::meta($this->getInput('id'));
		if ($meta === null || !$this->canOpen($meta)) {
			$this->json(['error' => _('This diagram does not exist or you have no access to it.')]);
			return;
		}
		$ids = $meta['hostids'] ?? [];
		$live = LiveState::now($ids)['hosts'];
		$metrics = Capacity::forHosts($ids);
		$hosts = [];
		foreach ($live as $hid => $h) {
			$hosts[] = ['hostid' => (string) $hid, 'name' => $h['name'], 'ip' => $h['ip'], 'component' => $h['component'] ?? '',
				'disabled' => !empty($h['disabled']), 'available' => $h['available'] ?? 0] + ($metrics[$hid] ?? []);
		}
		usort($hosts, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
		$this->json(['hosts' => $hosts, 'limit' => Capacity::MAX_HOSTS]);
	}
}
