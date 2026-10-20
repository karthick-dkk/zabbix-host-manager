<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{DiagramStore, DrawioXml};

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
		$xml = (string) DiagramStore::xml($meta['id']);
		$linked = $meta['hostids'] ?? [];
		if ($linked && $this->getUserType() != USER_TYPE_SUPER_ADMIN) {
			// The diagram may have been drawn by someone who sees more hosts than this user.
			$visible = array_column(\API::Host()->get(['output' => ['hostid'], 'hostids' => $linked, 'preservekeys' => true]), 'hostid');
			$xml = DrawioXml::redactHosts($xml, $visible);
		}
		$this->json(['meta' => $meta, 'xml' => $xml, 'can_edit' => $this->isAdmin()]);
	}
}
