<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/**
 * Monthly cost per host in USD.
 *
 *   estimate = EC2 hourly × hours/month + EBS + snapshots + data transfer + public IPv4 + extras
 *   actual   = billed cost for the matched EC2 instance (from the daily AWS sync), when present
 *
 * Rates come from, in order: a monthly or hourly override in Settings → Cost, the cached
 * AWS Pricing API price for the region and instance type, or nothing (shown as "no price").
 * Rules apply component → group → host; a more specific rule wins field by field.
 */
class CostModel {

	/** Public list prices used only when neither Settings nor the Pricing API cache has one. */
	public const EBS_DEFAULT = ['gp3' => 0.08, 'gp2' => 0.10, 'io2' => 0.125, 'io1' => 0.125, 'st1' => 0.045, 'sc1' => 0.015];
	public const IOPS_RATE = ['gp3' => 0.005, 'io2' => 0.065, 'io1' => 0.065];
	public const GP3_FREE_IOPS = 3000;
	public const SNAPSHOT_RATE = 0.05;
	public const S3_RATES = ['STANDARD' => 0.023, 'STANDARD_IA' => 0.0125, 'ONEZONE_IA' => 0.01, 'INTELLIGENT_TIERING' => 0.023,
		'GLACIER_IR' => 0.004, 'GLACIER' => 0.0036, 'DEEP_ARCHIVE' => 0.00099];

	/**
	 * @param array  $hosts    LiveState hosts (hostid => [...], with groups, component, tags, disabled)
	 * @param array  $costIds  cost-only shapes on the diagram
	 */
	public static function forDiagram(array $hosts, array $costIds, string $client): array {
		$cfg = Config::get();
		$cost = $cfg['cost'];
		$pricing = CostCache::pricing();
		$aws = CostCache::aws();
		$transfer = CostCache::transfer();

		$out = ['currency' => 'USD', 'hosts' => [], 'cost_only' => [], 'components' => [], 'total' => 0.0,
			'pricing_at' => $pricing['fetched_at'] ?? null, 'aws_at' => $aws['synced_at'] ?? null];

		foreach ($hosts as $hid => $h) {
			$c = self::host($h, $cfg, $pricing, $aws, $transfer);
			$out['hosts'][$hid] = $c;
			if (!$c['excluded']) {
				$comp = $h['component'] ?? '';
				$out['components'][$comp] = round(($out['components'][$comp] ?? 0) + $c['monthly'], 2);
				$out['total'] += $c['monthly'];
			}
		}
		foreach (self::costOnlyFor($client, $cfg) as $x) {
			if ($costIds && !in_array($x['id'], $costIds, true)) {
				continue;
			}
			$m = self::costOnly($x, $aws);
			$out['cost_only'][$x['id']] = $m;
			$out['components'][$x['component']] = round(($out['components'][$x['component']] ?? 0) + $m['monthly'], 2);
			$out['total'] += $m['monthly'];
		}
		$out['total'] = round($out['total'], 2);

		return $out;
	}

	public static function host(array $h, array $cfg, array $pricing, array $aws, array $transfer): array {
		$cost = $cfg['cost'];
		$parts = ['ec2' => 0.0, 'ebs' => 0.0, 'snapshots' => 0.0, 'transfer' => 0.0, 'ipv4' => 0.0, 'extras' => 0.0];
		$result = ['monthly' => 0.0, 'parts' => $parts, 'source' => 'none', 'excluded' => false, 'instance' => null];

		if (self::excluded($h, $cost) || (!$cost['count_disabled'] && !empty($h['disabled']))) {
			$result['excluded'] = true;

			return $result;
		}

		$r = self::effectiveRule($h, $cost['rules']);
		$hours = $cost['hours_per_month'];
		$region = $r['region'] ?: $cost['default_region'];
		$basis = $r['basis'] ?: $cost['pricing_basis'];
		$match = $aws['matches'][$h['hostid']] ?? null;
		if ($match !== null && empty($match['ambiguous'])) {
			$result['instance'] = $match['instance_id'];
			$region = $match['region'] ?? $region;
		}
		$type = $r['instance_type'] ?: ($match['instance_type'] ?? '');

		if ($r['monthly'] !== null) {
			$parts['ec2'] = $r['monthly'];
			$result['source'] = 'override';
		}
		elseif ($r['hourly'] !== null) {
			$parts['ec2'] = $r['hourly'] * $hours;
			$result['source'] = 'override';
		}
		elseif ($type !== '' && isset($pricing['ec2'][$region][$type])) {
			$p = $pricing['ec2'][$region][$type];
			$parts['ec2'] = ($p[$basis] ?? $p['on_demand']) * $hours;
			$result['source'] = 'api';
		}

		$ebsType = $r['ebs_type'];
		$ebsRate = $r['ebs_rate'] ?? ($pricing['ebs'][$region][$ebsType] ?? self::EBS_DEFAULT[$ebsType]);
		$ebsGb = $r['ebs_gb'] ?: (float) ($match['ebs_gb'] ?? 0);
		$parts['ebs'] = $ebsGb * $ebsRate;
		if ($r['iops'] > 0 && isset(self::IOPS_RATE[$ebsType])) {
			$extra = $ebsType === 'gp3' ? max(0, $r['iops'] - self::GP3_FREE_IOPS) : $r['iops'];
			$parts['ebs'] += $extra * self::IOPS_RATE[$ebsType];
		}
		$parts['snapshots'] = $r['snapshot_gb'] * self::SNAPSHOT_RATE;

		$gbOut = (float) ($transfer['hosts'][$h['hostid']]['gb_month'] ?? 0);
		$t = $cost['transfer'];
		$parts['transfer'] = $gbOut * ($t['internet_share'] * $t['internet_rate'] + $t['cross_az_share'] * $t['cross_az_rate']
			+ $t['cross_region_share'] * $t['cross_region_rate']);

		$ips = $r['public_ipv4'] ?: (int) ($match['public_ipv4'] ?? 0);
		$parts['ipv4'] = $ips * $cost['ipv4_hourly'] * $hours;
		$parts['extras'] = $r['extras'];

		$actual = $match !== null ? ($aws['actual'][$match['instance_id']] ?? null) : null;
		if ($actual !== null) {
			$parts = array_merge($parts, array_intersect_key($actual['parts'] ?? [], $parts));
			$parts['extras'] = $r['extras'];
			$result['source'] = 'actual';
		}
		elseif ($result['source'] === 'none' && array_sum($parts) > 0) {
			$result['source'] = 'partial';
		}

		$result['parts'] = array_map(static fn($v) => round($v, 2), $parts);
		$result['monthly'] = round(array_sum($parts), 2);
		$result['type'] = $type;
		$result['region'] = $region;

		return $result;
	}

	/** Cost-only resources (AWS VPN, S3 buckets…) configured for a client, plus S3 buckets found by tag. */
	public static function costOnlyFor(string $client, ?array $cfg = null): array {
		$cfg = $cfg ?? Config::get();
		$out = [];
		foreach ($cfg['cost']['cost_only'] as $x) {
			if ($x['client'] === '' || $x['client'] === $client) {
				$out[] = $x;
			}
		}
		foreach (CostCache::aws()['buckets'] ?? [] as $b) {
			if (($b['client'] ?? '') === $client && $client !== '') {
				$out[] = ['id' => 's3-'.substr(sha1($b['name']), 0, 10), 'type' => 's3_bucket', 'name' => $b['name'], 'client' => $client,
					'component' => 's3', 'resource_id' => $b['name'], 'monthly' => null];
			}
		}

		return $out;
	}

	public static function costOnly(array $x, array $aws): array {
		if ($x['monthly'] !== null) {
			return ['name' => $x['name'], 'monthly' => round($x['monthly'], 2), 'source' => 'override'];
		}
		if ($x['resource_id'] !== '' && isset($aws['actual'][$x['resource_id']])) {
			return ['name' => $x['name'], 'monthly' => round($aws['actual'][$x['resource_id']]['total'], 2), 'source' => 'actual'];
		}
		if ($x['type'] === 's3_bucket') {
			$b = null;
			foreach ($aws['buckets'] ?? [] as $bb) {
				if ($bb['name'] === ($x['resource_id'] ?: $x['name'])) {
					$b = $bb;
				}
			}
			if ($b !== null) {
				$m = 0.0;
				foreach ($b['bytes'] ?? [] as $class => $bytes) {
					$m += $bytes / 1073741824 * (self::S3_RATES[$class] ?? self::S3_RATES['STANDARD']);
				}

				return ['name' => $x['name'], 'monthly' => round($m, 2), 'source' => 'estimate', 'size_gb' => round(array_sum($b['bytes']) / 1073741824, 1)];
			}
		}
		if ($x['type'] === 'vpn_connection') {
			// Site-to-Site VPN connection-hours at the common list price.
			return ['name' => $x['name'], 'monthly' => round(0.05 * Config::get()['cost']['hours_per_month'], 2), 'source' => 'estimate'];
		}

		return ['name' => $x['name'], 'monthly' => 0.0, 'source' => 'none'];
	}

	public static function excluded(array $h, array $cost): bool {
		$ex = $cost['excludes'];
		if (in_array($h['hostid'], $ex['hosts'], true) || in_array($h['host'] ?? '', $ex['hosts'], true) || in_array($h['name'] ?? '', $ex['hosts'], true)) {
			return true;
		}
		if (array_intersect($ex['groups'], $h['groups'] ?? [])) {
			return true;
		}
		foreach ($ex['tags'] as $want) {
			foreach ($h['tags'] ?? [] as $t) {
				if ($t['tag'] === $want['tag'] && ($want['value'] === '' || $t['value'] === $want['value'])) {
					return true;
				}
			}
		}

		return false;
	}

	/** Merge matching rules: component first, then group, then host; non-empty fields win. */
	public static function effectiveRule(array $h, array $rules): array {
		$eff = ['region' => '', 'instance_type' => '', 'basis' => '', 'hourly' => null, 'monthly' => null, 'ebs_gb' => 0.0,
			'ebs_type' => 'gp3', 'ebs_rate' => null, 'iops' => 0, 'snapshot_gb' => 0.0, 'public_ipv4' => 0, 'extras' => 0.0];
		foreach (['component', 'group', 'host'] as $scope) {
			foreach ($rules as $r) {
				if ($r['scope'] !== $scope) {
					continue;
				}
				$hit = ($scope === 'component' && $r['target'] === ($h['component'] ?? ''))
					|| ($scope === 'group' && in_array($r['target'], $h['groups'] ?? [], true))
					|| ($scope === 'host' && in_array($r['target'], [$h['hostid'], $h['host'] ?? '', $h['name'] ?? ''], true));
				if (!$hit) {
					continue;
				}
				foreach ($r as $k => $v) {
					if ($k === 'scope' || $k === 'target') {
						continue;
					}
					if ($v !== null && $v !== '' && $v !== 0 && $v !== 0.0) {
						$eff[$k] = $v;
					}
				}
			}
		}

		return $eff;
	}
}
