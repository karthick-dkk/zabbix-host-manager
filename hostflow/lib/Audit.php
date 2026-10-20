<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/**
 * Who changed what, one JSON object per line (audit/hostflow-audit.jsonl).
 * Never contains diagram XML, prices typed as secrets, or AWS credentials.
 */
class Audit {

	public static function log(string $action, array $fields = []): void {
		$event = [
			'@timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'event.action' => $action,
			'user.name' => self::user(),
			'source.ip' => $_SERVER['REMOTE_ADDR'] ?? null
		] + $fields;

		try {
			Storage::appendLine(Storage::path('audit', 'hostflow-audit.jsonl'),
				json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			);
		}
		catch (\Throwable $e) {
			// Auditing must never break the change it records.
			error_log('hostflow: audit write failed: '.$e->getMessage());
		}
	}

	public static function user(): ?string {
		if (class_exists('CWebUser', false) && isset(\CWebUser::$data['username'])) {
			return \CWebUser::$data['username'];
		}

		return null;
	}

	public static function userId(): ?string {
		if (class_exists('CWebUser', false) && isset(\CWebUser::$data['userid'])) {
			return (string) \CWebUser::$data['userid'];
		}

		return null;
	}
}
