<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\MapImporter;

/** Without sysmapid: the maps you can see. With sysmapid: that map as draw.io XML (not saved). */
class MapImport extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['sysmapid' => 'id']);
	}

	protected function handle(): void {
		if (!$this->hasInput('sysmapid')) {
			$this->json(['maps' => \API::Map()->get(['output' => ['sysmapid', 'name'], 'sortfield' => 'name'])]);
			return;
		}
		$this->json(MapImporter::toXml($this->getInput('sysmapid')));
	}
}
