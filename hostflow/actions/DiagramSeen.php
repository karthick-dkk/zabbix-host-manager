<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{Audit, DiagramStore, LiveState, Seen};

/** "Mark as seen": the current state becomes the baseline for the next visit. */
class DiagramSeen extends Base {

	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['id' => 'required|string']);
	}

	protected function handle(): void {
		$meta = DiagramStore::meta($this->getInput('id'));
		$uid = Audit::userId();
		if ($meta === null || $uid === null || !$this->canOpen($meta)) {
			$this->json(['error' => _('This diagram does not exist or you have no access to it.')]);
			return;
		}
		Seen::store($uid, $meta['id'], LiveState::now($meta['hostids'])['hosts']);
		$this->json(['ok' => true]);
	}
}
