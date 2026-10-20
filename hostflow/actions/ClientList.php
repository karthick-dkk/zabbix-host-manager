<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\ZabbixModel;

class ClientList extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const JSON = true;

	protected function handle(): void {
		$this->json(['clients' => array_values(array_map(static fn($c) => ['name' => $c['name'], 'groupid' => $c['groupid']],
			ZabbixModel::clients()))]);
	}
}
