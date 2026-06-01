<?php

namespace Drupal\mantle2\Custom;

use Drupal\mantle2\Service\GeneralHelper;
use JsonSerializable;

/**
 * Domain model for an issued API key. Constructed from a database row by
 * [[ApiKeysHelper]]. Does not hold the raw token — that exists only once at
 * issuance time and is returned in the create response.
 */
class ApiKey implements JsonSerializable
{
	public const TOKEN_PREFIX = 'EA';

	public const NAME_MIN = 3;
	public const NAME_MAX = 64;
	public const DESCRIPTION_MAX = 512;

	public const RANDOM_HEX_LEN = 32; // 128 bits
	public const USER_HEX_LEN = 24; // matches GeneralHelper::formatId width
	public const TIMESTAMP_HEX_LEN = 16; // 64-bit ms timestamp
	public const PUBLIC_PREFIX_LEN = 14; // EA<YY> + first 10 of random — safe to store/display

	public const TOTAL_LENGTH =
		2 + 2 + self::RANDOM_HEX_LEN + 1 + self::USER_HEX_LEN + 1 + self::TIMESTAMP_HEX_LEN;

	public function __construct(
		private readonly int $id,
		private readonly string $keyId,
		private readonly int $userId,
		private readonly string $tokenHash,
		private readonly string $tokenPrefix,
		private readonly string $name,
		private readonly ?string $description,
		private readonly array $scopes,
		private readonly int $createdAt,
		private readonly ?int $expiresAt,
		private readonly ?int $lastUsedAt,
		private readonly ?string $lastUsedIp,
		private readonly ?int $revokedAt,
	) {}

	public static function fromRow(array $row): self
	{
		$scopes = [];
		if (!empty($row['scopes'])) {
			$decoded = json_decode((string) $row['scopes'], true);
			if (is_array($decoded)) {
				$scopes = array_values(array_filter($decoded, 'is_string'));
			}
		}

		return new self(
			id: (int) $row['id'],
			keyId: (string) $row['key_id'],
			userId: (int) $row['user_id'],
			tokenHash: (string) $row['token_hash'],
			tokenPrefix: (string) $row['token_prefix'],
			name: (string) $row['name'],
			description: isset($row['description']) ? (string) $row['description'] : null,
			scopes: $scopes,
			createdAt: (int) $row['created_at'],
			expiresAt: isset($row['expires_at']) ? (int) $row['expires_at'] : null,
			lastUsedAt: isset($row['last_used_at']) ? (int) $row['last_used_at'] : null,
			lastUsedIp: isset($row['last_used_ip']) ? (string) $row['last_used_ip'] : null,
			revokedAt: isset($row['revoked_at']) ? (int) $row['revoked_at'] : null,
		);
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getKeyId(): string
	{
		return $this->keyId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getTokenHash(): string
	{
		return $this->tokenHash;
	}

	public function getTokenPrefix(): string
	{
		return $this->tokenPrefix;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	/**
	 * Returns the scopes exactly as granted (may include parents).
	 *
	 * @return string[]
	 */
	public function getScopes(): array
	{
		return $this->scopes;
	}

	public function getCreatedAt(): int
	{
		return $this->createdAt;
	}

	public function getExpiresAt(): ?int
	{
		return $this->expiresAt;
	}

	public function getLastUsedAt(): ?int
	{
		return $this->lastUsedAt;
	}

	public function getLastUsedIp(): ?string
	{
		return $this->lastUsedIp;
	}

	public function getRevokedAt(): ?int
	{
		return $this->revokedAt;
	}

	public function isRevoked(): bool
	{
		return $this->revokedAt !== null;
	}

	public function isExpired(int $now = 0): bool
	{
		if ($this->expiresAt === null) {
			return false;
		}
		$now = $now > 0 ? $now : time();
		return $this->expiresAt <= $now;
	}

	public function isUsable(int $now = 0): bool
	{
		return !$this->isRevoked() && !$this->isExpired($now);
	}

	public function hasScope(string $scope): bool
	{
		return ApiKeyScope::satisfies($this->scopes, $scope);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->keyId,
			'name' => $this->name,
			'description' => $this->description,
			'scopes' => $this->scopes,
			'token_prefix' => $this->tokenPrefix,
			'created_at' => GeneralHelper::dateToIso($this->createdAt),
			'expires_at' => $this->expiresAt ? GeneralHelper::dateToIso($this->expiresAt) : null,
			'last_used_at' => $this->lastUsedAt
				? GeneralHelper::dateToIso($this->lastUsedAt)
				: null,
			'last_used_ip' => $this->lastUsedIp,
			'revoked' => $this->isRevoked(),
			'revoked_at' => $this->revokedAt ? GeneralHelper::dateToIso($this->revokedAt) : null,
			'expired' => $this->isExpired(),
			'never_expires' => $this->expiresAt === null,
		];
	}
}
