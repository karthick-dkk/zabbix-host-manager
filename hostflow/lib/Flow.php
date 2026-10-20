<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/**
 * The configurable data-flow graph: components (tiers) and arrows between them.
 */
class Flow {

	/**
	 * Column for each component. Uses the component's own column setting when the
	 * graph doesn't say otherwise; arrows that would loop back (ES → AIML → ES) are ignored
	 * when ranking, so loops are drawn as return arrows.
	 */
	public static function columns(array $components, array $edges): array {
		$ids = array_column($components, 'id');
		$out = [];
		$in = array_fill_keys($ids, 0);
		$next = array_fill_keys($ids, []);
		foreach (self::acyclic($ids, $edges) as [$a, $b]) {
			$next[$a][] = $b;
			$in[$b]++;
		}
		$rank = [];
		$queue = array_keys(array_filter($in, static fn($n) => $n === 0));
		foreach ($queue as $id) {
			$rank[$id] = 0;
		}
		while ($queue) {
			$id = array_shift($queue);
			foreach ($next[$id] as $b) {
				$rank[$b] = max($rank[$b] ?? 0, $rank[$id] + 1);
				if (--$in[$b] === 0) {
					$queue[] = $b;
				}
			}
		}
		$hasEdges = [];
		$pairs = [];
		foreach ($edges as $e) {
			$hasEdges[$e['from']] = $hasEdges[$e['to']] = true;
			$pairs[$e['from']][$e['to']] = true;
		}
		// A side feed (SOAR → ES) sits just before the tier it feeds, not at the far left.
		foreach ($ids as $id) {
			if (($rank[$id] ?? null) === 0 && !empty($next[$id])) {
				$targets = array_map(static fn($b) => $rank[$b] ?? 1, $next[$id]);
				$rank[$id] = max(0, min($targets) - 1);
			}
		}
		// A two-way partner (ES ⇄ AIML) shares the column of the tier it loops with.
		foreach ($pairs as $a => $tos) {
			foreach ($tos as $b => $_) {
				if (isset($pairs[$b][$a]) && isset($rank[$a], $rank[$b]) && $rank[$b] > $rank[$a]) {
					$rank[$b] = $rank[$a];
				}
			}
		}
		foreach ($components as $c) {
			$out[$c['id']] = isset($hasEdges[$c['id']]) ? ($rank[$c['id']] ?? $c['column']) : $c['column'];
		}

		return $out;
	}

	/** Components downstream of $from (following arrows), not including $from itself. */
	public static function downstream(string $from, array $edges): array {
		$seen = [];
		$stack = [$from];
		while ($stack) {
			$id = array_pop($stack);
			foreach ($edges as $e) {
				if ($e['from'] === $id && !isset($seen[$e['to']]) && $e['to'] !== $from) {
					$seen[$e['to']] = true;
					$stack[] = $e['to'];
				}
			}
		}

		return array_keys($seen);
	}

	/** Edges with back-edges removed (depth-first over the configured order). */
	private static function acyclic(array $ids, array $edges): array {
		$adj = array_fill_keys($ids, []);
		foreach ($edges as $e) {
			if (isset($adj[$e['from']], $adj[$e['to']])) {
				$adj[$e['from']][] = $e['to'];
			}
		}
		$state = [];
		$keep = [];
		$visit = static function ($u) use (&$visit, &$state, &$keep, $adj) {
			$state[$u] = 1;
			foreach ($adj[$u] as $v) {
				if (($state[$v] ?? 0) === 1) {
					continue;
				}
				$keep[] = [$u, $v];
				if (($state[$v] ?? 0) === 0) {
					$visit($v);
				}
			}
			$state[$u] = 2;
		};
		foreach ($ids as $id) {
			if (($state[$id] ?? 0) === 0) {
				$visit($id);
			}
		}

		return $keep;
	}
}
