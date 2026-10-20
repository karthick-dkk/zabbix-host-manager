<?php declare(strict_types = 0);
/** @var CView $this @var array $data */

use Modules\HostFlow\Lib\View;

$html = '<div class="hf-toolbar"><a class="btn-alt" href="zabbix.php?action=hostflow.list">'.htmlspecialchars(_('Back to diagrams')).'</a>'
	.'<span class="hf-spacer"></span><span id="hf-dirty" class="hf-muted" role="status"></span>'
	.'<button type="button" id="hf-save-settings">'.htmlspecialchars(_('Save settings')).'</button></div>'
	.'<div id="hf-settings"></div>';

View::page(_('Host Flow settings'), $html, [
	'settings' => $data['settings'],
	'icons' => $data['icons'],
	'colors' => $data['colors'],
	'glyphs' => $data['glyphs'],
	'dataDir' => $data['data_dir'],
	'dirOk' => $data['dir_ok'],
	'dirMsg' => $data['dir_msg'],
	'scriptInstalled' => $data['script_installed'],
	'drawioVersion' => $data['drawio_version'],
	'csrf' => View::tokens(['hostflow.settings.update', 'hostflow.script.install', 'hostflow.aws.test', 'hostflow.cost.refresh'])
], ['hostflow.settings.js']);
