<?php declare(strict_types = 0);

namespace Modules\HostFlow\Actions;

use CController;
use CControllerResponseData;
use Modules\HostFlow\Lib\Config;
use Throwable;

require_once __DIR__.'/../lib/autoload.php';

/**
 * Shared controller behaviour.
 *   ACCESS   lowest Zabbix user type allowed (checked on the server for every request)
 *   READONLY GET actions skip CSRF; everything else is POST with Zabbix's CSRF token
 *   JSON     the response is JSON ({error: "..."} on failure)
 */
abstract class Base extends CController {

	protected const ACCESS = USER_TYPE_ZABBIX_USER;
	protected const READONLY = true;
	protected const JSON = false;

	protected function init(): void {
		if (static::READONLY) {
			$this->disableCsrfValidation();
		}
		elseif (static::JSON) {
			$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		}
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= static::ACCESS;
	}

	/** Validate input; for JSON actions, a failure still produces a JSON answer. */
	protected function validated(array $rules): bool {
		$ok = $this->validateInput($rules);
		if (!$ok && static::JSON) {
			$messages = array_column(get_and_clear_messages(), 'message');
			$this->json(['error' => $messages ? implode(' ', $messages) : _('Invalid request.')]);
		}

		return $ok;
	}

	protected function checkInput(): bool {
		return $this->validated([]);
	}

	protected function doAction(): void {
		try {
			$this->handle();
		}
		catch (Throwable $e) {
			if (!static::JSON) {
				throw $e;
			}
			error_log('hostflow: '.get_class($e).': '.$e->getMessage());
			$this->json(['error' => $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException
				? $e->getMessage() : _('Something went wrong. The details are in the web server error log.')]);
		}
	}

	abstract protected function handle(): void;

	protected function json(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]));
	}

	protected function isAdmin(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	}

	protected function isSuperAdmin(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	/** Cost is shown according to Settings → Cost → Visible to. */
	protected function canSeeCost(): bool {
		$cost = Config::get()['cost'];
		if (!$cost['enabled']) {
			return false;
		}

		return $cost['visible_to'] === 'everyone'
			|| ($cost['visible_to'] === 'admins' && $this->isAdmin())
			|| ($cost['visible_to'] === 'superadmins' && $this->isSuperAdmin());
	}

	/** Diagram access lists: empty means everyone with module access. */
	protected function canOpen(array $meta): bool {
		if ($this->isSuperAdmin() || empty($meta['acl'])) {
			return true;
		}
		$mine = array_column(\CWebUser::$data['usrgrps'] ?? [], 'usrgrpid');
		if (!$mine) {
			$mine = array_column(\API::UserGroup()->get(['output' => ['usrgrpid'], 'userids' => [\CWebUser::$data['userid']]]), 'usrgrpid');
		}

		return (bool) array_intersect(array_map('strval', $meta['acl']), array_map('strval', $mine));
	}
}
