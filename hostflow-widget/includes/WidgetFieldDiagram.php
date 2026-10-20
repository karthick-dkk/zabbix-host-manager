<?php declare(strict_types = 0);

namespace Modules\HostFlowWidget\Includes;

use Zabbix\Widgets\Fields\CWidgetFieldSelect;

/** A select whose value is a Host Flow diagram id (hex), so it is saved as a string, not an integer. */
class WidgetFieldDiagram extends CWidgetFieldSelect {

	public function __construct(string $name, string $label, array $values) {
		parent::__construct($name, $label, $values);
		$this->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR);
	}
}
