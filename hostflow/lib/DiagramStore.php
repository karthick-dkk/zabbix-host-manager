<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use RuntimeException;

/**
 * Diagrams on disk, one file each (no version history):
 *   diagrams/<id>/meta.json        name, client, owner, acl, auto, hostids, flow, updated_*
 *   diagrams/<id>/diagram.drawio   the XML
 * meta.revision is only a save counter: a save based on an older counter is refused,
 * so two people editing at once can't overwrite each other without noticing.
 */
class DiagramStore {

	public const FILE = 'diagram.drawio';

	/** Diagrams that are not deleted, by name. $withDeleted also returns deleted ones (to remember removed auto flows). */
	public static function list(bool $withDeleted = false): array {
		$dir = Storage::path('diagrams');
		if (!is_dir($dir)) {
			return [];
		}
		$out = [];
		foreach (scandir($dir) as $id) {
			if (self::validId($id) && is_file("$dir/$id/meta.json")) {
				$meta = Storage::readJson("$dir/$id/meta.json");
				if (is_array($meta) && ($withDeleted || empty($meta['deleted']))) {
					$out[] = $meta;
				}
			}
		}
		usort($out, static fn($a, $b) => strnatcasecmp($a['name'] ?? '', $b['name'] ?? ''));

		return $out;
	}

	public static function meta(string $id): ?array {
		if (!self::validId($id)) {
			return null;
		}
		$meta = Storage::readJson(Storage::path('diagrams', $id, 'meta.json'));

		return is_array($meta) && empty($meta['deleted']) ? $meta : null;
	}

	public static function xml(string $id): ?string {
		if (self::meta($id) === null) {
			return null;
		}
		$file = Storage::path('diagrams', $id, self::FILE);
		if (is_file($file)) {
			return file_get_contents($file);
		}
		// Diagrams saved by 1.0.0 builds kept numbered files; read the newest one.
		$legacy = self::legacyFiles($id);

		return $legacy ? file_get_contents(end($legacy)) : null;
	}

	/**
	 * Create (id null) or overwrite a diagram. $expectedRev must match the stored save counter,
	 * otherwise a ConflictException names who saved in between.
	 */
	public static function save(?string $id, string $xml, array $fields, ?int $expectedRev): array {
		$xml = DrawioXml::sanitize($xml);
		$hostids = DrawioXml::linkedHostIds($xml);
		$create = $id === null;
		$id = $id ?? self::newId();

		return Storage::withLock('diagram-'.$id, static function () use ($id, $xml, $fields, $expectedRev, $hostids, $create) {
			$metaFile = Storage::path('diagrams', $id, 'meta.json');
			$meta = Storage::readJson($metaFile);

			if ($create) {
				$meta = [
					'id' => $id,
					'name' => 'Untitled diagram',
					'client' => '',
					'owner' => Audit::user(),
					'acl' => [],
					'auto' => false,
					'revision' => 0,
					'created_at' => time()
				];
			}
			elseif (!is_array($meta) || !empty($meta['deleted'])) {
				throw new RuntimeException('This diagram no longer exists.');
			}
			elseif ($expectedRev !== null && (int) $meta['revision'] !== $expectedRev) {
				throw new ConflictException($meta['updated_by'] ?? '', (int) ($meta['updated_at'] ?? 0), (int) $meta['revision']);
			}

			$before = $meta['hostids'] ?? [];
			foreach (['name', 'client', 'acl', 'flow', 'layout', 'auto', 'synced_at', 'origin', 'auto_kind', 'gen'] as $k) {
				if (array_key_exists($k, $fields)) {
					$meta[$k] = $fields[$k];
				}
			}
			$meta['name'] = trim((string) $meta['name']) === '' ? 'Untitled diagram' : mb_substr(trim($meta['name']), 0, 128);
			$meta['auto'] = !empty($meta['auto']);
			$meta['revision'] = (int) $meta['revision'] + 1;
			$meta['hostids'] = $hostids;
			$meta['updated_by'] = Audit::user();
			$meta['updated_at'] = time();

			Storage::writeAtomic(Storage::path('diagrams', $id, self::FILE), $xml);
			Storage::writeJson($metaFile, $meta);
			foreach (self::legacyFiles($id) as $f) {
				@unlink($f);
			}

			$added = array_values(array_diff($hostids, $before));
			$removed = array_values(array_diff($before, $hostids));
			if ($create || $added || $removed || empty($fields['synced_at'])) {
				Audit::log($create ? 'diagram.create' : 'diagram.save', [
					'diagram.id' => $id,
					'diagram.name' => $meta['name'],
					'diagram.auto' => $meta['auto'],
					'hosts.added' => $added,
					'hosts.removed' => $removed
				]);
			}

			return $meta;
		});
	}

	/** Update name/client/acl without touching the XML. */
	public static function updateMeta(string $id, array $fields): array {
		return Storage::withLock('diagram-'.$id, static function () use ($id, $fields) {
			$meta = self::meta($id);
			if ($meta === null) {
				throw new RuntimeException('This diagram no longer exists.');
			}
			foreach (['name', 'client', 'acl', 'synced_at'] as $k) {
				if (array_key_exists($k, $fields)) {
					$meta[$k] = $fields[$k];
				}
			}
			Storage::writeJson(Storage::path('diagrams', $id, 'meta.json'), $meta);
			if (!array_key_exists('synced_at', $fields) || count($fields) > 1) {
				Audit::log('diagram.update', ['diagram.id' => $id, 'diagram.name' => $meta['name']]);
			}

			return $meta;
		});
	}

	public static function delete(string $id): void {
		Storage::withLock('diagram-'.$id, static function () use ($id) {
			$meta = self::meta($id);
			if ($meta === null) {
				return;
			}
			$meta['deleted'] = time();
			$meta['deleted_by'] = Audit::user();
			Storage::writeJson(Storage::path('diagrams', $id, 'meta.json'), $meta);
			@unlink(Storage::path('diagrams', $id, self::FILE));
			Audit::log('diagram.delete', ['diagram.id' => $id, 'diagram.name' => $meta['name']]);
		});
	}

	public static function validId(string $id): bool {
		return (bool) preg_match('/^[a-f0-9]{12}$/', $id);
	}

	private static function newId(): string {
		return bin2hex(random_bytes(6));
	}

	/** rev-NNNNNN.drawio files written by 1.0.0, oldest first. */
	private static function legacyFiles(string $id): array {
		$files = glob(Storage::path('diagrams', $id).'/rev-*.drawio') ?: [];
		sort($files);

		return $files;
	}
}
