<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/../lib/autoload.php';

use Modules\HostFlow\Lib\{Config, CostModel, CostSync, Storage};
use Modules\HostFlow\Lib\Aws\{Credentials, SigV4, Http, Client};

Storage::setDir(sys_get_temp_dir().'/hf-cost-'.bin2hex(random_bytes(4)));

// --- SigV4: AWS test suite "get-vanilla" ------------------------------------
$cred = new Credentials('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY');
$h = SigV4::sign('GET', 'https://example.amazonaws.com/', [], '', $cred, 'us-east-1', 'service', strtotime('2015-08-30T12:36:00Z'));
ok($h['authorization'] === 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', 'SigV4 get-vanilla vector: '.$h['authorization']);
// "get-vanilla-query-order-key-case": params sorted by key then value
$h2 = SigV4::sign('GET', 'https://example.amazonaws.com/?Param2=value2&Param1=value1', [], '', $cred, 'us-east-1', 'service', strtotime('2015-08-30T12:36:00Z'));
ok(substr($h2['authorization'], -64) === 'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500', 'SigV4 query-order vector: '.substr($h2['authorization'], -64));
ok(SigV4::canonicalQuery('b=2&a=1&a=0') === 'a=0&a=1&b=2', 'canonical query sort');
ok(SigV4::canonicalPath('/my key/x', 's3') === '/my%20key/x' && SigV4::canonicalPath('/my key', 'ec2') === '/my%2520key', 'path encoding (S3 once, others twice)');
$tok = new Credentials('A', 'B', 'TOKEN');
ok(SigV4::sign('GET', 'https://x.amazonaws.com/', [], '', $tok, 'us-east-1', 'ec2')['x-amz-security-token'] === 'TOKEN', 'session token header');

// --- IP matching with safeguards ---------------------------------------------
$inst = static fn($id, $priv, $pub = [], $vols = []) => ['instance_id' => $id, 'instance_type' => 'm6i.large', 'region' => 'ap-south-1',
	'state' => 'running', 'private_ips' => $priv, 'public_ips' => $pub, 'volumes' => $vols, 'account' => '111122223333'];
$instances = [
	'i-a' => $inst('i-a', ['10.0.0.1'], ['3.3.3.3'], ['vol-1', 'vol-2']),
	'i-b' => $inst('i-b', ['10.0.0.2']),
	'i-c' => $inst('i-c', ['10.0.0.9']),     // same private IP in two VPCs
	'i-d' => $inst('i-d', ['10.0.0.9']),
	'i-e' => $inst('i-e', ['10.0.0.5']),
	'i-old' => $inst('i-old', ['10.0.0.6'])
];
$vols = ['vol-1' => ['size_gb' => 100], 'vol-2' => ['size_gb' => 50]];
$hosts = [
	['hostid' => '1', 'ip' => '10.0.0.1'],
	['hostid' => '2', 'ip' => '10.0.0.2'],
	['hostid' => '3', 'ip' => '10.0.0.9'],
	['hostid' => '4', 'ip' => '10.0.0.5', 'tags' => [['tag' => 'aws-instance-id', 'value' => 'i-b']]],
	['hostid' => '5', 'ip' => '10.0.0.5'],
	['hostid' => '6', 'ip' => '172.16.0.1'],
];
$pins = ['5' => ['instance_id' => 'i-old']];
[$m, $amb, $moved] = CostSync::match($hosts, $instances, $vols, $pins);
ok($m['1']['instance_id'] === 'i-a' && $m['1']['ebs_gb'] === 150 && $m['1']['public_ipv4'] === 1, 'IP match with volumes and public IP');
ok(isset($amb['3']) && $m['3']['ambiguous'] && $amb['3']['candidates'] === ['i-c', 'i-d'], 'ambiguous IP is listed, never guessed');
ok($m['4']['instance_id'] === 'i-b' && $m['4']['by'] === 'tag', 'aws-instance-id tag overrides IP');
ok(isset($moved['5']) && $m['5']['instance_id'] === 'i-old' && $moved['5']['now'] === 'i-e', 'pinned instance kept, move flagged');
ok(!isset($m['6']), 'no AWS match for non-AWS host');

// --- usage categories + CUR parsing ------------------------------------------
ok(CostSync::category('APS3-BoxUsage:m6i.large') === 'ec2' && CostSync::category('APS3-EBS:VolumeUsage.gp3') === 'ebs'
	&& CostSync::category('APS3-EBS:SnapshotUsage') === 'snapshots' && CostSync::category('APS3-DataTransfer-Out-Bytes') === 'transfer'
	&& CostSync::category('APS3-PublicIPv4:InUseAddress') === 'ipv4' && CostSync::category('VPN-Usage-Hours:ipsec.1') === 'other', 'usage type categories');
foreach (['cur2' => "line_item_resource_id,line_item_usage_type,line_item_unblended_cost\n", 'legacy' => "lineItem/ResourceId,lineItem/UsageType,lineItem/UnblendedCost\n"] as $k => $head) {
	$f = tempnam(sys_get_temp_dir(), 'cur');
	file_put_contents($f, gzencode($head."i-a,APS3-BoxUsage:m6i.large,1.5\nvol-1,APS3-EBS:VolumeUsage.gp3,0.25\n,Tax,9\n"));
	$rows = CostSync::parseCurCsv($f);
	ok(count($rows) === 2 && $rows[0]['resource_id'] === 'i-a' && $rows[1]['cost'] === 0.25, "CUR parse ($k)");
}

// --- cost model ----------------------------------------------------------------
$cfg = Config::get();
$cfg['cost']['rules'] = [
	['scope' => 'component', 'target' => 'es', 'region' => 'ap-south-1', 'instance_type' => 'r6i.xlarge', 'basis' => '', 'hourly' => null, 'monthly' => null,
		'ebs_gb' => 500, 'ebs_type' => 'gp3', 'ebs_rate' => null, 'iops' => 4000, 'snapshot_gb' => 100, 'public_ipv4' => 0, 'extras' => 10],
	['scope' => 'host', 'target' => 'acme-es-02', 'region' => '', 'instance_type' => '', 'basis' => '', 'hourly' => 0.5, 'monthly' => null,
		'ebs_gb' => 0, 'ebs_type' => 'gp3', 'ebs_rate' => null, 'iops' => 0, 'snapshot_gb' => 0, 'public_ipv4' => 1, 'extras' => 0],
];
$pricing = ['ec2' => ['ap-south-1' => ['r6i.xlarge' => ['on_demand' => 0.2, 'reserved' => 0.13, 'savings_plan' => 0.13]]], 'ebs' => []];
$es1 = ['hostid' => '31', 'host' => 'acme-es-01', 'name' => 'acme-es-01', 'groups' => ['ESNodes'], 'component' => 'es', 'tags' => []];
$c1 = CostModel::host($es1, $cfg, $pricing, [], ['hosts' => ['31' => ['gb_month' => 100]]]);
$transferRate = 0.3 * 0.09 + 0.5 * 0.01 + 0.2 * 0.02;
ok($c1['source'] === 'api' && abs($c1['parts']['ec2'] - 146.0) < 0.01, 'EC2 from Pricing API × 730 h: '.$c1['parts']['ec2']);
ok(abs($c1['parts']['ebs'] - (500 * 0.08 + 1000 * 0.005)) < 0.01, 'EBS gp3 incl. IOPS above 3000: '.$c1['parts']['ebs']);
ok(abs($c1['parts']['snapshots'] - 5) < 0.01 && abs($c1['parts']['transfer'] - round(100 * $transferRate, 2)) < 0.01 && $c1['parts']['extras'] == 10, 'snapshots, transfer, extras');
ok(abs($c1['monthly'] - array_sum($c1['parts'])) < 0.02, 'monthly = sum of parts');
$es2 = ['hostid' => '32', 'host' => 'acme-es-02', 'name' => 'acme-es-02', 'groups' => ['ESNodes'], 'component' => 'es', 'tags' => []];
$c2 = CostModel::host($es2, $cfg, $pricing, [], []);
ok($c2['source'] === 'override' && abs($c2['parts']['ec2'] - 365) < 0.01 && abs($c2['parts']['ipv4'] - 3.65) < 0.01 && abs($c2['parts']['ebs'] - 45) < 0.01, 'host rule overrides rate, inherits component EBS');
$cfg2 = $cfg; $cfg2['cost']['pricing_basis'] = 'reserved';
ok(abs(CostModel::host($es1, $cfg2, $pricing, [], [])['parts']['ec2'] - 94.9) < 0.01, 'reserved basis');
$ex = $es1; $ex['tags'] = [['tag' => 'cost', 'value' => 'exclude']];
ok(CostModel::host($ex, $cfg, $pricing, [], [])['excluded'] === true, 'cost: exclude tag');
$aws = ['matches' => ['31' => ['instance_id' => 'i-a', 'instance_type' => 'r6i.xlarge', 'region' => 'ap-south-1']],
	'actual' => ['i-a' => ['total' => 210, 'parts' => ['ec2' => 150, 'ebs' => 60]]]];
$c3 = CostModel::host($es1, $cfg, $pricing, $aws, []);
ok($c3['source'] === 'actual' && $c3['parts']['ec2'] == 150 && $c3['parts']['ebs'] == 60 && $c3['instance'] === 'i-a', 'actual billed cost replaces estimate');
ok(CostModel::costOnly(['name' => 'S2S', 'type' => 'vpn_connection', 'resource_id' => '', 'monthly' => null], [])['monthly'] == 36.5, 'VPN connection estimate');
ok(CostModel::costOnly(['name' => 'b', 'type' => 's3_bucket', 'resource_id' => 'b', 'monthly' => null],
	['buckets' => [['name' => 'b', 'bytes' => ['STANDARD' => 1073741824 * 100, 'GLACIER' => 1073741824 * 1000]]]])['monthly'] == 5.9, 'S3 bucket by storage class');

done();
