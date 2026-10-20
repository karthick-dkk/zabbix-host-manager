<?php declare(strict_types = 0);
/** @var CView $this @var array $data */

use Modules\HostFlow\Lib\View;

$opts = '';
foreach ($data['all'] as $d) {
	$sel = in_array($d['id'], array_column($data['diagrams'], 'id'), true) ? ' selected' : '';
	$opts .= '<option value="'.htmlspecialchars($d['id']).'"'.$sel.'>'.htmlspecialchars($d['name']).'</option>';
}
$html = '<div class="hf-toolbar"><b id="hf-wall-title"></b><span class="hf-muted" id="hf-wall-next"></span><span class="hf-spacer"></span>'
	.'<select id="hf-wall-pick" multiple size="1" aria-label="'.htmlspecialchars(_('Diagrams on the wall')).'" style="min-width:220px">'.$opts.'</select>'
	.'<label class="hf-muted">'.htmlspecialchars(_('Every')).' <input type="number" id="hf-wall-every" min="10" max="600" value="'.(int) $data['every'].'" style="width:64px"> s</label>'
	.'<button type="button" class="btn-alt" id="hf-wall-full">'.htmlspecialchars(_('Full screen')).'</button></div>'
	.'<div class="hf-kiosk"><div class="hf-viewwrap"><div class="hf-viewer" id="hf-viewer"></div></div></div>'
	.'<div class="hf-legend" id="hf-legend"></div>';

View::page(_('Host Flow wall'), $html, [
	'wall' => array_values($data['diagrams']),
	'every' => $data['every'],
	'severityColors' => array_values($data['severity_colors']),
	'severityNames' => $data['severity_names'],
	'refresh' => $data['refresh'],
	'kiosk' => true,
	'csrf' => []
], ['drawio/js/viewer-static.min.js', 'hostflow.view.js']);
