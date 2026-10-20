<?php declare(strict_types = 0);
/** @var CView $this @var array $data */

use Modules\HostFlow\Lib\{Config, Icons, View};

$meta = $data['meta'];
$palette = '';
foreach ($data['components'] as $c) {
	$palette .= '<button type="button" data-comp="'.htmlspecialchars($c['id']).'" title="'.htmlspecialchars(_('Add a tier box')).'">'
		.'<img alt="" src="data:image/svg+xml;base64,'.base64_encode(Icons::svg($c['icon'], $c['color'])).'">'.htmlspecialchars($c['label']).'</button>';
}
foreach (['server' => 'Zabbix server', 'proxy' => 'Proxy', 'firewall' => 'Firewall', 'lb' => 'Load balancer', 'client' => 'Client'] as $icon => $label) {
	$palette .= '<button type="button" data-icon="'.$icon.'"><img alt="" src="data:image/svg+xml;base64,'.base64_encode(Icons::svg($icon, $icon === 'server' ? 'zbx' : 'neutral')).'">'.htmlspecialchars(_($label)).'</button>';
}
$groups = '';
foreach ($data['groups'] as $g) {
	$sel = in_array($g['usrgrpid'], $meta['acl'] ?? [], true) ? ' selected' : '';
	$groups .= '<option value="'.htmlspecialchars($g['usrgrpid']).'"'.$sel.'>'.htmlspecialchars($g['name']).'</option>';
}
$layouts = '';
foreach ($data['layouts'] as $l) {
	$layouts .= '<option value="'.$l.'"'.($l === $data['layout'] ? ' selected' : '').'>'.htmlspecialchars([
		'client' => _('Client flow (pipeline + hierarchy)'), 'hierarchy' => _('Client hierarchy'), 'dataflow' => _('Data flow'), 'monitoring' => _('Monitoring path'), 'combined' => _('Combined')][$l]).'</option>';
}

$html = '<div class="hf-toolbar">'
	.'<input type="text" id="hf-name" maxlength="128" placeholder="'.htmlspecialchars(_('Diagram name')).'" value="'.htmlspecialchars($meta['name'] ?? '').'">'
	.'<input type="text" id="hf-client" list="hf-clients" maxlength="128" placeholder="'.htmlspecialchars(_('Client group')).'" value="'.htmlspecialchars($data['client']).'">'
	.'<datalist id="hf-clients"></datalist>'
	.'<span class="hf-spacer"></span>'
	.'<span id="hf-save-status" class="hf-muted" role="status"></span>'
	.'<label class="hf-switch" title="'.htmlspecialchars(_('Save a few seconds after each change')).'"><input type="checkbox" id="hf-autosave"> '.htmlspecialchars(_('Auto-save')).'</label>'
	.'<button type="button" class="btn-alt" id="hf-discard" title="'.htmlspecialchars(_('Undo everything changed since you opened the editor')).'">'.htmlspecialchars(_('Discard')).'</button>'
	.'<button type="button" id="hf-save">'.htmlspecialchars(_('Save')).'</button>'
	.'<button type="button" class="btn-alt" id="hf-close">'.htmlspecialchars(_('Close')).'</button>'
	.'</div>'
	.'<div class="hf-editor">'
	.'<iframe id="hf-frame" title="'.htmlspecialchars(_('draw.io editor')).'"></iframe>'
	.'<aside class="hf-side">'
	.'<div class="hf-tabs" role="tablist">'
	.'<button type="button" data-tab="link" aria-selected="true">'.htmlspecialchars(_('Link')).'</button>'
	.'<button type="button" data-tab="shapes" aria-selected="false">'.htmlspecialchars(_('Shapes')).'</button>'
	.'<button type="button" data-tab="gen" aria-selected="false">'.htmlspecialchars(_('Generate')).'</button>'
	.'<button type="button" data-tab="more" aria-selected="false">'.htmlspecialchars(_('More')).'</button>'
	.'</div>'

	.'<div class="hf-pane on" data-pane="link">'
	.'<div class="hf-sel" id="hf-sel">'.htmlspecialchars(_('Select a shape or an arrow in the diagram.')).'</div>'
	.'<div id="hf-link-host"><h4>'.htmlspecialchars(_('Link to Zabbix host')).'</h4>'
	.'<div class="hf-field"><input type="search" id="hf-hostq" placeholder="'.htmlspecialchars(_('Search name or IP')).'"></div>'
	.'<div class="hf-results" id="hf-hosts"></div>'
	.'<div class="hf-field" style="margin-top:8px"><button type="button" class="btn-alt" id="hf-unlink">'.htmlspecialchars(_('Unlink host')).'</button></div></div>'
	.'<div id="hf-link-note"><h4>'.htmlspecialchars(_('Note and runbook')).'</h4>'
	.'<div class="hf-field"><label for="hf-note">'.htmlspecialchars(_('Note (shown in the tooltip and click menu)')).'</label><textarea id="hf-note" maxlength="2000"></textarea></div>'
	.'<div class="hf-field"><label for="hf-runbook">'.htmlspecialchars(_('Runbook link')).'</label><input type="text" id="hf-runbook" placeholder="https://"></div>'
	.'<button type="button" class="btn-alt" id="hf-note-save">'.htmlspecialchars(_('Apply to shape')).'</button></div>'
	.'<div id="hf-link-item" hidden><h4>'.htmlspecialchars(_('Value on this arrow')).'</h4>'
	.'<p class="hf-muted">'.htmlspecialchars(_('Show an item (EPS, traffic) on the arrow. With a second item, the arrow turns red when the second is lower than the first by more than the drop %.')).'</p>'
	.'<div class="hf-field"><label for="hf-itemhost">'.htmlspecialchars(_('Host')).'</label><input type="search" id="hf-itemhost" placeholder="'.htmlspecialchars(_('Search host')).'"></div>'
	.'<div class="hf-results" id="hf-itemhosts"></div>'
	.'<div class="hf-field"><label for="hf-item">'.htmlspecialchars(_('Item sent (source)')).'</label><select id="hf-item"><option value="">—</option></select></div>'
	.'<div class="hf-field"><label for="hf-item2">'.htmlspecialchars(_('Item received (target, optional)')).'</label><select id="hf-item2"><option value="">—</option></select></div>'
	.'<div class="hf-field"><label for="hf-drop">'.htmlspecialchars(_('Drop % that turns the arrow red')).'</label><input type="number" id="hf-drop" min="0" max="100" step="1" value="20"></div>'
	.'<button type="button" class="btn-alt" id="hf-item-save">'.htmlspecialchars(_('Apply to arrow')).'</button></div>'
	.'<div class="hf-status" id="hf-link-status" role="status"></div>'
	.'</div>'

	.'<div class="hf-pane" data-pane="shapes"><h4>'.htmlspecialchars(_('Zabbix SIEM shapes')).'</h4>'
	.'<div class="hf-palette" id="hf-palette">'.$palette.'</div>'
	.'<p class="hf-muted" style="margin-top:10px">'.htmlspecialchars(_('Shapes are added in the middle of the view. Select a shape, then link it to a host on the Link tab.')).'</p></div>'

	.'<div class="hf-pane" data-pane="gen"><h4>'.htmlspecialchars(_('Generate from Zabbix')).'</h4>'
	.'<div class="hf-field"><label for="hf-layout">'.htmlspecialchars(_('Layout')).'</label><select id="hf-layout">'.$layouts.'</select></div>'
	.'<div class="hf-field"><label for="hf-collapse">'.htmlspecialchars(_('Collapse tiers with more than (0 = never)')).'</label><input type="number" id="hf-collapse" min="0" max="500" value="0"></div>'
	.'<div class="hf-field"><label><input type="checkbox" id="hf-disabled" checked> '.htmlspecialchars(_('Include disabled hosts')).'</label></div>'
	.'<button type="button" id="hf-generate">'.htmlspecialchars(_('Replace diagram with a new one')).'</button> '
	.'<button type="button" class="btn-alt" id="hf-update" style="margin-top:6px">'.htmlspecialchars(_('Update from Zabbix')).'</button>'
	.'<p class="hf-muted" style="margin-top:8px">'.htmlspecialchars(_('Update adds new hosts and marks removed ones with a dashed outline. It never deletes anything you drew.')).'</p>'
	.'<div class="hf-status" id="hf-gen-status" role="status"></div></div>'

	.'<div class="hf-pane" data-pane="more">'
	.'<h4>'.htmlspecialchars(_('Access')).'</h4>'
	.'<div class="hf-field"><label for="hf-acl">'.htmlspecialchars(_('Only these user groups can open it (none = everyone with access)')).'</label><select id="hf-acl" multiple size="5">'.$groups.'</select></div>'
	.($meta ? '<h4 style="margin-top:12px">'.htmlspecialchars(_('Copy')).'</h4>'
		.'<button type="button" class="btn-alt" id="hf-copy">'.htmlspecialchars(_('Duplicate this diagram')).'</button>'
		.'<h4 style="margin-top:12px">'.htmlspecialchars(_('Zabbix integration')).'</h4>'
		.'<button type="button" class="btn-alt" id="hf-deps">'.htmlspecialchars(_('Suggest trigger dependencies')).'</button> '
		.'<button type="button" class="btn-alt" id="hf-services" style="margin-top:6px">'.htmlspecialchars(_('Create services and SLA')).'</button>'
		.'<h4 style="margin-top:12px">'.htmlspecialchars(_('Danger zone')).'</h4>'
		.'<button type="button" class="btn-alt" id="hf-delete">'.htmlspecialchars(_('Delete diagram')).'</button>' : '')
	.'<div class="hf-status" id="hf-more-status" role="status"></div></div>'
	.'</aside></div>';

$cfg = Config::get();
View::page('', $html, [
	'meta' => $meta,
	'client' => $data['client'],
	'sysmapid' => $data['sysmapid'],
	'components' => $data['components'],
	'icons' => array_combine(array_column($data['components'], 'id'), array_map(static fn($c) => Icons::styleImage($c['icon'], $c['color']), $data['components'])),
	'iconImages' => array_combine(Icons::names(), array_map(static fn($i) => Icons::styleImage($i, $i === 'server' ? 'zbx' : 'neutral'), Icons::names())),
	'colors' => Icons::COLORS,
	'hostLabel' => $cfg['labels']['host'],
	'drawioUrl' => 'modules/hostflow/assets/drawio/index.html?embed=1&proto=json&spin=1&stealth=1&noExitBtn=1&noSaveBtn=1&saveAndExit=0&configure=1&ui=kennedy&libraries=1',
	'csrf' => View::tokens(['hostflow.save', 'hostflow.generate', 'hostflow.delete', 'hostflow.mapimport',
		'hostflow.deps.apply', 'hostflow.services'])
], ['hostflow.edit.js'], 'hf-full');
