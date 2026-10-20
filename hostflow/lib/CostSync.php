<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use API;
use Modules\HostFlow\Lib\Aws\{Client, Credentials, Http};
use Throwable;

/**
 * The once-a-day cost refresh. Runs as a Super admin (manually, or automatically when a
 * Super admin opens a cost view and the caches are older than a day), so it sees every host.
 *
 *   1. transfer   GB out per host this month, from Zabbix network trends
 *   2. aws        instances by IP (with safeguards), volumes, S3 buckets, billed cost
 *   3. pricing    AWS Pricing API prices for the instance types and regions in use
 *   4. snapshot   today's totals per client, for the 12-month trend
 * Each step reports its own errors; one failing step does not stop the others.
 */
class CostSync {

	public const MAX_AGE = 86400;
	public const INSTANCE_TAG = 'aws-instance-id';

	private array $cfg;
	private Http $http;
	private array $report = ['steps' => [], 'errors' => []];

	public function __construct(?array $cfg = null, ?Http $http = null) {
		$this->cfg = $cfg ?? Config::get();
		$this->http = $http ?? new Http();
	}

	public static function stale(): bool {
		$age = CostCache::age('pricing.json') ?? PHP_INT_MAX;
		$snap = Storage::path('cost', 'snapshots', gmdate('Y-m-d').'.json');

		return $age > self::MAX_AGE || !is_file($snap);
	}

	public function run(): array {
		return Storage::withLock('cost-sync', function () {
			$hosts = ZabbixModel::hostsWith([]);
			$this->step('transfer', fn() => $this->transfer($hosts));
			if ($this->cfg['aws']['enabled'] && $this->cfg['aws']['accounts']) {
				$this->step('aws', fn() => $this->aws($hosts));
			}
			if ($this->cfg['cost']['pricing_api'] && $this->cfg['aws']['accounts']) {
				$this->step('pricing', fn() => $this->pricing());
			}
			$this->step('snapshot', fn() => $this->snapshot());
			Audit::log('cost.sync', ['steps' => array_keys($this->report['steps']), 'errors' => count($this->report['errors'])]);

			return $this->report;
		}, false) ?? ['steps' => [], 'errors' => ['Another refresh is already running.']];
	}

	private function step(string $name, callable $fn): void {
		$t = microtime(true);
		try {
			$this->report['steps'][$name] = ['ok' => true, 'detail' => $fn(), 'seconds' => round(microtime(true) - $t, 1)];
		}
		catch (Throwable $e) {
			$this->report['steps'][$name] = ['ok' => false, 'detail' => $e->getMessage(), 'seconds' => round(microtime(true) - $t, 1)];
			$this->report['errors'][] = "$name: ".$e->getMessage();
		}
	}

	// ------------------------------------------------------------------ 1. transfer

	private function transfer(array $hosts): string {
		$key = $this->cfg['cost']['transfer']['item_key'];
		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'units'],
			'hostids' => array_column($hosts, 'hostid'),
			'search' => ['key_' => $key],
			'startSearch' => true,
			'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
			'templated' => false
		]);
		$items = array_values(array_filter($items, static fn($i) => !preg_match('/\[\s*"?(lo|lo0|Loopback)/i', $i['key_'])));
		$from = strtotime(gmdate('Y-m-01'));
		$out = ['month' => gmdate('Y-m'), 'hosts' => []];
		if ($items) {
			$bits = [];
			foreach ($items as $i) {
				$bits[$i['itemid']] = $i;
			}
			foreach (array_chunk(array_keys($bits), 500) as $chunk) {
				foreach (API::Trend()->get(['output' => ['itemid', 'clock', 'value_avg'], 'itemids' => $chunk, 'time_from' => $from]) as $t) {
					$i = $bits[$t['itemid']];
					// net.if.out is bits per second (units bps); an hour moves avg × 3600 / 8 bytes.
					$bytes = (float) $t['value_avg'] * 3600 / ($i['units'] === 'Bps' || $i['units'] === 'B' ? 1 : 8);
					$out['hosts'][$i['hostid']]['bytes'] = ($out['hosts'][$i['hostid']]['bytes'] ?? 0) + $bytes;
				}
			}
			$elapsed = max(1, time() - $from);
			$monthSecs = (int) gmdate('t') * 86400;
			foreach ($out['hosts'] as &$h) {
				// Project the month from the days seen so far.
				$h['gb_month'] = round($h['bytes'] / 1073741824 * $monthSecs / $elapsed, 2);
			}
			unset($h);
		}
		CostCache::write('transfer.json', $out);

		return sprintf('%d hosts with network-out trends', count($out['hosts']));
	}

	// ------------------------------------------------------------------ 2. aws

	private function aws(array $hosts): string {
		$prev = CostCache::aws();
		$instances = [];
		$volumes = [];
		$buckets = [];
		$errors = [];
		$clientTag = $this->cfg['aws']['bucket_client_tag'] ?? 'client';
		$clients = array_keys(ZabbixModel::clients($this->cfg));

		foreach ($this->cfg['aws']['accounts'] as $acc) {
			try {
				$client = new Client(Credentials::forAccount($acc, $this->http), $this->http);
				foreach ($acc['regions'] ?: [$this->cfg['cost']['default_region']] as $region) {
					$list = $client->describeInstances($region, $acc['vpcs']);
					$volIds = [];
					foreach ($list as $i) {
						$i['account'] = $acc['id'];
						$instances[$i['instance_id']] = $i;
						$volIds = array_merge($volIds, $i['volumes']);
					}
					if ($volIds) {
						$volumes += $client->describeVolumes($region, $volIds);
					}
				}
				foreach ($client->listBuckets() as $b) {
					try {
						$region = $client->bucketRegion($b);
						$tags = $client->bucketTags($b, $region);
						$owner = $tags[$clientTag] ?? '';
						if ($owner === '' || !in_array($owner, $clients, true)) {
							continue;
						}
						$buckets[] = ['name' => $b, 'region' => $region, 'client' => $owner, 'account' => $acc['id'],
							'bytes' => $client->bucketSizes($b, $region)];
					}
					catch (Throwable $e) {
						$errors[] = "bucket $b: ".$e->getMessage();
					}
				}
			}
			catch (Throwable $e) {
				$errors[] = 'account '.$acc['id'].': '.$e->getMessage();
			}
		}

		[$matches, $ambiguous, $moved] = self::match($hosts, $instances, $volumes, $prev['matches'] ?? []);

		$actual = [];
		$unmatched = [];
		try {
			[$actual, $unmatched] = $this->actualCost($instances, $matches, $buckets);
		}
		catch (Throwable $e) {
			$errors[] = 'billed cost: '.$e->getMessage();
		}

		CostCache::write('aws.json', [
			'synced_at' => time(),
			'instances' => count($instances),
			'matches' => $matches,
			'ambiguous' => $ambiguous,
			'moved' => $moved,
			'buckets' => $buckets,
			'actual' => $actual,
			'unmatched' => $unmatched,
			'errors' => $errors
		]);
		if ($errors && !$instances) {
			throw new \RuntimeException(implode('; ', $errors));
		}

		return sprintf('%d instances, %d hosts matched, %d ambiguous, %d moved, %d buckets%s', count($instances), count($matches),
			count($ambiguous), count($moved), count($buckets), $errors ? '; '.count($errors).' warning(s)' : '');
	}

	/**
	 * Match Zabbix hosts to EC2 instances by IP.
	 *   - an aws-instance-id host tag wins over the IP
	 *   - one IP, several instances: never guessed, listed as ambiguous
	 *   - a host pinned to an instance whose IP now belongs to another instance is flagged as moved
	 */
	public static function match(array $hosts, array $instances, array $volumes, array $pins): array {
		$byIp = [];
		foreach ($instances as $i) {
			foreach (array_merge($i['private_ips'], $i['public_ips']) as $ip) {
				$byIp[$ip][$i['instance_id']] = $i;
			}
		}
		$matches = [];
		$ambiguous = [];
		$moved = [];
		foreach ($hosts as $h) {
			$hid = $h['hostid'];
			$forced = null;
			foreach ($h['tags'] ?? [] as $t) {
				if ($t['tag'] === self::INSTANCE_TAG && isset($instances[$t['value']])) {
					$forced = $instances[$t['value']];
				}
			}
			$cands = $forced !== null ? [$forced['instance_id'] => $forced] : ($byIp[$h['ip'] ?? ''] ?? []);
			if (!$cands) {
				continue;
			}
			if (count($cands) > 1) {
				$ambiguous[$hid] = ['ip' => $h['ip'], 'candidates' => array_keys($cands)];
				$matches[$hid] = ['ambiguous' => true, 'instance_id' => null, 'candidates' => array_keys($cands)];
				continue;
			}
			$i = reset($cands);
			$pinned = $pins[$hid]['instance_id'] ?? null;
			if ($forced === null && $pinned !== null && $pinned !== $i['instance_id'] && isset($instances[$pinned])) {
				// The pinned instance still exists, but this IP now points elsewhere: keep the pin, flag it.
				$moved[$hid] = ['ip' => $h['ip'], 'pinned' => $pinned, 'now' => $i['instance_id']];
				$i = $instances[$pinned];
			}
			$ebs = 0;
			foreach ($i['volumes'] as $v) {
				$ebs += $volumes[$v]['size_gb'] ?? 0;
			}
			$matches[$hid] = [
				'instance_id' => $i['instance_id'], 'instance_type' => $i['instance_type'], 'region' => $i['region'],
				'account' => $i['account'] ?? '', 'state' => $i['state'], 'ebs_gb' => $ebs, 'public_ipv4' => count($i['public_ips']),
				'volumes' => $i['volumes'], 'by' => $forced !== null ? 'tag' : 'ip'
			];
		}

		return [$matches, $ambiguous, $moved];
	}

	/** Month-to-date billed cost per instance (with its volumes) and per other resource. */
	private function actualCost(array $instances, array $matches, array $buckets): array {
		$aws = $this->cfg['aws'];
		$rows = [];
		if ($aws['source'] === 'cost_explorer') {
			$acc = $aws['accounts'][0];
			$client = new Client(Credentials::forAccount($acc, $this->http), $this->http);
			$rows = $client->costByResource(gmdate('Y-m-d', max(strtotime(gmdate('Y-m-01')), time() - 13 * 86400)), gmdate('Y-m-d', time() + 86400));
		}
		elseif ($aws['cur']['bucket'] !== '') {
			$acc = null;
			foreach ($aws['accounts'] as $a) {
				if ($a['id'] === ($aws['cur']['account'] ?: $a['id'])) {
					$acc = $a;
					break;
				}
			}
			$client = new Client(Credentials::forAccount($acc, $this->http), $this->http);
			$rows = self::readCur($client, $aws['cur']);
		}
		else {
			return [[], []];
		}

		$volToInstance = [];
		foreach ($instances as $i) {
			foreach ($i['volumes'] as $v) {
				$volToInstance[$v] = $i['instance_id'];
			}
		}
		$known = array_fill_keys(array_merge(array_keys($instances), array_column($buckets, 'name')), true);
		foreach ($this->cfg['cost']['cost_only'] as $x) {
			if ($x['resource_id'] !== '') {
				$known[$x['resource_id']] = true;
			}
		}
		$matchedInstances = array_fill_keys(array_filter(array_column($matches, 'instance_id')), true);

		$actual = [];
		$other = [];
		foreach ($rows as $r) {
			$rid = $r['resource_id'];
			if ($rid === '' || $r['cost'] == 0) {
				continue;
			}
			$part = self::category($r['usage_type']);
			$target = $volToInstance[$rid] ?? (preg_match('#instance/(i-[0-9a-f]+)#', $rid, $m) ? $m[1] : $rid);
			$actual[$target]['parts'][$part] = ($actual[$target]['parts'][$part] ?? 0) + $r['cost'];
			$actual[$target]['total'] = ($actual[$target]['total'] ?? 0) + $r['cost'];
			if (!isset($known[$target]) && !isset($volToInstance[$rid])) {
				$other[$target] = ($other[$target] ?? 0) + $r['cost'];
			}
		}
		$unmatched = [];
		foreach ($actual as $id => $a) {
			if (isset($instances[$id]) && !isset($matchedInstances[$id])) {
				$unmatched[] = ['resource_id' => $id, 'kind' => 'instance without a Zabbix host', 'monthly' => round($a['total'], 2)];
			}
		}
		arsort($other);
		foreach (array_slice($other, 0, 50, true) as $id => $c) {
			if ($c >= 1) {
				$unmatched[] = ['resource_id' => $id, 'kind' => 'resource not on any diagram', 'monthly' => round($c, 2)];
			}
		}

		return [$actual, $unmatched];
	}

	public static function category(string $usage): string {
		if (preg_match('/BoxUsage|SpotUsage|DedicatedUsage|HostUsage|UnusedBox/', $usage)) {
			return 'ec2';
		}
		if (strpos($usage, 'EBS:Snapshot') !== false) {
			return 'snapshots';
		}
		if (strpos($usage, 'EBS:') !== false) {
			return 'ebs';
		}
		if (strpos($usage, 'PublicIPv4') !== false) {
			return 'ipv4';
		}
		if (preg_match('/DataTransfer|AWS-Out-Bytes|AWS-In-Bytes|-Bytes$/', $usage)) {
			return 'transfer';
		}

		return 'other';
	}

	/** CUR (CSV.gz) for the current billing period: resource id, usage type, unblended cost. */
	public static function readCur(Client $client, array $cur): array {
		$period = gmdate('Y-m');
		$objects = $client->listObjects($cur['bucket'], $cur['region'], $cur['prefix'] === '' ? '' : $cur['prefix'].'/');
		$files = array_values(array_filter($objects, static fn($o) =>
			preg_match('/\.csv\.gz$/', $o['key']) && (strpos($o['key'], 'BILLING_PERIOD='.$period) !== false
				|| strpos($o['key'], str_replace('-', '', $period).'01-') !== false)
		));
		if (!$files) {
			throw new \RuntimeException("No CSV.gz files for $period under s3://{$cur['bucket']}/{$cur['prefix']}. (Parquet exports are not supported; choose CSV in the data export.)");
		}
		$rows = [];
		foreach ($files as $f) {
			$tmp = tempnam(sys_get_temp_dir(), 'hfcur');
			try {
				$client->getObjectToFile($cur['bucket'], $cur['region'], $f['key'], $tmp);
				$rows = array_merge($rows, self::parseCurCsv($tmp));
			}
			finally {
				@unlink($tmp);
			}
		}

		return $rows;
	}

	/** Reads both CUR 2.0 (line_item_resource_id) and legacy (lineItem/ResourceId) headers. */
	public static function parseCurCsv(string $gzFile): array {
		$fh = gzopen($gzFile, 'rb');
		if ($fh === false) {
			throw new \RuntimeException('A CUR file could not be opened.');
		}
		$head = fgetcsv($fh, 0, ',', '"', '\\');
		$idx = [];
		foreach ((array) $head as $i => $h) {
			$idx[strtolower(str_replace(['/', ' '], '_', (string) $h))] = $i;
		}
		$col = static function (array $names) use ($idx) {
			foreach ($names as $n) {
				if (isset($idx[$n])) {
					return $idx[$n];
				}
			}

			return null;
		};
		$rid = $col(['line_item_resource_id', 'lineitem_resourceid']);
		$ut = $col(['line_item_usage_type', 'lineitem_usagetype']);
		$cost = $col(['line_item_unblended_cost', 'lineitem_unblendedcost']);
		if ($rid === null || $ut === null || $cost === null) {
			gzclose($fh);
			throw new \RuntimeException('The CUR file has no resource id / usage type / unblended cost columns. Turn on "Include resource IDs" in the export.');
		}
		$rows = [];
		while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
			if (!isset($r[$cost]) || $r[$rid] === '') {
				continue;
			}
			$rows[] = ['resource_id' => $r[$rid], 'usage_type' => $r[$ut], 'cost' => (float) $r[$cost]];
		}
		gzclose($fh);

		return $rows;
	}

	// ------------------------------------------------------------------ 3. pricing

	private function pricing(): string {
		$aws = CostCache::aws();
		$want = [];
		foreach ($aws['matches'] ?? [] as $m) {
			if (!empty($m['instance_type'])) {
				$want[$m['region']][$m['instance_type']] = true;
			}
		}
		foreach ($this->cfg['cost']['rules'] as $r) {
			if ($r['instance_type'] !== '') {
				$want[$r['region'] ?: $this->cfg['cost']['default_region']][$r['instance_type']] = true;
			}
		}
		$acc = $this->cfg['aws']['accounts'][0];
		$client = new Client(Credentials::forAccount($acc, $this->http), $this->http);
		$prev = CostCache::pricing();
		$out = ['fetched_at' => time(), 'ec2' => $prev['ec2'] ?? [], 'ebs' => $prev['ebs'] ?? []];
		$n = 0;
		foreach ($want as $region => $types) {
			foreach (array_keys($types) as $type) {
				$p = $client->ec2Price($region, $type);
				if ($p !== null) {
					$out['ec2'][$region][$type] = $p;
					$n++;
				}
			}
			$out['ebs'][$region] = $client->ebsPrices($region) ?: ($out['ebs'][$region] ?? []);
		}
		CostCache::write('pricing.json', $out);

		return sprintf('%d instance prices in %d region(s)', $n, count($want));
	}

	// ------------------------------------------------------------------ 4. snapshot

	private function snapshot(): string {
		$totals = ['total' => 0.0, 'clients' => [], 'components' => []];
		foreach (array_keys(ZabbixModel::clients($this->cfg)) as $client) {
			$m = ZabbixModel::clientModel($client, $this->cfg);
			$hosts = [];
			foreach (array_merge($m['hosts'], $m['cluster']) as $h) {
				$hosts[$h['hostid']] = [
					'hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'], 'groups' => array_column($h['hostgroups'], 'name'),
					'tags' => $h['tags'] ?? [], 'component' => $h['component'] ?? null, 'disabled' => (int) $h['status'] === 1
				];
			}
			$c = CostModel::forDiagram($hosts, [], $client);
			$totals['clients'][$client] = $c['total'];
			$totals['components'][$client] = $c['components'];
			$totals['total'] += $c['total'];
		}
		$totals['total'] = round($totals['total'], 2);
		CostCache::snapshot($totals);

		return sprintf('%d clients, $%s per month', count($totals['clients']), number_format($totals['total'], 2));
	}
}
