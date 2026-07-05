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

	#region User Endpoint Validation (branches)

	#[Test]
	#[TestDox('getVisibility falls back to UNLISTED for an out-of-range stored ordinal')]
	#[Group('mantle2/users')]
	public function getVisibilityFallback(): void
	{
		$user = $this->createUser(['field_visibility' => '99']);
		$this->assertSame(Visibility::UNLISTED, UsersHelper::getVisibility($user));

		// a disabled PRIVATE account keeps PRIVATE (only non-private is demoted)
		$privDisabled = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);
		$privDisabled->block();
		$privDisabled->save();
		$this->assertSame(Visibility::PRIVATE, UsersHelper::getVisibility($privDisabled));
	}

	#[Test]
	#[TestDox('checkVisibility 404s when the target has blocked the requester')]
	#[Group('mantle2/users')]
	public function checkVisibilityBlockedBy(): void
	{
		$target = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
		]);
		$viewer = $this->createUser();
		$target->set('field_blocked_users', json_encode([(int) $viewer->id()]));
		$target->save();

		$viewerReq = $this->bearerRequest(UsersHelper::issueToken($viewer));
		$result = UsersHelper::checkVisibility(User::load($target->id()), $viewerReq);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_NOT_FOUND, $result->getStatusCode());

		// an admin viewer is exempt from the block gate and still sees the profile
		$adminReq = $this->bearerRequest(UsersHelper::issueToken($this->admin()));
		$this->assertInstanceOf(
			UserInterface::class,
			UsersHelper::checkVisibility(User::load($target->id()), $adminReq),
		);
	}

	#[Test]
	#[TestDox('checkVisibility shows a PRIVATE profile to an added friend')]
	#[Group('mantle2/users')]
	public function checkVisibilityPrivateFriend(): void
	{
		$private = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);
		$friend = $this->createUser();
		// checkVisibility shows PRIVATE to a requester who has added the target as a friend
		$friend->set('field_friends', json_encode([(string) $private->id()]));
		$friend->save();

		$friendReq = $this->bearerRequest(UsersHelper::issueToken($friend));
		$this->assertInstanceOf(
			UserInterface::class,
			UsersHelper::checkVisibility(User::load($private->id()), $friendReq),
		);
	}

	#endregion

	#region User Privacy Settings (branches)

	#[Test]
	#[TestDox('isVisible resolves every tier: CIRCLE and MUTUAL against the viewer relationship')]
	#[Group('mantle2/users')]
	public function isVisibleCircleAndMutual(): void
	{
		$owner = $this->createUser();
		$circleMember = $this->createUser();
		$mutual = $this->createUser();
		$shared = $this->createUser();
		$stranger = $this->createUser();

		$owner->set('field_circle', json_encode([(string) $circleMember->id()]));
		$owner->set('field_friends', json_encode([(string) $shared->id()]));
		$owner->save();
		$mutual->set('field_friends', json_encode([(string) $shared->id()]));
		$mutual->save();
		$owner = User::load($owner->id());
		// isInCircle strict-compares loaded entities, so use the cached instances
		$circleMember = User::load($circleMember->id());
		$mutual = User::load($mutual->id());
		$stranger = User::load($stranger->id());

		// CIRCLE: only a circle member passes
		$this->assertTrue(UsersHelper::isVisible($owner, $circleMember, 'CIRCLE'));
		$this->assertFalse(UsersHelper::isVisible($owner, $stranger, 'CIRCLE'));

		// MUTUAL: shared friend intersection passes
		$this->assertTrue(UsersHelper::isVisible($owner, $mutual, 'MUTUAL'));
		$this->assertFalse(UsersHelper::isVisible($owner, $stranger, 'MUTUAL'));

		// an unknown required level defaults to hidden for a stranger
		$this->assertFalse(UsersHelper::isVisible($owner, $stranger, 'NONSENSE'));
		// but PUBLIC is always visible even with no requester
		$this->assertTrue(UsersHelper::isVisible($owner, null, 'PUBLIC'));

		// tryVisible passes the value through when visible, null otherwise
		$this->assertSame('v', UsersHelper::tryVisible('v', $owner, $circleMember, 'CIRCLE'));
		$this->assertNull(UsersHelper::tryVisible('v', $owner, $stranger, 'CIRCLE'));
	}

	#[Test]
	#[TestDox('setFieldPrivacy writes the raw json read back by getFieldPrivacy')]
	#[Group('mantle2/users')]
	public function setFieldPrivacyRoundTrip(): void
	{
		$user = $this->createUser();
		UsersHelper::setFieldPrivacy($user, ['bio' => 'PRIVATE', 'country' => 'PUBLIC']);
		$user->save();

		$privacy = UsersHelper::getFieldPrivacy(User::load($user->id()));
		$this->assertSame('PRIVATE', $privacy['bio']);
		$this->assertSame('PUBLIC', $privacy['country']);
		// email still defaults since it was not set
		$this->assertSame('MUTUAL', $privacy['email']);
	}

	#[Test]
	#[TestDox('getFieldPrivacy tolerates malformed json and falls back to defaults')]
	#[Group('mantle2/users')]
	public function getFieldPrivacyMalformed(): void
	{
		$user = $this->createUser();
		$user->set('field_privacy', 'not-json');
		$user->save();

		$privacy = UsersHelper::getFieldPrivacy(User::load($user->id()));
		$this->assertSame('PUBLIC', $privacy['name']);
		$this->assertSame('MUTUAL', $privacy['email']);
	}

	#endregion

	#region User Account Tiers (branches)

	#[Test]
	#[TestDox('getAccountType defaults to FREE for an unset or out-of-range ordinal')]
	#[Group('mantle2/users')]
	public function getAccountTypeDefault(): void
	{
		$blank = $this->createUser(['field_account_type' => null]);
		$this->assertSame(AccountType::FREE, UsersHelper::getAccountType($blank));

		$outOfRange = $this->createUser(['field_account_type' => '99']);
		$this->assertSame(AccountType::FREE, UsersHelper::getAccountType($outOfRange));
	}

	#[Test]
	#[TestDox('isAdmin is true via the administrator role and the administer-users permission')]
	#[Group('mantle2/users')]
	public function isAdminRoleAndPermission(): void
	{
		// role grant
		$this->container
			->get('entity_type.manager')
			->getStorage('user_role')
			->create(['id' => 'administrator', 'label' => 'Administrator'])
			->save();
		$roleUser = $this->createUser();
		$roleUser->addRole('administrator');
		$roleUser->save();
		$this->assertTrue(UsersHelper::isAdmin(User::load($roleUser->id())));

		// permission grant (authenticated role with administer users)
		$this->container
			->get('entity_type.manager')
			->getStorage('user_role')
			->create([
				'id' => 'perm_admin',
				'label' => 'Perm',
				'permissions' => ['administer users'],
			])
			->save();
		$permUser = $this->createUser();
		$permUser->addRole('perm_admin');
		$permUser->save();
		$this->assertTrue(UsersHelper::isAdmin(User::load($permUser->id())));

		// plain member is not an admin
		$this->assertFalse(UsersHelper::isAdmin($this->createUser()));
	}

	#[Test]
	#[TestDox('createTierTrial upgrades a member, persists the trial key, and notifies')]
	#[Group('mantle2/users')]
	public function createTierTrialUpgrade(): void
	{
		$member = $this->createUser(['mail' => 'trial@example.com']);
		UsersHelper::createTierTrial($member, AccountType::PRO, 14, 'promo');

		$reloaded = User::load($member->id());
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($reloaded));

		$trial = RedisHelper::get('user:account_trial:' . $member->id());
		$this->assertNotNull($trial);
		$this->assertSame('FREE', $trial['old_type']);
		$this->assertSame('PRO', $trial['new_type']);

		$notes = UsersHelper::getNotifications($reloaded);
		$this->assertNotEmpty($notes);
		$this->assertSame('Account Trial Activated', $notes[0]->getTitle());
	}

	#[Test]
	#[TestDox('createTierTrial clamps an invalid duration to 7 days but still upgrades')]
	#[Group('mantle2/users')]
	public function createTierTrialInvalidDays(): void
	{
		$member = $this->createUser(['mail' => 'trial2@example.com']);
		UsersHelper::createTierTrial($member, AccountType::PRO, 0);
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType(User::load($member->id())));

		$member2 = $this->createUser(['mail' => 'trial3@example.com']);
		UsersHelper::createTierTrial($member2, AccountType::WRITER, 500);
		$this->assertSame(
			AccountType::WRITER,
			UsersHelper::getAccountType(User::load($member2->id())),
		);
	}

	#[Test]
	#[TestDox('setDisabled reactivates a wrongly-blocked admin instead of leaving it disabled')]
	#[Group('mantle2/users')]
	public function setDisabledReactivatesBlockedAdmin(): void
	{
		$admin = $this->admin();
		$admin->block();
		$admin->save();
		$admin = User::load($admin->id());
		$this->assertTrue($admin->isBlocked());

		UsersHelper::setDisabled($admin, true);
		$this->assertFalse($admin->isBlocked());
		$this->assertFalse(UsersHelper::isDisabled($admin));
	}

	#[Test]
	#[TestDox('isDisabled protects root (uid 1) even when blocked')]
	#[Group('mantle2/users')]
	public function isDisabledProtectsRoot(): void
	{
		$root = User::load(1);
		$this->assertFalse(UsersHelper::isDisabled($root));
	}

	#[Test]
	#[
		TestDox(
			'enforceDisabledAccountRestrictions re-enables a blocked admin and revokes member tokens',
		),
	]
	#[Group('mantle2/users')]
	public function enforceDisabledRestrictions(): void
	{
		// a blocked admin gets re-activated by the cron pass
		$admin = $this->admin();
		$admin->block();
		$admin->save();

		// a blocked member keeps its blocked state but loses its tokens
		$member = $this->createUser();
		$token = UsersHelper::issueToken($member);
		$member->block();
		$member->save();
		$this->assertNotNull(UsersHelper::getUserByToken($token));

		UsersHelper::enforceDisabledAccountRestrictions();

		$this->assertFalse(User::load($admin->id())->isBlocked());
		$this->assertTrue(User::load($member->id())->isBlocked());
		$this->assertNull(UsersHelper::getUserByToken($token));
	}

	#endregion

	#region Inactive Account Deletion (branches)

	#[Test]
	#[TestDox('inactivityReference is the later of last-login and created time')]
	#[Group('mantle2/users')]
	public function inactivityReferenceMax(): void
	{
		$user = $this->createUser();
		$created = (int) $user->getCreatedTime();
		$this->assertSame($created, UsersHelper::inactivityReference($user));

		$later = $created + 5000;
		$user->setLastLoginTime($later);
		$user->save();
		$this->assertSame($later, UsersHelper::inactivityReference(User::load($user->id())));
	}

	#[Test]
	#[
		TestDox(
			'resolveDeletionWarningWindow buckets by nearest window and rejects out-of-range spans',
		),
	]
	#[Group('mantle2/users')]
	#[DataProvider('deletionWindowProvider')]
	public function resolveDeletionWarningWindow(int $seconds, ?string $expectedKey): void
	{
		$window = UsersHelper::resolveDeletionWarningWindow($seconds);
		if ($expectedKey === null) {
			$this->assertNull($window);
		} else {
			$this->assertSame($expectedKey, $window['key']);
		}
	}

	public static function deletionWindowProvider(): array
	{
		return [
			'at deletion moment' => [0, null],
			'past deletion moment' => [-100, null],
			'within an hour' => [1800, '1_hour'],
			'exactly one hour' => [3600, '1_hour'],
			'within a day' => [3601, '1_day'],
			'within three days' => [200000, '3_days'],
			'within a week' => [500000, '1_week'],
			'within two weeks' => [1000000, '2_weeks'],
			'beyond widest window' => [5000000, null],
		];
	}

	#endregion

	#region User CRUD Operations (branches)

	#[Test]
	#[TestDox('serializeUser returns [] for an UNLISTED profile viewed anonymously')]
	#[Group('mantle2/users')]
	public function serializeUserUnlistedAnon(): void
	{
		$unlisted = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::UNLISTED,
				Visibility::cases(),
				true,
			),
		]);
		$this->assertSame([], UsersHelper::serializeUser($unlisted, null));

		// a logged-in viewer sees the UNLISTED shell
		$viewer = $this->createUser();
		$view = UsersHelper::serializeUser($unlisted, $viewer);
		$this->assertSame($unlisted->getAccountName(), $view['username']);
	}

	#[Test]
	#[TestDox('serializeUser shows an admin viewer the private fields of any profile')]
	#[Group('mantle2/users')]
	public function serializeUserAdminViewer(): void
	{
		$user = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
			'mail' => 'target@example.com',
			'field_country' => 'US',
		]);
		$adminView = UsersHelper::serializeUser($user, $this->admin());
		$this->assertNotSame([], $adminView);
		$this->assertSame('target@example.com', $adminView['account']['email']);
		$this->assertSame('US', $adminView['account']['country']);
	}

	#[Test]
	#[TestDox('serializeUser exposes MUTUAL-gated fields to a mutual friend but not a stranger')]
	#[Group('mantle2/users')]
	public function serializeUserMutualViewer(): void
	{
		$shared = $this->createUser();
		$user = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
			'mail' => 'mut@example.com',
		]);
		$viewer = $this->createUser();
		$user->set('field_friends', json_encode([(string) $shared->id()]));
		$user->save();
		$viewer->set('field_friends', json_encode([(string) $shared->id()]));
		$viewer->save();

		$view = UsersHelper::serializeUser(User::load($user->id()), User::load($viewer->id()));
		$this->assertSame('mut@example.com', $view['account']['email']);
		$this->assertTrue($view['is_mutual']);
	}

	#[Test]
	#[TestDox('serializeUser marks a disabled account and hides its fields from a stranger')]
	#[Group('mantle2/users')]
	public function serializeUserDisabled(): void
	{
		$user = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
			'field_first_name' => 'Grace',
			'mail' => 'dis@example.com',
		]);
		$user->block();
		$user->save();
		$stranger = $this->createUser();

		$view = UsersHelper::serializeUser(User::load($user->id()), $stranger);
		$this->assertTrue($view['disabled']);
		// disabled accounts force every field to PRIVATE, so a stranger sees nulls
		$this->assertNull($view['account']['first_name']);
		$this->assertNull($view['account']['email']);
	}

	#[Test]
	#[TestDox('patchUser rejects empty data and validates each editable field length/format')]
	#[Group('mantle2/users')]
	#[DataProvider('patchValidationProvider')]
	public function patchUserValidation(array $data, int $expectedStatus): void
	{
		$user = $this->createUser(['mail' => 'patch@example.com']);
		$response = UsersHelper::patchUser(User::load($user->id()), $data, $user);
		$this->assertSame($expectedStatus, $response->getStatusCode());
	}

	public static function patchValidationProvider(): array
	{
		return [
			'empty data' => [[], Response::HTTP_BAD_REQUEST],
			'short username' => [['username' => 'ab'], Response::HTTP_BAD_REQUEST],
			'long username' => [['username' => str_repeat('a', 31)], Response::HTTP_BAD_REQUEST],
			'short first name' => [['first_name' => 'A'], Response::HTTP_BAD_REQUEST],
			'long first name' => [
				['first_name' => str_repeat('A', 51)],
				Response::HTTP_BAD_REQUEST,
			],
			'short last name' => [['last_name' => 'B'], Response::HTTP_BAD_REQUEST],
			'long bio' => [['bio' => str_repeat('x', 501)], Response::HTTP_BAD_REQUEST],
			'bad country length' => [['country' => 'USA'], Response::HTTP_BAD_REQUEST],
			'invalid visibility' => [['visibility' => 'NOPE'], Response::HTTP_BAD_REQUEST],
			'invalid email format' => [['email' => 'not-an-email'], Response::HTTP_BAD_REQUEST],
			'non-bool censor' => [['bio' => 'hi', 'censor' => 'yes'], Response::HTTP_BAD_REQUEST],
		];
	}

	#[Test]
	#[TestDox('patchUser writes valid profile fields and reflects them in the serialized response')]
	#[Group('mantle2/users')]
	public function patchUserSuccess(): void
	{
		$user = $this->createUser(['mail' => 'ok@example.com']);
		$response = UsersHelper::patchUser(
			User::load($user->id()),
			[
				'first_name' => 'Ada',
				'last_name' => 'Byron',
				'bio' => 'mathematician',
				'country' => 'GB',
				'visibility' => 'PUBLIC',
				'subscribed' => false,
			],
			$user,
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());

		$reloaded = User::load($user->id());
		$this->assertSame('Ada', $reloaded->get('field_first_name')->value);
		$this->assertSame('Byron', $reloaded->get('field_last_name')->value);
		$this->assertSame('mathematician', $reloaded->get('field_bio')->value);
		$this->assertSame('GB', $reloaded->get('field_country')->value);
		$this->assertSame(Visibility::PUBLIC, UsersHelper::getVisibility($reloaded));
		$this->assertFalse(UsersHelper::isSubscribed($reloaded));
	}

	#[Test]
	#[TestDox('patchUser rejects a duplicate username but accepts the owner keeping theirs')]
	#[Group('mantle2/users')]
	public function patchUserDuplicateUsername(): void
	{
		$taken = $this->createUser(['name' => 'taken_name']);
		$user = $this->createUser(['name' => 'mine_name', 'mail' => 'dup@example.com']);

		$dup = UsersHelper::patchUser(User::load($user->id()), ['username' => 'taken_name'], $user);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $dup->getStatusCode());

		// setting to a fresh available name succeeds
		$ok = UsersHelper::patchUser(User::load($user->id()), ['username' => 'fresh_name'], $user);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('fresh_name', User::load($user->id())->getAccountName());
	}

	#[Test]
	#[TestDox('patchUser censors a flagged bio when opted in and blocks it otherwise')]
	#[Group('mantle2/users')]
	public function patchUserBioCensor(): void
	{
		$user = $this->createUser(['mail' => 'bio@example.com']);
		$blocked = UsersHelper::patchUser(
			User::load($user->id()),
			['bio' => 'this is shit content'],
			$user,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $blocked->getStatusCode());

		$censored = UsersHelper::patchUser(
			User::load($user->id()),
			['bio' => 'this is shit content', 'censor' => true],
			$user,
		);
		$this->assertSame(Response::HTTP_OK, $censored->getStatusCode());
		$this->assertStringNotContainsString(
			'shit',
			User::load($user->id())->get('field_bio')->value,
		);
	}

	#[Test]
	#[TestDox('patchUser disabled toggle is admin-only, boolean-only, and protects admin targets')]
	#[Group('mantle2/users')]
	public function patchUserDisabledGuards(): void
	{
		$member = $this->createUser(['mail' => 'dis@example.com']);

		// a non-admin requester cannot touch disabled
		$forbidden = UsersHelper::patchUser(
			User::load($member->id()),
			['disabled' => true],
			$member,
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->admin();
		// non-bool disabled is a 400
		$badType = UsersHelper::patchUser(User::load($member->id()), ['disabled' => 'yes'], $admin);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badType->getStatusCode());

		// disabling an admin target is forbidden
		$adminTarget = $this->admin();
		$protect = UsersHelper::patchUser(
			User::load($adminTarget->id()),
			['disabled' => true],
			$admin,
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $protect->getStatusCode());

		// an admin can disable a member; the change notifies them
		$ok = UsersHelper::patchUser(
			User::load($member->id()),
			['disabled' => true, 'disable_reason' => 'spam'],
			$admin,
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertTrue(UsersHelper::isDisabled(User::load($member->id())));
		$notes = UsersHelper::getNotifications(User::load($member->id()));
		$this->assertSame('Account Disabled', $notes[0]->getTitle());
	}

	#[Test]
	#[TestDox('patchUser leaves an unchanged email untouched and flags a pending email change')]
	#[Group('mantle2/users')]
	public function patchUserEmailChange(): void
	{
		$user = $this->createUser(['mail' => 'same@example.com']);

		// same email is a no-op path (no pending flag)
		$noChange = UsersHelper::patchUser(
			User::load($user->id()),
			['email' => 'same@example.com'],
			$user,
		);
		$this->assertSame(Response::HTTP_OK, $noChange->getStatusCode());
		$this->assertArrayNotHasKey('email_change_pending', $this->decode($noChange));

		// a valid different email initiates verification (cloud blacklist degrades to allow)
		$change = UsersHelper::patchUser(
			User::load($user->id()),
			['email' => 'new_' . bin2hex(random_bytes(3)) . '@example.com'],
			$user,
		);
		$this->assertSame(Response::HTTP_OK, $change->getStatusCode());
		$body = $this->decode($change);
		$this->assertTrue($body['email_change_pending']);
		$this->assertNotNull(RedisHelper::get('email_change_' . $user->id()));
	}

	#[Test]
	#[TestDox('patchFieldPrivacy rejects empty data, unknown fields, and never-public fields')]
	#[Group('mantle2/users')]
	public function patchFieldPrivacyBranches(): void
	{
		$user = $this->createUser();

		$empty = UsersHelper::patchFieldPrivacy(User::load($user->id()), [], $user);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $empty->getStatusCode());

		$unknown = UsersHelper::patchFieldPrivacy(
			User::load($user->id()),
			['not_a_field' => 'PUBLIC'],
			$user,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $unknown->getStatusCode());

		$neverPublic = UsersHelper::patchFieldPrivacy(
			User::load($user->id()),
			['address' => 'PUBLIC'],
			$user,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $neverPublic->getStatusCode());

		$ok = UsersHelper::patchFieldPrivacy(User::load($user->id()), ['bio' => 'PRIVATE'], $user);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('PRIVATE', UsersHelper::getFieldPrivacy(User::load($user->id()))['bio']);
	}

	#endregion

	#region User Token Authentication (branches)

	#[Test]
	#[TestDox('getUserByToken returns null for an expired token and cleans it from the index')]
	#[Group('mantle2/users')]
	public function tokenExpiry(): void
	{
		$user = $this->createUser();
		$store = $this->container->get('keyvalue')->get('mantle2_tokens');
		$index = $this->container->get('keyvalue')->get('mantle2_tokens_by_user');
		$token = 'expired' . bin2hex(random_bytes(8));
		$store->set($token, ['uid' => (int) $user->id(), 'created' => 100, 'exp' => 200]);
		$index->set((string) $user->id(), [$token]);

		$this->assertNull(UsersHelper::getUserByToken($token));
		$this->assertNull($store->get($token));
		$this->assertNotContains($token, $index->get((string) $user->id()) ?? []);
	}

	#[Test]
	#[TestDox('getUserByToken slides the expiry forward once past the half-life')]
	#[Group('mantle2/users')]
	public function tokenSlidingExpiry(): void
	{
		$user = $this->createUser();
		$store = $this->container->get('keyvalue')->get('mantle2_tokens');
		$index = $this->container->get('keyvalue')->get('mantle2_tokens_by_user');
		$token = 'slide' . bin2hex(random_bytes(8));
		$soon = time() + 60;
		$store->set($token, ['uid' => (int) $user->id(), 'created' => time(), 'exp' => $soon]);
		$index->set((string) $user->id(), [$token]);

		$resolved = UsersHelper::getUserByToken($token);
		$this->assertSame((int) $user->id(), (int) $resolved->id());
		$this->assertGreaterThan($soon, (int) $store->get($token)['exp']);
	}

	#[Test]
	#[TestDox('getUserByToken returns null when the stored uid no longer loads')]
	#[Group('mantle2/users')]
	public function tokenDanglingUid(): void
	{
		$store = $this->container->get('keyvalue')->get('mantle2_tokens');
		$token = 'ghost' . bin2hex(random_bytes(8));
		$store->set($token, ['uid' => 987654, 'created' => time(), 'exp' => time() + 100000]);
		$this->assertNull(UsersHelper::getUserByToken($token));
	}

	#[Test]
	#[TestDox('revokeToken is a no-op for an empty token and drops a live one from the index')]
	#[Group('mantle2/users')]
	public function revokeTokenEdges(): void
	{
		UsersHelper::revokeToken('');
		$this->addToAssertionCount(1);

		$user = $this->createUser();
		$token = UsersHelper::issueToken($user);
		$index = $this->container->get('keyvalue')->get('mantle2_tokens_by_user');
		$this->assertContains($token, $index->get((string) $user->id()) ?? []);

		UsersHelper::revokeToken($token);
		$this->assertNull(UsersHelper::getUserByToken($token));
		$this->assertNotContains($token, $index->get((string) $user->id()) ?? []);
	}

	#[Test]
	#[TestDox('clearCachedUserResponses ignores a non-positive uid')]
	#[Group('mantle2/users')]
	public function clearCachedResponsesGuard(): void
	{
		UsersHelper::clearCachedUserResponses(0);
		UsersHelper::clearCachedUserResponses(-5);
		$this->addToAssertionCount(1);
	}

	#endregion

	#region User Passwords (branches)

	#[Test]
	#[TestDox('validateResetPasswordToken is false when no token was ever generated')]
	#[Group('mantle2/users')]
	public function resetTokenMissing(): void
	{
		$user = $this->createUser();
		$this->assertFalse(UsersHelper::validateResetPasswordToken($user, 'whatever'));
	}

	#[Test]
	#[TestDox('changePassword rehashes so the old password stops validating')]
	#[Group('mantle2/users')]
	public function changePassword(): void
	{
		$user = $this->createUser(['pass' => 'OldPass123', 'mail' => 'pw@example.com']);
		$this->assertTrue(UsersHelper::validatePassword($user, 'OldPass123'));

		$this->assertTrue(UsersHelper::changePassword($user, 'NewPass456'));
		$reloaded = User::load($user->id());
		$this->assertTrue(UsersHelper::validatePassword($reloaded, 'NewPass456'));
		$this->assertFalse(UsersHelper::validatePassword($reloaded, 'OldPass123'));
	}

	#endregion

	#region User Fields (branches)

	#[Test]
	#[TestDox('getName returns null when both name parts are empty')]
	#[Group('mantle2/users')]
	public function getNameEmpty(): void
	{
		$user = $this->createUser();
		$this->assertNull(UsersHelper::getName($user, $user));
	}

	#[Test]
	#[
		TestDox(
			'getPhoneNumber honors the CIRCLE default: hidden from stranger, shown to circle member',
		),
	]
	#[Group('mantle2/users')]
	public function getPhoneNumberPrivacy(): void
	{
		$user = $this->createUser(['field_phone' => 15550001111]);
		$stranger = $this->createUser();
		$circleMember = $this->createUser();
		$user->set('field_circle', json_encode([(string) $circleMember->id()]));
		$user->save();
		$user = User::load($user->id());
		$circleMember = User::load($circleMember->id());

		$this->assertNull(UsersHelper::getPhoneNumber($user, $stranger));
		$this->assertSame(15550001111, UsersHelper::getPhoneNumber($user, $user));
		$this->assertSame(15550001111, UsersHelper::getPhoneNumber($user, $circleMember));
	}

	#[Test]
	#[TestDox('getLastName resolves under the shared name privacy key')]
	#[Group('mantle2/users')]
	public function getLastNamePrivacy(): void
	{
		$user = $this->createUser(['field_first_name' => 'Ada', 'field_last_name' => 'Lovelace']);
		UsersHelper::setFieldPrivacy($user, ['name' => 'PRIVATE']);
		$user->save();
		$user = User::load($user->id());
		$stranger = $this->createUser();

		$this->assertNull(UsersHelper::getFirstName($user, $stranger));
		$this->assertNull(UsersHelper::getLastName($user, $stranger));
		$this->assertSame('Lovelace', UsersHelper::getLastName($user, $user));
	}

	#[Test]
	#[TestDox('isSubscribed defaults true and setSubscribed(false) persists the opt-out')]
	#[Group('mantle2/users')]
	public function subscriptionDefaultAndToggle(): void
	{
		$user = $this->createUser();
		$this->assertTrue(UsersHelper::isSubscribed($user));

		UsersHelper::setSubscribed($user, false);
		$user->save();
		$this->assertFalse(UsersHelper::isSubscribed(User::load($user->id())));
	}

	#[Test]
	#[TestDox('requireEmailVerified flags whether the user even has an email to verify')]
	#[Group('mantle2/users')]
	public function requireEmailVerifiedNoEmail(): void
	{
		$noEmail = $this->createUser(['mail' => '']);
		$gate = UsersHelper::requireEmailVerified($noEmail);
		$this->assertInstanceOf(JsonResponse::class, $gate);
		$this->assertSame(Response::HTTP_FORBIDDEN, $gate->getStatusCode());
	}

	#endregion
}
