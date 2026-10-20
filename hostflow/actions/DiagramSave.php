<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use Modules\HostFlow\Lib\{Config, ConflictException, DiagramStore, DrawioXml};

class DiagramSave extends Base {

	protected const ACCESS = USER_TYPE_ZABBIX_ADMIN;
	protected const READONLY = false;
	protected const JSON = true;

	protected function checkInput(): bool {
		return $this->validated([
			'id' => 'string', 'xml' => 'required|string', 'name' => 'string', 'client' => 'string',
			'revision' => 'int32', 'acl' => 'array', 'layout' => 'string', 'as_copy' => 'in 0,1',
			'keep_auto' => 'in 0,1', 'refresh_flow' => 'in 0,1'
		]);
	}

	protected function handle(): void {
		$id = $this->getInput('id', '');
		$fields = [];
		foreach (['name', 'client', 'layout'] as $k) {
			if ($this->hasInput($k)) {
				$fields[$k] = (string) $this->getInput($k);
			}
		}
		if ($this->hasInput('acl')) {
			$fields['acl'] = array_values(array_filter(array_map('strval', $this->getInput('acl')), 'ctype_digit'));
		}
		$copy = $this->getInput('as_copy', '0') === '1';
		// Saved by a person: from now on it is theirs, and auto-sync leaves it alone.
		// keep_auto: Discard put an untouched client flow back exactly as it was.
		$fields['auto'] = $this->getInput('keep_auto', '0') === '1' && !$copy;
		// The diagram remembers the flow template it was drawn with (the client's, else the default),
		// so later Settings changes don't silently change it. Generate/Update in the editor refreshes it.
		$flow = null;
		if ($id === '' || $copy || $this->getInput('refresh_flow', '0') === '1') {
			$flow = $this->flowFor($fields['client'] ?? '');
		}

		if ($id === '' || $copy) {
			if ($copy) {
				$fields['name'] = ($fields['name'] ?? _('Diagram')).' '._('(copy)');
			}
			$fields['flow'] = $flow;
			$meta = DiagramStore::save(null, $this->getInput('xml'), $fields, null);
		}
		else {
			$existing = DiagramStore::meta($id);
			if ($existing === null || !$this->canOpen($existing)) {
				$this->json(['error' => _('This diagram does not exist or you have no access to it.')]);
				return;
			}
			if ($flow !== null) {
				$fields['flow'] = $flow;
			}
			$xml = $this->getInput('xml');
			if (!$this->isSuperAdmin()) {
				// This person saw hosts they may not read as "no access": keep those as stored.
				$old = (string) DiagramStore::xml($id);
				$xml = DrawioXml::sanitize($xml);
				// Links only to hosts this person may read; the hidden ones are put back as stored below.
				$xml = DrawioXml::dropHostLinks($xml, $this->readableHosts(DrawioXml::linkedHostIds($xml)));
				if ($old !== '') {
					$xml = DrawioXml::restoreHidden(DrawioXml::sanitize($xml), $old,
						$this->readableHosts(array_merge($existing['hostids'] ?? [], DrawioXml::linkedHostIds($old))));
				}
			}
			try {
				$meta = DiagramStore::save($id, $xml, $fields,
					$this->hasInput('revision') ? (int) $this->getInput('revision') : null);
			}
			catch (ConflictException $e) {
				$this->json(['error' => $e->getMessage(), 'conflict' => ['by' => $e->by, 'at' => $e->at, 'revision' => $e->revision]]);
				return;
			}
		}
		$this->json(['meta' => $meta]);
	}

	private function flowFor(string $client): array {
		$template = null;
		if ($client !== '') {
			try {
				$template = \Modules\HostFlow\Lib\ZabbixModel::clients()[$client]['template'] ?? null;
			}
			catch (\Throwable $e) {
				$template = null;
			}
		}

		return Config::template($template);
	}
}
