<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\DiagramStore;

class DiagramLoad extends Base {

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
		$v = $this->viewableXml($meta);
		$meta['hostids'] = $v['hostids'];
		$this->json(['meta' => $meta, 'xml' => $v['xml'], 'can_edit' => $this->isAdmin()]);
	}
}
