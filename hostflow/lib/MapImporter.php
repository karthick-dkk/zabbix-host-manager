<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use API;
use DOMDocument;
use RuntimeException;

/** Turns a native Zabbix map into an editable draw.io diagram. Host elements stay live. */
class MapImporter {

	public static function toXml(string $sysmapid): array {
		$maps = API::Map()->get([
			'output' => ['sysmapid', 'name', 'width', 'height'],
			'sysmapids' => [$sysmapid],
			'selectSelements' => ['selementid', 'elements', 'elementtype', 'label', 'x', 'y', 'width', 'height'],
			'selectLinks' => ['selementid1', 'selementid2', 'label', 'color', 'drawtype'],
			'selectShapes' => ['type', 'x', 'y', 'width', 'height', 'text', 'background_color', 'border_color']
		]);
		if (!$maps) {
			throw new RuntimeException('That map does not exist or you have no access to it.');
		}
		$map = $maps[0];
		$cfg = Config::get();
		$hostids = [];
		foreach ($map['selements'] as $s) {
			if ((int) $s['elementtype'] === SYSMAP_ELEMENT_TYPE_HOST) {
				$hostids[] = $s['elements'][0]['hostid'];
			}
		}
		$hosts = $hostids ? array_column(ZabbixModel::hostsWith(['hostids' => $hostids]), null, 'hostid') : [];

		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->loadXML('<mxfile host="hostflow"><diagram id="page-1" name="'.htmlspecialchars($map['name']).'"><mxGraphModel grid="1" gridSize="10" page="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>');
		$root = $doc->getElementsByTagName('root')->item(0);
		$label = $cfg['labels']['host'];
		$n = 0;

		foreach ($map['shapes'] as $sh) {
			$cell = $doc->createElement('mxCell');
			$cell->setAttribute('id', 'sh'.$n++);
			$cell->setAttribute('value', htmlspecialchars($sh['text']));
			$cell->setAttribute('style', ((int) $sh['type'] === 1 ? 'ellipse;' : 'rounded=0;').'whiteSpace=wrap;html=1;'
				.($sh['background_color'] !== '' ? 'fillColor=#'.$sh['background_color'].';' : 'fillColor=none;')
				.($sh['border_color'] !== '' ? 'strokeColor=#'.$sh['border_color'].';' : 'strokeColor=none;'));
			$cell->setAttribute('vertex', '1');
			$cell->setAttribute('parent', '1');
			$cell->appendChild(self::geo($doc, $sh['x'], $sh['y'], $sh['width'], $sh['height']));
			$root->appendChild($cell);
		}

		$ids = [];
		foreach ($map['selements'] as $s) {
			$id = 'se'.$s['selementid'];
			$ids[$s['selementid']] = $id;
			$w = max(120, (int) $s['width']);
			$h = 54;
			$isHost = (int) $s['elementtype'] === SYSMAP_ELEMENT_TYPE_HOST;
			$o = $doc->createElement('object');
			$o->setAttribute('id', $id);
			$o->setAttribute('placeholders', '1');
			if ($isHost) {
				$hh = $hosts[$s['elements'][0]['hostid']] ?? null;
				$o->setAttribute('label', $label);
				$o->setAttribute('zbx_hostid', $s['elements'][0]['hostid']);
				$o->setAttribute('zbx_kind', 'host');
				$o->setAttribute('zbx_component', $hh ? (string) ZabbixModel::componentFor($hh, $cfg['components']) : '');
				$o->setAttribute('name', htmlspecialchars($hh['name'] ?? $s['label']));
				$o->setAttribute('ip', htmlspecialchars($hh['ip'] ?? ''));
				$style = 'rounded=1;whiteSpace=wrap;html=1;arcSize=12;fillColor=#ffffff;strokeColor=#b9c4cf;fontSize=11;align=left;spacingLeft=10;shadow=1;';
			}
			else {
				$o->setAttribute('label', htmlspecialchars(preg_replace('/\{[A-Z.]+\}/', '', $s['label'])));
				$style = 'rounded=1;whiteSpace=wrap;html=1;dashed=1;fillColor=#eef2f6;strokeColor=#b9c4cf;fontSize=11;';
			}
			$cell = $doc->createElement('mxCell');
			$cell->setAttribute('style', $style);
			$cell->setAttribute('vertex', '1');
			$cell->setAttribute('parent', '1');
			$cell->appendChild(self::geo($doc, $s['x'], $s['y'], $w, $h));
			$o->appendChild($cell);
			$root->appendChild($o);
		}

		foreach ($map['links'] as $l) {
			if (!isset($ids[$l['selementid1']], $ids[$l['selementid2']])) {
				continue;
			}
			$cell = $doc->createElement('mxCell');
			$cell->setAttribute('id', 'ln'.$n++);
			$cell->setAttribute('value', htmlspecialchars($l['label']));
			$cell->setAttribute('style', 'endArrow=none;html=1;rounded=1;strokeWidth=1.5;strokeColor=#'.($l['color'] ?: '5b6977').';'
				.((int) $l['drawtype'] === 2 ? 'dashed=1;' : ''));
			$cell->setAttribute('edge', '1');
			$cell->setAttribute('parent', '1');
			$cell->setAttribute('source', $ids[$l['selementid1']]);
			$cell->setAttribute('target', $ids[$l['selementid2']]);
			$g = $doc->createElement('mxGeometry');
			$g->setAttribute('relative', '1');
			$g->setAttribute('as', 'geometry');
			$cell->appendChild($g);
			$root->appendChild($cell);
		}

		return ['xml' => $doc->saveXML($doc->documentElement), 'name' => $map['name'], 'hosts' => count($hostids)];
	}

	private static function geo(DOMDocument $doc, $x, $y, $w, $h) {
		$g = $doc->createElement('mxGeometry');
		foreach (['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h] as $k => $v) {
			$g->setAttribute($k, (string) (int) $v);
		}
		$g->setAttribute('as', 'geometry');

		return $g;
	}
}
