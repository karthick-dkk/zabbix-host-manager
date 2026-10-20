<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/**
 * Daily caches under cost/:
 *   pricing.json   AWS Pricing API prices (ec2[region][type][basis], ebs[region][type])
 *   aws.json       instance matches by IP, billed cost, S3 buckets (from the AWS sync)
 *   transfer.json  GB out per host this month (from Zabbix network trends)
 *   snapshots/YYYY-MM-DD.json  each day's totals, for the 12-month trend
 * Live views only read these files; they never call AWS.
 */
class CostCache {

	private static array $memo = [];

	public static function pricing(): array {
		return self::read('pricing.json');
	}

	public static function aws(): array {
		return self::read('aws.json');
	}

	public static function transfer(): array {
		return self::read('transfer.json');
	}

	public static function write(string $name, array $data): void {
		$data['written_at'] = time();
		Storage::writeJson(Storage::path('cost', $name), $data);
		self::$memo[$name] = $data;
	}

	public static function age(string $name): ?int {
		$f = Storage::path('cost', $name);

		return is_file($f) ? time() - filemtime($f) : null;
	}

	/** One snapshot per day; a later write the same day replaces it. */
	public static function snapshot(array $totals): void {
		Storage::writeJson(Storage::path('cost', 'snapshots', gmdate('Y-m-d').'.json'), ['at' => time()] + $totals);
		foreach (glob(Storage::path('cost', 'snapshots').'/*.json') ?: [] as $f) {
			if (basename($f) < gmdate('Y-m-d', strtotime('-400 days')).'.json') {
				@unlink($f);
			}
		}
	}

	/**
	 * Monthly trend for the last 12 months: the average daily snapshot total in each month,
	 * per client and per component.
	 */
	public static function trend(int $months = 12): array {
		$by = [];
		foreach (glob(Storage::path('cost', 'snapshots').'/*.json') ?: [] as $f) {
			$day = basename($f, '.json');
			$month = substr($day, 0, 7);
			if ($month < gmdate('Y-m', strtotime('-'.($months - 1).' months', strtotime(gmdate('Y-m-01'))))) {
				continue;
			}
			$s = Storage::readJson($f, []);
			$by[$month]['days'] = ($by[$month]['days'] ?? 0) + 1;
			$by[$month]['total'] = ($by[$month]['total'] ?? 0) + ($s['total'] ?? 0);
			foreach ($s['clients'] ?? [] as $client => $v) {
				$by[$month]['clients'][$client] = ($by[$month]['clients'][$client] ?? 0) + $v;
			}
		}
		ksort($by);
		$out = [];
		foreach ($by as $month => $v) {
			$n = max(1, $v['days']);
			$out[] = [
				'month' => $month,
				'total' => round($v['total'] / $n, 2),
				'clients' => array_map(static fn($x) => round($x / $n, 2), $v['clients'] ?? [])
			];
		}

		return $out;
	}

	private static function read(string $name): array {
		if (!isset(self::$memo[$name])) {
			$d = Storage::readJson(Storage::path('cost', $name), []);
			self::$memo[$name] = is_array($d) ? $d : [];
		}

		return self::$memo[$name];
	}
}
