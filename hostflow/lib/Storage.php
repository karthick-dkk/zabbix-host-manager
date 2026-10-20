<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use RuntimeException;

/**
 * Files under the Host Flow data directory: atomic JSON writes, per-file locks.
 * The directory is outside the web root and never served directly.
 */
class Storage {

	public const DEFAULT_DIR = '/var/lib/zabbix-hostflow';

	private static ?string $dir = null;

	public static function dir(): string {
		if (self::$dir === null) {
			$env = getenv('HOSTFLOW_DATA_DIR');
			self::$dir = rtrim($env !== false && $env !== '' ? $env : self::DEFAULT_DIR, '/');
		}

		return self::$dir;
	}

	/** Tests point the store at a temporary directory. */
	public static function setDir(string $dir): void {
		self::$dir = rtrim($dir, '/');
	}

	public static function path(string ...$parts): string {
		foreach ($parts as $p) {
			if ($p === '' || $p === '.' || $p === '..' || strpbrk($p, "/\\\0") !== false) {
				throw new RuntimeException('Invalid path segment.');
			}
		}

		return self::dir().'/'.implode('/', $parts);
	}

	public static function ensureDir(string $dir): void {
		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			throw new RuntimeException(sprintf('Cannot create the data directory %s. Create it and make it writable by the web server user, or set HOSTFLOW_DATA_DIR.', $dir));
		}
		if (!is_writable($dir)) {
			throw new RuntimeException(sprintf('The data directory %s is not writable by the web server user.', $dir));
		}
	}

	public static function readJson(string $file, $default = null) {
		if (!is_file($file)) {
			return $default;
		}
		$raw = @file_get_contents($file);
		if ($raw === false) {
			return $default;
		}
		$data = json_decode($raw, true);

		return $data === null && trim($raw) !== 'null' ? $default : $data;
	}

	public static function writeJson(string $file, $data): void {
		self::writeAtomic($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
	}

	/** Write to a temporary file in the same directory, then rename into place. */
	public static function writeAtomic(string $file, string $content, int $mode = 0640): void {
		self::ensureDir(dirname($file));
		$tmp = $file.'.tmp.'.bin2hex(random_bytes(6));
		if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
			throw new RuntimeException(sprintf('Cannot write %s.', $file));
		}
		@chmod($tmp, $mode);
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			throw new RuntimeException(sprintf('Cannot write %s.', $file));
		}
	}

	/**
	 * Run $fn while holding an exclusive lock named $name. Locks are files in locks/.
	 * Returns whatever $fn returns.
	 */
	public static function withLock(string $name, callable $fn, bool $wait = true) {
		$dir = self::path('locks');
		self::ensureDir($dir);
		$fh = fopen($dir.'/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $name).'.lock', 'c');
		if ($fh === false) {
			throw new RuntimeException('Cannot open a lock file.');
		}
		try {
			if (!flock($fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
				return null;
			}

			return $fn();
		}
		finally {
			flock($fh, LOCK_UN);
			fclose($fh);
		}
	}

	public static function appendLine(string $file, string $line): void {
		self::ensureDir(dirname($file));
		if (@file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX) === false) {
			throw new RuntimeException(sprintf('Cannot write %s.', $file));
		}
	}
}
