<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use InvalidArgumentException;

/**
 * Module settings (settings.json in the data directory), with the approved defaults.
 * Only a Super admin may change them; everyone else reads them.
 */
class Config {

	public const ICONS = ['fwd', 'parser', 'vpn', 'es', 'ui', 'engine', 'aiml', 'soar', 's3', 'cluster', 'proxy',
		'server', 'client', 'firewall', 'lb', 'kafka', 'ulm', 'rack', 'cloud', 'generic'];
	public const COLORS = ['ingest', 'net', 'store', 'proc', 'front', 'asset', 'zbx', 'neutral'];

	private static ?array $cache = null;

	public const SIEM_EDGES = [
		['from' => 'fwd', 'to' => 'vpn', 'label' => 'logs', 'both' => true],
		['from' => 'vpn', 'to' => 'parser', 'label' => 'logs'],
		['from' => 'parser', 'to' => 'es', 'label' => 'events'],
		['from' => 'es', 'to' => 'ui', 'label' => 'search'],
		['from' => 'es', 'to' => 'engine', 'label' => 'query'],
		['from' => 'engine', 'to' => 'ui', 'label' => 'alerts'],
		['from' => 'es', 'to' => 'aiml', 'label' => 'reads'],
		['from' => 'aiml', 'to' => 'es', 'label' => 'writes back'],
		['from' => 'soar', 'to' => 'es', 'label' => 'writes']
	];

	public static function defaults(): array {
		$c = static fn(string $id, string $label, string $icon, string $color, array $groups, int $col, string $kind = 'hosts', bool $small = false) => [
			'id' => $id, 'label' => $label, 'icon' => $icon, 'color' => $color, 'kind' => $kind, 'small' => $small, 'column' => $col,
			'match' => ['groups' => $groups, 'tags' => [], 'templates' => []]
		];

		return [
			'version' => 1,
			// Settings layout version, for migrate(): 3 = flow design of 20 Oct (names, static UI, VPN as a pass-through icon, queues).
			'schema' => 3,
			'refresh_sec' => 30,
			// A flow per client is created from Zabbix automatically, and kept up to date until someone edits it.
			'auto_flows' => ['enabled' => true, 'layout' => 'both', 'sync_min' => 10, 'v' => 2],
			'client' => [
				'cluster_template' => 'Elasticsearch Cluster by HTTP SISA',
				// A host with the client macro and one of these templates is drawn as the client's cluster/master node.
				'cluster_templates' => ['Elasticsearch Cluster by HTTP SISA', 'ElasticPro client master'],
				'grp_macro' => '{$GRP.CLIENT}',
				'groups' => [],
				'autodetect' => true
			],
			// Approved 16 Oct: FWD → Parser → VPN → ES → UI, ES → Engine → UI, ES ⇄ AIML, SOAR → ES.
			// VPN and UI have no default match (decided 17 Oct); they are set in Settings.
			'components' => [
				$c('fwd', 'Forwarder', 'fwd', 'ingest', ['Forwarders'], 0),
				$c('parser', 'Parser', 'parser', 'ingest', ['Parsers'], 1),
				// VPN is a pass-through: drawn as a small icon on the line (logs go via VPN, not to it).
				$c('vpn', 'VPN', 'vpn', 'net', [], 2, 'hosts', true),
				$c('es', 'Elasticsearch', 'es', 'store', ['ESNodes'], 3),
				$c('engine', 'Engine', 'engine', 'proc', ['Engines'], 4),
				$c('aiml', 'AIML', 'aiml', 'proc', ['AIML'], 3),
				$c('soar', 'SOAR', 'soar', 'proc', ['SOAR'], 2),
				// UI has no hosts of its own: a static tier.
				$c('ui', 'UI', 'ui', 'front', [], 5, 'static'),
				$c('s3', 'S3', 's3', 'asset', [], 3, 'cost'),
				$c('ulm', 'ULM', 'ulm', 'proc', ['ULM'], 4)
			],
			'flow' => self::SIEM_EDGES,
			// Named pipelines. A client uses the template named by the macro on its cluster host
			// (e.g. {$HOSTFLOW.TEMPLATE}=s3-ulm), otherwise the default one. 'flow' above mirrors the default template.
			'template_macro' => '{$HOSTFLOW.TEMPLATE}',
			'default_template' => 'siem',
			'flow_templates' => [
				['id' => 'siem', 'name' => 'SIEM pipeline', 'tiers' => [], 'notes' => [], 'queues' => ['fwd' => 'both', 'parser' => 'both'],
					'edges' => self::SIEM_EDGES],
				['id' => 's3-ulm', 'name' => 'S3 + ULM pipeline',
					'tiers' => ['fwd', 'parser', 'es', 'ui', 's3', 'ulm'],
					'notes' => ['s3' => 'raw log / enriched copy'], 'queues' => ['fwd' => 'both', 'parser' => 'both'],
					'edges' => [
						['from' => 'fwd', 'to' => 'parser', 'label' => 'logs'],
						['from' => 'parser', 'to' => 'es', 'label' => 'events'],
						['from' => 'es', 'to' => 'ui', 'label' => 'search'],
						['from' => 'parser', 'to' => 's3', 'label' => 'raw + enriched copy'],
						['from' => 's3', 'to' => 'ulm', 'label' => 'logs'],
						['from' => 'ulm', 'to' => 'ui', 'label' => 'search']
					]]
			],
			'labels' => ['host' => '%name%<br><span style="font-family:monospace">%ip%</span>'],
			'dependency_trigger_regex' => '\\b(unavailable|unreachable|no data|is down|not available)\\b',
			'cost' => [
				'enabled' => false,
				'visible_to' => 'everyone',
				'currency' => 'USD',
				'hours_per_month' => 730,
				'count_disabled' => true,
				'pricing_basis' => 'on_demand',
				'default_region' => 'ap-south-1',
				'excludes' => ['hosts' => [], 'groups' => [], 'tags' => [['tag' => 'cost', 'value' => 'exclude']]],
				'transfer' => ['internet_share' => 0.3, 'cross_az_share' => 0.5, 'cross_region_share' => 0.2,
					'internet_rate' => 0.09, 'cross_az_rate' => 0.01, 'cross_region_rate' => 0.02, 'item_key' => 'net.if.out'],
				'ipv4_hourly' => 0.005,
				// Rules apply in order; a host-level rule wins over group/component rules.
				'rules' => [],
				'cost_only' => [],
				'pricing_api' => true
			],
			'aws' => [
				'enabled' => false,
				'source' => 'cur',
				'bucket_client_tag' => 'client',
				'cur' => ['account' => '', 'bucket' => '', 'prefix' => '', 'region' => 'us-east-1'],
				'accounts' => []
			]
		];
	}

	public static function get(): array {
		if (self::$cache === null) {
			$saved = Storage::readJson(Storage::path('settings.json'), []);
			$merged = self::merge(self::defaults(), self::migrate(is_array($saved) ? $saved : []));
			try {
				self::$cache = self::validate($merged);
			}
			catch (\InvalidArgumentException $e) {
				error_log('hostflow: settings.json is invalid ('.$e->getMessage().'); using defaults.');
				self::$cache = self::validate(self::defaults());
			}
		}

		return self::$cache;
	}

	/**
	 * Settings saved before flow templates existed: their pipeline becomes the "SIEM pipeline"
	 * template, the built-in templates are added, and components those templates need (ULM) are
	 * appended so nothing the admin mapped is lost.
	 */
	public static function migrate(array $saved): array {
		$saved = self::migrateTo3($saved);
		// 20 Oct: the pipeline and the client hierarchy are separate flows, no longer one combined canvas.
		if (isset($saved['auto_flows']) && (int) ($saved['auto_flows']['v'] ?? 1) < 2) {
			if (in_array($saved['auto_flows']['layout'] ?? '', ['client', 'hierarchy'], true)) {
				$saved['auto_flows']['layout'] = 'both';
			}
			$saved['auto_flows']['v'] = 2;
		}
		if (!$saved || isset($saved['flow_templates'])) {
			return $saved;
		}
		$d = self::defaults();
		$templates = $d['flow_templates'];
		if (isset($saved['flow']) && is_array($saved['flow'])) {
			$templates[0]['edges'] = $saved['flow'];
		}
		$saved['flow_templates'] = $templates;
		if (isset($saved['components']) && is_array($saved['components'])) {
			$have = array_column($saved['components'], 'id');
			$need = [];
			foreach ($templates as $t) {
				foreach (array_merge($t['tiers'], array_column($t['edges'], 'from'), array_column($t['edges'], 'to')) as $id) {
					$need[$id] = true;
				}
			}
			foreach ($d['components'] as $c) {
				if (isset($need[$c['id']]) && !in_array($c['id'], $have, true)) {
					$saved['components'][] = $c;
				}
			}
		}

		return $saved;
	}

	/**
	 * 20 Oct flow design, applied once to settings saved before it: tier names Forwarder and
	 * Elasticsearch, UI as a static tier, VPN as a pass-through icon, queues instead of
	 * "Redis/Kafka" notes, and the SIEM pipeline as Forwarder ⇄ VPN → Parser → Elasticsearch with S3 fed by Parser.
	 */
	private static function migrateTo3(array $saved): array {
		if (!$saved || (int) ($saved['schema'] ?? 1) >= 3) {
			return $saved;
		}
		$saved['schema'] = 3;
		foreach ($saved['components'] ?? [] as $i => $c) {
			$rename = ['fwd' => ['FWD', 'Forwarder'], 'es' => ['ES', 'Elasticsearch']][$c['id'] ?? ''] ?? null;
			if ($rename && ($c['label'] ?? '') === $rename[0]) {
				$saved['components'][$i]['label'] = $rename[1];
			}
			if (($c['id'] ?? '') === 'ui' && ($c['kind'] ?? 'hosts') === 'hosts' && empty($c['match']['groups'])) {
				$saved['components'][$i]['kind'] = 'static';
			}
			if (($c['id'] ?? '') === 'vpn') {
				$saved['components'][$i]['small'] = true;
			}
		}
		foreach ($saved['flow_templates'] ?? [] as $i => $t) {
			$queues = [];
			foreach ((array) ($t['notes'] ?? []) as $k => $v) {
				if (preg_match('#^\s*redis\s*[/,+&]\s*kafka\s*$#i', (string) $v)) {
					$queues[$k] = 'both';
					unset($saved['flow_templates'][$i]['notes'][$k]);
				}
			}
			if (($t['id'] ?? '') === 'siem') {
				$queues += ['fwd' => 'both', 'parser' => 'both'];
				$edges = [];
				foreach ($t['edges'] ?? [] as $e) {
					$pair = ($e['from'] ?? '').'>'.($e['to'] ?? '');
					if ($pair === 'fwd>parser' || $pair === 'parser>vpn') {
						continue;
					}
					if ($pair === 'vpn>es') {
						$edges[] = ['from' => 'fwd', 'to' => 'vpn', 'label' => 'logs', 'both' => true];
						$edges[] = ['from' => 'vpn', 'to' => 'parser', 'label' => 'logs'];
						$e = ['from' => 'parser', 'to' => 'es'] + $e;
					}
					if ($pair === 'vpn>s3') {
						$e = ['from' => 'parser', 'to' => 's3'] + $e;
					}
					$edges[] = $e;
				}
				$saved['flow_templates'][$i]['edges'] = $edges;
			}
			$saved['flow_templates'][$i]['queues'] = $queues + (array) ($t['queues'] ?? []);
		}

		return $saved;
	}

	/**
	 * A flow template resolved for drawing: its tiers first (with their notes), then the other
	 * components (hosts can still match them), and its arrows. Unknown ids use the default template.
	 */
	public static function template(?string $id = null, ?array $cfg = null): array {
		$cfg = $cfg ?? self::get();
		$byId = array_column($cfg['flow_templates'], null, 'id');
		$t = $byId[$id ?? ''] ?? $byId[$cfg['default_template']];
		$comps = array_column($cfg['components'], null, 'id');
		$ordered = [];
		foreach ($t['tiers'] as $tid) {
			if (isset($comps[$tid])) {
				$ordered[] = $comps[$tid] + ['note' => $t['notes'][$tid] ?? '', 'queue' => $t['queues'][$tid] ?? ''];
				unset($comps[$tid]);
			}
		}
		foreach ($comps as $c) {
			$ordered[] = $c + ['note' => '', 'queue' => ''];
		}

		return ['template' => $t['id'], 'name' => $t['name'], 'tiers' => $t['tiers'], 'components' => $ordered, 'edges' => $t['edges']];
	}

	/** Validate a full settings document and save it. Secrets are encrypted before writing. */
	public static function save(array $new): array {
		$cfg = self::validate(self::merge(self::defaults(), $new));
		$old = self::get();
		foreach ($cfg['aws']['accounts'] as $i => &$acc) {
			if (($acc['secret_key'] ?? '') !== '') {
				$acc['secret_enc'] = Crypto::encrypt($acc['secret_key']);
			}
			else {
				// Blank secret in the form means "keep the stored one".
				foreach ($old['aws']['accounts'] as $o) {
					if (($o['id'] ?? '') === $acc['id'] && isset($o['secret_enc'])) {
						$acc['secret_enc'] = $o['secret_enc'];
					}
				}
			}
			unset($acc['secret_key']);
		}
		unset($acc);
		Storage::writeJson(Storage::path('settings.json'), $cfg);
		self::$cache = $cfg;
		Audit::log('settings.update', ['components' => count($cfg['components']), 'flow' => count($cfg['flow']),
			'aws.accounts' => count($cfg['aws']['accounts'])]);

		return $cfg;
	}

	/** Settings as sent to the browser: no secrets. */
	public static function forBrowser(): array {
		$cfg = self::get();
		foreach ($cfg['aws']['accounts'] as &$acc) {
			$acc['has_secret'] = isset($acc['secret_enc']);
			unset($acc['secret_enc']);
		}
		unset($acc);

		return $cfg;
	}

	public static function component(string $id): ?array {
		foreach (self::get()['components'] as $c) {
			if ($c['id'] === $id) {
				return $c;
			}
		}

		return null;
	}

	public static function validate(array $cfg): array {
		$cfg['refresh_sec'] = max(10, min(300, (int) $cfg['refresh_sec']));
		$cfg['auto_flows'] = [
			'enabled' => (bool) ($cfg['auto_flows']['enabled'] ?? true),
			// both = the pipeline and the client hierarchy as two separate flows per client
			'layout' => in_array($cfg['auto_flows']['layout'] ?? '', array_merge(['both'], Generator::LAYOUTS), true) ? $cfg['auto_flows']['layout'] : 'both',
			'sync_min' => max(1, min(1440, (int) ($cfg['auto_flows']['sync_min'] ?? 10))),
			'v' => 2
		];

		$ids = [];
		$comps = [];
		foreach ($cfg['components'] as $c) {
			$id = strtolower(trim((string) ($c['id'] ?? '')));
			if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id)) {
				throw new InvalidArgumentException(sprintf('Component id "%s" may use only letters, digits, - and _.', $id));
			}
			if (isset($ids[$id])) {
				throw new InvalidArgumentException(sprintf('Component id "%s" is used twice.', $id));
			}
			$ids[$id] = true;
			$m = $c['match'] ?? [];
			$comps[] = [
				'id' => $id,
				'label' => mb_substr(trim((string) ($c['label'] ?? $id)), 0, 32) ?: $id,
				'icon' => in_array($c['icon'] ?? '', self::ICONS, true) ? $c['icon'] : 'generic',
				'color' => in_array($c['color'] ?? '', self::COLORS, true) ? $c['color'] : 'neutral',
				'kind' => in_array($c['kind'] ?? '', ['cost', 'static'], true) ? $c['kind'] : 'hosts',
				'small' => !empty($c['small']),
				'column' => max(0, min(20, (int) ($c['column'] ?? 0))),
				'match' => [
					'groups' => self::strings($m['groups'] ?? []),
					'tags' => self::tags($m['tags'] ?? []),
					'templates' => self::strings($m['templates'] ?? [])
				]
			];
		}
		$cfg['components'] = $comps;

		$templates = [];
		foreach ($cfg['flow_templates'] ?? [] as $t) {
			$tid = strtolower(trim((string) ($t['id'] ?? '')));
			if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $tid)) {
				throw new InvalidArgumentException(sprintf('Flow template id "%s" may use only letters, digits, - and _.', $tid));
			}
			if (isset($templates[$tid])) {
				throw new InvalidArgumentException(sprintf('Flow template id "%s" is used twice.', $tid));
			}
			$name = mb_substr(trim((string) ($t['name'] ?? '')), 0, 64) ?: $tid;
			$edges = self::edges($t['edges'] ?? [], $ids, $name);
			$tiers = [];
			foreach (array_merge(self::strings($t['tiers'] ?? []), array_column($edges, 'from'), array_column($edges, 'to')) as $c) {
				if (isset($ids[$c]) && !in_array($c, $tiers, true)) {
					$tiers[] = $c;
				}
			}
			$notes = [];
			foreach ((array) ($t['notes'] ?? []) as $k => $v) {
				$v = mb_substr(trim((string) $v), 0, 40);
				if (isset($ids[$k]) && $v !== '') {
					$notes[$k] = $v;
				}
			}
			$queues = [];
			foreach ((array) ($t['queues'] ?? []) as $k => $v) {
				if (isset($ids[$k]) && in_array($v, ['redis', 'kafka', 'both'], true)) {
					$queues[$k] = $v;
				}
			}
			$templates[$tid] = ['id' => $tid, 'name' => $name, 'tiers' => $tiers, 'notes' => $notes, 'queues' => $queues, 'edges' => $edges];
		}
		if (!$templates) {
			$templates['siem'] = ['id' => 'siem', 'name' => 'SIEM pipeline', 'tiers' => [], 'notes' => [], 'queues' => [], 'edges' => self::edges($cfg['flow'] ?? [], $ids, '')];
		}
		$cfg['flow_templates'] = array_values($templates);
		$cfg['default_template'] = isset($templates[$cfg['default_template'] ?? '']) ? $cfg['default_template'] : array_key_first($templates);
		// 'flow' is the default template's arrows (older code and diagrams read it).
		$cfg['flow'] = $templates[$cfg['default_template']]['edges'];
		$cfg['template_macro'] = preg_match('/^\{\$[A-Z0-9_.]{1,64}\}$/', (string) ($cfg['template_macro'] ?? ''))
			? $cfg['template_macro'] : '{$HOSTFLOW.TEMPLATE}';

		$cfg['client']['groups'] = self::strings($cfg['client']['groups'] ?? []);
		$cfg['client']['cluster_templates'] = self::strings($cfg['client']['cluster_templates'] ?? []) ?: self::strings([$cfg['client']['cluster_template'] ?? '']);
		$cfg['client']['cluster_template'] = $cfg['client']['cluster_templates'][0] ?? '';
		$cfg['client']['autodetect'] = (bool) $cfg['client']['autodetect'];
		if (@preg_match('/'.str_replace('/', '\/', $cfg['dependency_trigger_regex']).'/i', '') === false) {
			throw new InvalidArgumentException('The trigger name pattern is not a valid regular expression.');
		}

		$cost = &$cfg['cost'];
		$cost['enabled'] = (bool) $cost['enabled'];
		$cost['visible_to'] = in_array($cost['visible_to'], ['everyone', 'admins', 'superadmins'], true) ? $cost['visible_to'] : 'everyone';
		$cost['hours_per_month'] = max(1, min(744, (float) $cost['hours_per_month']));
		$cost['pricing_basis'] = in_array($cost['pricing_basis'], ['on_demand', 'reserved', 'savings_plan'], true) ? $cost['pricing_basis'] : 'on_demand';
		$cost['default_region'] = self::region($cost['default_region']) ?? 'us-east-1';
		$cost['ipv4_hourly'] = max(0, (float) $cost['ipv4_hourly']);
		$cost['excludes'] = [
			'hosts' => self::strings($cost['excludes']['hosts'] ?? []),
			'groups' => self::strings($cost['excludes']['groups'] ?? []),
			'tags' => self::tags($cost['excludes']['tags'] ?? [])
		];
		foreach (['internet_share', 'cross_az_share', 'cross_region_share'] as $k) {
			$cost['transfer'][$k] = max(0, min(1, (float) ($cost['transfer'][$k] ?? 0)));
		}
		foreach (['internet_rate', 'cross_az_rate', 'cross_region_rate'] as $k) {
			$cost['transfer'][$k] = max(0, (float) ($cost['transfer'][$k] ?? 0));
		}
		$rules = [];
		foreach ($cost['rules'] as $r) {
			$scope = in_array($r['scope'] ?? '', ['host', 'group', 'component'], true) ? $r['scope'] : 'group';
			$rules[] = [
				'scope' => $scope,
				'target' => mb_substr(trim((string) ($r['target'] ?? '')), 0, 128),
				'region' => self::region($r['region'] ?? '') ?? '',
				'instance_type' => preg_match('/^[a-z0-9]+\.[a-z0-9]+$/', $r['instance_type'] ?? '') ? $r['instance_type'] : '',
				'basis' => in_array($r['basis'] ?? '', ['on_demand', 'reserved', 'savings_plan'], true) ? $r['basis'] : '',
				'hourly' => self::money($r['hourly'] ?? null),
				'monthly' => self::money($r['monthly'] ?? null),
				'ebs_gb' => max(0, (float) ($r['ebs_gb'] ?? 0)),
				'ebs_type' => in_array($r['ebs_type'] ?? '', ['gp3', 'gp2', 'io2', 'io1', 'st1', 'sc1'], true) ? $r['ebs_type'] : 'gp3',
				'ebs_rate' => self::money($r['ebs_rate'] ?? null),
				'iops' => max(0, (int) ($r['iops'] ?? 0)),
				'snapshot_gb' => max(0, (float) ($r['snapshot_gb'] ?? 0)),
				'public_ipv4' => max(0, (int) ($r['public_ipv4'] ?? 0)),
				'extras' => max(0, (float) ($r['extras'] ?? 0))
			];
		}
		$cost['rules'] = $rules;
		$only = [];
		foreach ($cost['cost_only'] as $x) {
			$type = in_array($x['type'] ?? '', ['vpn_connection', 'vpn_gateway', 'tgw_attachment', 'client_vpn', 's3_bucket', 'other'], true) ? $x['type'] : 'other';
			$only[] = [
				'id' => preg_match('/^[a-z0-9-]{1,40}$/', $x['id'] ?? '') ? $x['id'] : substr(md5(json_encode($x)), 0, 10),
				'type' => $type,
				'name' => mb_substr(trim((string) ($x['name'] ?? '')), 0, 64),
				'client' => mb_substr(trim((string) ($x['client'] ?? '')), 0, 128),
				'component' => isset($ids[$x['component'] ?? '']) ? $x['component'] : ($type === 's3_bucket' ? 's3' : 'vpn'),
				'resource_id' => mb_substr(trim((string) ($x['resource_id'] ?? '')), 0, 255),
				'monthly' => self::money($x['monthly'] ?? null)
			];
		}
		$cost['cost_only'] = $only;
		$cost['pricing_api'] = (bool) $cost['pricing_api'];
		unset($cost);

		$aws = &$cfg['aws'];
		$aws['enabled'] = (bool) $aws['enabled'];
		$aws['source'] = in_array($aws['source'], ['cur', 'cost_explorer'], true) ? $aws['source'] : 'cur';
		$aws['cur']['region'] = self::region($aws['cur']['region'] ?? '') ?? 'us-east-1';
		$aws['cur']['bucket'] = preg_match('/^[a-z0-9.-]{3,63}$/', $aws['cur']['bucket'] ?? '') ? $aws['cur']['bucket'] : '';
		$aws['cur']['prefix'] = trim((string) ($aws['cur']['prefix'] ?? ''), '/');
		$aws['bucket_client_tag'] = preg_match('/^[\w.:\/=+@ -]{1,128}$/', $aws['bucket_client_tag'] ?? '') ? $aws['bucket_client_tag'] : 'client';
		$accounts = [];
		foreach ($aws['accounts'] as $a) {
			$auth = in_array($a['auth'] ?? '', ['instance', 'assume', 'key'], true) ? $a['auth'] : 'instance';
			$acct = [
				'id' => preg_match('/^\d{12}$/', $a['id'] ?? '') ? $a['id'] : '',
				'name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 64),
				'auth' => $auth,
				'role_arn' => preg_match('/^arn:aws[a-z-]*:iam::\d{12}:role\/[\w+=,.@\/-]+$/', $a['role_arn'] ?? '') ? $a['role_arn'] : '',
				'external_id' => mb_substr((string) ($a['external_id'] ?? ''), 0, 1224),
				'key_id' => preg_match('/^(AKIA|ASIA)[A-Z0-9]{12,20}$/', $a['key_id'] ?? '') ? $a['key_id'] : '',
				'regions' => array_values(array_filter(array_map([self::class, 'region'], (array) ($a['regions'] ?? [])))),
				'vpcs' => array_values(array_filter((array) ($a['vpcs'] ?? []), static fn($v) => preg_match('/^vpc-[0-9a-f]{8,17}$/', $v)))
			];
			if ($acct['id'] === '') {
				throw new InvalidArgumentException('Each AWS account needs its 12-digit account ID.');
			}
			if ($auth === 'assume' && $acct['role_arn'] === '') {
				throw new InvalidArgumentException(sprintf('Account %s uses AssumeRole but has no valid role ARN.', $acct['id']));
			}
			if ($auth === 'key' && $acct['key_id'] === '') {
				throw new InvalidArgumentException(sprintf('Account %s uses an access key but the key ID is not valid.', $acct['id']));
			}
			if (isset($a['secret_key'])) {
				$acct['secret_key'] = (string) $a['secret_key'];
			}
			if (isset($a['secret_enc'])) {
				$acct['secret_enc'] = $a['secret_enc'];
			}
			$accounts[] = $acct;
		}
		$aws['accounts'] = $accounts;
		unset($aws);

		return $cfg;
	}

	private static function edges(array $list, array $ids, string $template): array {
		$out = [];
		foreach ($list as $e) {
			$from = (string) ($e['from'] ?? '');
			$to = (string) ($e['to'] ?? '');
			if (!isset($ids[$from]) || !isset($ids[$to])) {
				throw new InvalidArgumentException(sprintf('A flow arrow%s points to an unknown component (%s → %s).',
					$template !== '' ? ' in "'.$template.'"' : '', $from, $to));
			}
			$out[] = [
				'from' => $from,
				'to' => $to,
				'label' => mb_substr(trim((string) ($e['label'] ?? '')), 0, 32),
				'item_key' => mb_substr(trim((string) ($e['item_key'] ?? '')), 0, 255),
				'drop_pct' => max(0, min(100, (float) ($e['drop_pct'] ?? 0))),
				'both' => !empty($e['both'])
			];
		}

		return $out;
	}

	/** Recursive merge where lists in $over replace lists in $base. */
	private static function merge(array $base, array $over): array {
		foreach ($over as $k => $v) {
			if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($base[$k]) && !array_is_list($v)) {
				$base[$k] = self::merge($base[$k], $v);
			}
			else {
				$base[$k] = $v;
			}
		}

		return $base;
	}

	private static function strings($list): array {
		$out = [];
		foreach ((array) $list as $s) {
			$s = trim((string) $s);
			if ($s !== '' && mb_strlen($s) <= 255) {
				$out[] = $s;
			}
		}

		return array_values(array_unique($out));
	}

	private static function tags($list): array {
		$out = [];
		foreach ((array) $list as $t) {
			$tag = trim((string) ($t['tag'] ?? ''));
			if ($tag !== '') {
				$out[] = ['tag' => mb_substr($tag, 0, 255), 'value' => mb_substr(trim((string) ($t['value'] ?? '')), 0, 255)];
			}
		}

		return $out;
	}

	public static function region($r): ?string {
		$r = trim((string) $r);

		return preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d$/', $r) ? $r : null;
	}

	private static function money($v): ?float {
		if ($v === null || $v === '') {
			return null;
		}

		return max(0, round((float) $v, 6));
	}
}
