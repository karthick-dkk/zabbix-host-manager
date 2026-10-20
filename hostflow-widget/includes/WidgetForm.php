<?php declare(strict_types = 0);

namespace Modules\HostFlowWidget\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\CWidgetFieldCheckBox;

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		$diagrams = [];
		$lib = __DIR__.'/../../hostflow/lib/autoload.php';
		if (is_file($lib)) {
			require_once $lib;
			foreach (\Modules\HostFlow\Lib\DiagramStore::list() as $d) {
				$diagrams[$d['id']] = $d['name'];
			}
		}
		if (!$diagrams) {
			$diagrams = ['' => _('No diagrams yet (create one in Monitoring → Host Flow)')];
		}

		return $this
			->addField(
				(new WidgetFieldDiagram('diagramid', _('Diagram'), $diagrams))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_toolbar', _('Show toolbar')))->setDefault(0)
			);
	}
}
