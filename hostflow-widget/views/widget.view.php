<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

$item = $data['error'] !== null
	? (new CTableInfo())->setNoDataMessage($data['error'])
	: (new CIFrame($data['url'], '100%', '100%', 'auto'))->addStyle('border:0');

(new CWidgetView($data))
	->addItem($item)
	->show();
