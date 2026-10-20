<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/**
 * One flow per client, generated from Zabbix (Settings → Auto flows):
 *  - every client gets one automated flow (origin "auto"), next to any diagrams drawn by hand;
 *  - each client uses its flow template (macro on its cluster host, else the default template);
 *  - flows nobody has edited (auto = true) are redrawn at once when the client's template (its tiers,
 *    notes or arrows) or the layout in Settings changed, and checked for added or removed hosts at most
 *    every sync_min minutes;
 *  - an automated flow someone deleted is not created again; one saved in the editor stops following
 *    Zabbix (auto = false) but still counts as the client's automated flow, so no duplicate appears.
 * Runs as the logged-in Admin, so it sees what that user may see.
 */
class AutoFlows {

	public const KIND_NAMES = ['pipeline' => 'Pipeline', 'hierarchy' => 'Client hierarchy'];

	/**
	 * Which automated flows each client gets, as [kind => layout]. "both" (the default) gives two
	 * separate diagrams; any single layout gives one flow per client.
	 */
	public static function kinds(string $setting): array {
		switch ($setting) {
			case 'both':
				return ['pipeline' => 'dataflow', 'hierarchy' => 'hierarchy'];
			case 'hierarchy':
				return ['hierarchy' => 'hierarchy'];
			default:
				return ['pipeline' => $setting];
		}
	}

	/** Flows created before the split have no kind: a hierarchy layout is the hierarchy, anything else the pipeline. */
	public static function kindOf(array $d): string {
		return $d['auto_kind'] ?? (($d['layout'] ?? '') === 'hierarchy' ? 'hierarchy' : 'pipeline');
	}

	/** @return array{created: string[], updated: string[], clients: int, skipped: bool} */
	public static function ensure(bool $force = false): array {
		$cfg = Config::get();
		$out = ['created' => [], 'updated' => [], 'clients' => 0, 'skipped' => true];
		if (!$cfg['auto_flows']['enabled']) {
			return $out;
		}
		$state = Storage::path('auto-flows.json');
		$last = (int) (Storage::readJson($state, [])['checked_at'] ?? 0);
		if (!$force && $last > time() - 60) {
			return $out;
		}

		// Only one request does the work; the others return at once.
		$r = Storage::withLock('auto-flows', static function () use ($cfg, $state, $out) {
			Storage::writeJson($state, ['checked_at' => time()]);
			$out['skipped'] = false;
			$byClient = [];
			foreach (DiagramStore::list(true) as $d) {
				if (($d['client'] ?? '') !== '') {
					$byClient[$d['client']][] = $d;
				}
			}
			$clients = ZabbixModel::clients($cfg);
			$out['clients'] = count($clients);
			$layout = $cfg['auto_flows']['layout'];

			$kinds = self::kinds($cfg['auto_flows']['layout']);
			foreach (array_keys($clients) as $client) {
				$client = (string) $client;
				$existing = $byClient[$client] ?? [];
				$isAuto = static fn($d) => ($d['origin'] ?? '') === 'auto' || !empty($d['auto']);
				$want = Config::template($clients[$client]['template'] ?: null, $cfg);

				// One automated flow per kind: the pipeline and the client hierarchy are separate diagrams.
				foreach ($kinds as $kind => $layout) {
					$name = count($kinds) > 1 ? $client.' · '.self::KIND_NAMES[$kind] : $client;
					$alive = array_values(array_filter($existing, static fn($d) => empty($d['deleted']) && $isAuto($d) && self::kindOf($d) === $kind));
					$removed = array_filter($existing, static fn($d) => !empty($d['deleted']) && $isAuto($d) && self::kindOf($d) === $kind);

					if (!$alive) {
						if ($removed) {
							continue;
						}
						$b = self::build($client, $layout, $cfg);
						if ($b === null) {
							continue;
						}
						DiagramStore::save(null, $b['xml'], [
							'name' => $name, 'client' => $client, 'layout' => $layout, 'auto' => true, 'origin' => 'auto',
							'auto_kind' => $kind, 'synced_at' => time(), 'flow' => $b['flow'], 'gen' => Generator::VERSION
						], null);
						$out['created'][] = $name;
						continue;
					}

					foreach ($alive as $d) {
						if (empty($d['auto'])) {
							continue;
						}
						$renamed = ($d['name'] ?? '') !== $name || ($d['auto_kind'] ?? '') !== $kind;
						// A template, layout or name change is redrawn at once; host changes wait for sync_min.
						if (!$renamed && !self::outdated($d, $want, $layout)
								&& (int) ($d['synced_at'] ?? 0) > time() - 60 * $cfg['auto_flows']['sync_min']) {
							continue;
						}
						$old = (string) DiagramStore::xml($d['id']);
						$b = self::build($client, $layout, $cfg);
						if ($b === null) {
							continue;
						}
						$now = DrawioXml::linkedHostIds($b['xml']);
						$was = DrawioXml::linkedHostIds($old);
						sort($now);
						sort($was);
						if (!$renamed && $now === $was && !self::outdated($d, $b['flow'], $layout)) {
							DiagramStore::updateMeta($d['id'], ['synced_at' => time()]);
							continue;
						}
						try {
							DiagramStore::save($d['id'], $b['xml'], ['name' => $name, 'auto_kind' => $kind, 'synced_at' => time(),
								'layout' => $layout, 'flow' => $b['flow'], 'gen' => Generator::VERSION], (int) $d['revision']);
							$out['updated'][] = $name;
						}
						catch (ConflictException $e) {
							// Someone saved it a moment ago; leave it alone.
						}
					}
				}
			}

			return $out;
		}, false);

		return $r ?? $out;
	}

	/**
	 * A new auto flow is generated fresh. An existing one is regenerated too (it was never edited),
	 * so hosts that left the client disappear instead of being marked "removed".
	 */
	/**
	 * Put an automated flow that was edited by hand back under automatic control: redraw it from the
	 * client's current template and let it follow Zabbix again.
	 */
	public static function reset(string $id): array {
		$d = DiagramStore::meta($id);
		if ($d === null || (($d['origin'] ?? '') !== 'auto' && empty($d['auto'])) || ($d['client'] ?? '') === '') {
			throw new \RuntimeException('Only an automated client flow can follow its template again.');
		}
		$cfg = Config::get();
		$kinds = self::kinds($cfg['auto_flows']['layout']);
		$kind = self::kindOf($d);
		$layout = $kinds[$kind] ?? ($kind === 'hierarchy' ? 'hierarchy' : 'dataflow');
		$b = self::build($d['client'], $layout, $cfg);
		if ($b === null) {
			throw new \RuntimeException(sprintf('Client group "%s" was not found in Zabbix.', $d['client']));
		}

		return DiagramStore::save($id, $b['xml'], ['auto' => true, 'origin' => 'auto', 'auto_kind' => $kind, 'synced_at' => time(),
			'layout' => $layout, 'flow' => $b['flow'], 'gen' => Generator::VERSION], null);
	}

	/** The diagram was drawn with another layout or another version of its template. */
	private static function outdated(array $d, array $want, string $layout): bool {
		$f = $d['flow'] ?? [];

		return ($d['layout'] ?? '') !== $layout
			|| (int) ($d['gen'] ?? 1) < Generator::VERSION
			|| ($f['template'] ?? '') !== $want['template']
			|| ($f['name'] ?? '') !== $want['name']
			|| ($f['tiers'] ?? null) != $want['tiers']
			|| ($f['edges'] ?? null) != $want['edges']
			|| array_column($f['components'] ?? [], 'note', 'id') != array_column($want['components'], 'note', 'id');
	}

	/** @return array{xml: string, flow: array}|null */
	private static function build(string $client, string $layout, array $cfg): ?array {
		$model = ZabbixModel::clientModel($client, $cfg);
		if ($model['client'] === null) {
			return null;
		}
		$model['cost_only'] = CostModel::costOnlyFor($client, $cfg);

		return ['xml' => (new Generator($cfg, ['include_disabled' => true]))->build($model, $layout), 'flow' => $model['flow']];
	}
}
