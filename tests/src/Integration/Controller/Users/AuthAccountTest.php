<?php

namespace Drupal\Tests\mantle2\Integration\Controller\Users;

use Drupal\mantle2\Controller\UsersController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Visibility;
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
use Throwable;

class AuthAccountTest extends IntegrationTestBase
{
	// success paths here fan out to CloudHelper::sendWebsocketMessage (addNotification)
	// which is unavailable in the integration tier; the local write completes before
	// that hop, so we let a cloud failure through and assert the persisted state.
	// the full success response is covered in E2E against a live worker.
	private function invokeToleratingCloud(callable $fn): ?JsonResponse
	{
		try {
			return $fn();
		} catch (Throwable $e) {
			$this->assertStringContainsString('HTTP Error', $e->getMessage());
			return null;
		}
	}

	private function controller(): UsersController
	{
		return UsersController::create($this->container);
	}

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

	// a member whose plaintext password is known and hashed by drupal on save
	private function memberWithPassword(string $password, array $values = []): UserInterface
	{
		return $this->createUser(['pass' => $password] + $values);
	}

	// stored visibility is the enum ordinal (list_string coerces to a string), and the
	// users() list query filters against that ordinal; new users otherwise default to UNLISTED
	private function visibilityOrdinal(Visibility $v): string
	{
		return (string) array_search($v, Visibility::cases(), true);
	}

	private function publicUser(array $values = []): UserInterface
	{
		return $this->createUser(
			['field_visibility' => $this->visibilityOrdinal(Visibility::PUBLIC)] + $values,
		);
	}

	private function basicAuthRequest(
		string $name,
		string $pass,
		string $uri = '/v2/users/login',
		array $server = [],
	): Request {
		$request = $this->request('POST', $uri, $server);
		$request->headers->set('Authorization', 'Basic ' . base64_encode("$name:$pass"));
		return $request;
	}

	private function capturedMail(): array
	{
		return \Drupal::state()->get('system.test_mail_collector') ?? [];
	}

	#region login

	#[Test]
	#[TestDox('POST /v2/users/login rejects missing/malformed Basic auth and empty credentials')]
	#[Group('mantle2/users')]
	#[DataProvider('badLoginHeaderProvider')]
	public function loginRejectsBadHeaders(?string $header, int $status): void
	{
		$request = $this->request('POST', '/v2/users/login');
		if ($header !== null) {
			$request->headers->set('Authorization', $header);
		}
		$response = $this->controller()->login($request);
		$this->assertSame($status, $response->getStatusCode());
	}

	public static function badLoginHeaderProvider(): array
	{
		return [
			'no header' => [null, Response::HTTP_UNAUTHORIZED],
			'not basic' => ['Bearer abc', Response::HTTP_UNAUTHORIZED],
			'undecodable' => ['Basic !!!!', Response::HTTP_UNAUTHORIZED],
			'no colon' => ['Basic ' . base64_encode('noseparator'), Response::HTTP_UNAUTHORIZED],
			'empty name' => ['Basic ' . base64_encode(':pass'), Response::HTTP_BAD_REQUEST],
			'empty pass' => ['Basic ' . base64_encode('name:'), Response::HTTP_BAD_REQUEST],
		];
	}

	#[Test]
	#[TestDox('POST /v2/users/login 401s an unknown user and a wrong password')]
	#[Group('mantle2/users')]
	public function loginUnknownAndWrongPassword(): void
	{
		$unknown = $this->controller()->login($this->basicAuthRequest('nobody_here', 'whatever12'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $unknown->getStatusCode());

		$user = $this->memberWithPassword('CorrectHorse1');
		$wrong = $this->controller()->login(
			$this->basicAuthRequest($user->getAccountName(), 'WrongPassword9'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $wrong->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/users/login 403s a disabled account and 400s an oauth-only account')]
	#[Group('mantle2/users')]
	public function loginDisabledAndOAuthOnly(): void
	{
		// base createUser() forces status=1 via array-union precedence, so block explicitly
		$disabled = $this->memberWithPassword('SecretPass1');
		$disabled->block();
		$disabled->save();
		$this->assertTrue(UsersHelper::isDisabled($disabled));
		$res = $this->controller()->login(
			$this->basicAuthRequest($disabled->getAccountName(), 'SecretPass1'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $res->getStatusCode());
		$this->assertSame('Account disabled by administrator', $this->decode($res)['message']);

		// oauth-only user has an empty password hash
		$oauth = $this->createUser();
		$oauth->setPassword(null);
		$oauth->save();
		$this->assertFalse(UsersHelper::hasPassword($oauth));
		$oauthRes = $this->controller()->login(
			$this->basicAuthRequest($oauth->getAccountName(), 'anything123'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $oauthRes->getStatusCode());
		$this->assertStringContainsString('OAuth', $this->decode($oauthRes)['message']);
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/users/login issues a session token on success and accepts email as the login name',
		),
	]
	#[Group('mantle2/users')]
	public function loginSuccessByUsernameAndEmail(): void
	{
		// seed the request's client IP as already-known so the login neither gates on
		// new-IP 2FA nor fans a new-login notification out to the (E2E-only) cloud
		$user = $this->memberWithPassword('GoodPass123', [
			'mail' => 'login.me@example.com',
			'field_previous_ips' => json_encode(['127.0.0.1']),
		]);

		$byName = $this->controller()->login(
			$this->basicAuthRequest($user->getAccountName(), 'GoodPass123'),
		);
		$this->assertSame(Response::HTTP_OK, $byName->getStatusCode());
		$body = $this->decode($byName);
		$this->assertSame($user->getAccountName(), $body['username']);
		$this->assertNotEmpty($body['session_token']);
		$this->assertSame(
			(int) $user->id(),
			(int) UsersHelper::getUserByToken($body['session_token'])->id(),
		);
		$this->assertArrayHasKey('user', $body);

		// the successful login sets a 30s rate-limit key, so a second login is rate-limited
		$again = $this->controller()->login(
			$this->basicAuthRequest($user->getAccountName(), 'GoodPass123'),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $again->getStatusCode());
		$this->assertNotNull($again->headers->get('Retry-After'));
		$this->assertArrayHasKey('retry_after', $this->decode($again));

		// email resolves to the same account (clear the rate-limit first)
		RedisHelper::delete('login_success_rate_limit_' . $user->id());
		$byEmail = $this->controller()->login(
			$this->basicAuthRequest('login.me@example.com', 'GoodPass123'),
		);
		$this->assertSame(Response::HTTP_OK, $byEmail->getStatusCode());
		$this->assertSame($user->getAccountName(), $this->decode($byEmail)['username']);
	}

	#[Test]
	#[TestDox('POST /v2/users/login gates a new IP behind an emailed 8-digit code')]
	#[Group('mantle2/users')]
	public function loginNewIpGate(): void
	{
		$user = $this->memberWithPassword('GoodPass123', [
			'mail' => 'gate.me@example.com',
			'field_previous_ips' => json_encode(['203.0.113.9']),
		]);

		$request = $this->basicAuthRequest(
			$user->getAccountName(),
			'GoodPass123',
			'/v2/users/login',
			[
				'REMOTE_ADDR' => '198.51.100.4',
			],
		);
		$response = $this->controller()->login($request);

		$this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertTrue($body['requires_verification']);
		$this->assertNotEmpty($body['ticket']);
		$this->assertSame(600, $body['expires_in']);
		$this->assertStringContainsString('@example.com', $body['email']);

		// a verification email was queued
		$mail = $this->capturedMail();
		$this->assertNotEmpty($mail);
		$this->assertSame('login_verification', end($mail)['key']);

		// no token yet: the ticket must be redeemed first
		$ticket = RedisHelper::get('login_2fa:' . $body['ticket']);
		$this->assertSame((int) $user->id(), (int) $ticket['user_id']);
		$this->assertMatchesRegularExpression('/^\d{8}$/', $ticket['code']);
	}

	#endregion

	#region verifyLoginNewIP

	#[Test]
	#[TestDox('POST /v2/users/login/verify_new_ip validates ticket+code and 400s bad input')]
	#[Group('mantle2/users')]
	#[DataProvider('verifyBadInputProvider')]
	public function verifyLoginNewIpBadInput(array $query, string $needle): void
	{
		$request = $this->request('POST', '/v2/users/login/verify_new_ip');
		$request->query->replace($query);
		$response = $this->controller()->verifyLoginNewIP($request);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertStringContainsString($needle, $this->decode($response)['message']);
	}

	public static function verifyBadInputProvider(): array
	{
		return [
			'missing ticket' => [[], 'Missing ticket'],
			'missing code' => [['ticket' => 'abc'], 'Missing verification code'],
			'bad code shape' => [
				['ticket' => 'abc', 'code' => '123'],
				'Invalid verification code format',
			],
			'expired ticket' => [['ticket' => 'nope', 'code' => '12345678'], 'expired or invalid'],
		];
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/users/login/verify_new_ip completes sign-in with the right code and 400s a wrong one',
		),
	]
	#[Group('mantle2/users')]
	public function verifyLoginNewIpSuccess(): void
	{
		// verify from an IP the user already knows so finalizeLogin does not emit the
		// (E2E-only, cloud-backed) new-login notification
		$user = $this->memberWithPassword('GoodPass123', [
			'mail' => 'verify.me@example.com',
			'field_previous_ips' => json_encode(['198.51.100.4']),
		]);

		$begin = UsersHelper::beginLogin2FAChallenge(
			$user,
			$this->request('POST', '/v2/users/login', ['REMOTE_ADDR' => '198.51.100.4']),
		);
		$this->assertIsArray($begin);
		$code = RedisHelper::get('login_2fa:' . $begin['ticket'])['code'];

		$wrong = $this->request('POST', '/v2/users/login/verify_new_ip', [
			'REMOTE_ADDR' => '198.51.100.4',
		]);
		$wrong->query->replace(['ticket' => $begin['ticket'], 'code' => '00000000']);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$this->controller()->verifyLoginNewIP($wrong)->getStatusCode(),
		);

		$request = $this->request('POST', '/v2/users/login/verify_new_ip', [
			'REMOTE_ADDR' => '198.51.100.4',
		]);
		$request->query->replace(['ticket' => $begin['ticket'], 'code' => $code]);
		$response = $this->controller()->verifyLoginNewIP($request);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertNotEmpty($body['session_token']);
		$this->assertSame(
			(int) $user->id(),
			(int) UsersHelper::getUserByToken($body['session_token'])->id(),
		);

		// ticket is consumed
		$this->assertNull(RedisHelper::get('login_2fa:' . $begin['ticket']));

		// the new IP is now recorded on the user
		$reloaded = User::load($user->id());
		$this->assertContains('198.51.100.4', UsersHelper::getKnownLoginIPs($reloaded));
	}

	#endregion

	#region logout

	#[Test]
	#[TestDox('POST /v2/users/logout 401s without a bearer and revokes the token when present')]
	#[Group('mantle2/users')]
	public function logout(): void
	{
		$anon = $this->controller()->logout($this->request('POST', '/v2/users/logout'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->createUser();
		$token = UsersHelper::issueToken($user);
		$this->assertInstanceOf(UserInterface::class, UsersHelper::getUserByToken($token));

		$request = $this->request('POST', '/v2/users/logout');
		$request->headers->set('Authorization', 'Bearer ' . $token);
		$response = $this->controller()->logout($request);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame('Logout successful', $body['message']);
		$this->assertSame($token, $body['session_token']);
		$this->assertArrayHasKey('user', $body);

		// token is revoked
		$this->assertNull(UsersHelper::getUserByToken($token));
	}

	#endregion

	#region users

	#[Test]
	#[TestDox('GET /v2/users lists public users with pagination and honors sort direction')]
	#[Group('mantle2/users')]
	public function usersListPaginationSort(): void
	{
		// three public members created oldest->newest
		$this->publicUser(['name' => 'aaa_' . bin2hex(random_bytes(2))]);
		$this->publicUser(['name' => 'bbb_' . bin2hex(random_bytes(2))]);
		$this->publicUser(['name' => 'ccc_' . bin2hex(random_bytes(2))]);

		$request = $this->request('GET', '/v2/users');
		$request->query->replace(['limit' => '2', 'page' => '1', 'sort' => 'asc']);
		$response = $this->controller()->users($request);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame(1, $body['page']);
		$this->assertSame(2, $body['limit']);
		$this->assertSame(3, $body['total']);
		$this->assertCount(2, $body['items']);

		// page 2 has the remaining record
		$page2 = $this->request('GET', '/v2/users');
		$page2->query->replace(['limit' => '2', 'page' => '2', 'sort' => 'asc']);
		$body2 = $this->decode($this->controller()->users($page2));
		$this->assertCount(1, $body2['items']);

		// ascending returns the oldest (a) first; descending returns the newest (c) first
		$firstAsc = $body['items'][0]['id'];
		$desc = $this->request('GET', '/v2/users');
		$desc->query->replace(['limit' => '2', 'sort' => 'desc']);
		$bodyDesc = $this->decode($this->controller()->users($desc));
		$this->assertNotSame($firstAsc, $bodyDesc['items'][0]['id']);
	}

	#[Test]
	#[
		TestDox(
			'GET /v2/users hides non-public users from anonymous callers but shows them to admins',
		),
	]
	#[Group('mantle2/users')]
	public function usersListVisibilityFilter(): void
	{
		$this->publicUser();
		$this->createUser([
			'field_visibility' => $this->visibilityOrdinal(Visibility::PRIVATE),
		]);

		$anon = $this->decode($this->controller()->users($this->request('GET', '/v2/users')));
		$this->assertSame(1, $anon['total']);

		$admin = $this->admin();
		$adminReq = $this->authRequest($admin, 'GET', '/v2/users');
		$adminBody = $this->decode($this->controller()->users($adminReq));
		// admin sees both members plus the admin account itself
		$this->assertGreaterThanOrEqual(3, $adminBody['total']);
	}

	#endregion

	#region getUser

	#[Test]
	#[TestDox('GET /v2/users/{id|username|current} resolves the same handler and 404s the unknown')]
	#[Group('mantle2/users')]
	public function getUser(): void
	{
		$user = $this->publicUser();

		$byId = $this->controller()->getUser(
			$this->request('GET', '/v2/users'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_OK, $byId->getStatusCode());
		$this->assertSame((int) $user->id(), (int) $this->decode($byId)['id']);

		$byName = $this->controller()->getUser(
			$this->request('GET', '/v2/users'),
			null,
			'@' . $user->getAccountName(),
		);
		$this->assertSame((int) $user->id(), (int) $this->decode($byName)['id']);

		$current = $this->controller()->getUser(
			$this->authRequest($user, 'GET', '/v2/users/current'),
		);
		$this->assertSame((int) $user->id(), (int) $this->decode($current)['id']);

		$missing = $this->controller()->getUser($this->request('GET', '/v2/users'), '99999');
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/users/{id} 404s a private user for an anonymous caller')]
	#[Group('mantle2/users')]
	public function getUserPrivateHidden(): void
	{
		$private = $this->createUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);

		$response = $this->controller()->getUser(
			$this->request('GET', '/v2/users'),
			(string) $private->id(),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
	}

	#endregion

	#region patchUser

	#[Test]
	#[TestDox('PATCH /v2/users/current updates local fields and 400s an empty body')]
	#[Group('mantle2/users')]
	public function patchUserLocalFields(): void
	{
		$user = $this->createUser();

		$empty = $this->controller()->patchUser(
			$this->authRequest($user, 'PATCH', '/v2/users/current', [], '{}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $empty->getStatusCode());

		$body = json_encode([
			'first_name' => 'Ada',
			'last_name' => 'Lovelace',
			'bio' => 'mathematician',
			'country' => 'GB',
			'visibility' => 'UNLISTED',
			'subscribed' => false,
		]);
		$response = $this->controller()->patchUser(
			$this->authRequest($user, 'PATCH', '/v2/users/current', [], $body),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());

		$reloaded = User::load($user->id());
		$this->assertSame('Ada', $reloaded->get('field_first_name')->value);
		$this->assertSame('Lovelace', $reloaded->get('field_last_name')->value);
		$this->assertSame('mathematician', $reloaded->get('field_bio')->value);
		$this->assertSame('GB', $reloaded->get('field_country')->value);
		$this->assertSame(Visibility::UNLISTED, UsersHelper::getVisibility($reloaded));
		$this->assertFalse(UsersHelper::isSubscribed($reloaded));
	}

	#[Test]
	#[TestDox('PATCH /v2/users validates field lengths and forbids non-admin disabled changes')]
	#[Group('mantle2/users')]
	public function patchUserValidation(): void
	{
		$user = $this->createUser();

		$shortName = $this->controller()->patchUser(
			$this->authRequest($user, 'PATCH', '/v2/users/current', [], '{"first_name":"A"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $shortName->getStatusCode());
		$this->assertStringContainsString(
			'first name length',
			$this->decode($shortName)['message'],
		);

		$badCountry = $this->controller()->patchUser(
			$this->authRequest($user, 'PATCH', '/v2/users/current', [], '{"country":"USA"}'),
		);
		$this->assertStringContainsString(
			'country code length',
			$this->decode($badCountry)['message'],
		);

		$badVisibility = $this->controller()->patchUser(
			$this->authRequest($user, 'PATCH', '/v2/users/current', [], '{"visibility":"NOPE"}'),
		);
		$this->assertStringContainsString('visibility', $this->decode($badVisibility)['message']);

		// only admins can flip the disabled flag
		$forbidden = $this->controller()->patchUser(
			$this->authRequest($user, 'PATCH', '/v2/users/current', [], '{"disabled":true}'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	#[Test]
	#[TestDox('PATCH /v2/users/{id} lets an admin disable a member and persists the block')]
	#[Group('mantle2/users')]
	public function patchUserAdminDisable(): void
	{
		$admin = $this->admin();
		$member = $this->createUser(['mail' => 'target@example.com']);

		$this->invokeToleratingCloud(
			fn() => $this->controller()->patchUser(
				$this->authRequest(
					$admin,
					'PATCH',
					'/v2/users/' . $member->id(),
					[],
					'{"disabled":true}',
				),
				(string) $member->id(),
			),
		);

		// the block is persisted before the (cloud-backed) notification fans out
		$reloaded = User::load($member->id());
		$this->assertTrue($reloaded->isBlocked());
		$this->assertTrue(UsersHelper::isDisabled($reloaded));
	}

	#endregion

	#region deleteUser

	#[Test]
	#[TestDox('DELETE /v2/users/current requires a fresh reauth or a correct password')]
	#[Group('mantle2/users')]
	public function deleteUserSelfReauth(): void
	{
		$user = $this->memberWithPassword('DeleteMe123');

		// no reauth, no password -> 400 missing password
		$noPass = $this->controller()->deleteUser(
			$this->authRequest($user, 'DELETE', '/v2/users/current', [], ''),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noPass->getStatusCode());
		$this->assertStringContainsString(
			'password',
			strtolower($this->decode($noPass)['message']),
		);

		// wrong password -> 400
		$wrong = $this->controller()->deleteUser(
			$this->authRequest(
				$user,
				'DELETE',
				'/v2/users/current',
				[],
				'{"password":"WrongPass9"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $wrong->getStatusCode());
		$this->assertSame('Password is incorrect', $this->decode($wrong)['message']);

		// correct password clears every local proof check (no 400/403). the actual
		// entity delete + CloudHelper purge run past this point and are covered in E2E;
		// under this minimal kernel the user->delete() cascade cannot complete (500)
		$ok = $this->controller()->deleteUser(
			$this->authRequest(
				$user,
				'DELETE',
				'/v2/users/current',
				[],
				'{"password":"DeleteMe123"}',
			),
		);
		$this->assertNotSame(Response::HTTP_BAD_REQUEST, $ok->getStatusCode());
		$this->assertNotSame(Response::HTTP_FORBIDDEN, $ok->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE /v2/users skips the password prompt inside the reauth window')]
	#[Group('mantle2/users')]
	public function deleteUserWithinReauthWindow(): void
	{
		$user = $this->memberWithPassword('DeleteMe123');
		UsersHelper::markReauthenticated($user);

		// the reauth window skips the password prompt entirely (no 400/403 proof gate);
		// the entity delete + cloud purge that follow are covered in E2E
		$ok = $this->controller()->deleteUser(
			$this->authRequest($user, 'DELETE', '/v2/users/current', [], ''),
		);
		$this->assertNotSame(Response::HTTP_BAD_REQUEST, $ok->getStatusCode());
		$this->assertNotSame(Response::HTTP_FORBIDDEN, $ok->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE /v2/users refuses to delete admin/root accounts')]
	#[Group('mantle2/users')]
	public function deleteUserProtectedAccounts(): void
	{
		$admin = $this->admin();
		$response = $this->controller()->deleteUser(
			$this->authRequest($admin, 'DELETE', '/v2/users/' . $admin->id(), [], ''),
			(string) $admin->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
		$this->assertNotNull(User::load($admin->id()));
	}

	#endregion

	#region resetPassword

	#[Test]
	#[
		TestDox(
			'POST /v2/users/reset_password 400s missing email and 204s regardless of user existence',
		),
	]
	#[Group('mantle2/users')]
	public function resetPassword(): void
	{
		$missing = $this->controller()->resetPassword(
			$this->request('POST', '/v2/users/reset_password'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());

		// unknown email: still 204 to avoid enumeration
		$unknown = $this->request('POST', '/v2/users/reset_password');
		$unknown->query->replace(['email' => 'ghost@example.com']);
		$this->assertSame(
			Response::HTTP_NO_CONTENT,
			$this->controller()->resetPassword($unknown)->getStatusCode(),
		);

		// known email: 204 and a reset email is queued + a reset token is stored
		$user = $this->createUser(['mail' => 'reset.me@example.com']);
		$known = $this->request('POST', '/v2/users/reset_password');
		$known->query->replace(['email' => 'reset.me@example.com']);
		$response = $this->controller()->resetPassword($known);
		$this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

		$mail = $this->capturedMail();
		$this->assertSame('password_reset', end($mail)['key']);
		$this->assertNotNull(RedisHelper::get('password_reset_' . $user->id()));
	}

	#[Test]
	#[TestDox('POST /v2/users/reset_password rate-limits repeated requests for the same email')]
	#[Group('mantle2/users')]
	public function resetPasswordRateLimited(): void
	{
		$this->createUser(['mail' => 'limit.me@example.com']);
		$first = $this->request('POST', '/v2/users/reset_password');
		$first->query->replace(['email' => 'limit.me@example.com']);
		$this->controller()->resetPassword($first);

		$second = $this->request('POST', '/v2/users/reset_password');
		$second->query->replace(['email' => 'limit.me@example.com']);
		$response = $this->controller()->resetPassword($second);
		$this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
		$this->assertArrayHasKey('retry_after', $this->decode($response));
	}

	#endregion

	#region changePassword

	#[Test]
	#[
		TestDox(
			'POST /v2/users/current/change_password requires token or old_password when one is set',
		),
	]
	#[Group('mantle2/users')]
	public function changePasswordRequiresProof(): void
	{
		$user = $this->memberWithPassword('OldPass1234');

		$missingProof = $this->controller()->changePassword(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/change_password',
				[],
				'{"new_password":"NewPass1234"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missingProof->getStatusCode());
		$this->assertStringContainsString(
			'token or old_password',
			$this->decode($missingProof)['message'],
		);
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/users/current/change_password verifies old_password then persists the new one',
		),
	]
	#[Group('mantle2/users')]
	public function changePasswordWithOldPassword(): void
	{
		$user = $this->memberWithPassword('OldPass1234');

		$wrongOld = $this->request(
			'POST',
			'/v2/users/current/change_password',
			[],
			'{"new_password":"NewPass1234"}',
		);
		$wrongOld->query->replace(['old_password' => 'NotItAtAll1']);
		$wrongOld->headers->set('Authorization', 'Bearer ' . UsersHelper::issueToken($user));
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$this->controller()->changePassword($wrongOld)->getStatusCode(),
		);

		$request = $this->request(
			'POST',
			'/v2/users/current/change_password',
			[],
			'{"new_password":"NewPass1234"}',
		);
		$request->query->replace(['old_password' => 'OldPass1234']);
		$request->headers->set('Authorization', 'Bearer ' . UsersHelper::issueToken($user));
		// the new hash is persisted before the cloud-backed change notification fans out
		$this->invokeToleratingCloud(fn() => $this->controller()->changePassword($request));

		$reloaded = User::load($user->id());
		$this->assertTrue(UsersHelper::validatePassword($reloaded, 'NewPass1234'));
		$this->assertFalse(UsersHelper::validatePassword($reloaded, 'OldPass1234'));
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/users/current/change_password accepts a valid reset token and rejects a bad one',
		),
	]
	#[Group('mantle2/users')]
	public function changePasswordWithToken(): void
	{
		$user = $this->memberWithPassword('OldPass1234');

		$badToken = $this->request(
			'POST',
			'/v2/users/current/change_password',
			[],
			'{"new_password":"NewPass1234"}',
		);
		$badToken->query->replace(['token' => 'not-a-real-token']);
		$badToken->headers->set('Authorization', 'Bearer ' . UsersHelper::issueToken($user));
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$this->controller()->changePassword($badToken)->getStatusCode(),
		);

		$token = UsersHelper::generateResetPasswordToken($user);
		$request = $this->request(
			'POST',
			'/v2/users/current/change_password',
			[],
			'{"new_password":"NewPass1234"}',
		);
		$request->query->replace(['token' => $token]);
		$request->headers->set('Authorization', 'Bearer ' . UsersHelper::issueToken($user));
		$this->invokeToleratingCloud(fn() => $this->controller()->changePassword($request));
		$this->assertTrue(UsersHelper::validatePassword(User::load($user->id()), 'NewPass1234'));
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/users/current/change_password lets an oauth-only account set a first password',
		),
	]
	#[Group('mantle2/users')]
	public function changePasswordOAuthFirstSet(): void
	{
		$user = $this->createUser();
		$user->setPassword(null);
		$user->save();
		$this->assertFalse(UsersHelper::hasPassword($user));

		// no token/old_password needed since there is no existing password
		$this->invokeToleratingCloud(
			fn() => $this->controller()->changePassword(
				$this->authRequest(
					$user,
					'POST',
					'/v2/users/current/change_password',
					[],
					'{"new_password":"FirstPass99"}',
				),
			),
		);
		$this->assertTrue(UsersHelper::validatePassword(User::load($user->id()), 'FirstPass99'));
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/users/current/change_password rejects a new password with disallowed characters',
		),
	]
	#[Group('mantle2/users')]
	public function changePasswordRejectsWeakNew(): void
	{
		$user = $this->createUser();
		$user->setPassword(null);
		$user->save();

		// a space is outside the allowed character class, so the pattern rejects it
		$spaced = $this->controller()->changePassword(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/change_password',
				[],
				'{"new_password":"has space here"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $spaced->getStatusCode());
		$this->assertStringContainsString('Password must be', $this->decode($spaced)['message']);

		// the pattern now enforces the documented 8-100 length: a too-short value is rejected
		$tooShort = $this->controller()->changePassword(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/change_password',
				[],
				'{"new_password":"short"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $tooShort->getStatusCode());
	}

	#endregion

	#region reauth

	#[Test]
	#[TestDox('GET /v2/users/current/reauth_state reports the reauth window and 401s anonymous')]
	#[Group('mantle2/users')]
	public function reauthState(): void
	{
		$anon = $this->controller()->getReauthState(
			$this->request('GET', '/v2/users/current/reauth_state'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->createUser();
		$cold = $this->decode(
			$this->controller()->getReauthState(
				$this->authRequest($user, 'GET', '/v2/users/current/reauth_state'),
			),
		);
		$this->assertFalse($cold['recently_authenticated']);
		$this->assertNull($cold['expires_at']);
		$this->assertSame(UsersHelper::REAUTH_WINDOW_SECONDS, $cold['window_seconds']);

		UsersHelper::markReauthenticated($user);
		$warm = $this->decode(
			$this->controller()->getReauthState(
				$this->authRequest($user, 'GET', '/v2/users/current/reauth_state'),
			),
		);
		$this->assertTrue($warm['recently_authenticated']);
		$this->assertIsInt($warm['expires_at']);
	}

	#[Test]
	#[TestDox('POST /v2/users/current/reauth/password validates the password and marks the window')]
	#[Group('mantle2/users')]
	public function reauthWithPassword(): void
	{
		$anon = $this->controller()->reauthWithPassword(
			$this->request('POST', '/v2/users/current/reauth/password', [], '{"password":"x"}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->memberWithPassword('ReauthPass1');

		$missing = $this->controller()->reauthWithPassword(
			$this->authRequest($user, 'POST', '/v2/users/current/reauth/password', [], '{}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());

		$wrong = $this->controller()->reauthWithPassword(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/reauth/password',
				[],
				'{"password":"WrongPass9"}',
			),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $wrong->getStatusCode());

		$this->assertFalse(UsersHelper::getReauthState($user)[0]);
		$ok = $this->controller()->reauthWithPassword(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/reauth/password',
				[],
				'{"password":"ReauthPass1"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertTrue($this->decode($ok)['recently_authenticated']);
		$this->assertTrue(UsersHelper::getReauthState($user)[0]);
	}

	#[Test]
	#[TestDox('POST /v2/users/current/reauth/oauth validates provider and token presence')]
	#[Group('mantle2/users')]
	public function reauthWithOAuth(): void
	{
		$user = $this->createUser();

		$badProvider = $this->controller()->reauthWithOAuth(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/reauth/oauth',
				[],
				'{"provider":"myspace"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badProvider->getStatusCode());
		$this->assertStringContainsString('provider', $this->decode($badProvider)['message']);

		$noToken = $this->controller()->reauthWithOAuth(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/reauth/oauth',
				[],
				'{"provider":"google"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noToken->getStatusCode());
		$this->assertStringContainsString('token', $this->decode($noToken)['message']);
	}

	#endregion

	#region setAccountType

	#[Test]
	#[TestDox('PUT /v2/users/{id}/account_type is admin-only and validates the type')]
	#[Group('mantle2/users')]
	public function setAccountType(): void
	{
		$member = $this->createUser();

		$anon = $this->controller()->setAccountType(
			$this->request('PUT', '/v2/users/' . $member->id() . '/account_type'),
			(string) $member->id(),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$forbidden = $this->controller()->setAccountType(
			$this->authRequest($member, 'PUT', '/v2/users/' . $member->id() . '/account_type'),
			(string) $member->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->admin();
		$missingType = $this->controller()->setAccountType(
			$this->authRequest($admin, 'PUT', '/v2/users/' . $member->id() . '/account_type'),
			(string) $member->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missingType->getStatusCode());

		$badType = $this->authRequest(
			$admin,
			'PUT',
			'/v2/users/' . $member->id() . '/account_type',
		);
		$badType->query->replace(['type' => 'wizard']);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$this->controller()->setAccountType($badType, (string) $member->id())->getStatusCode(),
		);

		$ok = $this->authRequest($admin, 'PUT', '/v2/users/' . $member->id() . '/account_type');
		$ok->query->replace(['type' => 'pro']);
		$response = $this->controller()->setAccountType($ok, (string) $member->id());
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType(User::load($member->id())));
	}

	#endregion

	#region createTypeTrial

	#[Test]
	#[
		TestDox(
			'PUT /v2/users/{id}/account_type/trial is admin-only, validates days, and upgrades the tier',
		),
	]
	#[Group('mantle2/users')]
	public function createTypeTrial(): void
	{
		$member = $this->createUser();

		$forbidden = $this->controller()->createTypeTrial(
			$this->authRequest(
				$member,
				'PUT',
				'/v2/users/' . $member->id() . '/account_type/trial',
			),
			(string) $member->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->admin();

		$badDays = $this->authRequest(
			$admin,
			'PUT',
			'/v2/users/' . $member->id() . '/account_type/trial',
		);
		$badDays->query->replace(['type' => 'pro', 'days' => '0']);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$this->controller()->createTypeTrial($badDays, (string) $member->id())->getStatusCode(),
		);

		$ok = $this->authRequest(
			$admin,
			'PUT',
			'/v2/users/' . $member->id() . '/account_type/trial',
		);
		$ok->query->replace(['type' => 'pro', 'days' => '14']);
		// tier + redis key are written before the cloud-backed trial notification fans out
		$this->invokeToleratingCloud(
			fn() => $this->controller()->createTypeTrial($ok, (string) $member->id()),
		);

		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType(User::load($member->id())));
		$this->assertNotNull(RedisHelper::get('user:account_trial:' . $member->id()));
	}

	#endregion

	#region patchFieldPrivacy

	#[Test]
	#[
		TestDox(
			'PATCH /v2/users/current/field_privacy updates keys and rejects invalid/never-public values',
		),
	]
	#[Group('mantle2/users')]
	public function patchFieldPrivacy(): void
	{
		$user = $this->createUser();

		$empty = $this->controller()->patchFieldPrivacy(
			$this->authRequest($user, 'PATCH', '/v2/users/current/field_privacy', [], '{}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $empty->getStatusCode());

		$badKey = $this->controller()->patchFieldPrivacy(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/field_privacy',
				[],
				'{"not_a_field":"PUBLIC"}',
			),
		);
		$this->assertStringContainsString('Invalid field', $this->decode($badKey)['message']);

		$neverPublic = $this->controller()->patchFieldPrivacy(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/field_privacy',
				[],
				'{"address":"PUBLIC"}',
			),
		);
		$this->assertStringContainsString(
			'cannot be made public',
			$this->decode($neverPublic)['message'],
		);

		$ok = $this->controller()->patchFieldPrivacy(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/field_privacy',
				[],
				'{"bio":"PRIVATE"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('PRIVATE', UsersHelper::getFieldPrivacy(User::load($user->id()))['bio']);
	}

	#endregion
}
