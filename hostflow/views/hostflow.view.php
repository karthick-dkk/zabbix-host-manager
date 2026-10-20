<?php declare(strict_types = 0);
/** @var CView $this @var array $data */

use Modules\HostFlow\Lib\View;

$meta = $data['meta'];
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$b = static fn($id, $label, $extra = '') => '<button type="button" class="btn-alt" id="'.$id.'"'.$extra.'>'.htmlspecialchars($label).'</button>';

$viewer = '<div id="hf-changes"></div>'
	.'<div class="hf-viewwrap"><div class="hf-viewer" id="hf-viewer"></div></div>'
	.'<div class="hf-capacity" id="hf-capacity" hidden></div>'
	.'<div class="hf-legend" id="hf-legend"></div>';

if ($data['kiosk']) {
	$html = $viewer;
}
else {
	$html = '<div class="hf-app" id="hf-app">'
		// Left: flows and details of the selected one
		.'<aside class="hf-nav" id="hf-nav" aria-label="'.$e(_('Flows')).'">'
			.'<div class="hf-nav-head">'
				.'<div class="hf-nav-title"><b>'.$e(_('Host Flow')).'</b>'
					.($data['can_edit'] ? '<button type="button" id="hf-new">'.$e(_('New')).'</button>' : '')
				.'</div>'
				.'<div class="hf-navnote" id="hf-navnote" role="status"></div>'
				.'<input type="search" id="hf-find" placeholder="'.$e(_('Find a host or IP')).'" value="'.$e($data['q']).'" aria-controls="hf-find-results">'
				.'<div class="hf-nav-links">'
					.($data['can_edit'] ? '<button type="button" class="btn-link" id="hf-import">'.$e(_('Import map')).'</button>' : '')
					.'<button type="button" class="btn-link" id="hf-wall">'.$e(_('NOC wall')).'</button>'
					.'<label class="hf-switch" title="'.$e(_('Show problem counts in the list and the problem list in the details')).'"><input type="checkbox" id="hf-showprob" checked> '.$e(_('Problems')).'</label>'
					.($data['cost'] ? '<button type="button" class="btn-link" id="hf-cost">'.$e(_('Cost')).'</button>' : '')
					.($data['is_super'] ? '<button type="button" class="btn-link" id="hf-settings">'.$e(_('Settings')).'</button>' : '')
				.'</div>'
			.'</div>'
			.'<div id="hf-find-results" class="hf-find-results" role="region" aria-live="polite"></div>'
			.'<div class="hf-flows" id="hf-flows" role="list"><div class="hf-empty">'.$e(_('Loading flows…')).'</div></div>'
			.'<div class="hf-details" id="hf-details"></div>'
		.'</aside>'
		// Right: the selected flow, live
		.'<section class="hf-main" aria-label="'.$e(_('Live flow')).'">'
			.'<div class="hf-toolbar hf-mainbar">'
				.'<button type="button" class="btn-alt hf-navtoggle" id="hf-navtoggle" aria-controls="hf-nav" aria-expanded="true" title="'.$e(_('Hide the flow list')).'">⟨</button>'
				.'<h2 class="hf-title" id="hf-title">'.$e($meta['name'] ?? '').'</h2>'
				.'<div class="hf-views" id="hf-views" role="tablist" aria-label="'.$e(_('Client flow views')).'" hidden></div>'
				.'<span id="hf-kind"></span>'
				.'<span class="hf-spacer"></span>'
				.'<div class="hf-timetravel"><label for="hf-time" class="hf-muted">'.$e(_('Time travel')).'</label>'
					.'<input type="range" id="hf-time" min="-1440" max="0" step="5" value="0" aria-describedby="hf-at" title="'.$e(_('Time travel: drag left to see the state in the past')).'"><span class="hf-at" id="hf-at">'.$e(_('now')).'</span>'
					.'<select id="hf-span" aria-label="'.$e(_('Time travel range')).'"><option value="1440">24 h</option><option value="10080">7 d</option><option value="43200">30 d</option></select></div>'
				.'<span id="hf-refreshed" class="hf-muted" role="status"></span>'
				.$b('hf-refresh', _('Refresh'))
				.($data['cost'] ? $b('hf-costbtn', _('Show cost'), ' aria-pressed="false"') : '')
				.'<span class="hf-pop-wrap"><button type="button" class="btn-alt" id="hf-hide-btn" aria-expanded="false" aria-controls="hf-hide-pop">'.$e(_('Show / hide')).'</button>'
					.'<div class="hf-pop" id="hf-hide-pop" hidden></div></span>'
				.'<span class="hf-pop-wrap"><button type="button" class="btn-alt" id="hf-dl-btn" aria-expanded="false" aria-controls="hf-dl-pop">'.$e(_('Download')).'</button>'
					.'<div class="hf-pop" id="hf-dl-pop" hidden>'
						.'<b>'.$e(_('Download this view')).'</b>'
						.'<fieldset><legend>'.$e(_('Content')).'</legend>'
							.'<label><input type="radio" name="hf-dl-c" value="ip" checked> '.$e(_('With IP and cost')).'<small>'.$e(_('Host names, IPs and monthly cost')).'</small></label>'
							.'<label><input type="radio" name="hf-dl-c" value="plain"> '.$e(_('Plain')).'<small>'.$e(_('Tiers, host names and arrows only')).'</small></label>'
						.'</fieldset>'
						.'<label class="hf-dl-row">'.$e(_('Format')).' <select id="hf-dl-fmt">'
							.'<option value="png">PNG image</option><option value="svg">SVG</option><option value="drawio">draw.io (.drawio)</option><option value="csv">CSV (host list)</option>'
						.'</select></label>'
						.'<div class="hf-dl-acts"><button type="button" id="hf-dl-go">'.$e(_('Download')).'</button><button type="button" class="btn-alt" id="hf-dl-cancel">'.$e(_('Cancel')).'</button></div>'
					.'</div></span>'
				.$b('hf-kiosk', _('Full screen'))
				.($data['can_edit'] ? '<button type="button" id="hf-edit">'.$e(_('Edit')).'</button>' : '')
			.'</div>'
			.$viewer
		.'</section>'
	.'</div>';
}

View::page($data['kiosk'] ? ($meta['name'] ?? '') : '', $html, [
	'meta' => $meta ? ['id' => $meta['id'], 'name' => $meta['name'], 'client' => $meta['client'] ?? ''] : null,
	'canEdit' => $data['can_edit'],
	'isSuper' => $data['is_super'],
	'cost' => $data['cost'],
	'costStale' => $data['cost_stale'],
	'focus' => $data['focus'],
	'hostid' => $data['hostid'],
	'kiosk' => $data['kiosk'],
	'autoFlows' => $data['auto_flows'],
	'tierColors' => \Modules\HostFlow\Lib\Icons::COLORS,
	'layouts' => $data['layouts'],
	'severityColors' => array_values($data['severity_colors']),
	'severityNames' => $data['severity_names'],
	'refresh' => $data['refresh'],
	'csrf' => View::tokens(['hostflow.seen', 'hostflow.cost.refresh', 'hostflow.autoflows', 'hostflow.delete', 'hostflow.mapimport'])
], $data['kiosk'] ? ['drawio/js/viewer-static.min.js', 'hostflow.view.js'] : ['drawio/js/viewer-static.min.js', 'hostflow.list.js', 'hostflow.view.js'],
	$data['kiosk'] ? '' : 'hf-full');
