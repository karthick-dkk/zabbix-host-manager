<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use RuntimeException;

class ConflictException extends RuntimeException {

	public string $by;
	public int $at;
	public int $revision;

	public function __construct(string $by, int $at, int $revision) {
		parent::__construct(sprintf('Changed by %s at %s. Reload, or save as a copy.', $by ?: 'someone', gmdate('Y-m-d H:i', $at).' UTC'));
		$this->by = $by;
		$this->at = $at;
		$this->revision = $revision;
	}
}
