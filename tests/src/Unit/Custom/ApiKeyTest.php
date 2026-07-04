<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\ApiKey;
use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\mantle2\Service\GeneralHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ApiKeyTest extends TestCase
{
	private function make(array $overrides = []): ApiKey
	{
		$d = array_merge(
			[
				'id' => 7,
				'keyId' => 'key_abc',
				'userId' => 42,
				'tokenHash' => str_repeat('a', 64),
				'tokenPrefix' => 'EA26abcd1234',
				'name' => 'CI Key',
				'description' => 'used by ci',
				'scopes' => [ApiKeyScope::USER_READ],
				'createdAt' => 1717000000,
				'expiresAt' => null,
				'lastUsedAt' => null,
				'lastUsedIp' => null,
				'revokedAt' => null,
			],
			$overrides,
		);

		return new ApiKey(
			$d['id'],
			$d['keyId'],
			$d['userId'],
			$d['tokenHash'],
			$d['tokenPrefix'],
			$d['name'],
			$d['description'],
			$d['scopes'],
			$d['createdAt'],
			$d['expiresAt'],
			$d['lastUsedAt'],
			$d['lastUsedIp'],
			$d['revokedAt'],
		);
	}

	#[Test]
	#[TestDox('Length constants compose into TOTAL_LENGTH')]
	#[Group('mantle2/custom')]
	public function testConstants(): void
	{
		$this->assertSame('EA', ApiKey::TOKEN_PREFIX);
		$this->assertSame(32, ApiKey::RANDOM_HEX_LEN);
		$this->assertSame(24, ApiKey::USER_HEX_LEN);
		$this->assertSame(16, ApiKey::TIMESTAMP_HEX_LEN);
		$this->assertSame(2 + 2 + 32 + 1 + 24 + 1 + 16, ApiKey::TOTAL_LENGTH);
	}

	#[Test]
	#[TestDox('Every getter returns its constructor argument')]
	#[Group('mantle2/custom')]
	public function testGetters(): void
	{
		$k = $this->make([
			'expiresAt' => 1717003600,
			'lastUsedAt' => 1717001000,
			'lastUsedIp' => '10.0.0.1',
			'revokedAt' => 1717002000,
		]);

		$this->assertSame(7, $k->getId());
		$this->assertSame('key_abc', $k->getKeyId());
		$this->assertSame(42, $k->getUserId());
		$this->assertSame(str_repeat('a', 64), $k->getTokenHash());
		$this->assertSame('EA26abcd1234', $k->getTokenPrefix());
		$this->assertSame('CI Key', $k->getName());
		$this->assertSame('used by ci', $k->getDescription());
		$this->assertSame([ApiKeyScope::USER_READ], $k->getScopes());
		$this->assertSame(1717000000, $k->getCreatedAt());
		$this->assertSame(1717003600, $k->getExpiresAt());
		$this->assertSame(1717001000, $k->getLastUsedAt());
		$this->assertSame('10.0.0.1', $k->getLastUsedIp());
		$this->assertSame(1717002000, $k->getRevokedAt());
	}

	#[Test]
	#[TestDox('isRevoked reflects whether revokedAt is set')]
	#[Group('mantle2/custom')]
	public function testIsRevoked(): void
	{
		$this->assertFalse($this->make(['revokedAt' => null])->isRevoked());
		$this->assertTrue($this->make(['revokedAt' => 1])->isRevoked());
		$this->assertTrue($this->make(['revokedAt' => 0])->isRevoked() === false);
	}

	#[Test]
	#[TestDox('isExpired is false when never-expiring and honors the now boundary')]
	#[Group('mantle2/custom')]
	public function testIsExpired(): void
	{
		$this->assertFalse($this->make(['expiresAt' => null])->isExpired(9999999999));

		$k = $this->make(['expiresAt' => 1000]);
		$this->assertTrue($k->isExpired(1000)); // <= now
		$this->assertTrue($k->isExpired(1001));
		$this->assertFalse($k->isExpired(999));
	}

	#[Test]
	#[TestDox('isUsable requires not revoked and not expired')]
	#[Group('mantle2/custom')]
	public function testIsUsable(): void
	{
		$this->assertTrue($this->make(['expiresAt' => 2000])->isUsable(1000));
		$this->assertFalse($this->make(['expiresAt' => 500])->isUsable(1000));
		$this->assertFalse($this->make(['expiresAt' => 2000, 'revokedAt' => 10])->isUsable(1000));
	}

	#[Test]
	#[TestDox('hasScope delegates to ApiKeyScope::satisfies with implicit-parent semantics')]
	#[Group('mantle2/custom')]
	public function testHasScope(): void
	{
		$k = $this->make(['scopes' => [ApiKeyScope::USER_EDIT]]);
		$this->assertTrue($k->hasScope(ApiKeyScope::USER_EDIT_EMAIL));
		$this->assertTrue($k->hasScope(ApiKeyScope::USER_EDIT));
		$this->assertFalse($k->hasScope(ApiKeyScope::USER_READ));
	}

	#[Test]
	#[TestDox('fromRow coerces types, decodes json scopes, and nulls optional columns')]
	#[Group('mantle2/custom')]
	public function testFromRow(): void
	{
		$k = ApiKey::fromRow([
			'id' => '7',
			'key_id' => 'key_abc',
			'user_id' => '42',
			'token_hash' => 'hash',
			'token_prefix' => 'EA26aaaa',
			'name' => 'K',
			'description' => 'd',
			'scopes' => json_encode([ApiKeyScope::USER_READ, 123, ApiKeyScope::EVENTS_READ]),
			'created_at' => '1717000000',
			'expires_at' => '1717003600',
			'last_used_at' => '1717001000',
			'last_used_ip' => '10.0.0.1',
			'revoked_at' => '1717002000',
		]);

		$this->assertSame(7, $k->getId());
		$this->assertSame(42, $k->getUserId());
		// non-string scope entries are filtered out
		$this->assertSame([ApiKeyScope::USER_READ, ApiKeyScope::EVENTS_READ], $k->getScopes());
		$this->assertSame(1717003600, $k->getExpiresAt());
	}

	#[Test]
	#[TestDox('fromRow leaves scopes empty and optional columns null when absent')]
	#[Group('mantle2/custom')]
	public function testFromRowDefaults(): void
	{
		$k = ApiKey::fromRow([
			'id' => 1,
			'key_id' => 'k',
			'user_id' => 2,
			'token_hash' => 'h',
			'token_prefix' => 'EA',
			'name' => 'N',
			'created_at' => 100,
		]);

		$this->assertSame([], $k->getScopes());
		$this->assertNull($k->getDescription());
		$this->assertNull($k->getExpiresAt());
		$this->assertNull($k->getLastUsedAt());
		$this->assertNull($k->getLastUsedIp());
		$this->assertNull($k->getRevokedAt());
	}

	#[Test]
	#[TestDox('jsonSerialize exposes keyId as id, ISO dates, and derived flags')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$k = $this->make([
			'expiresAt' => 1717003600,
			'lastUsedAt' => 1717001000,
			'lastUsedIp' => '10.0.0.1',
			'revokedAt' => 1717002000,
		]);
		$json = $k->jsonSerialize();

		$this->assertSame(
			[
				'id',
				'name',
				'description',
				'scopes',
				'token_prefix',
				'created_at',
				'expires_at',
				'last_used_at',
				'last_used_ip',
				'revoked',
				'revoked_at',
				'expired',
				'never_expires',
			],
			array_keys($json),
		);
		$this->assertSame('key_abc', $json['id']);
		$this->assertSame(GeneralHelper::dateToIso(1717000000), $json['created_at']);
		$this->assertSame(GeneralHelper::dateToIso(1717003600), $json['expires_at']);
		$this->assertTrue($json['revoked']);
		$this->assertFalse($json['never_expires']);
	}

	#[Test]
	#[TestDox('jsonSerialize nulls date fields and marks never_expires when unset')]
	#[Group('mantle2/custom')]
	public function testJsonSerializeNulls(): void
	{
		$json = $this->make()->jsonSerialize();
		$this->assertNull($json['expires_at']);
		$this->assertNull($json['last_used_at']);
		$this->assertNull($json['last_used_ip']);
		$this->assertNull($json['revoked_at']);
		$this->assertFalse($json['revoked']);
		$this->assertFalse($json['expired']);
		$this->assertTrue($json['never_expires']);
	}
}
