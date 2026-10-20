<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/**
 * "Changes since your last visit": a per-user snapshot of each diagram's hosts
 * (users/<userid>/<diagramid>.json), compared with the live state.
 */
class Seen {

	public static function snapshot(array $hosts): array {
		$out = [];
		foreach ($hosts as $h) {
			$out[$h['hostid']] = ['name' => $h['name'], 'ip' => $h['ip'], 'worst' => $h['worst'], 'disabled' => $h['disabled']];
		}

		return $out;
	}

	public static function load(string $userid, string $diagramId): ?array {
		return Storage::readJson(Storage::path('users', self::uid($userid), $diagramId.'.json'));
	}

	public static function store(string $userid, string $diagramId, array $hosts): void {
		Storage::writeJson(Storage::path('users', self::uid($userid), $diagramId.'.json'),
			['at' => time(), 'hosts' => self::snapshot($hosts)]);
	}

	/**
	 * Differences between a stored snapshot and the current hosts:
	 * added, removed, re-addressed (IP changed), newly in trouble (worst severity went up to Warning or above).
	 */
	public static function diff(?array $stored, array $hosts): array {
		if ($stored === null) {
			return [];
		}
		$old = $stored['hosts'] ?? [];
		$now = self::snapshot($hosts);
		$out = [];
		foreach ($now as $id => $h) {
			if (!isset($old[$id])) {
				$out[] = ['hostid' => (string) $id, 'name' => $h['name'], 'kind' => 'added'];
				continue;
			}
			if ($old[$id]['ip'] !== $h['ip']) {
				$out[] = ['hostid' => (string) $id, 'name' => $h['name'], 'kind' => 'readdressed', 'from' => $old[$id]['ip'], 'to' => $h['ip']];
			}
			if ($h['worst'] >= 2 && $h['worst'] > $old[$id]['worst']) {
				$out[] = ['hostid' => (string) $id, 'name' => $h['name'], 'kind' => 'trouble', 'severity' => $h['worst']];
			}
		}
		foreach ($old as $id => $h) {
			if (!isset($now[$id])) {
				$out[] = ['hostid' => (string) $id, 'name' => $h['name'], 'kind' => 'removed'];
			}
		}

		return ['since' => (int) ($stored['at'] ?? 0), 'changes' => $out];
	}

	private static function uid(string $userid): string {
		return ctype_digit($userid) ? $userid : 'u'.substr(sha1($userid), 0, 12);
	}
}
