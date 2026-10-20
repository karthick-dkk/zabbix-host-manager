<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use RuntimeException;

/**
 * Encrypts stored secrets (AWS secret access keys) with AES-256-GCM.
 * The key lives in keys/secret.key (0600) in the data directory, never in settings.json,
 * never in the Zabbix database, and is created on first use.
 */
class Crypto {

	public static function encrypt(string $plain): string {
		$iv = random_bytes(12);
		$tag = '';
		$ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($ct === false) {
			throw new RuntimeException('Encryption failed.');
		}

		return 'v1:'.base64_encode($iv.$tag.$ct);
	}

	public static function decrypt(string $enc): string {
		if (strncmp($enc, 'v1:', 3) !== 0) {
			throw new RuntimeException('Unknown secret format.');
		}
		$raw = base64_decode(substr($enc, 3), true);
		if ($raw === false || strlen($raw) < 29) {
			throw new RuntimeException('The stored secret is damaged.');
		}
		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
		if ($plain === false) {
			throw new RuntimeException('The stored secret cannot be decrypted (was keys/secret.key replaced?). Enter it again.');
		}

		return $plain;
	}

	private static function key(): string {
		$file = Storage::path('keys', 'secret.key');
		if (!is_file($file)) {
			Storage::ensureDir(dirname($file));
			@chmod(dirname($file), 0700);
			Storage::writeAtomic($file, base64_encode(random_bytes(32)), 0600);
		}
		$key = base64_decode(trim((string) file_get_contents($file)), true);
		if ($key === false || strlen($key) !== 32) {
			throw new RuntimeException('keys/secret.key is damaged.');
		}

		return $key;
	}
}
