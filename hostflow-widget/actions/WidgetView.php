<?php declare(strict_types = 0);

namespace Modules\HostFlowWidget\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$id = (string) ($this->fields_values['diagramid'] ?? '');
		$error = null;
		if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
			$error = _('Choose a diagram in the widget settings.');
		}
		$url = (new \CUrl('zabbix.php'))
			->setArgument('action', $this->fields_values['show_toolbar'] ? 'hostflow.view' : 'hostflow.embed')
			->setArgument('id', $id)
			->getUrl();

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'url' => $url,
			'error' => $error,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}
}
