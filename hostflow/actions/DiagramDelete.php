<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\DiagramStore;

class DiagramDelete extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const READONLY = false;
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
		DiagramStore::delete($meta['id']);
		$this->json(['ok' => true]);
	}
}
