<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use API;
use Modules\HostFlow\Lib\Audit;

/** Adds "Open in Host Flow" to the host menu everywhere in Zabbix (a global script of type URL). */
class ScriptInstall extends Base {

	protected const ACCESS = USER_TYPE_SUPER_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	public const NAME = 'Open in Host Flow';

	protected function handle(): void {
		$script = [
			'name' => self::NAME,
			'type' => ZBX_SCRIPT_TYPE_URL,
			'scope' => ZBX_SCRIPT_SCOPE_HOST,
			'url' => 'zabbix.php?action=hostflow.locate&hostid={HOST.ID}',
			'new_window' => ZBX_SCRIPT_URL_NEW_WINDOW_NO,
			'description' => 'Shows this host on its Host Flow diagram.'
		];
		$existing = API::Script()->get(['output' => ['scriptid'], 'filter' => ['name' => self::NAME]]);
		if ($existing) {
			$r = API::Script()->update(['scriptid' => $existing[0]['scriptid']] + $script);
		}
		else {
			$r = API::Script()->create($script);
		}
		if ($r === false) {
			$this->json(['error' => implode(' ', array_column(get_and_clear_messages(), 'message')) ?: _('Could not save the script.')]);
			return;
		}
		Audit::log('script.install', ['script.name' => self::NAME]);
		$this->json(['ok' => true, 'updated' => (bool) $existing]);
	}
}
