<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CControllerResponseData;
use Modules\HostFlow\Lib\{CostModel, ZabbixModel};

class CostExport extends Base {

	protected function checkPermissions(): bool {
		return parent::checkPermissions() && $this->canSeeCost();
	}

	protected function handle(): void {
		$rows = [['client', 'component', 'host', 'ip', 'instance', 'type', 'region', 'source', 'ec2', 'ebs', 'snapshots', 'transfer', 'ipv4', 'extras', 'monthly_usd']];
		foreach (array_keys(ZabbixModel::clients()) as $client) {
			$m = ZabbixModel::clientModel($client);
			$hosts = [];
			foreach (array_merge($m['hosts'], $m['cluster']) as $h) {
				$hosts[$h['hostid']] = ['hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'], 'ip' => $h['ip'],
					'groups' => array_column($h['hostgroups'], 'name'), 'tags' => $h['tags'] ?? [],
					'component' => $h['component'] ?? 'cluster', 'disabled' => (int) $h['status'] === 1];
			}
			$c = CostModel::forDiagram($hosts, [], $client);
			foreach ($hosts as $hid => $h) {
				$x = $c['hosts'][$hid];
				if ($x['excluded']) {
					continue;
				}
				$rows[] = [$client, $h['component'], $h['name'], $h['ip'], $x['instance'] ?? '', $x['type'] ?? '', $x['region'] ?? '', $x['source'],
					$x['parts']['ec2'], $x['parts']['ebs'], $x['parts']['snapshots'], $x['parts']['transfer'], $x['parts']['ipv4'], $x['parts']['extras'], $x['monthly']];
			}
			foreach ($c['cost_only'] as $x) {
				$rows[] = [$client, 'cost only', $x['name'], '', '', '', '', $x['source'], '', '', '', '', '', '', $x['monthly']];
			}
		}
		$csv = '';
		foreach ($rows as $r) {
			$csv .= implode(',', array_map(static function ($v) {
				$v = (string) $v;
				if ($v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) {
					$v = "'".$v;   // keep spreadsheets from treating names as formulas
				}

				return '"'.str_replace('"', '""', $v).'"';
			}, $r))."\r\n";
		}
		$response = new CControllerResponseData(['main_block' => $csv]);
		$response->setFileName('host-flow-cost-'.gmdate('Y-m-d').'.csv');
		$this->setResponse($response);
	}
}
