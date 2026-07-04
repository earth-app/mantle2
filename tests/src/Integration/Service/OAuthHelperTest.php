<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\OAuthHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class OAuthHelperTest extends IntegrationTestBase
{
	// swaps in a fake client manager whose createInstance() returns a client
	// that echoes back canned userinfo, so no live oauth provider is contacted
	private function mockClientManager(array $userInfoByProvider): void
	{
		$client = new class ($userInfoByProvider) {
			public function __construct(private array $map, private ?string $provider = null) {}

			public function forProvider(string $provider): self
			{
				$clone = clone $this;
				$clone->provider = $provider;
				return $clone;
			}

			public function retrieveUserInfo(string $token): ?array
			{
				return $this->map[$this->provider] ?? null;
			}
		};

		$manager = new class ($client) {
			public function __construct(private object $client) {}

			public function createInstance($provider, array $configuration = []): object
			{
				return $this->client->forProvider($provider);
			}
		};

		$this->container->set('plugin.manager.openid_connect_client', $manager);
	}

	// validateToken

	#[Test]
	#[TestDox('validateToken normalizes provider userinfo into a stable shape')]
	#[Group('mantle2/oauth')]
	public function validateTokenNormalizes(): void
	{
		$this->mockClientManager([
			'google' => [
				'sub' => 'g-123',
				'email' => 'Person@Example.com',
				'emails' => [['email' => 'alt@example.com'], 'Person@Example.com'],
				'name' => 'Grace Hopper',
				'given_name' => 'Grace',
				'family_name' => 'Hopper',
				'picture' => 'https://img/p.png',
			],
		]);

		$info = OAuthHelper::validateToken('google', 'tok');
		$this->assertSame('g-123', $info['sub']);
		$this->assertSame('Person@Example.com', $info['email']);
		$this->assertSame('Grace Hopper', $info['name']);
		$this->assertSame('Grace', $info['given_name']);
		$this->assertContains('alt@example.com', $info['emails']);
		$this->assertContains('Person@Example.com', $info['emails']);
		// the primary email appears once despite being present in both fields
		$this->assertSame(1, count(array_keys($info['emails'], 'Person@Example.com', true)));
	}

	#[Test]
	#[TestDox('validateToken returns null when the provider yields no userinfo')]
	#[Group('mantle2/oauth')]
	public function validateTokenNoUserInfo(): void
	{
		$this->mockClientManager([]);
		$this->assertNull(OAuthHelper::validateToken('github', 'tok'));
	}

	// findOrCreateUser

	#[Test]
	#[TestDox('findOrCreateUser creates a user on first login then returns the same one after')]
	#[Group('mantle2/oauth')]
	public function findOrCreateUser(): void
	{
		$data = [
			'sub' => 'ms-777',
			'email' => 'newperson@example.com',
			'given_name' => 'New',
			'family_name' => 'Person',
		];

		$created = OAuthHelper::findOrCreateUser('microsoft', $data);
		$this->assertInstanceOf(UserInterface::class, $created);
		$this->assertSame('newperson@example.com', $created->getEmail());
		$this->assertSame('New', $created->get('field_first_name')->value);
		$this->assertTrue((bool) $created->get('field_email_verified')->value);
		$this->assertSame('ms-777', $created->get('field_oauth_microsoft_sub')->value);

		$again = OAuthHelper::findOrCreateUser('microsoft', $data);
		$this->assertSame((int) $created->id(), (int) $again->id());
	}

	#[Test]
	#[TestDox('findByProviderSub resolves a linked user and null for an unknown sub')]
	#[Group('mantle2/oauth')]
	public function findByProviderSub(): void
	{
		$user = $this->createUser();
		$user->set('field_oauth_discord_sub', 'dc-42');
		$user->save();

		$found = OAuthHelper::findByProviderSub('discord', 'dc-42');
		$this->assertNotNull($found);
		$this->assertSame((int) $user->id(), (int) $found->id());

		$this->assertNull(OAuthHelper::findByProviderSub('discord', 'dc-nope'));
	}

	// linkProvider

	#[Test]
	#[TestDox('linkProvider stores the sub and backfills a missing email as pre-verified')]
	#[Group('mantle2/oauth')]
	public function linkProviderAutoSetsEmail(): void
	{
		$user = $this->createUser(['mail' => '']);
		$this->assertSame('', (string) $user->getEmail());

		$autoSet = null;
		$ok = OAuthHelper::linkProvider(
			$user,
			'github',
			'gh-1',
			['email' => 'Filled@Example.com', 'given_name' => 'Fill', 'family_name' => 'Ed'],
			$autoSet,
		);

		$this->assertTrue($ok);
		$this->assertSame('gh-1', $user->get('field_oauth_github_sub')->value);
		$this->assertSame('filled@example.com', $user->getEmail());
		$this->assertTrue((bool) $user->get('field_email_verified')->value);
		$this->assertSame('Filled@Example.com', $autoSet);
		$this->assertSame('Fill', $user->get('field_first_name')->value);
	}

	#[Test]
	#[
		TestDox(
			'linkProvider leaves an existing email untouched and does not set the auto-set out param',
		),
	]
	#[Group('mantle2/oauth')]
	public function linkProviderKeepsExistingEmail(): void
	{
		$user = $this->createUser(['mail' => 'keep@example.com']);

		$autoSet = null;
		$ok = OAuthHelper::linkProvider(
			$user,
			'google',
			'g-keep',
			['email' => 'other@example.com'],
			$autoSet,
		);

		$this->assertTrue($ok);
		$this->assertSame('keep@example.com', $user->getEmail());
		$this->assertNull($autoSet);
	}

	#[Test]
	#[TestDox('linkProvider refuses to relink a sub already owned by another account')]
	#[Group('mantle2/oauth')]
	public function linkProviderRejectsConflict(): void
	{
		$owner = $this->createUser();
		$owner->set('field_oauth_apple_sub', 'apl-shared');
		$owner->save();

		$other = $this->createUser();
		$this->assertFalse(OAuthHelper::linkProvider($other, 'apple', 'apl-shared'));
		$this->assertEmpty($other->get('field_oauth_apple_sub')->value);
	}

	// hasProviderLinked / getLinkedProviders

	#[Test]
	#[TestDox('hasProviderLinked and getLinkedProviders report the linked provider set')]
	#[Group('mantle2/oauth')]
	public function linkedProviders(): void
	{
		$user = $this->createUser();
		$this->assertFalse(OAuthHelper::hasProviderLinked($user, 'google'));
		$this->assertSame([], OAuthHelper::getLinkedProviders($user));

		$user->set('field_oauth_google_sub', 'g-1');
		$user->set('field_oauth_github_sub', 'gh-1');
		$user->save();

		$this->assertTrue(OAuthHelper::hasProviderLinked($user, 'google'));
		$this->assertTrue(OAuthHelper::hasProviderLinked($user, 'github'));
		$this->assertFalse(OAuthHelper::hasProviderLinked($user, 'discord'));

		$linked = OAuthHelper::getLinkedProviders($user);
		$this->assertContains('google', $linked);
		$this->assertContains('github', $linked);
		$this->assertNotContains('discord', $linked);
	}

	// unlinkProvider

	#[Test]
	#[TestDox('unlinkProvider removes a provider when another login method remains')]
	#[Group('mantle2/oauth')]
	public function unlinkProviderSucceedsWithFallback(): void
	{
		$user = $this->createUser();
		$user->set('field_oauth_google_sub', 'g-1');
		$user->set('field_oauth_github_sub', 'gh-1');
		$user->save();

		$this->assertTrue(OAuthHelper::unlinkProvider($user, 'google'));
		$this->assertEmpty($user->get('field_oauth_google_sub')->value);
		$this->assertTrue(OAuthHelper::hasProviderLinked($user, 'github'));
	}

	#[Test]
	#[
		TestDox(
			'unlinkProvider refuses the last login method, an unlinked provider, and unknown providers',
		),
	]
	#[Group('mantle2/oauth')]
	public function unlinkProviderGuards(): void
	{
		$user = $this->createUser();
		$user->setPassword('');
		$user->set('field_oauth_google_sub', 'g-only');
		$user->save();

		// only login method left, no password
		$this->assertFalse(OAuthHelper::unlinkProvider($user, 'google'));
		$this->assertSame('g-only', $user->get('field_oauth_google_sub')->value);

		// provider not linked
		$this->assertFalse(OAuthHelper::unlinkProvider($user, 'discord'));

		// unsupported provider name
		$this->assertFalse(OAuthHelper::unlinkProvider($user, 'myspace'));
	}

	#[Test]
	#[TestDox('the supported providers list is exactly the six expected OAuth providers')]
	#[Group('mantle2/oauth')]
	public function providersList(): void
	{
		$this->assertSame(
			['google', 'microsoft', 'discord', 'github', 'facebook', 'apple'],
			OAuthHelper::$providers,
		);
	}
}
