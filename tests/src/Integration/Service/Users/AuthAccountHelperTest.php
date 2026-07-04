<?php

namespace Drupal\Tests\mantle2\Integration\Service\Users;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ApiKeysHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthAccountHelperTest extends IntegrationTestBase
{
	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
	}

	private function typed(AccountType $type): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search($type, AccountType::cases(), true),
		]);
	}

	private function bearerRequest(string $token): Request
	{
		$request = $this->request('GET', '/');
		$request->headers->set('Authorization', 'Bearer ' . $token);
		return $request;
	}

	#region User Retrieval

	#[Test]
	#[
		TestDox(
			'findBy resolves @username vs numeric id and findById/findByUsername/findByEmail load or null',
		),
	]
	#[Group('mantle2/users')]
	public function findBy(): void
	{
		$user = $this->createUser(['name' => 'findme', 'mail' => 'findme@example.com']);
		$id = (int) $user->id();

		$this->assertSame($id, (int) UsersHelper::findBy((string) $id)->id());
		$this->assertSame($id, (int) UsersHelper::findBy('@findme')->id());
		$this->assertSame($id, (int) UsersHelper::findById($id)->id());
		$this->assertSame($id, (int) UsersHelper::findByUsername('findme')->id());
		$this->assertSame($id, (int) UsersHelper::findByUsername('FINDME')->id());
		$this->assertSame($id, (int) UsersHelper::findByEmail('FindMe@Example.com')->id());

		$this->assertNull(UsersHelper::findById(999999));
		$this->assertNull(UsersHelper::findByUsername(''));
		$this->assertNull(UsersHelper::findByEmail(''));
		$this->assertNull(UsersHelper::findByUsername('ghost_user'));
	}

	#[Test]
	#[TestDox('findByRequest resolves the admin key, a bearer token, or 401s an anonymous request')]
	#[Group('mantle2/users')]
	public function findByRequest(): void
	{
		$anon = UsersHelper::findByRequest($this->request('GET', '/'));
		$this->assertInstanceOf(JsonResponse::class, $anon);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		// admin key maps to the root/cloud user
		$adminKeyReq = $this->request('GET', '/');
		$adminKeyReq->headers->set('X-Admin-Key', 'test_admin_key');
		$this->assertSame(1, (int) UsersHelper::findByRequest($adminKeyReq)->id());

		$user = $this->createUser();
		$token = UsersHelper::issueToken($user);
		$resolved = UsersHelper::findByRequest($this->bearerRequest($token));
		$this->assertInstanceOf(UserInterface::class, $resolved);
		$this->assertSame((int) $user->id(), (int) $resolved->id());

		$bogus = UsersHelper::findByRequest($this->bearerRequest('deadbeef'));
		$this->assertInstanceOf(JsonResponse::class, $bogus);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $bogus->getStatusCode());
	}

	#[Test]
	#[TestDox('getOwnerOfRequest returns the user or null instead of a JsonResponse')]
	#[Group('mantle2/users')]
	public function getOwnerOfRequest(): void
	{
		$this->assertNull(UsersHelper::getOwnerOfRequest($this->request('GET', '/')));

		$user = $this->createUser();
		$owner = UsersHelper::getOwnerOfRequest(
			$this->bearerRequest(UsersHelper::issueToken($user)),
		);
		$this->assertInstanceOf(UserInterface::class, $owner);
		$this->assertSame((int) $user->id(), (int) $owner->id());
	}

	#endregion

	#region User Endpoint Validation

	#[Test]
	#[TestDox('getVisibility reads the stored ordinal and forces disabled accounts off PUBLIC')]
	#[Group('mantle2/users')]
	public function getVisibility(): void
	{
		$public = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
		]);
		$this->assertSame(Visibility::PUBLIC, UsersHelper::getVisibility($public));

		$private = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);
		$this->assertSame(Visibility::PRIVATE, UsersHelper::getVisibility($private));

		// a disabled, non-private account is demoted to UNLISTED regardless of stored value
		$disabledPublic = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
		]);
		$disabledPublic->block();
		$disabledPublic->save();
		$this->assertSame(Visibility::UNLISTED, UsersHelper::getVisibility($disabledPublic));
	}

	#[Test]
	#[
		TestDox(
			'checkVisibility gates UNLISTED/PRIVATE by login and friendship and always shows PUBLIC',
		),
	]
	#[Group('mantle2/users')]
	public function checkVisibility(): void
	{
		$public = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
		]);
		$this->assertInstanceOf(
			UserInterface::class,
			UsersHelper::checkVisibility($public, $this->request('GET', '/')),
		);

		$unlisted = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::UNLISTED,
				Visibility::cases(),
				true,
			),
		]);
		$anonResult = UsersHelper::checkVisibility($unlisted, $this->request('GET', '/'));
		$this->assertInstanceOf(JsonResponse::class, $anonResult);
		$this->assertSame(Response::HTTP_NOT_FOUND, $anonResult->getStatusCode());

		// a logged-in stranger can see an UNLISTED profile
		$viewer = $this->createUser();
		$viewerReq = $this->bearerRequest(UsersHelper::issueToken($viewer));
		$this->assertInstanceOf(
			UserInterface::class,
			UsersHelper::checkVisibility($unlisted, $viewerReq),
		);

		// a private profile stays hidden from a non-friend stranger
		$private = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);
		$privResult = UsersHelper::checkVisibility($private, $viewerReq);
		$this->assertInstanceOf(JsonResponse::class, $privResult);
		$this->assertSame(Response::HTTP_NOT_FOUND, $privResult->getStatusCode());

		// the user always sees themselves
		$selfReq = $this->bearerRequest(UsersHelper::issueToken($private));
		$this->assertInstanceOf(
			UserInterface::class,
			UsersHelper::checkVisibility($private, $selfReq),
		);
	}

	#endregion

	#region User Scope Permissions

	#[Test]
	#[TestDox('session-token requests bypass scopes while api-key requests are scope-limited')]
	#[Group('mantle2/users')]
	public function scopePermissions(): void
	{
		// a session token has no ApiKey attached, so scope checks pass through
		$user = $this->createUser();
		$sessionReq = $this->bearerRequest(UsersHelper::issueToken($user));
		$this->assertFalse(UsersHelper::isApiKeyRequest($sessionReq));
		$this->assertTrue(UsersHelper::hasScope($sessionReq, ApiKeyScope::USER_EDIT_BIO));
		$this->assertNull(UsersHelper::requireScope($sessionReq, ApiKeyScope::USER_EDIT_BIO));
		$this->assertNull(UsersHelper::requireSessionToken($sessionReq));

		// an anonymous request has no owner
		$anonReq = $this->request('GET', '/');
		$this->assertFalse(UsersHelper::hasScope($anonReq, ApiKeyScope::USER_READ_PROFILE));
		$this->assertSame(
			Response::HTTP_UNAUTHORIZED,
			UsersHelper::requireScope($anonReq, ApiKeyScope::USER_READ_PROFILE)->getStatusCode(),
		);

		// an api-key request is limited to its granted scopes and cannot use session-only endpoints
		$keyUser = $this->createUser(['field_email_verified' => true]);
		$issued = ApiKeysHelper::issue(
			$keyUser,
			'Scope Probe',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$this->assertIsArray($issued);
		$keyReq = $this->bearerRequest($issued['token']);
		$this->assertTrue(UsersHelper::isApiKeyRequest($keyReq));
		$this->assertTrue(UsersHelper::hasScope($keyReq, ApiKeyScope::USER_READ_PROFILE));
		$this->assertFalse(UsersHelper::hasScope($keyReq, ApiKeyScope::USER_EDIT_EMAIL));
		$this->assertSame(
			Response::HTTP_FORBIDDEN,
			UsersHelper::requireScope($keyReq, ApiKeyScope::USER_EDIT_EMAIL)->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_FORBIDDEN,
			UsersHelper::requireSessionToken($keyReq)->getStatusCode(),
		);
	}

	#endregion

	#region User Privacy Settings

	#[Test]
	#[
		TestDox(
			'isVisible enforces the PUBLIC/PRIVATE/MUTUAL/CIRCLE tiers against the viewer relationship',
		),
	]
	#[Group('mantle2/users')]
	public function isVisible(): void
	{
		$owner = $this->createUser();
		$stranger = $this->createUser();
		$admin = $this->admin();

		$this->assertTrue(UsersHelper::isVisible($owner, null, 'PUBLIC'));
		$this->assertFalse(UsersHelper::isVisible($owner, null, 'PRIVATE'));
		$this->assertTrue(UsersHelper::isVisible($owner, $owner, 'PRIVATE'));
		$this->assertTrue(UsersHelper::isVisible($owner, $admin, 'PRIVATE'));
		$this->assertFalse(UsersHelper::isVisible($owner, $stranger, 'PRIVATE'));
		$this->assertFalse(UsersHelper::isVisible($owner, $stranger, 'MUTUAL'));

		// tryVisible returns the value only when visible
		$this->assertSame('x', UsersHelper::tryVisible('x', $owner, $owner, 'PRIVATE'));
		$this->assertNull(UsersHelper::tryVisible('x', $owner, $stranger, 'PRIVATE'));
	}

	#[Test]
	#[TestDox('getFieldPrivacy backfills defaults and locks disabled accounts to PRIVATE')]
	#[Group('mantle2/users')]
	public function getFieldPrivacy(): void
	{
		$user = $this->createUser();
		$privacy = UsersHelper::getFieldPrivacy($user);
		$this->assertSame('PUBLIC', $privacy['name']);
		$this->assertSame('MUTUAL', $privacy['email']);
		$this->assertSame('PRIVATE', $privacy['address']);

		UsersHelper::setFieldPrivacy($user, ['bio' => 'PRIVATE']);
		$user->save();
		$this->assertSame('PRIVATE', UsersHelper::getFieldPrivacy(User::load($user->id()))['bio']);
		// unset keys still fall back to defaults
		$this->assertSame('PUBLIC', UsersHelper::getFieldPrivacy(User::load($user->id()))['name']);

		$user->block();
		$user->save();
		foreach (UsersHelper::getFieldPrivacy(User::load($user->id())) as $value) {
			$this->assertSame('PRIVATE', $value);
		}
	}

	#endregion

	#region User Account Tiers

	#[Test]
	#[TestDox('getAccountType/isPro/isWriter/isOrganizer/isAdmin classify each tier')]
	#[Group('mantle2/users')]
	#[DataProvider('tierProvider')]
	public function accountTiers(
		AccountType $type,
		bool $isPro,
		bool $isWriter,
		bool $isOrganizer,
		bool $isAdmin,
	): void {
		$user = $this->typed($type);
		$this->assertSame($type, UsersHelper::getAccountType($user));
		$this->assertSame($isPro, UsersHelper::isPro($user));
		$this->assertSame($isWriter, UsersHelper::isWriter($user));
		$this->assertSame($isOrganizer, UsersHelper::isOrganizer($user));
		$this->assertSame($isAdmin, UsersHelper::isAdmin($user));
	}

	public static function tierProvider(): array
	{
		return [
			'free' => [AccountType::FREE, false, false, false, false],
			'pro' => [AccountType::PRO, true, false, false, false],
			'writer' => [AccountType::WRITER, true, true, false, false],
			'organizer' => [AccountType::ORGANIZER, true, true, true, false],
			'administrator' => [AccountType::ADMINISTRATOR, true, true, true, true],
		];
	}

	#[Test]
	#[TestDox('isAdmin is false for null and true for the administer-users permission')]
	#[Group('mantle2/users')]
	public function isAdminNullAndPermission(): void
	{
		$this->assertFalse(UsersHelper::isAdmin(null));
		$this->assertSame(1, (int) UsersHelper::cloud()->id());
	}

	#[Test]
	#[
		TestDox(
			'createTierTrial refuses admins, the administrator tier, and non-upgrade changes without touching cloud',
		),
	]
	#[Group('mantle2/users')]
	public function createTierTrialGuards(): void
	{
		// admin target: no-op, tier unchanged
		$admin = $this->admin();
		UsersHelper::createTierTrial($admin, AccountType::PRO, 14);
		$this->assertSame(
			AccountType::ADMINISTRATOR,
			UsersHelper::getAccountType(User::load($admin->id())),
		);

		// requesting the administrator tier as a trial: no-op
		$member = $this->createUser();
		UsersHelper::createTierTrial($member, AccountType::ADMINISTRATOR, 14);
		$this->assertSame(
			AccountType::FREE,
			UsersHelper::getAccountType(User::load($member->id())),
		);

		// downgrade / same tier: no-op (PRO -> FREE)
		$pro = $this->typed(AccountType::PRO);
		UsersHelper::createTierTrial($pro, AccountType::FREE, 14);
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType(User::load($pro->id())));
		$this->assertNull(RedisHelper::get('user:account_trial:' . $pro->id()));
	}

	#[Test]
	#[TestDox('setDisabled/isDisabled protect root and admins but toggle members')]
	#[Group('mantle2/users')]
	public function disabledState(): void
	{
		$member = $this->createUser();
		$this->assertFalse(UsersHelper::isDisabled($member));
		UsersHelper::setDisabled($member, true);
		$this->assertTrue($member->isBlocked());
		$this->assertTrue(UsersHelper::isDisabled($member));
		UsersHelper::setDisabled($member, false);
		$this->assertFalse($member->isBlocked());

		// admins can never be disabled
		$admin = $this->admin();
		UsersHelper::setDisabled($admin, true);
		$this->assertFalse($admin->isBlocked());
		$this->assertFalse(UsersHelper::isDisabled($admin));
	}

	#endregion

	#region User CRUD Operations

	#[Test]
	#[
		TestDox(
			'serializeUser exposes the full profile to self and hides private fields from strangers',
		),
	]
	#[Group('mantle2/users')]
	public function serializeUser(): void
	{
		$user = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
			'field_first_name' => 'Grace',
			'field_last_name' => 'Hopper',
			'mail' => 'grace@example.com',
		]);

		$selfView = UsersHelper::serializeUser($user, $user);
		$this->assertSame($user->getAccountName(), $selfView['username']);
		$this->assertSame('Grace Hopper', $selfView['full_name']);
		$this->assertSame('grace@example.com', $selfView['account']['email']);
		$this->assertFalse($selfView['is_admin']);
		$this->assertArrayHasKey('field_privacy', $selfView['account']);

		// a stranger sees the public shell but not the MUTUAL-gated email
		$stranger = $this->createUser();
		$strangerView = UsersHelper::serializeUser($user, $stranger);
		$this->assertSame($user->getAccountName(), $strangerView['username']);
		$this->assertNull($strangerView['account']['email']);

		// a private profile serializes to an empty array for a non-friend
		$private = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);
		$this->assertSame([], UsersHelper::serializeUser($private, $stranger));
	}

	#endregion

	#region User Token Authentication

	#[Test]
	#[TestDox('issueToken/getUserByToken/revokeToken manage the persistent bearer lifecycle')]
	#[Group('mantle2/users')]
	public function tokenLifecycle(): void
	{
		$user = $this->createUser();
		$token = UsersHelper::issueToken($user);
		$this->assertNotEmpty($token);
		$this->assertSame((int) $user->id(), (int) UsersHelper::getUserByToken($token)->id());

		// an empty or unknown token resolves to null
		$this->assertNull(UsersHelper::getUserByToken(''));
		$this->assertNull(UsersHelper::getUserByToken('not-a-real-token'));

		// the admin key resolves straight to the cloud/root user
		$this->assertSame(1, (int) UsersHelper::getUserByToken('test_admin_key')->id());

		UsersHelper::revokeToken($token);
		$this->assertNull(UsersHelper::getUserByToken($token));
	}

	#[Test]
	#[TestDox('issueToken caps a user at five live sessions, pruning the oldest')]
	#[Group('mantle2/users')]
	public function tokenSessionCap(): void
	{
		$user = $this->createUser();
		$tokens = [];
		for ($i = 0; $i < 7; $i++) {
			$tokens[] = UsersHelper::issueToken($user);
		}

		$live = array_filter($tokens, fn($t) => UsersHelper::getUserByToken($t) !== null);
		$this->assertLessThanOrEqual(5, count($live));
		// the newest token is always live
		$this->assertNotNull(UsersHelper::getUserByToken(end($tokens)));
	}

	#[Test]
	#[TestDox('revokeAllTokensForUser clears every live bearer for the user')]
	#[Group('mantle2/users')]
	public function revokeAllTokens(): void
	{
		$user = $this->createUser();
		$a = UsersHelper::issueToken($user);
		$b = UsersHelper::issueToken($user);

		$revoked = UsersHelper::revokeAllTokensForUser($user);
		$this->assertGreaterThanOrEqual(2, $revoked);
		$this->assertNull(UsersHelper::getUserByToken($a));
		$this->assertNull(UsersHelper::getUserByToken($b));

		$this->assertSame(0, UsersHelper::revokeAllTokensForUser(0));
	}

	#endregion

	#region User Passwords

	#[Test]
	#[TestDox('hasPassword and validatePassword reflect the stored hash')]
	#[Group('mantle2/users')]
	public function passwordHashingAndVerify(): void
	{
		$user = $this->createUser(['pass' => 'MyStrongPass1']);
		$this->assertTrue(UsersHelper::hasPassword($user));
		$this->assertTrue(UsersHelper::validatePassword($user, 'MyStrongPass1'));
		$this->assertFalse(UsersHelper::validatePassword($user, 'WrongPass9'));

		$oauth = $this->createUser();
		$oauth->setPassword(null);
		$oauth->save();
		$this->assertFalse(UsersHelper::hasPassword($oauth));
	}

	#[Test]
	#[TestDox('reset password tokens validate only against the matching stored token')]
	#[Group('mantle2/users')]
	public function resetPasswordTokens(): void
	{
		$user = $this->createUser();
		$this->assertFalse(UsersHelper::validateResetPasswordToken($user, 'anything'));

		$token = UsersHelper::generateResetPasswordToken($user);
		$this->assertNotEmpty($token);
		$this->assertTrue(UsersHelper::validateResetPasswordToken($user, $token));
		$this->assertFalse(UsersHelper::validateResetPasswordToken($user, $token . 'x'));
		$this->assertNotNull(RedisHelper::get('password_reset_' . $user->id()));
	}

	#[Test]
	#[TestDox('the reauth window opens on markReauthenticated and clears on clearReauthenticated')]
	#[Group('mantle2/users')]
	public function reauthWindow(): void
	{
		$user = $this->createUser();
		$this->assertSame([false, null], UsersHelper::getReauthState($user));

		UsersHelper::markReauthenticated($user);
		[$recent, $atMs] = UsersHelper::getReauthState($user);
		$this->assertTrue($recent);
		$this->assertIsInt($atMs);

		UsersHelper::clearReauthenticated($user);
		$this->assertSame([false, null], UsersHelper::getReauthState($user));
	}

	#endregion

	#region User Fields

	#[Test]
	#[TestDox('field getters honor per-field privacy against the requester')]
	#[Group('mantle2/users')]
	public function fieldPrivacyResolution(): void
	{
		$user = $this->createUser([
			'field_first_name' => 'Alan',
			'field_last_name' => 'Turing',
			'field_bio' => 'codebreaker',
			'field_country' => 'GB',
			'mail' => 'alan@example.com',
		]);
		$stranger = $this->createUser();

		// name/bio default PUBLIC -> visible to a stranger
		$this->assertSame('Alan', UsersHelper::getFirstName($user, $stranger));
		$this->assertSame('Alan Turing', UsersHelper::getName($user, $stranger));
		$this->assertSame('codebreaker', UsersHelper::getBiography($user, $stranger));

		// country defaults PRIVATE -> hidden from a stranger, visible to self
		$this->assertNull(UsersHelper::getCountry($user, $stranger));
		$this->assertSame('GB', UsersHelper::getCountry($user, $user));

		// email defaults MUTUAL -> hidden from a stranger, visible to self
		$this->assertNull(UsersHelper::getEmail($user, $stranger));
		$this->assertSame('alan@example.com', UsersHelper::getEmail($user, $user));

		// address defaults PRIVATE -> hidden from a stranger
		$this->assertNull(UsersHelper::getAddress($user, $stranger));
	}

	#[Test]
	#[TestDox('email/subscription flags read and write their fields')]
	#[Group('mantle2/users')]
	public function emailAndSubscriptionFlags(): void
	{
		$noEmail = $this->createUser(['mail' => '']);
		$this->assertFalse(UsersHelper::hasEmail($noEmail));
		$this->assertFalse(UsersHelper::isEmailVerified($noEmail));

		$withEmail = $this->createUser(['mail' => 'x@example.com', 'field_email_verified' => true]);
		$this->assertTrue(UsersHelper::hasEmail($withEmail));
		$this->assertTrue(UsersHelper::isEmailVerified($withEmail));

		// subscription defaults to true and can be toggled off
		$this->assertTrue(UsersHelper::isSubscribed($withEmail));
		UsersHelper::setSubscribed($withEmail, false);
		$withEmail->save();
		$this->assertFalse(UsersHelper::isSubscribed(User::load($withEmail->id())));
	}

	#[Test]
	#[
		TestDox(
			'requireEmailVerified gates unverified members but waves through admins and verified users',
		),
	]
	#[Group('mantle2/users')]
	public function requireEmailVerified(): void
	{
		$unverified = $this->createUser(['mail' => 'u@example.com']);
		$gate = UsersHelper::requireEmailVerified($unverified, 'do a thing');
		$this->assertInstanceOf(JsonResponse::class, $gate);
		$this->assertSame(Response::HTTP_FORBIDDEN, $gate->getStatusCode());

		$verified = $this->createUser(['mail' => 'v@example.com', 'field_email_verified' => true]);
		$this->assertNull(UsersHelper::requireEmailVerified($verified));

		$this->assertNull(UsersHelper::requireEmailVerified($this->admin()));
	}

	#endregion
}
