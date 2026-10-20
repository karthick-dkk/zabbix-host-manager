<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CControllerResponseData;
use Modules\HostFlow\Lib\{Config, Icons, Storage};

class SettingsView extends Base {

	protected const ACCESS = USER_TYPE_SUPER_ADMIN;

	protected function handle(): void {
		$dirOk = true;
		$dirMsg = '';
		try {
			Storage::ensureDir(Storage::dir());
		}
		catch (\RuntimeException $e) {
			$dirOk = false;
			$dirMsg = $e->getMessage();
		}
		$scripts = \API::Script()->get(['output' => ['scriptid'], 'filter' => ['name' => ScriptInstall::NAME]]);
		$r = new CControllerResponseData([
			'settings' => Config::forBrowser(),
			'icons' => Config::ICONS,
			'colors' => Icons::COLORS,
			'glyphs' => Icons::glyphs(),
			'data_dir' => Storage::dir(),
			'dir_ok' => $dirOk,
			'dir_msg' => $dirMsg,
			'script_installed' => (bool) $scripts,
			'drawio_version' => is_file(__DIR__.'/../assets/drawio/VERSION') ? trim(file_get_contents(__DIR__.'/../assets/drawio/VERSION')) : null
		]);
		$r->setTitle(_('Host Flow settings'));
		$this->setResponse($r);
	}
}
