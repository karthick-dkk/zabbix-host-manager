<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib\Aws;

use RuntimeException;

/** Small curl wrapper; tests replace it with a fake. */
class Http {

	/** @return array{status:int, body:string, headers:array} */
	public function request(string $method, string $url, array $headers, string $body, int $timeout = 30, ?string $saveTo = null): array {
		$ch = curl_init($url);
		$hdr = [];
		foreach ($headers as $k => $v) {
			$hdr[] = $k.': '.$v;
		}
		$respHeaders = [];
		$opts = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $hdr,
			CURLOPT_RETURNTRANSFER => $saveTo === null,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders) {
				$p = strpos($line, ':');
				if ($p !== false) {
					$respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
				}

				return strlen($line);
			}
		];
		if ($body !== '' || in_array($method, ['POST', 'PUT'], true)) {
			$opts[CURLOPT_POSTFIELDS] = $body;
		}
		$fh = null;
		if ($saveTo !== null) {
			$fh = fopen($saveTo, 'wb');
			$opts[CURLOPT_FILE] = $fh;
		}
		curl_setopt_array($ch, $opts);
		$out = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err = curl_error($ch);
		if ($fh) {
			fclose($fh);
		}
		if ($out === false && $status === 0) {
			throw new RuntimeException('Could not reach '.parse_url($url, PHP_URL_HOST).': '.$err);
		}

		return ['status' => $status, 'body' => $saveTo === null ? (string) $out : '', 'headers' => $respHeaders];
	}
}
