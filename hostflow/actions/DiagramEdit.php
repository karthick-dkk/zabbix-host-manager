<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CControllerResponseData;
use Modules\HostFlow\Lib\{Config, DiagramStore, Generator};

class DiagramEdit extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;

	protected function checkInput(): bool {
		return $this->validateInput(['id' => 'string', 'client' => 'string', 'layout' => 'in '.implode(',', Generator::LAYOUTS),
			'sysmapid' => 'id']);
	}

	protected function handle(): void {
		$meta = null;
		if ($this->getInput('id', '') !== '') {
			$meta = DiagramStore::meta($this->getInput('id'));
			if ($meta === null || !$this->canOpen($meta)) {
				\CMessageHelper::setErrorTitle(_('This diagram does not exist or you have no access to it.'));
				$this->setResponse(new \CControllerResponseRedirect((new \CUrl('zabbix.php'))->setArgument('action', 'hostflow.list')));
				return;
			}
		}
		$cfg = Config::get();
		$r = new CControllerResponseData([
			'meta' => $meta,
			'client' => $this->getInput('client', $meta['client'] ?? ''),
			'layout' => $this->getInput('layout', $meta['layout'] ?? 'client'),
			'sysmapid' => $this->getInput('sysmapid', ''),
			'layouts' => Generator::LAYOUTS,
			'components' => $cfg['components'],
			'groups' => \API::UserGroup()->get(['output' => ['usrgrpid', 'name'], 'sortfield' => 'name'])
		]);
		$r->setTitle($meta['name'] ?? _('New diagram'));
		$this->setResponse($r);
	}
}
