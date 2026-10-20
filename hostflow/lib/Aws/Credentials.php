<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib\Aws;

use Modules\HostFlow\Lib\Crypto;
use RuntimeException;

/**
 * Credentials for one configured AWS account:
 *   instance  the Zabbix server's own IAM role (environment variables, ECS task role, or EC2 IMDSv2)
 *   assume    AssumeRole into role_arn (with optional external ID), starting from a key if one is
 *             stored for the account, otherwise from the instance role
 *   key       a stored access key (secret kept encrypted)
 */
class Credentials {

	public string $keyId;
	public string $secret;
	public ?string $token;
	public ?int $expires;

	public function __construct(string $keyId, string $secret, ?string $token = null, ?int $expires = null) {
		$this->keyId = $keyId;
		$this->secret = $secret;
		$this->token = $token;
		$this->expires = $expires;
	}

	public static function forAccount(array $acc, ?Http $http = null): self {
		$http = $http ?? new Http();
		switch ($acc['auth']) {
			case 'key':
				return self::stored($acc);
			case 'assume':
				$base = ($acc['key_id'] ?? '') !== '' && isset($acc['secret_enc']) ? self::stored($acc) : self::instance($http);

				return self::assumeRole($base, $acc['role_arn'], $acc['external_id'] ?? '', $http);
			default:
				return self::instance($http);
		}
	}

	public static function stored(array $acc): self {
		if (($acc['key_id'] ?? '') === '' || !isset($acc['secret_enc'])) {
			throw new RuntimeException(sprintf('Account %s has no stored access key.', $acc['id']));
		}

		return new self($acc['key_id'], Crypto::decrypt($acc['secret_enc']));
	}

	/** Environment, ECS task role, or EC2 instance profile (IMDSv2). */
	public static function instance(Http $http): self {
		$id = getenv('AWS_ACCESS_KEY_ID');
		$secret = getenv('AWS_SECRET_ACCESS_KEY');
		if ($id && $secret) {
			return new self($id, $secret, getenv('AWS_SESSION_TOKEN') ?: null);
		}

		$rel = getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
		if ($rel) {
			$r = $http->request('GET', 'http://169.254.170.2'.$rel, [], '', 3);

			return self::fromJson($r['body'], 'ECS task role');
		}

		$tok = $http->request('PUT', 'http://169.254.169.254/latest/api/token', ['X-aws-ec2-metadata-token-ttl-seconds' => '21600'], '', 2);
		if ($tok['status'] !== 200) {
			throw new RuntimeException('No instance role: this server is not on EC2 (or IMDS is blocked). Use AssumeRole with a key, or an access key.');
		}
		$h = ['X-aws-ec2-metadata-token' => $tok['body']];
		$role = trim($http->request('GET', 'http://169.254.169.254/latest/meta-data/iam/security-credentials/', $h, '', 2)['body']);
		if ($role === '') {
			throw new RuntimeException('This EC2 instance has no IAM role attached.');
		}
		$r = $http->request('GET', 'http://169.254.169.254/latest/meta-data/iam/security-credentials/'.rawurlencode(strtok($role, "\n")), $h, '', 2);

		return self::fromJson($r['body'], 'instance role');
	}

	public static function assumeRole(self $base, string $roleArn, string $externalId, Http $http): self {
		$q = ['Action' => 'AssumeRole', 'Version' => '2011-06-15', 'RoleArn' => $roleArn,
			'RoleSessionName' => 'zabbix-hostflow', 'DurationSeconds' => '3600'];
		if ($externalId !== '') {
			$q['ExternalId'] = $externalId;
		}
		$url = 'https://sts.amazonaws.com/?'.http_build_query($q, '', '&', PHP_QUERY_RFC3986);
		$headers = SigV4::sign('GET', $url, [], '', $base, 'us-east-1', 'sts');
		$r = $http->request('GET', $url, $headers, '');
		$xml = Client::xml($r, 'AssumeRole');
		$c = $xml->AssumeRoleResult->Credentials;
		if (!$c) {
			throw new RuntimeException('AssumeRole returned no credentials.');
		}

		return new self((string) $c->AccessKeyId, (string) $c->SecretAccessKey, (string) $c->SessionToken, strtotime((string) $c->Expiration));
	}

	private static function fromJson(string $body, string $what): self {
		$j = json_decode($body, true);
		if (!isset($j['AccessKeyId'], $j['SecretAccessKey'])) {
			throw new RuntimeException("Could not read credentials from the $what.");
		}

		return new self($j['AccessKeyId'], $j['SecretAccessKey'], $j['Token'] ?? null, isset($j['Expiration']) ? strtotime($j['Expiration']) : null);
	}
}
