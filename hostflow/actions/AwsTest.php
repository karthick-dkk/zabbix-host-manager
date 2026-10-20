<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\Aws\{Client, Credentials};
use Modules\HostFlow\Lib\Config;

/** "Test connection" for one AWS account: credentials, then one read in its first region. */
class AwsTest extends Base {

	protected const ACCESS = USER_TYPE_SUPER_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated(['account' => 'required|string']);
	}

	protected function handle(): void {
		$cfg = Config::get();
		foreach ($cfg['aws']['accounts'] as $acc) {
			if ($acc['id'] !== $this->getInput('account')) {
				continue;
			}
			$cred = Credentials::forAccount($acc);
			$region = $acc['regions'][0] ?? $cfg['cost']['default_region'];
			$n = count((new Client($cred))->describeInstances($region, $acc['vpcs']));
			$this->json(['ok' => true, 'message' => sprintf(_('Connected. %1$d instances visible in %2$s.'), $n, $region)]);
			return;
		}
		$this->json(['error' => _('Save the account first, then test it.')]);
	}
}
