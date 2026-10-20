<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Builds draw.io XML from the Zabbix model. Every host shape stores only its hostid;
 * the live view fills in name, IP and state on each refresh (label placeholders).
 *
 * Layouts: client (the pipeline of the client's flow template, with the client hierarchy below),
 *          hierarchy (client → host groups → hosts, the approved live view),
 *          dataflow (tiers in flow-graph columns), monitoring (server → proxies → hosts),
 *          combined (data flow with the monitoring path as a lane below).
 * Tiers and arrows come from the client's flow template (ZabbixModel::clientModel()['flow']).
 */
class Generator {

	public const LAYOUTS = ['client', 'hierarchy', 'dataflow', 'monitoring', 'combined'];
	/** Raised when the drawing changes (2: straight arrows), so automated flows are redrawn once. */
	public const VERSION = 4;
	private const QUEUES = ['redis' => 'Redis', 'kafka' => 'Kafka', 'both' => 'Redis · Kafka'];

	private const HOST_W = 150, HOST_H = 54, GAP = 16;
	private const GROUP_W = 190, GROUP_H = 54, ROW = 80;
	private const PER_ROW = 6;

	private DOMDocument $doc;
	private DOMElement $root;
	private int $seq = 1;
	private array $cfg;
	private array $opt;
	private array $flow;

	public function __construct(?array $cfg = null, array $opt = []) {
		$this->cfg = $cfg ?? Config::get();
		$this->opt = $opt + ['collapse_over' => 0, 'include_disabled' => true, 'label' => $this->cfg['labels']['host']];
		$this->flow = Config::template(null, $this->cfg);
	}

	/**
	 * @param array $model  ZabbixModel::clientModel() output, plus 'cost_only' entries for the client
	 */
	public function build(array $model, string $layout): string {
		$this->begin();
		$model = $this->filter($model);

		switch ($layout) {
			case 'client':
				$this->text('PIPELINE', 20, 10, false);
				$h = $this->dataflow($model, 20, 30);
				$this->text('CLIENT HIERARCHY', 20, $h + 90, false);
				$this->hierarchy($model, 20, $h + 130);
				break;
			case 'dataflow':
				$this->dataflow($model, 20, 20);
				break;
			case 'monitoring':
				$this->monitoring($model, 20, 20);
				break;
			case 'combined':
				$h = $this->dataflow($model, 20, 20);
				$this->text('MONITORING PATH', 20, $h + 40);
				$this->monitoring($model, 20, $h + 70);
				break;
			default:
				$this->hierarchy($model, 20, 20);
		}

		return $this->finish();
	}

	/**
	 * Re-run against an existing diagram: add hosts that are new, mark hosts that are gone
	 * with a dashed outline (never delete), keep everything drawn by hand.
	 * Returns [xml, added hostids, removed hostids].
	 */
	public function update(string $xml, array $model, string $layout): array {
		$xml = DrawioXml::sanitize($xml);
		$model = $this->filter($model);
		$this->doc = DrawioXml::load($xml);
		$xp = new DOMXPath($this->doc);
		$graph = $xp->query('//diagram/mxGraphModel/root')->item(0);
		if (!$graph) {
			return [$xml, [], []];
		}
		$this->root = $graph;

		$present = [];
		foreach ($xp->query('//object[@zbx_hostid]|//UserObject[@zbx_hostid]') as $o) {
			$present[$o->getAttribute('zbx_hostid')][] = $o;
		}
		$want = [];
		foreach ($model['hosts'] as $h) {
			$want[$h['hostid']] = $h;
		}
		foreach ($model['cluster'] as $h) {
			$want[$h['hostid']] = $h + ['component' => null];
		}

		// New shapes continue after the highest existing generated id (hfN), so ids never collide.
		$max = 0;
		foreach ($xp->query('//*[@id]') as $el) {
			if (preg_match('/^hf(\d+)$/', $el->getAttribute('id'), $mm)) {
				$max = max($max, (int) $mm[1]);
			}
		}
		$this->seq = $max + 1;

		$added = [];
		foreach ($want as $hid => $h) {
			if (isset($present[$hid])) {
				foreach ($present[$hid] as $o) {
					if ($o->getAttribute('zbx_removed') === '1') {
						$o->removeAttribute('zbx_removed');
						$this->setStyle($o, ['dashed' => null, 'opacity' => null]);
					}
				}
				continue;
			}
			if ($h['component'] === null) {
				continue;
			}
			[$parent, $x, $y] = $this->slotFor($xp, $h['component'], $layout);
			$parent !== null ? $this->host($h, $x, $y, $parent, 216, 48) : $this->host($h, $x, $y);
			$added[] = (string) $hid;
		}

		$removed = [];
		foreach ($present as $hid => $objs) {
			if (!isset($want[$hid])) {
				foreach ($objs as $o) {
					if ($o->getAttribute('zbx_removed') !== '1') {
						$o->setAttribute('zbx_removed', '1');
						$this->setStyle($o, ['dashed' => '1', 'opacity' => '50']);
						$removed[] = (string) $hid;
					}
				}
			}
		}
		return [$this->doc->saveXML($this->doc->documentElement), $added, array_values(array_unique($removed))];
	}

	// ----------------------------------------------------------------- layouts

	private function hierarchy(array $m, int $x0, int $y0): void {
		$groups = $this->groupsByComponent($m);
		$rows = 0;
		foreach ($groups as $g) {
			$rows += max(1, (int) ceil(count($g['hosts']) / self::PER_ROW));
		}
		$height = max(1, $rows) * self::ROW;
		$clientY = $y0 + (int) ($height / 2) - 45;

		$client = $this->clientNode($m, $x0, $clientY);
		if ($m['cluster']) {
			$cy = $clientY - 90;
			foreach ($m['cluster'] as $i => $ch) {
				$c = $this->node('cluster', $ch['name'], 'cluster', 'store', $x0 + 10, $cy - $i * 64, 170, 52,
					['zbx_hostid' => $ch['hostid'], 'name' => htmlspecialchars($ch['name']), 'ip' => htmlspecialchars($ch['ip'] ?? ''), 'zbx_label' => $this->opt['label']],
					$this->opt['label']);
				$this->edge($c, $client, htmlspecialchars($this->cfg['client']['grp_macro'].' = '.$m['client']['name']), 'dashed=1;endArrow=none;');
			}
		}

		$gx = $x0 + 270;
		$hx = $gx + self::GROUP_W + 50;
		$y = $y0;
		foreach ($groups as $g) {
			$c = $g['component'];
			// A second line only when it adds something: queue, note, "cost only" or "not mapped yet".
			$sub = self::subline($c);
			$label = '<b>'.htmlspecialchars($c['label']).'</b>'.($sub !== '' ? '<br><span style="font-size:10px">'.htmlspecialchars($sub).'</span>' : '');
			$grp = $this->node('component', $label, $c['icon'], $c['color'], $gx, $y, self::GROUP_W, self::GROUP_H,
				['zbx_component' => $c['id']], null, $c['kind'] === 'cost' ? 'dashed=1;' : '');
			$this->edge($client, $grp, '', 'edgeStyle=orthogonalEdgeStyle;exitX=1;exitY=0.5;exitDx=0;exitDy=0;entryX=0;entryY=0.5;entryDx=0;entryDy=0;');

			$prev = null;
			foreach (array_values($g['hosts']) as $i => $h) {
				$row = intdiv($i, self::PER_ROW);
				$col = $i % self::PER_ROW;
				$hy = $y + $row * self::ROW;
				$node = $h['_cost_only'] ?? false
					? $this->costNode($h, $hx + $col * (self::HOST_W + self::GAP), $hy)
					: $this->host($h, $hx + $col * (self::HOST_W + self::GAP), $hy);
				if ($col === 0) {
					$this->edge($grp, $node, '', 'edgeStyle=orthogonalEdgeStyle;exitX=1;exitY=0.5;exitDx=0;exitDy=0;entryX=0;entryY=0.5;entryDx=0;entryDy=0;');
				}
				elseif ($prev !== null) {
					$this->edge($prev, $node, '', 'endArrow=none;strokeWidth=1;exitX=1;exitY=0.5;exitDx=0;exitDy=0;entryX=0;entryY=0.5;entryDx=0;entryDy=0;');
				}
				$prev = $node;
			}
			$y += max(1, (int) ceil(count($g['hosts']) / self::PER_ROW)) * self::ROW;
		}
	}

	/** Returns the height used. */
	private function dataflow(array $m, int $x0, int $y0): int {
		$comps = $this->flow['components'];
		$cols = Flow::columns($comps, $this->flow['edges']);
		$groups = $this->groupsByComponent($m, true);
		$tiers = array_flip($this->flow['tiers'] ?: array_merge(array_column($this->flow['edges'], 'from'), array_column($this->flow['edges'], 'to')));
		$colW = 300;
		$boxW = 236;
		$geo = [];
		$nextY = [];
		$cells = [];
		$placedY = [];
		$maxY = $y0;
		// A template's pipeline shows only its own tiers (hosts of other tiers are in the hierarchy);
		// without template tiers (older settings), every tier with hosts is drawn.
		$explicit = !empty($this->flow['tiers']);
		$draw = array_values(array_filter($comps, static fn($c) => $explicit ? isset($tiers[$c['id']]) : (isset($groups[$c['id']]) || isset($tiers[$c['id']]))));
		// Left to right; in a column, keep template order. A tier lines up with the tier that feeds it,
		// so a branch (Parser → S3 → ULM) reads as its own row.
		$order = array_flip(array_column($draw, 'id'));
		usort($draw, static fn($a, $b) => [$cols[$a['id']], $order[$a['id']]] <=> [$cols[$b['id']], $order[$b['id']]]);
		$small = [];
		foreach ($draw as $c) {
			$hosts = $groups[$c['id']]['hosts'] ?? [];
			$collapse = $this->opt['collapse_over'] > 0 && count($hosts) > $this->opt['collapse_over'];
			$col = $cols[$c['id']];
			$x = $x0 + $col * $colW;
			$y = $nextY[$col] ?? $y0 + 30;
			foreach ($this->flow['edges'] as $e) {
				if ($e['to'] === $c['id'] && isset($placedY[$e['from']]) && $cols[$e['from']] < $col) {
					$y = max($y, $placedY[$e['from']]);
					break;
				}
			}
			$attrs = ['zbx_component' => $c['id']];
			if (!empty($c['small'])) {
				// A pass-through (VPN): a small round icon on the line, centred on the header line of its row.
				$small[$c['id']] = $c;
				$cells[$c['id']] = $this->obj($attrs + ['zbx_kind' => 'component'], htmlspecialchars($c['label']),
					'shape=label;rounded=1;arcSize=50;html=1;fillColor=#ffffff;strokeColor='.Icons::COLORS[$c['color']].';strokeWidth=2;'
					.'image='.Icons::styleImage($c['icon'], $c['color']).';imageWidth=30;imageHeight=30;imageAlign=center;imageVerticalAlign=middle;'
					.'labelPosition=center;verticalLabelPosition=top;align=center;verticalAlign=bottom;fontStyle=1;fontSize=12;spacing=2;',
					$x + (int) (($boxW - 48) / 2), $y - 6, 48, 48);
				$geo[$c['id']] = [$x + (int) (($boxW - 48) / 2), $y - 6, 48, 24, $x + (int) ($boxW / 2)];
				$placedY[$c['id']] = $y;
				$nextY[$col] = $y + 48 + 40;
				$maxY = max($maxY, $y + 48);
				continue;
			}
			$static = $c['kind'] === 'static';
			$innerH = $static ? -10 : ($collapse || !$hosts ? 40 : count($hosts) * 56 + 10);
			$h = 46 + $innerH;
			$sub = implode(' · ', array_filter([self::subline($c, false), $collapse ? sprintf('%d hosts', count($hosts)) : '']));
			$box = $this->container($c, $x, $y, $boxW, $h, $attrs, $sub);
			$geo[$c['id']] = [$x, $y, $h, 18, $x + (int) ($boxW / 2)];
			$cells[$c['id']] = $box;
			if ($static) {
				// UI and other static tiers have no hosts: the header is the whole box.
			}
			elseif (!$hosts) {
				$this->obj(['zbx_kind' => 'placeholder'], $c['kind'] === 'cost' ? 'add buckets in Settings → Cost'
					: (self::mapped($c) ? 'no hosts in this group yet' : 'not mapped yet: Settings → Components'),
					'text;html=1;fontSize=10;fontColor=#768d99;align=center;verticalAlign=middle;', 10, 46, $boxW - 20, 30, $box);
			}
			elseif (!$collapse) {
				foreach (array_values($hosts) as $i => $hh) {
					$hh['_cost_only'] ?? false
						? $this->costNode($hh, 10, 46 + $i * 56, $box, $boxW - 20, 48)
						: $this->host($hh, 10, 46 + $i * 56, $box, $boxW - 20, 48);
				}
			}
			$placedY[$c['id']] = $y;
			$nextY[$col] = $y + $h + 40;
			$maxY = max($maxY, $y + $h);
		}
		$edges = $this->flow['edges'];
		foreach ($edges as $i => $e) {
			if (!isset($cells[$e['from']], $cells[$e['to']])) {
				continue;
			}
			// Two tiers sending to each other: one line with a head at each end and both labels.
			$back = null;
			foreach ($edges as $j => $f) {
				if ($f['from'] === $e['to'] && $f['to'] === $e['from']) {
					$back = $j;
				}
			}
			if ($back !== null && $back < $i) {
				continue;
			}
			$label = $back !== null ? implode(' / ', array_filter([$e['label'], $edges[$back]['label']])) : $e['label'];
			$style = 'edgeStyle=orthogonalEdgeStyle;'.self::port($geo[$e['from']], $geo[$e['to']]);
			if ($back !== null || !empty($e['both'])) {
				$style .= 'startArrow=block;startFill=1;';
			}
			if (isset($small[$e['to']])) {
				// Into a pass-through: no head on the icon, no label (the label goes on the line out of it).
				$style .= 'endArrow=none;';
				$label = '';
			}
			elseif (isset($small[$e['from']]) && $label !== '') {
				$label .= ' via '.$small[$e['from']]['label'];
			}
			$extra = ($e['item_key'] ?? '') !== '' ? ['zbx_item_key' => $e['item_key'], 'zbx_drop_pct' => (string) $e['drop_pct']] : [];
			$this->edge($cells[$e['from']], $cells[$e['to']], htmlspecialchars($label), $style, $extra);
		}

		return $maxY - $y0;
	}

	/**
	 * Where a pipeline arrow leaves and enters, so arrows stay straight wherever they can:
	 * tiers in one row connect at header height (a straight line whatever the tiers' heights),
	 * a branch to a lower row leaves from the bottom and turns once, an arrow up to a higher row
	 * enters from below, and tiers stacked in one column connect top to bottom.
	 * $a, $b are [x, y, height, port height, centre x] of the two tiers.
	 */
	private static function port(array $a, array $b): string {
		[, $ay, $ah, $ap, $ac] = $a;
		[, $by, $bh, $bp, $bc] = $b;
		$p = static fn($ex, $ey, $edy, $nx, $ny, $ndy) => "exitX=$ex;exitY=$ey;exitDx=0;exitDy=$edy;entryX=$nx;entryY=$ny;entryDx=0;entryDy=$ndy;";
		if (abs($ac - $bc) < 50) {
			// Same column: straight up or down.
			return $by > $ay ? $p(0.5, 1, 0, 0.5, 0, 0) : $p(0.5, 0, 0, 0.5, 1, 0);
		}
		$fwd = $bc > $ac;
		if ($ay + $ap === $by + $bp) {
			if (abs($bc - $ac) > 400) {
				// Same row but tiers in between: leave from the top and come down into the target, over them.
				return $fwd ? $p(0.85, 0, 0, 0.15, 0, 0) : $p(0.15, 0, 0, 0.85, 0, 0);
			}
			return $fwd ? $p(1, 0, $ap, 0, 0, $bp) : $p(0, 0, $ap, 1, 0, $bp);
		}
		if ($by >= $ay + $ah) {
			return $p(0.5, 1, 0, $fwd ? 0 : 1, 0, $bp);
		}
		if ($ay >= $by + $bh) {
			return $p($fwd ? 1 : 0, 0, $ap, 0.5, 1, 0);
		}

		return $fwd ? $p(1, 0, $ap, 0, 0, $bp) : $p(0, 0, $ap, 1, 0, $bp);
	}

	/** Second line of a tier: its queue and note; else "cost only" or "not mapped yet" ($empty: those two too). */
	private static function subline(array $c, bool $empty = true): string {
		$line = implode(' · ', array_filter([self::QUEUES[$c['queue'] ?? ''] ?? '', $c['note'] ?? '']));
		if ($line !== '' || !$empty || $c['kind'] === 'static') {
			return $line;
		}
		if ($c['kind'] === 'cost') {
			return 'cost only';
		}

		return self::mapped($c) ? '' : 'not mapped yet';
	}

	private static function mapped(array $c): bool {
		return $c['match']['groups'] || $c['match']['tags'] || $c['match']['templates'];
	}

	/** [icon, colour] of a tier; hosts without a tier get the generic server icon. */
	private function iconFor(string $component): array {
		foreach ($this->flow['components'] as $c) {
			if ($c['id'] === $component) {
				return [$c['icon'], $c['color']];
			}
		}

		return ['server', 'neutral'];
	}

	private function monitoring(array $m, int $x0, int $y0): void {
		$server = $this->node('server', '<b>Zabbix server</b>', 'server', 'zbx', $x0, $y0 + 40, 170, 54, ['zbx_kind' => 'server']);
		$byProxy = ['' => []];
		foreach (array_merge($m['cluster'], $m['hosts']) as $h) {
			$byProxy[(int) $h['monitored_by'] === 1 ? $h['proxyid'] : ''][] = $h;
		}
		$proxies = [];
		foreach ($m['proxies'] as $p) {
			$proxies[$p['proxyid']] = $p;
		}
		$x = $x0 + 260;
		$col = 0;
		foreach ($byProxy as $pid => $hosts) {
			if (!$hosts) {
				continue;
			}
			$cx = $x + $col * 220;
			$top = $y0;
			if ($pid !== '') {
				$p = $proxies[$pid] ?? ['name' => 'proxy '.$pid, 'address' => ''];
				$pn = $this->node('proxy', '<b>'.htmlspecialchars($p['name']).'</b><br><span style="font-family:monospace;font-size:10px">'
					.htmlspecialchars($p['address'] ?? '').'</span>', 'proxy', 'neutral', $cx, $top, 170, 54, ['zbx_proxyid' => (string) $pid]);
				$this->edge($server, $pn, 'monitors', 'edgeStyle=orthogonalEdgeStyle;');
				$parent = $pn;
				$top += 80;
			}
			else {
				$parent = $server;
				$this->text('MONITORED BY SERVER', $cx, $top + 10);
				$top += 40;
			}
			foreach (array_values($hosts) as $i => $h) {
				$n = $this->host($h, $cx, $top + $i * 64);
				if ($i === 0) {
					$this->edge($parent, $n, '', 'edgeStyle=orthogonalEdgeStyle;');
				}
			}
			$col++;
		}
	}

	// ----------------------------------------------------------------- shapes

	private function clientNode(array $m, int $x, int $y): DOMElement {
		$name = $m['client']['name'] ?? 'Client';

		return $this->node('client', '<b style="font-size:16px">'.htmlspecialchars($name).'</b><br><span style="font-size:11px">client group · %hostcount% hosts</span>',
			'client', 'zbx', $x, $y, 190, 90, ['zbx_kind' => 'client', 'zbx_client' => $name, 'hostcount' => (string) count($m['hosts'])],
			null, 'strokeWidth=2;');
	}

	private function host(array $h, int $x, int $y, ?DOMElement $parent = null, int $w = self::HOST_W, int $hgt = self::HOST_H): DOMElement {
		$attrs = [
			'zbx_hostid' => $h['hostid'],
			'zbx_kind' => 'host',
			'zbx_component' => (string) ($h['component'] ?? ''),
			'zbx_label' => $this->opt['label'],
			'name' => htmlspecialchars($h['name']),
			'ip' => htmlspecialchars($h['ip'] ?? ''),
			'cost' => ''
		];

		// Each host shows its tier's icon (Settings → Components), so roles read at a glance.
		[$icon, $color] = $this->iconFor((string) ($h['component'] ?? ''));

		return $this->obj($attrs, $this->opt['label'],
			'shape=label;rounded=1;whiteSpace=wrap;html=1;arcSize=12;fillColor=#ffffff;strokeColor=#b9c4cf;fontSize=11;align=left;spacingLeft=36;'
			.'image='.Icons::styleImage($icon, $color).';imageWidth=22;imageHeight=22;imageAlign=left;imageVerticalAlign=middle;'
			.'shadow=1;'.(!empty($h['_disabled']) ? 'dashed=1;opacity=70;' : ''),
			$x, $y, $w, $hgt, $parent);
	}

	private function costNode(array $c, int $x, int $y, ?DOMElement $parent = null, int $w = self::HOST_W, int $hgt = self::HOST_H): DOMElement {
		return $this->obj(['zbx_kind' => 'cost', 'zbx_cost_id' => $c['id'], 'zbx_component' => $c['component'], 'name' => htmlspecialchars($c['name']), 'cost' => ''],
			'<b>%name%</b><br><span style="font-size:10px">cost only</span><br><span style="font-family:monospace;font-size:10px">%cost%</span>',
			'rounded=1;whiteSpace=wrap;html=1;dashed=1;fillColor=#eef2f6;strokeColor=#b9c4cf;fontSize=11;align=left;spacingLeft=10;',
			$x, $y, $w, $hgt, $parent);
	}

	private function container(array $c, int $x, int $y, int $w, int $h, array $attrs, string $sub): DOMElement {
		$label = htmlspecialchars($c['label']).($sub !== '' ? ' · <span style="font-weight:normal">'.htmlspecialchars($sub).'</span>' : '');

		return $this->obj($attrs + ['zbx_kind' => 'component'], $label,
			'swimlane;rounded=1;arcSize=6;html=1;startSize=36;fontStyle=1;fontSize=13;align=left;spacingLeft=44;'
			.'fillColor='.Icons::COLORS[$c['color']].';fontColor=#ffffff;swimlaneFillColor=#ffffff;strokeColor=#b9c4cf;'
			.'image='.Icons::styleImage($c['icon'], $c['color']).';imageWidth=26;imageHeight=26;imageAlign=left;imageVerticalAlign=top;spacingTop=0;'
			.($c['kind'] === 'cost' ? 'dashed=1;' : ''),
			$x, $y, $w, $h);
	}

	private function node(string $kind, string $label, string $icon, string $color, int $x, int $y, int $w, int $h,
			array $attrs = [], ?string $placeholderLabel = null, string $extraStyle = ''): DOMElement {
		return $this->obj($attrs + ['zbx_kind' => $kind], $placeholderLabel ?? $label,
			'shape=label;rounded=1;whiteSpace=wrap;html=1;arcSize=12;fillColor=#ffffff;strokeColor=#b9c4cf;fontSize=12;align=left;spacingLeft=46;'
			.'shadow=1;image='.Icons::styleImage($icon, $color).';imageWidth=30;imageHeight=30;imageAlign=left;imageVerticalAlign=middle;'
			.$extraStyle,
			$x, $y, $w, $h);
	}

	private function text(string $text, int $x, int $y, bool $muted = true): void {
		$cell = $this->doc->createElement('mxCell');
		$cell->setAttribute('id', 'hf'.$this->seq++);
		$cell->setAttribute('value', $text);
		$cell->setAttribute('style', 'text;html=1;fontStyle=1;fontSize=11;fontColor='.($muted ? '#5d6979' : '#18222d').';spacing=0;');
		$cell->setAttribute('vertex', '1');
		$cell->setAttribute('parent', '1');
		$cell->appendChild($this->geometry($x, $y, 220, 20));
		$this->root->appendChild($cell);
	}

	private function obj(array $attrs, string $label, string $style, int $x, int $y, int $w, int $h, ?DOMElement $parent = null): DOMElement {
		$o = $this->doc->createElement('object');
		$id = 'hf'.$this->seq++;
		$o->setAttribute('id', $id);
		$o->setAttribute('label', $label);
		$o->setAttribute('placeholders', '1');
		foreach ($attrs as $k => $v) {
			$o->setAttribute($k, (string) $v);
		}
		$cell = $this->doc->createElement('mxCell');
		$cell->setAttribute('style', $style);
		$cell->setAttribute('vertex', '1');
		$cell->setAttribute('parent', $parent !== null ? $parent->getAttribute('id') : '1');
		$cell->appendChild($this->geometry($x, $y, $w, $h));
		$o->appendChild($cell);
		$this->root->appendChild($o);

		return $o;
	}

	private function edge(DOMElement $from, DOMElement $to, string $label, string $style = '', array $attrs = []): void {
		$id = 'hf'.$this->seq++;
		$cell = $this->doc->createElement('mxCell');
		$cell->setAttribute('style', 'rounded=0;html=1;endArrow=block;endFill=1;strokeColor=#5b6977;strokeWidth=1.5;fontSize=10;'
			.'labelBackgroundColor=#ffffff;'.$style);
		$cell->setAttribute('edge', '1');
		$cell->setAttribute('parent', '1');
		$cell->setAttribute('source', $from->getAttribute('id'));
		$cell->setAttribute('target', $to->getAttribute('id'));
		$g = $this->doc->createElement('mxGeometry');
		$g->setAttribute('relative', '1');
		$g->setAttribute('as', 'geometry');
		$cell->appendChild($g);

		if ($attrs || $label !== '') {
			$o = $this->doc->createElement('object');
			$o->setAttribute('id', $id);
			$o->setAttribute('label', $label);
			$o->setAttribute('zbx_kind', 'flow');
			foreach ($attrs as $k => $v) {
				$o->setAttribute($k, (string) $v);
			}
			$o->appendChild($cell);
			$this->root->appendChild($o);
		}
		else {
			$cell->setAttribute('id', $id);
			$cell->setAttribute('value', '');
			$this->root->appendChild($cell);
		}
	}

	private function geometry(int $x, int $y, int $w, int $h): DOMElement {
		$g = $this->doc->createElement('mxGeometry');
		$g->setAttribute('x', (string) $x);
		$g->setAttribute('y', (string) $y);
		$g->setAttribute('width', (string) $w);
		$g->setAttribute('height', (string) $h);
		$g->setAttribute('as', 'geometry');

		return $g;
	}

	// ----------------------------------------------------------------- helpers

	private function begin(): void {
		$this->doc = new DOMDocument('1.0', 'UTF-8');
		$mx = $this->doc->createElement('mxfile');
		$mx->setAttribute('host', 'hostflow');
		$page = $this->doc->createElement('diagram');
		$page->setAttribute('id', 'page-1');
		$page->setAttribute('name', 'Page-1');
		$model = $this->doc->createElement('mxGraphModel');
		foreach (['grid' => '1', 'gridSize' => '10', 'guides' => '1', 'tooltips' => '1', 'connect' => '1', 'arrows' => '1',
				'fold' => '1', 'page' => '0', 'pageScale' => '1', 'math' => '0', 'shadow' => '0'] as $k => $v) {
			$model->setAttribute($k, $v);
		}
		$root = $this->doc->createElement('root');
		$c0 = $this->doc->createElement('mxCell');
		$c0->setAttribute('id', '0');
		$c1 = $this->doc->createElement('mxCell');
		$c1->setAttribute('id', '1');
		$c1->setAttribute('parent', '0');
		$root->appendChild($c0);
		$root->appendChild($c1);
		$model->appendChild($root);
		$page->appendChild($model);
		$mx->appendChild($page);
		$this->doc->appendChild($mx);
		$this->root = $root;
		$this->seq = 1;
	}

	private function finish(): string {
		return $this->doc->saveXML($this->doc->documentElement);
	}

	private function filter(array $m): array {
		if (!empty($m['flow'])) {
			$this->flow = $m['flow'];
		}
		foreach ($m['hosts'] as $i => $h) {
			$disabled = (int) $h['status'] === 1;
			if ($disabled && !$this->opt['include_disabled']) {
				unset($m['hosts'][$i]);
				continue;
			}
			$m['hosts'][$i]['_disabled'] = $disabled;
		}
		$m['hosts'] = array_values($m['hosts']);
		$m['cost_only'] = $m['cost_only'] ?? [];

		return $m;
	}

	/** [componentId => ['component' => ..., 'hosts' => [...]]] in Settings order, empty tiers skipped. */
	private function groupsByComponent(array $m, bool $keepEmptyCost = false): array {
		$by = [];
		foreach ($m['hosts'] as $h) {
			if ($h['component'] !== null) {
				$by[$h['component']][] = $h;
			}
		}
		foreach ($m['cost_only'] as $c) {
			$by[$c['component']][] = $c + ['_cost_only' => true];
		}
		$out = [];
		foreach ($this->flow['components'] as $c) {
			if (!empty($by[$c['id']])) {
				$out[$c['id']] = ['component' => $c, 'hosts' => $by[$c['id']]];
			}
		}

		return $out;
	}

	/** Where a new host of $component goes in an existing diagram. */
	private function slotFor(DOMXPath $xp, string $component, string $layout): array {
		$container = $xp->query('//object[@zbx_kind="component"][@zbx_component="'.$component.'"]')->item(0);
		if ($container) {
			$cid = $container->getAttribute('id');
			$maxY = 46 - 56;
			foreach ($xp->query('//object[@zbx_kind="host"]/mxCell[@parent="'.$cid.'"]/mxGeometry') as $g) {
				$maxY = max($maxY, (int) $g->getAttribute('y'));
			}
			$isSwimlane = strpos($container->firstChild instanceof DOMElement ? $container->firstChild->getAttribute('style') : '', 'swimlane') !== false;
			if ($isSwimlane) {
				$cg = $xp->query('mxCell/mxGeometry', $container)->item(0);
				if ($cg) {
					$cg->setAttribute('height', (string) ((int) $cg->getAttribute('height') + 56));
				}

				return [$container, 10, $maxY + 56];
			}
			// hierarchy: append to the row of hosts beside the group card
			$maxX = 0;
			$rowY = (int) $xp->query('mxCell/mxGeometry', $container)->item(0)->getAttribute('y');
			foreach ($xp->query('//object[@zbx_kind="host"][@zbx_component="'.$component.'"]/mxCell/mxGeometry') as $g) {
				$maxX = max($maxX, (int) $g->getAttribute('x'));
				$rowY = (int) $g->getAttribute('y');
			}

			return [null, $maxX > 0 ? $maxX + self::HOST_W + self::GAP : 510, $rowY];
		}
		// no tier for it yet: park new hosts at the bottom left, the editor can move them
		$maxY = 0;
		foreach ($xp->query('//mxGeometry[@y]') as $g) {
			$maxY = max($maxY, (int) $g->getAttribute('y') + (int) $g->getAttribute('height'));
		}

		return [null, 20, $maxY + 40];
	}

	private function setStyle(DOMElement $obj, array $set): void {
		$cell = null;
		foreach ($obj->childNodes as $c) {
			if ($c instanceof DOMElement && $c->nodeName === 'mxCell') {
				$cell = $c;
			}
		}
		if (!$cell) {
			return;
		}
		$parts = [];
		foreach (array_filter(explode(';', $cell->getAttribute('style'))) as $p) {
			[$k, $v] = array_pad(explode('=', $p, 2), 2, null);
			$parts[$k] = $v;
		}
		foreach ($set as $k => $v) {
			if ($v === null) {
				unset($parts[$k]);
			}
			else {
				$parts[$k] = $v;
			}
		}
		$style = '';
		foreach ($parts as $k => $v) {
			$style .= $v === null ? $k.';' : $k.'='.$v.';';
		}
		$cell->setAttribute('style', $style);
	}
}
