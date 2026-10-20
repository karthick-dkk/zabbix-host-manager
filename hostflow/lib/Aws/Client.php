<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib\Aws;

use RuntimeException;
use SimpleXMLElement;

/**
 * The read-only AWS calls Host Flow makes, each signed with SigV4:
 *   ec2:DescribeInstances, ec2:DescribeVolumes, pricing:GetProducts,
 *   s3:ListAllMyBuckets, s3:GetBucketLocation, s3:GetBucketTagging, s3:ListBucket, s3:GetObject,
 *   cloudwatch:GetMetricStatistics, ce:GetCostAndUsageWithResources
 */
class Client {

	private Credentials $cred;
	private Http $http;

	public function __construct(Credentials $cred, ?Http $http = null) {
		$this->cred = $cred;
		$this->http = $http ?? new Http();
	}

	// ------------------------------------------------------------------ EC2

	/** Instances in a region (optionally limited to VPCs), with every private and public IP. */
	public function describeInstances(string $region, array $vpcs = []): array {
		$out = [];
		$token = null;
		do {
			$q = ['Action' => 'DescribeInstances', 'Version' => '2016-11-15', 'MaxResults' => '1000'];
			$n = 1;
			if ($vpcs) {
				$q["Filter.$n.Name"] = 'vpc-id';
				foreach (array_values($vpcs) as $i => $v) {
					$q["Filter.$n.Value.".($i + 1)] = $v;
				}
				$n++;
			}
			$q["Filter.$n.Name"] = 'instance-state-name';
			foreach (['pending', 'running', 'stopping', 'stopped'] as $i => $s) {
				$q["Filter.$n.Value.".($i + 1)] = $s;
			}
			if ($token !== null) {
				$q['NextToken'] = $token;
			}
			$x = $this->query("https://ec2.$region.amazonaws.com/", $q, $region, 'ec2');
			foreach ($x->reservationSet->item ?? [] as $res) {
				foreach ($res->instancesSet->item ?? [] as $i) {
					$ips = [];
					$public = [];
					foreach ($i->networkInterfaceSet->item ?? [] as $ni) {
						foreach ($ni->privateIpAddressesSet->item ?? [] as $p) {
							$ips[] = (string) $p->privateIpAddress;
							if ((string) $p->association->publicIp !== '') {
								$public[] = (string) $p->association->publicIp;
							}
						}
					}
					if ((string) $i->privateIpAddress !== '') {
						$ips[] = (string) $i->privateIpAddress;
					}
					if ((string) $i->ipAddress !== '') {
						$public[] = (string) $i->ipAddress;
					}
					$vols = [];
					foreach ($i->blockDeviceMapping->item ?? [] as $b) {
						if ((string) $b->ebs->volumeId !== '') {
							$vols[] = (string) $b->ebs->volumeId;
						}
					}
					$name = '';
					foreach ($i->tagSet->item ?? [] as $t) {
						if ((string) $t->key === 'Name') {
							$name = (string) $t->value;
						}
					}
					$out[] = [
						'instance_id' => (string) $i->instanceId,
						'instance_type' => (string) $i->instanceType,
						'state' => (string) $i->instanceState->name,
						'vpc_id' => (string) $i->vpcId,
						'region' => $region,
						'name' => $name,
						'private_ips' => array_values(array_unique($ips)),
						'public_ips' => array_values(array_unique($public)),
						'volumes' => $vols
					];
				}
			}
			$token = (string) ($x->nextToken ?? '');
			$token = $token === '' ? null : $token;
		} while ($token !== null);

		return $out;
	}

	/** volumeId => [size_gb, type, iops] */
	public function describeVolumes(string $region, array $ids): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 200) as $chunk) {
			$q = ['Action' => 'DescribeVolumes', 'Version' => '2016-11-15'];
			foreach ($chunk as $i => $id) {
				$q['VolumeId.'.($i + 1)] = $id;
			}
			$x = $this->query("https://ec2.$region.amazonaws.com/", $q, $region, 'ec2');
			foreach ($x->volumeSet->item ?? [] as $v) {
				$out[(string) $v->volumeId] = ['size_gb' => (int) $v->size, 'type' => (string) $v->volumeType, 'iops' => (int) $v->iops];
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------ Pricing

	/** Linux, shared tenancy hourly prices: ['on_demand' => x, 'reserved' => y] for one instance type. */
	public function ec2Price(string $region, string $type): ?array {
		$items = $this->getProducts('AmazonEC2', [
			'instanceType' => $type, 'regionCode' => $region, 'operatingSystem' => 'Linux', 'tenancy' => 'Shared',
			'preInstalledSw' => 'NA', 'capacitystatus' => 'Used', 'licenseModel' => 'No License required'
		]);
		foreach ($items as $p) {
			$od = self::firstUsd($p['terms']['OnDemand'] ?? [], 'Hrs');
			if ($od === null) {
				continue;
			}
			$ri = null;
			foreach ($p['terms']['Reserved'] ?? [] as $term) {
				$a = $term['termAttributes'] ?? [];
				if (($a['LeaseContractLength'] ?? '') === '1yr' && ($a['PurchaseOption'] ?? '') === 'No Upfront'
						&& ($a['OfferingClass'] ?? '') === 'standard') {
					$ri = self::firstUsd([$term], 'Hrs');
				}
			}

			return ['on_demand' => $od, 'reserved' => $ri ?? $od, 'savings_plan' => $ri ?? $od];
		}

		return null;
	}

	/** $/GB-month for each EBS volume type in a region. */
	public function ebsPrices(string $region): array {
		$out = [];
		foreach ($this->getProducts('AmazonEC2', ['regionCode' => $region, 'productFamily' => 'Storage']) as $p) {
			$api = $p['product']['attributes']['volumeApiName'] ?? '';
			$usd = self::firstUsd($p['terms']['OnDemand'] ?? [], 'GB-Mo');
			if ($api !== '' && $usd !== null) {
				$out[$api] = $usd;
			}
		}

		return $out;
	}

	private function getProducts(string $service, array $filters): array {
		$body = ['ServiceCode' => $service, 'FormatVersion' => 'aws_v1', 'MaxResults' => 100, 'Filters' => []];
		foreach ($filters as $k => $v) {
			$body['Filters'][] = ['Type' => 'TERM_MATCH', 'Field' => $k, 'Value' => $v];
		}
		$out = [];
		do {
			$j = $this->jsonRpc('https://api.pricing.us-east-1.amazonaws.com/', 'AWSPriceListService.GetProducts', $body, 'us-east-1', 'pricing');
			foreach ($j['PriceList'] ?? [] as $s) {
				$out[] = is_string($s) ? json_decode($s, true) : $s;
			}
			$body['NextToken'] = $j['NextToken'] ?? null;
		} while ($body['NextToken'] !== null && count($out) < 1000);

		return $out;
	}

	private static function firstUsd(array $terms, string $unit): ?float {
		foreach ($terms as $t) {
			foreach ($t['priceDimensions'] ?? [] as $d) {
				if (($d['unit'] ?? '') === $unit && isset($d['pricePerUnit']['USD']) && (float) $d['pricePerUnit']['USD'] > 0) {
					return (float) $d['pricePerUnit']['USD'];
				}
			}
		}

		return null;
	}

	// ------------------------------------------------------------------ S3 + CloudWatch

	public function listBuckets(): array {
		$r = $this->signed('GET', 'https://s3.amazonaws.com/', '', 'us-east-1', 's3');
		$x = self::xml($r, 'ListBuckets');
		$out = [];
		foreach ($x->Buckets->Bucket ?? [] as $b) {
			$out[] = (string) $b->Name;
		}

		return $out;
	}

	public function bucketRegion(string $bucket): string {
		$r = $this->signed('GET', 'https://s3.amazonaws.com/'.rawurlencode($bucket).'?location', '', 'us-east-1', 's3');
		if ($r['status'] !== 200) {
			return $r['headers']['x-amz-bucket-region'] ?? 'us-east-1';
		}
		$loc = trim(strip_tags($r['body']));

		return $loc === '' ? 'us-east-1' : ($loc === 'EU' ? 'eu-west-1' : $loc);
	}

	/** Bucket tags; [] when the bucket has none (NoSuchTagSet). */
	public function bucketTags(string $bucket, string $region): array {
		$r = $this->signed('GET', "https://s3.$region.amazonaws.com/".rawurlencode($bucket).'?tagging', '', $region, 's3');
		if ($r['status'] === 404) {
			return [];
		}
		$x = self::xml($r, 'GetBucketTagging');
		$out = [];
		foreach ($x->TagSet->Tag ?? [] as $t) {
			$out[(string) $t->Key] = (string) $t->Value;
		}

		return $out;
	}

	/** Latest daily BucketSizeBytes per storage class. */
	public function bucketSizes(string $bucket, string $region): array {
		$classes = ['StandardStorage' => 'STANDARD', 'StandardIAStorage' => 'STANDARD_IA', 'OneZoneIAStorage' => 'ONEZONE_IA',
			'IntelligentTieringFAStorage' => 'INTELLIGENT_TIERING', 'GlacierInstantRetrievalStorage' => 'GLACIER_IR',
			'GlacierStorage' => 'GLACIER', 'DeepArchiveStorage' => 'DEEP_ARCHIVE'];
		$out = [];
		foreach ($classes as $st => $class) {
			$q = ['Action' => 'GetMetricStatistics', 'Version' => '2010-08-01', 'Namespace' => 'AWS/S3', 'MetricName' => 'BucketSizeBytes',
				'Dimensions.member.1.Name' => 'BucketName', 'Dimensions.member.1.Value' => $bucket,
				'Dimensions.member.2.Name' => 'StorageType', 'Dimensions.member.2.Value' => $st,
				'StartTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 86400), 'EndTime' => gmdate('Y-m-d\TH:i:s\Z'),
				'Period' => '86400', 'Statistics.member.1' => 'Average'];
			$x = $this->query("https://monitoring.$region.amazonaws.com/", $q, $region, 'monitoring');
			$latest = null;
			foreach ($x->GetMetricStatisticsResult->Datapoints->member ?? [] as $d) {
				if ($latest === null || strtotime((string) $d->Timestamp) > $latest[0]) {
					$latest = [strtotime((string) $d->Timestamp), (float) $d->Average];
				}
			}
			if ($latest !== null && $latest[1] > 0) {
				$out[$class] = $latest[1];
			}
		}

		return $out;
	}

	/** Object keys under a prefix (ListObjectsV2). */
	public function listObjects(string $bucket, string $region, string $prefix): array {
		$out = [];
		$token = null;
		do {
			$q = ['list-type' => '2', 'prefix' => $prefix];
			if ($token !== null) {
				$q['continuation-token'] = $token;
			}
			$url = "https://s3.$region.amazonaws.com/".rawurlencode($bucket).'?'.http_build_query($q, '', '&', PHP_QUERY_RFC3986);
			$x = self::xml($this->signed('GET', $url, '', $region, 's3'), 'ListObjectsV2');
			foreach ($x->Contents ?? [] as $c) {
				$out[] = ['key' => (string) $c->Key, 'size' => (int) $c->Size];
			}
			$token = (string) $x->IsTruncated === 'true' ? (string) $x->NextContinuationToken : null;
		} while ($token !== null);

		return $out;
	}

	public function getObjectToFile(string $bucket, string $region, string $key, string $file): void {
		$path = implode('/', array_map('rawurlencode', explode('/', $key)));
		$url = "https://s3.$region.amazonaws.com/".rawurlencode($bucket).'/'.$path;
		$headers = SigV4::sign('GET', $url, [], '', $this->cred, $region, 's3');
		$r = $this->http->request('GET', $url, $headers, '', 600, $file);
		if ($r['status'] !== 200) {
			throw new RuntimeException("S3 GetObject failed for $key (HTTP {$r['status']}).");
		}
	}

	// ------------------------------------------------------------------ Cost Explorer

	/** Month-to-date unblended cost per resource id (Cost Explorer keeps 14 days at resource level). */
	public function costByResource(string $start, string $end): array {
		$body = [
			'TimePeriod' => ['Start' => $start, 'End' => $end],
			'Granularity' => 'DAILY',
			'Metrics' => ['UnblendedCost'],
			'Filter' => ['Dimensions' => ['Key' => 'SERVICE', 'Values' => ['Amazon Elastic Compute Cloud - Compute', 'EC2 - Other',
				'Amazon Simple Storage Service', 'Amazon Virtual Private Cloud']]],
			'GroupBy' => [['Type' => 'DIMENSION', 'Key' => 'RESOURCE_ID'], ['Type' => 'DIMENSION', 'Key' => 'USAGE_TYPE']]
		];
		$out = [];
		do {
			$j = $this->jsonRpc('https://ce.us-east-1.amazonaws.com/', 'AWSInsightsIndexService.GetCostAndUsageWithResources', $body, 'us-east-1', 'ce');
			foreach ($j['ResultsByTime'] ?? [] as $day) {
				foreach ($day['Groups'] ?? [] as $g) {
					[$rid, $usage] = $g['Keys'] + ['', ''];
					$out[] = ['resource_id' => $rid, 'usage_type' => $usage, 'cost' => (float) ($g['Metrics']['UnblendedCost']['Amount'] ?? 0)];
				}
			}
			$body['NextPageToken'] = $j['NextPageToken'] ?? null;
		} while ($body['NextPageToken'] !== null);

		return $out;
	}

	// ------------------------------------------------------------------ plumbing

	private function query(string $endpoint, array $q, string $region, string $service): SimpleXMLElement {
		$body = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
		$headers = SigV4::sign('POST', $endpoint, ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'], $body,
			$this->cred, $region, $service);

		return self::xml($this->http->request('POST', $endpoint, $headers, $body), $q['Action']);
	}

	private function jsonRpc(string $endpoint, string $target, array $body, string $region, string $service): array {
		$json = json_encode(array_filter($body, static fn($v) => $v !== null));
		$headers = SigV4::sign('POST', $endpoint, ['content-type' => 'application/x-amz-json-1.1', 'x-amz-target' => $target], $json,
			$this->cred, $region, $service);
		$r = $this->http->request('POST', $endpoint, $headers, $json);
		$j = json_decode($r['body'], true);
		if ($r['status'] !== 200) {
			$msg = $j['message'] ?? $j['Message'] ?? ('HTTP '.$r['status']);
			throw new RuntimeException(explode('.', $target)[1].' failed: '.$msg);
		}

		return is_array($j) ? $j : [];
	}

	private function signed(string $method, string $url, string $body, string $region, string $service): array {
		return $this->http->request($method, $url, SigV4::sign($method, $url, [], $body, $this->cred, $region, $service), $body);
	}

	public static function xml(array $r, string $op): SimpleXMLElement {
		$prev = libxml_use_internal_errors(true);
		$x = $r['body'] !== '' ? simplexml_load_string($r['body'], SimpleXMLElement::class, LIBXML_NONET) : false;
		libxml_use_internal_errors($prev);
		if ($r['status'] !== 200) {
			$code = $x ? (string) ($x->Errors->Error->Code ?? $x->Error->Code ?? $x->Code ?? '') : '';
			$msg = $x ? (string) ($x->Errors->Error->Message ?? $x->Error->Message ?? $x->Message ?? '') : '';
			throw new RuntimeException(trim("$op failed (HTTP {$r['status']}) $code: $msg", ' :'));
		}
		if ($x === false) {
			throw new RuntimeException("$op returned an unreadable answer.");
		}

		return $x;
	}
}
