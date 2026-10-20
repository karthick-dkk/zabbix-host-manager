<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib\Aws;

/**
 * AWS Signature Version 4 request signing (no SDK).
 * https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
class SigV4 {

	/**
	 * Returns the headers to send, including Authorization.
	 * $headers must not include Authorization; Host is added from the URL.
	 */
	public static function sign(string $method, string $url, array $headers, string $body, Credentials $cred,
			string $region, string $service, ?int $time = null): array {
		$time = $time ?? time();
		$amzDate = gmdate('Ymd\THis\Z', $time);
		$date = substr($amzDate, 0, 8);
		$u = parse_url($url);
		$host = $u['host'].(isset($u['port']) ? ':'.$u['port'] : '');

		$headers = array_change_key_case($headers, CASE_LOWER);
		$headers['host'] = $host;
		$headers['x-amz-date'] = $amzDate;
		if ($cred->token !== null && $cred->token !== '') {
			$headers['x-amz-security-token'] = $cred->token;
		}
		$payloadHash = hash('sha256', $body);
		if ($service === 's3') {
			$headers['x-amz-content-sha256'] = $payloadHash;
		}

		ksort($headers);
		$canonHeaders = '';
		foreach ($headers as $k => $v) {
			$canonHeaders .= $k.':'.preg_replace('/\s+/', ' ', trim((string) $v))."\n";
		}
		$signed = implode(';', array_keys($headers));

		$canonical = implode("\n", [
			strtoupper($method),
			self::canonicalPath($u['path'] ?? '/', $service),
			self::canonicalQuery($u['query'] ?? ''),
			$canonHeaders,
			$signed,
			$payloadHash
		]);
		$scope = "$date/$region/$service/aws4_request";
		$toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n".hash('sha256', $canonical);

		$k = hash_hmac('sha256', $date, 'AWS4'.$cred->secret, true);
		$k = hash_hmac('sha256', $region, $k, true);
		$k = hash_hmac('sha256', $service, $k, true);
		$k = hash_hmac('sha256', 'aws4_request', $k, true);
		$sig = hash_hmac('sha256', $toSign, $k);

		$headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$cred->keyId}/$scope, SignedHeaders=$signed, Signature=$sig";

		return $headers;
	}

	public static function canonicalPath(string $path, string $service): string {
		if ($path === '') {
			return '/';
		}
		$segments = explode('/', $path);
		$out = [];
		foreach ($segments as $s) {
			$enc = rawurlencode(rawurldecode($s));
			// Every service except S3 encodes path segments twice.
			$out[] = $service === 's3' ? $enc : rawurlencode($enc);
		}

		return implode('/', $out);
	}

	public static function canonicalQuery(string $query): string {
		if ($query === '') {
			return '';
		}
		$pairs = [];
		foreach (explode('&', $query) as $p) {
			if ($p === '') {
				continue;
			}
			[$k, $v] = array_pad(explode('=', $p, 2), 2, '');
			$pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
		}
		usort($pairs, static fn($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

		return implode('&', array_map(static fn($p) => $p[0].'='.$p[1], $pairs));
	}
}
