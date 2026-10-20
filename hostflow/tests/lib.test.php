<?php
require __DIR__.'/bootstrap.php';

use Modules\HostFlow\Lib\{Config, ConflictException, Crypto, DiagramStore, DrawioXml, Flow, Generator, Storage};

$tmp = sys_get_temp_dir().'/hf-test-'.bin2hex(random_bytes(4));
Storage::setDir($tmp);

// --- DrawioXml -------------------------------------------------------------
$model = '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
	.'<object id="2" label="&lt;b onclick=&quot;x()&quot;&gt;Hi&lt;/b&gt;&lt;script&gt;alert(1)&lt;/script&gt;" zbx_hostid="10101" link="javascript:alert(1)" onmouseover="bad()">'
	.'<mxCell style="rounded=1" vertex="1" parent="1"><mxGeometry x="1" y="2" width="3" height="4" as="geometry"/></mxCell></object>'
	.'<UserObject id="3" label="ok" zbx_hostid="10102" zbx_runbook="https://wiki/x"><mxCell vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell></UserObject>'
	.'</root></mxGraphModel>';
$clean = DrawioXml::sanitize($model);
ok(strpos($clean, '<mxfile') === 0, 'bare model wrapped in mxfile');
ok(strpos($clean, 'onclick') === false && strpos($clean, 'onmouseover') === false, 'event handlers stripped');
ok(stripos($clean, 'script') === false, 'script tag stripped from label');
ok(strpos($clean, 'javascript:') === false, 'javascript link removed');
ok(strpos($clean, 'https://wiki/x') !== false, 'safe runbook link kept');
ok(DrawioXml::linkedHostIds($clean) === ['10101', '10102'], 'linked host ids');
throws(fn() => DrawioXml::sanitize('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><mxfile>&e;</mxfile>'), 'DTD rejected', 'DTD');
throws(fn() => DrawioXml::sanitize('<html/>'), 'wrong root rejected', 'not a draw.io');
throws(fn() => DrawioXml::sanitize(str_repeat('a', DrawioXml::MAX_BYTES + 1)), 'size limit', '5 MB');
throws(fn() => DrawioXml::sanitize('<mxfile><diagram'), 'invalid xml', 'not valid XML');
// HTML label sanitizer: regex-bypass payloads must not survive
foreach ([
	'<img/src=x/onerror=alert(1)>',
	'<svg><animate onbegin=alert(1) attributeName=x dur=1s>',
	'<a href="jav&#x09;ascript:alert(1)">x</a>',
	'<a href=" JavaScript:alert(1)">x</a>',
	'<div style="background:url(javascript:alert(1))">x</div>',
	'<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
	'<scr<script>ipt>alert(1)</script>',
	'<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
	'<details open ontoggle=alert(1)>',
	'<!--><img src=x onerror=alert(1)>-->'
] as $p) {
	$c = DrawioXml::cleanHtml($p);
	ok(!preg_match('/<[^>]*\son|javascript:|<(script|svg|iframe|math|style)/i', $c), 'label sanitizer blocks '.$p.' => '.$c);
}
ok(DrawioXml::cleanHtml('<b>ES</b><br><font color="#ff0000">10.0.0.1</font>') === '<b>ES</b><br><font color="#ff0000">10.0.0.1</font>', 'formatting labels untouched');
ok(DrawioXml::cleanHtml('a < b & c') === 'a < b & c', 'plain-text label untouched');
ok(strpos(DrawioXml::cleanHtml('<a href="https://wiki/x" onclick="y()">w</a>'), 'href="https://wiki/x"') !== false, 'safe link kept, handler dropped');
// link attributes: allowlist, checked with the characters browsers ignore removed
$lk = static function (string $link): bool {
	$x = DrawioXml::sanitize('<mxGraphModel><root><mxCell id="0"/><UserObject id="2" label="l" link="'.strtr(htmlspecialchars($link, ENT_QUOTES), ["\t" => '&#9;', "\n" => '&#10;']).'"><mxCell vertex="1" parent="0"><mxGeometry as="geometry"/></mxCell></UserObject></root></mxGraphModel>');
	return strpos($x, ' link=') !== false;
};
foreach (["java\tscript:alert(1)", "java\nscript:alert(1)", " \x7fjavascript:alert(1)", "JaVaScRiPt:alert(1)", "vbscript:x", "data:text/html,<script>x</script>",
	"data:action/json,{}", "file:///etc/passwd", "ms-settings:x"] as $l) {
	ok(!$lk($l), 'link dropped: '.addcslashes($l, "\0..\37"));
}
foreach (['https://wiki/x', 'http://a', 'mailto:a@b.c', '#anchor', '/zabbix.php?action=x', 'zabbix.php?a=b', 'data:page/id,abc'] as $l) {
	ok($lk($l), 'link kept: '.$l);
}
// compressed page (draw.io deflate+base64 of urlencoded model)
$compressed = '<mxfile><diagram id="a" name="P">'.base64_encode(gzdeflate(rawurlencode($model))).'</diagram></mxfile>';
ok(DrawioXml::linkedHostIds(DrawioXml::sanitize($compressed)) === ['10101', '10102'], 'compressed page expanded');

// --- Config ----------------------------------------------------------------
$cfg = Config::get();
ok(count($cfg['components']) === 10 && $cfg['components'][2]['match']['groups'] === [], 'defaults: 10 components (with ULM), VPN unmapped');
ok(count($cfg['flow']) === 9, 'defaults: approved 9 arrows');
$badf = $cfg; $badf['flow_templates'][0]['edges'][] = ['from' => 'fwd', 'to' => 'nope'];
throws(fn() => Config::validate($badf), 'unknown flow target', 'unknown component');
$bad = $cfg; $bad['components'][] = $cfg['components'][0];
throws(fn() => Config::validate($bad), 'duplicate component', 'used twice');
$aws = $cfg; $aws['aws']['accounts'] = [['id' => '123456789012', 'auth' => 'key', 'key_id' => 'AKIAABCDEFGHIJKLMNOP', 'secret_key' => 's3cr3t', 'regions' => ['ap-south-1', 'bogus']]];
$saved = Config::save($aws);
ok(isset($saved['aws']['accounts'][0]['secret_enc']) && !isset($saved['aws']['accounts'][0]['secret_key']), 'secret encrypted on save');
ok($saved['aws']['accounts'][0]['regions'] === ['ap-south-1'], 'invalid region dropped');
ok(strpos(file_get_contents("$tmp/settings.json"), 's3cr3t') === false, 'plaintext secret never written');
ok(Crypto::decrypt($saved['aws']['accounts'][0]['secret_enc']) === 's3cr3t', 'secret decrypts');
$keep = $saved; unset($keep['aws']['accounts'][0]['secret_enc']); $keep['aws']['accounts'][0]['secret_key'] = '';
ok(Crypto::decrypt(Config::save($keep)['aws']['accounts'][0]['secret_enc']) === 's3cr3t', 'blank secret keeps stored one');
ok(!isset(Config::forBrowser()['aws']['accounts'][0]['secret_enc']) && Config::forBrowser()['aws']['accounts'][0]['has_secret'], 'browser copy has no secret');
throws(fn() => Config::validate(array_replace($cfg, ['aws' => ['accounts' => [['id' => '1', 'auth' => 'instance']]] + $cfg['aws']])), 'bad account id', '12-digit');
ok(substr(sprintf('%o', fileperms("$tmp/keys/secret.key")), -3) === '600', 'key file is 0600');

// --- Flow ------------------------------------------------------------------
$cols = Flow::columns($cfg['components'], $cfg['flow']);
ok($cols['fwd'] === 0 && $cols['vpn'] === 1 && $cols['parser'] === 2 && $cols['es'] === 3, 'pipeline columns: Forwarder, VPN, Parser, Elasticsearch');
ok($cols['aiml'] === $cols['es'], 'ES ⇄ AIML share a column');
ok($cols['soar'] === $cols['es'] - 1, 'SOAR sits just before ES');
ok($cols['ui'] > $cols['engine'] && $cols['engine'] > $cols['es'], 'UI after Engine after ES');
$down = Flow::downstream('es', $cfg['flow']); sort($down);
ok($down === ['aiml', 'engine', 'ui'], 'downstream of ES');
ok(Flow::downstream('ui', $cfg['flow']) === [], 'nothing downstream of UI');

// --- Generator ---------------------------------------------------------------
$h = static fn($id, $name, $comp, $status = 0) => ['hostid' => $id, 'host' => $name, 'name' => $name, 'status' => $status, 'ip' => '10.0.0.'.$id,
	'component' => $comp, 'monitored_by' => 0, 'proxyid' => '0'];
$m = ['client' => ['name' => 'ACME', 'groupid' => '9'], 'cluster' => [$h('1', 'acme-es-cluster', null)],
	'hosts' => [$h('11', 'acme-fwd-01', 'fwd'), $h('12', 'acme-fwd-02', 'fwd'), $h('21', 'acme-parser-01', 'parser'), $h('31', 'acme-es-01', 'es'), $h('41', 'acme-soar-01', 'soar', 1)],
	'proxies' => [], 'cost_only' => [['id' => 'vpn1', 'name' => 'AWS S2S VPN', 'component' => 'vpn', 'type' => 'vpn_connection']]];
foreach (Generator::LAYOUTS as $layout) {
	$xml = (new Generator($cfg))->build($m, $layout);
	$again = DrawioXml::sanitize($xml);
	$ids = DrawioXml::linkedHostIds($again); sort($ids);
	$expect = $layout === 'dataflow' ? ['11', '12', '21', '31', '41'] : ['1', '11', '12', '21', '31', '41'];
	ok($ids === $expect, "$layout: all hosts linked (".implode(',', $ids).')');
	ok(strpos($xml, 'placeholders="1"') !== false, "$layout: placeholders on");
	ok(strpos($xml, 'data:image/svg+xml,') !== false || $layout === 'monitoring', "$layout: icons embedded");
}
// update from Zabbix: one host gone, one new
$xml = (new Generator($cfg))->build($m, 'dataflow');
$m2 = $m; $m2['hosts'] = array_values(array_filter($m['hosts'], fn($x) => $x['hostid'] !== '12')); $m2['hosts'][] = $h('13', 'acme-fwd-03', 'fwd');
[$upd, $added, $removed] = (new Generator($cfg))->update($xml, $m2, 'dataflow');
ok($added === ['13'] && $removed === ['12'], 'update: added 13, removed 12 got '.json_encode([$added, $removed]));
$ids = DrawioXml::linkedHostIds($upd); sort($ids);
ok(in_array('12', $ids, true) && in_array('13', $ids, true), 'update keeps removed host (marked), adds new');
ok(strpos($upd, 'zbx_removed="1"') !== false, 'removed host marked');
$allIds = []; preg_match_all('/\sid="([^"]+)"/', $upd, $mm); $allIds = $mm[1];
ok(count($allIds) === count(array_unique($allIds)), 'update: no duplicate cell ids');
[$upd2, $a2, $r2] = (new Generator($cfg))->update($upd, $m2, 'dataflow');
ok($a2 === [] && $r2 === [], 'second update is a no-op');

// --- auto flows settings
$cf = Config::validate(array_replace(Config::defaults(), ['auto_flows' => ['enabled' => 0, 'layout' => 'bogus', 'sync_min' => 0]]));
ok($cf['auto_flows'] === ['enabled' => false, 'layout' => 'both', 'sync_min' => 1, 'v' => 2], 'auto_flows validated');
ok(Config::defaults()['auto_flows']['enabled'] === true && Config::defaults()['auto_flows']['layout'] === 'both', 'client flows on by default: pipeline and hierarchy as separate flows');
ok(\Modules\HostFlow\Lib\AutoFlows::kinds('both') === ['pipeline' => 'dataflow', 'hierarchy' => 'hierarchy'], 'both = two flows per client');
ok(\Modules\HostFlow\Lib\AutoFlows::kinds('client') === ['pipeline' => 'client'] && \Modules\HostFlow\Lib\AutoFlows::kinds('hierarchy') === ['hierarchy' => 'hierarchy'], 'single-layout settings give one flow');
ok(\Modules\HostFlow\Lib\AutoFlows::kindOf(['layout' => 'client']) === 'pipeline' && \Modules\HostFlow\Lib\AutoFlows::kindOf(['layout' => 'hierarchy']) === 'hierarchy'
	&& \Modules\HostFlow\Lib\AutoFlows::kindOf(['auto_kind' => 'hierarchy', 'layout' => 'dataflow']) === 'hierarchy', 'older flows get a kind from their layout');
$v1 = Config::migrate(['flow_templates' => [], 'auto_flows' => ['enabled' => true, 'layout' => 'client', 'sync_min' => 10]]);
ok($v1['auto_flows']['layout'] === 'both' && $v1['auto_flows']['v'] === 2, 'saved combined layout becomes two separate flows');
$v2 = Config::migrate(['flow_templates' => [], 'auto_flows' => ['enabled' => true, 'layout' => 'client', 'sync_min' => 10, 'v' => 2]]);
ok($v2['auto_flows']['layout'] === 'client', 'a combined layout chosen after the split is kept');

// --- flow templates
$d = Config::validate(Config::defaults());
ok(array_column($d['flow_templates'], 'id') === ['siem', 's3-ulm'] && $d['default_template'] === 'siem', 'built-in templates, SIEM is default');
ok($d['flow'] === $d['flow_templates'][0]['edges'], "'flow' mirrors the default template");
$t = Config::template('s3-ulm', $d);
ok(array_slice(array_column($t['components'], 'id'), 0, 6) === ['fwd', 'parser', 'es', 'ui', 's3', 'ulm'], 'template tiers come first, in template order');
ok($t['components'][0]['queue'] === 'both' && $t['components'][1]['queue'] === 'both' && $t['components'][4]['note'] === 'raw log / enriched copy', 'tier queues and notes');
$pairs = array_map(fn($e) => $e['from'].'>'.$e['to'], $t['edges']);
ok($pairs === ['fwd>parser', 'parser>es', 'es>ui', 'parser>s3', 's3>ulm', 'ulm>ui'], 'S3 + ULM arrows: '.implode(' ', $pairs));
ok(Config::template('nope', $d)['template'] === 'siem' && Config::template(null, $d)['template'] === 'siem', 'unknown or missing template uses the default');
$cols = Flow::columns($t['components'], $t['edges']);
ok($cols['fwd'] < $cols['parser'] && $cols['parser'] < $cols['es'] && $cols['es'] < $cols['ui'] && $cols['parser'] < $cols['s3'] && $cols['s3'] < $cols['ulm'] && $cols['ulm'] < $cols['ui'], 'pipeline columns left to right '.json_encode($cols));
$bad = Config::defaults(); $bad['flow_templates'][1]['edges'][] = ['from' => 'es', 'to' => 'ghost'];
throws(fn() => Config::validate($bad), 'template arrow to unknown component rejected', 'S3 + ULM pipeline');
$bad = Config::defaults(); $bad['flow_templates'][] = $bad['flow_templates'][0];
throws(fn() => Config::validate($bad), 'duplicate template id rejected', 'used twice');
$old = ['components' => array_values(array_filter(Config::defaults()['components'], fn($c) => $c['id'] !== 'ulm')),
	'flow' => [['from' => 'fwd', 'to' => 'es', 'label' => 'custom']], 'auto_flows' => ['layout' => 'hierarchy']];
$mig = Config::validate(array_replace(Config::defaults(), Config::migrate($old)));
ok($mig['flow_templates'][0]['edges'][0]['label'] === 'custom' && count($mig['flow_templates'][0]['edges']) === 1, 'migration: saved pipeline becomes the SIEM template');
ok(in_array('ulm', array_column($mig['components'], 'id'), true) && in_array('s3-ulm', array_column($mig['flow_templates'], 'id'), true), 'migration: S3 + ULM template and ULM component added');
ok($mig['auto_flows']['layout'] === 'both', 'migration: client flows become separate pipeline and hierarchy flows');
ok(Config::migrate(['schema' => 3, 'flow_templates' => [], 'auto_flows' => ['v' => 2]]) === ['schema' => 3, 'flow_templates' => [], 'auto_flows' => ['v' => 2]], 'migration runs once');
// 20 Oct design applied to settings saved before it (as on the test server)
$old20 = ['flow_templates' => [
	['id' => 'siem', 'name' => 'SIEM pipeline', 'tiers' => [], 'notes' => [], 'edges' => [
		['from' => 'fwd', 'to' => 'parser', 'label' => 'logs'], ['from' => 'parser', 'to' => 'vpn', 'label' => 'events'],
		['from' => 'vpn', 'to' => 'es', 'label' => 'events'], ['from' => 'es', 'to' => 'ui', 'label' => 'search'],
		['from' => 'vpn', 'to' => 's3', 'label' => 'writes'], ['from' => 's3', 'to' => 'ulm', 'label' => 'read']]],
	['id' => 's3-ulm', 'name' => 'S3 + ULM pipeline', 'tiers' => [], 'notes' => ['fwd' => 'Redis/Kafka', 's3' => 'raw log'], 'edges' => []]],
	'components' => array_map(fn($c) => $c + [], Config::defaults()['components'])];
foreach ($old20['components'] as &$oc) {
	if ($oc['id'] === 'fwd') { $oc['label'] = 'FWD'; }
	if ($oc['id'] === 'es') { $oc['label'] = 'ES'; }
	if ($oc['id'] === 'ui') { $oc['kind'] = 'hosts'; }
	if ($oc['id'] === 'vpn') { $oc['small'] = false; }
}
unset($oc);
$m20 = Config::validate(array_replace(Config::defaults(), Config::migrate($old20)));
$cm = array_column($m20['components'], null, 'id');
ok($cm['fwd']['label'] === 'Forwarder' && $cm['es']['label'] === 'Elasticsearch' && $cm['ui']['kind'] === 'static' && $cm['vpn']['small'] === true, 'migration: names, static UI, VPN as icon');
$sm = array_map(fn($e) => $e['from'].'>'.$e['to'].(!empty($e['both']) ? '<>' : ''), $m20['flow_templates'][0]['edges']);
ok($sm === ['fwd>vpn<>', 'vpn>parser', 'parser>es', 'es>ui', 'parser>s3', 's3>ulm'], 'migration: SIEM is Forwarder ⇄ VPN → Parser → Elasticsearch, S3 from Parser: '.implode(' ', $sm));
ok($m20['flow_templates'][0]['queues'] === ['fwd' => 'both', 'parser' => 'both'] && $m20['flow_templates'][1]['queues'] === ['fwd' => 'both']
	&& $m20['flow_templates'][1]['notes'] === ['s3' => 'raw log'], 'migration: Redis/Kafka notes become queues');

// --- generator with a template: S3 + ULM client
$mt = $m;
$mt['flow'] = $t;
$mt['hosts'][] = $h('51', 'acme-ulm-01', 'ulm');
$x = (new Generator($cfg))->build($mt, 'client');
foreach (['Forwarder', 'Parser', 'Elasticsearch', 'UI', 'S3', 'ULM'] as $lbl) {
	ok(preg_match('/label="'.$lbl.'( ·|")/', $x) === 1, "client layout: $lbl tier drawn");
}
ok(preg_match_all('/Redis (·|&#xB7;) Kafka/u', $x) >= 2, 'client layout: queue shown on Forwarder and Parser');
// SIEM pipeline: VPN as a pass-through icon, two-way pairs as one double-headed line, static UI
$ms = $m;
$ms['flow'] = Config::template('siem', $d);
$ms['hosts'][] = $h('61', 'acme-vpn-01', 'vpn');
$xs = (new Generator($cfg))->build($ms, 'dataflow');
ok(preg_match('/label="VPN"[^>]*zbx_component="vpn"[^>]*zbx_kind="component"/', $xs) === 1 && strpos($xs, 'arcSize=50') !== false, 'VPN drawn as a small round icon');
ok(strpos($xs, 'zbx_hostid="61"') === false, 'VPN hosts are not drawn as boxes');
ok(preg_match('/label="logs via VPN"/', $xs) === 1, 'the line out of VPN reads "logs via VPN"');
ok(preg_match('/label="reads \/ writes back"/', $xs) === 1 && substr_count($xs, 'startArrow=block') >= 2, 'two-way pairs are one line with heads at both ends');
ok(preg_match('/endArrow=none/', $xs) === 1, 'no arrowhead points at the VPN icon');
ok(preg_match('/label="UI"[^>]*zbx_component="ui"/', $xs) === 1 && strpos($xs, 'not mapped yet: Settings') === false, 'UI is a static tier without a placeholder');
ok(strpos($xs, 'Forwarders') === false, 'the mapped group name is not repeated under the tier name');
ok(strpos($x, 'raw + enriched copy') !== false && strpos($x, 'add buckets in Settings') !== false, 'client layout: parser → S3 arrow, empty S3 tier kept');
ok(strpos($x, '>PIPELINE<') === false && strpos($x, 'value="PIPELINE"') !== false && strpos($x, 'CLIENT HIERARCHY') !== false, 'client layout: pipeline above the client hierarchy');
ok(stripos($x, 'S3 + ULM') === false, 'template name is not drawn inside the diagram');
$ids = DrawioXml::linkedHostIds(DrawioXml::sanitize($x)); sort($ids);
ok($ids === ['1', '11', '12', '21', '31', '41', '51'], 'client layout: every host linked');
ok(strpos($x, '>events<') === false && preg_match('/label="query"/', $x) === 0, 'client layout: no SIEM-only arrows (VPN, Engine)');

// --- security: placeholders, redaction, escaped labels
$ph = DrawioXml::sanitize('<mxGraphModel><root><mxCell id="0"/><object id="2" label="%name%" placeholders="1" name="&lt;img src=x onerror=alert(1)&gt;" ip="&lt;svg onload=alert(2)&gt;"><mxCell vertex="1" parent="0"><mxGeometry as="geometry"/></mxCell></object></root></mxGraphModel>');
ok(stripos($ph, 'onerror') === false && stripos($ph, 'onload') === false && stripos($ph, '&lt;svg') === false, 'attributes used as %placeholders% are cleaned too');
$rx = DrawioXml::redactHosts('<mxfile><diagram id="p"><mxGraphModel><root><mxCell id="0"/><object id="a" label="secret-db 10.9.9.9" zbx_hostid="5" name="secret-db" ip="10.9.9.9" zbx_note="root pw"><mxCell vertex="1" parent="0"/></object><object id="b" label="%name%" zbx_hostid="6" name="web" ip="10.0.0.6"><mxCell vertex="1" parent="0"/></object></root></mxGraphModel></diagram></mxfile>', ['6']);
ok(strpos($rx, 'secret-db') === false && strpos($rx, '10.9.9.9') === false && strpos($rx, 'root pw') === false && strpos($rx, 'no access') !== false, 'hosts the viewer cannot read are redacted');
ok(strpos($rx, 'name="web"') !== false && strpos($rx, '10.0.0.6') !== false, 'hosts the viewer can read are kept');
$rx2 = DrawioXml::redactHosts('<mxfile><diagram id="p"><mxGraphModel><root><mxCell id="0"/><object id="a" label="x" zbx_hostid="5" link="https://db.internal/secret" zbx_runbook="https://wiki/secret" owner="alice" custom_ip="10.9.9.9"><mxCell vertex="1" parent="0"/></object></root></mxGraphModel></diagram></mxfile>', []);
ok(strpos($rx2, 'secret') === false && strpos($rx2, 'alice') === false && strpos($rx2, '10.9.9.9') === false && strpos($rx2, 'zbx_hostid="5"') !== false, 'redaction keeps only id, host id and tier on hidden hosts');
$evil = $m;
$evil['client']['name'] = '<img src=x onerror=alert(3)>';
$ex = DrawioXml::sanitize((new Generator($cfg))->build($evil, 'hierarchy'));
// Escaped once in the XML (&lt;img) would be a live tag in an HTML label; escaped twice (&amp;lt;img) is text.
ok(!preg_match('/&lt;[^&]*onerror/i', $ex) && strpos($ex, '<img') === false && strpos($ex, '&amp;lt;img') !== false, 'client group name is escaped in labels; no handler survives anywhere');

// --- hidden hosts: lines redacted, saves cannot change or lose them, updates never mark them removed
$two = '<mxfile><diagram id="p"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
	.'<object id="h" label="%name%" zbx_hostid="5" name="secret-db" zbx_note="pw"><mxCell vertex="1" parent="1"><mxGeometry x="10" y="10" width="10" height="10" as="geometry"/></mxCell></object>'
	.'<object id="v" label="%name%" zbx_hostid="6" name="web"><mxCell vertex="1" parent="1"><mxGeometry x="50" y="10" width="10" height="10" as="geometry"/></mxCell></object>'
	.'<object id="e" label="to secret-db"><mxCell edge="1" parent="1" source="v" target="h"><mxGeometry relative="1" as="geometry"/></mxCell></object>'
	.'</root></mxGraphModel></diagram></mxfile>';
$red = DrawioXml::redactHosts($two, ['6']);
ok(strpos($red, 'secret-db') === false && strpos($red, 'pw') === false, 'labels of lines to a hidden host are redacted too');
$edited = str_replace('name="web"', 'name="web-renamed"', $red);
$back = DrawioXml::restoreHidden($edited, $two, ['6']);
ok(strpos($back, 'secret-db') !== false && strpos($back, 'zbx_note="pw"') !== false && strpos($back, 'label="to secret-db"') !== false && strpos($back, 'web-renamed') !== false,
	'save keeps hidden hosts and their lines as stored, and keeps the edits to visible ones');
$deleted = preg_replace('#<object id="h".*?</object>#s', '', $red);
ok(strpos(DrawioXml::restoreHidden($deleted, $two, ['6']), 'secret-db') !== false, 'a hidden host deleted by someone who could not see it comes back');
[$updXml, , $updRemoved] = (new Generator($cfg))->update($xml, ['client' => ['name' => 'ACME'], 'cluster' => [], 'hosts' => [], 'proxies' => [], 'cost_only' => []], 'dataflow', ['10101', '10102', '11', '12', '21', '31', '41', '1']);
ok($updRemoved === [], 'Update from Zabbix never marks hosts the user cannot see as removed');

// cells nested in a hidden host, and host ids on plain cells
$nest = '<mxfile><diagram id="p"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
	.'<object id="h" label="x" zbx_hostid="5"><mxCell vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell></object>'
	.'<mxCell id="c1" value="db password inside" vertex="1" parent="h"><mxGeometry as="geometry"/></mxCell>'
	.'<object id="c2" label="nested note"><mxCell vertex="1" parent="c1"><mxGeometry as="geometry"/></mxCell></object>'
	.'<mxCell id="p1" value="plain-cell-host" zbx_hostid="7" vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell>'
	.'</root></mxGraphModel></diagram></mxfile>';
$rn = DrawioXml::redactHosts($nest, []);
ok(strpos($rn, 'password') === false && strpos($rn, 'nested note') === false && strpos($rn, 'plain-cell-host') === false, 'cells nested in hidden hosts, and plain cells with a host id, are redacted');
ok(strpos(DrawioXml::restoreHidden($rn, $nest, []), 'nested note') !== false, 'and restored on save');

$forged = DrawioXml::dropHostLinks($nest, ['5']);
ok(strpos($forged, 'zbx_hostid="7"') === false && strpos($forged, 'zbx_hostid="5"') !== false, 'links to hosts the saver may not read are dropped');
ok(DrawioXml::linkedHostIds($nest) === ['5', '7'], 'linked host ids include plain cells');

// duplicate ids, labels on hidden lines, pages
$pg = static fn(string $id, string $cells) => '<diagram id="'.$id.'"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'.$cells.'</root></mxGraphModel></diagram>';
$h5 = '<object id="h" label="secret-host" zbx_hostid="5"><mxCell vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell></object>';
$dup = '<mxfile>'.$pg('p', '<object id="h" label="decoy" zbx_hostid="9"><mxCell vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell></object>'
	.'<object id="h" label="secret-dup" zbx_hostid="5"><mxCell vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell></object>').'</mxfile>';
ok(strpos(DrawioXml::redactHosts($dup, ['9']), 'secret-dup') === false, 'a later cell with a duplicate id is removed');
ok(substr_count(DrawioXml::sanitize($dup), 'id="h"') === 1, 'sanitize keeps one cell per id per page');
$two = '<mxfile>'.$pg('a', '<mxCell id="x" value="page-a-ok" vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell>')
	.$pg('b', $h5.'<mxCell id="x" value="page-b" vertex="1" parent="h"><mxGeometry as="geometry"/></mxCell>').'</mxfile>';
$r2 = DrawioXml::redactHosts($two, []);
ok(strpos($r2, 'page-a-ok') !== false && strpos($r2, 'secret-host') === false, 'the same id on another page is a different cell');
$deep = '<mxfile>'.$pg('p', $h5.'<mxCell id="v" value="vis" vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell>'
	.'<mxCell id="e" edge="1" source="h" target="v" parent="1"><mxGeometry as="geometry"/></mxCell>'
	.'<mxCell id="l" value="label-on-hidden-line" vertex="1" connectable="0" parent="e"><mxGeometry as="geometry"/></mxCell>'
	.'<mxCell id="e2" edge="1" source="l" target="v" parent="1"><mxGeometry as="geometry"/></mxCell>'
	.'<mxCell id="l2" value="second-hop" vertex="1" parent="e2"><mxGeometry as="geometry"/></mxCell>').'</mxfile>';
$rd = DrawioXml::redactHosts($deep, []);
ok(strpos($rd, 'label-on-hidden-line') === false && strpos($rd, 'second-hop') === false && strpos($rd, '"vis"') !== false, 'labels on hidden lines are redacted, repeatedly');
$forge = '<mxfile>'.$pg('p', '<object id="h" label="renamed" zbx_hostid="5"><mxCell vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell></object>'
	.'<object id="h" label="dup-forged" zbx_hostid="5"><mxCell vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell></object>').'</mxfile>';
$rf = DrawioXml::restoreHidden(DrawioXml::sanitize($forge), $deep, []);
ok(strpos($rf, 'secret-host') !== false && strpos($rf, 'renamed') === false && strpos($rf, 'dup-forged') === false, 'hidden cells are restored as stored, forged copies dropped');
ok(strpos(DrawioXml::dropHostLinks($forge, []), 'zbx_hostid') === false, 'stored hidden host ids are not allowed on new links');
$gone = DrawioXml::restoreHidden('<mxfile>'.$pg('other', '').'</mxfile>', $deep, []);
ok(strpos($gone, 'secret-host') !== false && strpos($gone, 'label-on-hidden-line') !== false, 'a deleted page with hidden hosts is put back');

// --- capacity from agent item keys
$lin = \Modules\HostFlow\Lib\Capacity::fromKeys(['system.cpu.num' => 8, 'system.cpu.util' => 23.456, 'vm.memory.size[total]' => 16 * 2 ** 30,
	'vm.memory.utilization' => 61.2, 'vfs.fs.dependent.size[/,total]' => 100 * 2 ** 30, 'vfs.fs.dependent.size[/,pused]' => 50,
	'vfs.fs.dependent.size[/data,total]' => 300 * 2 ** 30, 'vfs.fs.dependent.size[/data,used]' => 270 * 2 ** 30]);
ok($lin['cpu_num'] === 8 && $lin['cpu_pct'] === 23.5 && $lin['mem_pct'] === 61.2 && $lin['disk_total'] == 400 * 2 ** 30 && $lin['disk_pct'] === 80.0 && $lin['disk_fs'] === 2,
	'capacity: Linux agent keys, disks summed '.json_encode($lin));
$win = \Modules\HostFlow\Lib\Capacity::fromKeys(['system.cpu.util[,idle]' => 70, 'vm.memory.size[total]' => 8 * 2 ** 30, 'vm.memory.size[available]' => 2 * 2 ** 30,
	'vfs.fs.size[C:,total]' => 200, 'vfs.fs.size[C:,pused]' => 25]);
ok($win['cpu_pct'] === 30.0 && $win['mem_pct'] === 75.0 && $win['disk_pct'] === 25.0 && $win['cpu_num'] === null, 'capacity: idle-based CPU, memory from available, Windows disk');
ok(\Modules\HostFlow\Lib\Capacity::fromKeys([])['disk_total'] === null, 'capacity: no items, no numbers');

// --- DiagramStore ------------------------------------------------------------
$meta = DiagramStore::save(null, $xml, ['name' => 'ACME flow', 'client' => 'ACME'], null);
ok(DiagramStore::validId($meta['id']) && $meta['revision'] === 1, 'create diagram');
$meta2 = DiagramStore::save($meta['id'], $upd, [], 1);
ok($meta2['revision'] === 2 && in_array('13', $meta2['hostids'], true), 'second revision');
throws(fn() => DiagramStore::save($meta['id'], $xml, [], 1), 'stale revision conflicts', 'changed by');
for ($i = 0; $i < 5; $i++) { DiagramStore::save($meta['id'], $xml, [], null); }
$files = array_map('basename', glob(Storage::path('diagrams', $meta['id']).'/*'));
sort($files);
ok($files === ['diagram.drawio', 'meta.json'], 'one file per diagram, no version history');
ok(DiagramStore::xml($meta['id']) === DrawioXml::sanitize($xml), 'latest save is what loads');
$m3 = DiagramStore::save($meta['id'], $xml, ['auto' => true, 'synced_at' => 1], null);
ok($m3['auto'] === true && DiagramStore::meta($meta['id'])['auto'] === true, 'auto flag stored');
// a diagram saved by 1.0.0 (numbered files) still loads, and the old files go on the next save
$legacy = DiagramStore::save(null, $xml, ['name' => 'old'], null);
$dir = Storage::path('diagrams', $legacy['id']);
rename("$dir/diagram.drawio", "$dir/rev-000001.drawio");
file_put_contents("$dir/rev-000002.drawio", $upd);
ok(DiagramStore::xml($legacy['id']) === $upd, 'legacy numbered files: newest loads');
DiagramStore::save($legacy['id'], $xml, [], null);
ok(!glob("$dir/rev-*.drawio") && is_file("$dir/diagram.drawio"), 'legacy files removed on save');
DiagramStore::delete($legacy['id']);
ok(count(DiagramStore::list(true)) === 2 && count(DiagramStore::list()) === 1, 'deleted diagrams are remembered, not listed');
ok(count(DiagramStore::list()) === 1, 'list');
DiagramStore::delete($meta['id']);
ok(DiagramStore::list() === [] && DiagramStore::meta($meta['id']) === null, 'delete');
ok(substr_count(file_get_contents("$tmp/audit/hostflow-audit.jsonl"), "\n") >= 10, 'audit lines written');
ok(strpos(file_get_contents("$tmp/audit/hostflow-audit.jsonl"), '<mxfile') === false, 'audit never contains XML');
throws(fn() => Storage::path('diagrams', '../etc'), 'path traversal blocked', 'Invalid path');

done();
