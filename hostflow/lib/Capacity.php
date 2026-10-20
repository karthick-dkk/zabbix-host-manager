<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use API;

/**
 * CPU, memory and disk size and use per host, from the items the standard Zabbix agent templates
 * create (Linux and Windows by Zabbix agent, active or passive). One item.get for all hosts.
 *
 *   cpu_num   system.cpu.num
 *   cpu_pct   system.cpu.util, or 100 − system.cpu.util[,idle]
 *   mem_total vm.memory.size[total]
 *   mem_pct   vm.memory.utilization, vm.memory.size[pused], or from available / pavailable
 *   disk_*    every filesystem from vfs.fs.size[…] / vfs.fs.dependent.size[…] (total, used, pused), summed
 */
class Capacity {

	public const MAX_HOSTS = 500;

	public static function forHosts(array $hostids): array {
		$hostids = array_slice(array_values(array_unique(array_map('strval', $hostids))), 0, self::MAX_HOSTS);
		if (!$hostids) {
			return [];
		}
		$items = API::Item()->get([
			'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
			'hostids' => $hostids,
			'search' => ['key_' => ['system.cpu.num', 'system.cpu.util', 'vm.memory.', 'vfs.fs.size[', 'vfs.fs.dependent.size[']],
			'searchByAny' => true,
			'startSearch' => true,
			'monitored' => true,
			'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]]
		]);

		$raw = [];
		foreach ($items as $i) {
			if ((int) $i['lastclock'] === 0) {
				continue;
			}
			$raw[$i['hostid']][$i['key_']] = (float) $i['lastvalue'];
		}

		$out = [];
		foreach ($raw as $hid => $k) {
			$out[$hid] = self::fromKeys($k);
		}

		return $out;
	}

	/** @param array $k item key => last value, for one host */
	public static function fromKeys(array $k): array {
		$first = static function (array $k, string $prefix): ?float {
			foreach ($k as $key => $v) {
				if ($key === $prefix || strncmp($key, $prefix.'[', strlen($prefix) + 1) === 0) {
					return $v;
				}
			}

			return null;
		};
		$pct = static fn(?float $v) => $v === null ? null : max(0.0, min(100.0, round($v, 1)));

		$cpuPct = $k['system.cpu.util'] ?? $k['system.cpu.util[]'] ?? null;
		if ($cpuPct === null && isset($k['system.cpu.util[,idle]'])) {
			$cpuPct = 100 - $k['system.cpu.util[,idle]'];
		}

		$memTotal = $k['vm.memory.size[total]'] ?? null;
		$memPct = $k['vm.memory.utilization'] ?? $k['vm.memory.size[pused]'] ?? null;
		if ($memPct === null && isset($k['vm.memory.size[pavailable]'])) {
			$memPct = 100 - $k['vm.memory.size[pavailable]'];
		}
		if ($memPct === null && $memTotal && isset($k['vm.memory.size[available]'])) {
			$memPct = 100 - $k['vm.memory.size[available]'] / $memTotal * 100;
		}

		$fs = [];
		foreach ($k as $key => $v) {
			if (preg_match('/^vfs\.fs\.(?:dependent\.)?size\[("?)([^,\]"]+)\1,(total|used|pused)\]$/', $key, $m)) {
				$fs[$m[2]][$m[3]] = $v;
			}
		}
		$diskTotal = 0.0;
		$diskUsed = 0.0;
		foreach ($fs as $f) {
			if (empty($f['total'])) {
				continue;
			}
			$diskTotal += $f['total'];
			$diskUsed += $f['used'] ?? (isset($f['pused']) ? $f['pused'] / 100 * $f['total'] : 0);
		}

		return [
			'cpu_num' => ($n = $first($k, 'system.cpu.num')) !== null ? (int) $n : null,
			'cpu_pct' => $pct($cpuPct),
			'mem_total' => $memTotal,
			'mem_pct' => $pct($memPct),
			'disk_total' => $diskTotal > 0 ? $diskTotal : null,
			'disk_pct' => $diskTotal > 0 ? $pct($diskUsed / $diskTotal * 100) : null,
			'disk_fs' => count($fs)
		];
	}
}
