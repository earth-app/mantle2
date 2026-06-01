<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\Custom\ApiKey;
use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\mantle2\Service\ApiKeysHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ApiKeysUnitTest extends TestCase
{
	#[Test]
	#[TestDox('Token format is EA + YY + 32 hex + U + 24 hex + G + 16 hex')]
	#[Group('mantle2/api_keys')]
	public function testTokenLayout(): void
	{
		// 2026-06-01 (matches frozen test date)
		$ts = 1748736000000;
		$random = str_repeat('a', ApiKey::RANDOM_HEX_LEN);
		$token = ApiKeysHelper::buildToken(7, $random, $ts);

		$this->assertSame(ApiKey::TOTAL_LENGTH, strlen($token));
		$this->assertStringStartsWith(ApiKey::TOKEN_PREFIX, $token);

		$expectedYY = (int) gmdate('y', (int) ($ts / 1000)) % 100;
		$this->assertSame(
			str_pad((string) $expectedYY, 2, '0', STR_PAD_LEFT),
			substr($token, 2, 2),
		);
		$this->assertSame($random, substr($token, 4, ApiKey::RANDOM_HEX_LEN));
		$this->assertSame('U', $token[4 + ApiKey::RANDOM_HEX_LEN]);
		$this->assertSame('G', $token[4 + ApiKey::RANDOM_HEX_LEN + 1 + ApiKey::USER_HEX_LEN]);
	}

	#[Test]
	#[TestDox('parseToken accepts a well-formed token and extracts user/timestamp')]
	#[Group('mantle2/api_keys')]
	public function testParseValid(): void
	{
		$ts = 1748736000000;
		$random = bin2hex(random_bytes(ApiKey::RANDOM_HEX_LEN / 2));
		$token = ApiKeysHelper::buildToken(42, $random, $ts);

		$parsed = ApiKeysHelper::parseToken($token);
		$this->assertNotNull($parsed);
		$this->assertSame(42, $parsed['user_id']);
		$this->assertSame($ts, $parsed['timestamp_ms']);
		$this->assertSame($random, $parsed['random']);
	}

	#[Test]
	#[TestDox('parseToken rejects wrong prefix, wrong length, and bad year embedding')]
	#[Group('mantle2/api_keys')]
	public function testParseInvalid(): void
	{
		$this->assertNull(ApiKeysHelper::parseToken(''));
		$this->assertNull(
			ApiKeysHelper::parseToken('ZZ' . str_repeat('a', ApiKey::TOTAL_LENGTH - 2)),
		);

		$valid = ApiKeysHelper::buildToken(
			1,
			str_repeat('b', ApiKey::RANDOM_HEX_LEN),
			1748736000000,
		);
		// Mutate the YY so it no longer matches the embedded timestamp.
		$broken = substr_replace($valid, '99', 2, 2);
		$this->assertNull(ApiKeysHelper::parseToken($broken));

		// Strip a character.
		$short = substr($valid, 0, -1);
		$this->assertNull(ApiKeysHelper::parseToken($short));
	}

	#[Test]
	#[TestDox('looksLikeApiKey requires both the EA prefix and the canonical length')]
	#[Group('mantle2/api_keys')]
	public function testLooksLikeApiKey(): void
	{
		$token = ApiKeysHelper::buildToken(
			1,
			str_repeat('c', ApiKey::RANDOM_HEX_LEN),
			1748736000000,
		);
		$this->assertTrue(ApiKeysHelper::looksLikeApiKey($token));

		// Length matches but prefix is wrong.
		$wrongPrefix = 'XX' . substr($token, 2);
		$this->assertFalse(ApiKeysHelper::looksLikeApiKey($wrongPrefix));

		// Prefix matches but length is wrong (session token shape).
		$sessionish = 'EA' . bin2hex(random_bytes(31));
		$this->assertFalse(ApiKeysHelper::looksLikeApiKey($sessionish));
	}

	#[Test]
	#[TestDox('hashToken is deterministic and SHA-256 length')]
	#[Group('mantle2/api_keys')]
	public function testHashToken(): void
	{
		$token = ApiKeysHelper::buildToken(
			1,
			str_repeat('d', ApiKey::RANDOM_HEX_LEN),
			1748736000000,
		);
		$h1 = ApiKeysHelper::hashToken($token);
		$h2 = ApiKeysHelper::hashToken($token);
		$this->assertSame($h1, $h2);
		$this->assertSame(64, strlen($h1));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h1);
	}

	#[Test]
	#[TestDox('Session-only routes refuse API keys outright')]
	#[Group('mantle2/api_keys')]
	public function testSessionOnly(): void
	{
		$this->assertTrue(ApiKeysHelper::isSessionOnly('mantle2.users.login'));
		$this->assertTrue(ApiKeysHelper::isSessionOnly('mantle2.users.id.change_password'));
		$this->assertTrue(ApiKeysHelper::isSessionOnly('mantle2.users.current.delete'));
		$this->assertTrue(ApiKeysHelper::isSessionOnly('mantle2.api_keys.create'));
		$this->assertTrue(ApiKeysHelper::isSessionOnly('mantle2.api_keys.delete'));
		$this->assertFalse(ApiKeysHelper::isSessionOnly('mantle2.users'));
		$this->assertFalse(ApiKeysHelper::isSessionOnly('mantle2.users.id.get'));
	}

	#[Test]
	#[TestDox('Route -> scope map returns null for unknown routes (safe-by-default)')]
	#[Group('mantle2/api_keys')]
	public function testRouteScopeMap(): void
	{
		$this->assertNotNull(ApiKeysHelper::scopeFor('mantle2.users.id.get'));
		$this->assertNotNull(ApiKeysHelper::scopeFor('mantle2.events.create'));
		// Catalog endpoint — explicitly empty string (allow any key).
		$this->assertSame('', ApiKeysHelper::scopeFor('mantle2.api_keys.scopes'));
		// Unknown route -> null (not reachable via API key without explicit opt-in).
		$this->assertNull(ApiKeysHelper::scopeFor('mantle2.nonexistent.route'));
	}

	#[Test]
	#[TestDox('hierarchy() includes every domain we expect')]
	#[Group('mantle2/api_keys')]
	public function testHierarchyDomains(): void
	{
		$hier = ApiKeyScope::hierarchy();
		$this->assertArrayHasKey(ApiKeyScope::USER_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::USER_EDIT, $hier);
		$this->assertArrayHasKey(ApiKeyScope::USERS_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::FRIENDS_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::CIRCLE_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::ACTIVITIES_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::EVENTS_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::PROMPTS_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::ARTICLES_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::QUESTS_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::BADGES_READ, $hier);
		$this->assertArrayHasKey(ApiKeyScope::NOTIFICATIONS_READ, $hier);
	}

	#[Test]
	#[TestDox('all() returns parents AND leaves, no duplicates')]
	#[Group('mantle2/api_keys')]
	public function testAllScopes(): void
	{
		$all = ApiKeyScope::all();
		$this->assertSame(count($all), count(array_unique($all)));
		$this->assertContains(ApiKeyScope::USER_EDIT, $all);
		$this->assertContains(ApiKeyScope::USER_EDIT_EMAIL, $all);
		$this->assertContains(ApiKeyScope::EVENTS_WRITE_RSVP, $all);
	}

	#[Test]
	#[TestDox('leaves() returns only nodes with no children')]
	#[Group('mantle2/api_keys')]
	public function testLeaves(): void
	{
		$leaves = ApiKeyScope::leaves();
		$this->assertContains(ApiKeyScope::USER_EDIT_EMAIL, $leaves);
		$this->assertContains(ApiKeyScope::EVENTS_WRITE_RSVP, $leaves);
		// USER_EDIT and EVENTS_WRITE are parents -> not leaves
		$this->assertNotContains(ApiKeyScope::USER_EDIT, $leaves);
		$this->assertNotContains(ApiKeyScope::EVENTS_WRITE, $leaves);
	}

	#[Test]
	#[TestDox('satisfies() honors implicit parent grants')]
	#[Group('mantle2/api_keys')]
	public function testParentSatisfiesChild(): void
	{
		$granted = [ApiKeyScope::USER_EDIT];
		$this->assertTrue(ApiKeyScope::satisfies($granted, ApiKeyScope::USER_EDIT_EMAIL));
		$this->assertTrue(ApiKeyScope::satisfies($granted, ApiKeyScope::USER_EDIT_BIO));
		$this->assertTrue(ApiKeyScope::satisfies($granted, ApiKeyScope::USER_EDIT));

		$this->assertFalse(ApiKeyScope::satisfies($granted, ApiKeyScope::USER_READ));
		$this->assertFalse(ApiKeyScope::satisfies($granted, ApiKeyScope::EVENTS_WRITE_CREATE));
	}

	#[Test]
	#[TestDox('satisfies() honors exact leaf grants without parent')]
	#[Group('mantle2/api_keys')]
	public function testLeafSatisfiesLeaf(): void
	{
		$granted = [ApiKeyScope::USER_EDIT_EMAIL];
		$this->assertTrue(ApiKeyScope::satisfies($granted, ApiKeyScope::USER_EDIT_EMAIL));
		// Sibling — not granted.
		$this->assertFalse(ApiKeyScope::satisfies($granted, ApiKeyScope::USER_EDIT_BIO));
		// Parent — not implicitly granted from a single child.
		$this->assertFalse(ApiKeyScope::satisfies($granted, ApiKeyScope::USER_EDIT));
	}

	#[Test]
	#[TestDox('expand() expands a parent into every leaf beneath it')]
	#[Group('mantle2/api_keys')]
	public function testExpandParent(): void
	{
		$expanded = ApiKeyScope::expand([ApiKeyScope::USER_EDIT]);
		$this->assertContains(ApiKeyScope::USER_EDIT_EMAIL, $expanded);
		$this->assertContains(ApiKeyScope::USER_EDIT_BIO, $expanded);
		$this->assertContains(ApiKeyScope::USER_EDIT_NAME, $expanded);
		$this->assertNotContains(ApiKeyScope::USER_READ_PROFILE, $expanded);
	}

	#[Test]
	#[TestDox('isValid() rejects unknown scopes and accepts known ones')]
	#[Group('mantle2/api_keys')]
	public function testIsValid(): void
	{
		$this->assertTrue(ApiKeyScope::isValid(ApiKeyScope::USER_EDIT));
		$this->assertTrue(ApiKeyScope::isValid(ApiKeyScope::EVENTS_WRITE_RSVP));
		$this->assertFalse(ApiKeyScope::isValid('user:edit:everything'));
		$this->assertFalse(ApiKeyScope::isValid('fake:scope'));
		$this->assertFalse(ApiKeyScope::isValid(''));
	}
}
