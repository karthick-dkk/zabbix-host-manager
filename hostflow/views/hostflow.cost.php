<?php declare(strict_types = 0);
/** @var CView $this @var array $data */

use Modules\HostFlow\Lib\View;

$html = '<div class="hf-toolbar"><a class="btn-alt" href="zabbix.php?action=hostflow.list">'.htmlspecialchars(_('Back to diagrams')).'</a>'
	.'<select id="hf-cost-client" aria-label="'.htmlspecialchars(_('Client')).'"><option value="">'.htmlspecialchars(_('All clients')).'</option></select>'
	.'<span class="hf-spacer"></span><span id="hf-cost-status" class="hf-muted" role="status"></span>'
	.($data['is_super'] ? '<button type="button" class="btn-alt" id="hf-cost-refresh">'.htmlspecialchars(_('Refresh from AWS now')).'</button>' : '')
	.'<a class="btn-alt" href="zabbix.php?action=hostflow.cost.export">'.htmlspecialchars(_('Export CSV')).'</a></div>'
	.'<div id="hf-cost"><div class="hf-empty">'.htmlspecialchars(_('Loading cost…')).'</div></div>';

View::page(_('Host Flow cost'), $html, [
	'isSuper' => $data['is_super'],
	'stale' => $data['stale'],
	'csrf' => View::tokens(['hostflow.cost.refresh'])
], ['hostflow.cost.js']);
