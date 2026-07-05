<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\Core\Database\Database;
use Drupal\mantle2\Controller\ApiKeysController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\ApiKey;
use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\mantle2\Service\ApiKeysHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class ApiKeysControllerTest extends IntegrationTestBase
{
	private function controller(): ApiKeysController
	{
		return ApiKeysController::create($this->container);
	}

	// verified email is a hard gate on create_(); default users have it off
	private function member(): UserInterface
	{
		return $this->createUser(['field_email_verified' => true]);
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
			'field_email_verified' => true,
		]);
	}

	private function rowFor(string $keyId): ?array
	{
		$row = Database::getConnection()
			->select(ApiKeysHelper::TABLE, 't')
			->fields('t')
			->condition('t.key_id', $keyId)
			->execute()
			->fetchAssoc();
		return $row ?: null;
	}

	private function createBody(array $overrides = []): string
	{
		return json_encode(
			$overrides + ['name' => 'CI Key', 'scopes' => [ApiKeyScope::USER_READ_PROFILE]],
		);
	}

	// mints a real, usable api-key bearer so session-only guards can be exercised
	private function apiKeyRequest(
		UserInterface $user,
		string $method,
		string $uri,
		?string $content = null,
	): \Symfony\Component\HttpFoundation\Request {
		$result = ApiKeysHelper::issue(
			$user,
			'Session Guard Probe',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$this->assertIsArray($result, 'expected api key issuance to succeed');
		$request = $this->request($method, $uri, [], $content);
		$request->headers->set('Authorization', 'Bearer ' . $result['token']);
		return $request;
	}

	#[Test]
	#[TestDox('GET /v2/api-keys/scopes returns the public scope catalog')]
	#[Group('mantle2/api_keys')]
	public function scopes(): void
	{
		$response = $this->controller()->scopes($this->request('GET', '/v2/api-keys/scopes'));
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());

		$body = $this->decode($response);
		$this->assertSame(ApiKeyScope::hierarchy(), $body['scopes']);
		$this->assertSame(ApiKeyScope::leaves(), $body['leaves']);
		$this->assertSame(ApiKeysHelper::TIER_LIMITS, $body['tier_limits']);

		$this->assertArrayHasKey('7d', $body['expiry_presets']);
		$this->assertSame(7, $body['expiry_presets']['7d']['days']);
		$this->assertSame(7 * 86400, $body['expiry_presets']['7d']['seconds']);

		$this->assertSame(ApiKey::TOKEN_PREFIX, $body['token']['prefix']);
		$this->assertSame(ApiKey::TOTAL_LENGTH, $body['token']['total_length']);
		$this->assertSame(ApiKey::RANDOM_HEX_LEN, $body['token']['random_hex_length']);
		$this->assertSame(ApiKey::NAME_MIN, $body['name']['min']);
		$this->assertSame(ApiKey::NAME_MAX, $body['name']['max']);
		$this->assertSame(ApiKey::DESCRIPTION_MAX, $body['description']['max']);
	}

	#[Test]
	#[
		TestDox(
			'POST create_ requires auth, verified email, valid body, persists a row, and enforces the tier limit',
		),
	]
	#[Group('mantle2/api_keys')]
	public function create_(): void
	{
		$anon = $this->controller()->create_(
			$this->request('POST', '/v2/users/current/api-keys', [], $this->createBody()),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$unverified = $this->createUser();
		$needsVerify = $this->controller()->create_(
			$this->authRequest(
				$unverified,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(),
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $needsVerify->getStatusCode());
		$this->assertSame('EMAIL_VERIFICATION_REQUIRED', $this->decode($needsVerify)['reason']);

		$user = $this->member();

		$noBody = $this->controller()->create_(
			$this->authRequest($user, 'POST', '/v2/users/current/api-keys', [], ''),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noBody->getStatusCode());
		$this->assertSame('Request body required', $this->decode($noBody)['message']);

		$badName = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['name' => 'x']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badName->getStatusCode());

		$badScopes = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['scopes' => 'nope']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badScopes->getStatusCode());
		$this->assertSame(
			'scopes must be an array of strings',
			$this->decode($badScopes)['message'],
		);

		$emptyScopes = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['scopes' => []]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $emptyScopes->getStatusCode());

		$unknownScope = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['scopes' => ['made:up:scope']]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $unknownScope->getStatusCode());

		$badPreset = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['expiry_preset' => '3d']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badPreset->getStatusCode());

		$badExpires = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['expires_at' => 'soon']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badExpires->getStatusCode());

		$ok = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody([
					'name' => 'Primary Key',
					'description' => 'main integration',
					'scopes' => [ApiKeyScope::USER_READ_PROFILE, ApiKeyScope::EVENTS_READ],
					'expiry_preset' => '30d',
				]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('Primary Key', $body['name']);
		$this->assertSame('main integration', $body['description']);
		$this->assertContains(ApiKeyScope::USER_READ_PROFILE, $body['scopes']);
		$this->assertStringStartsWith(ApiKey::TOKEN_PREFIX, $body['token']);
		$this->assertSame(ApiKey::TOTAL_LENGTH, strlen($body['token']));
		$this->assertArrayHasKey('warning', $body);
		$this->assertFalse($body['revoked']);
		$this->assertFalse($body['never_expires']);

		$row = $this->rowFor($body['id']);
		$this->assertNotNull($row, 'created key must persist');
		$this->assertSame((int) $user->id(), (int) $row['user_id']);
		$this->assertSame(hash('sha256', $body['token']), $row['token_hash']);
		$this->assertSame($body['token_prefix'], $row['token_prefix']);
		$this->assertNull($row['revoked_at']);

		$this->assertSame(1, ApiKeysHelper::countActive((int) $user->id()));

		// FREE tier caps at 2 active keys; second succeeds, third conflicts
		$this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['name' => 'Second Key']),
			),
		);
		$overLimit = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['name' => 'Third Key']),
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $overLimit->getStatusCode());
		$this->assertSame(2, ApiKeysHelper::countActive((int) $user->id()));
	}

	#[Test]
	#[TestDox('create_ rejects api-key callers (session-only) with 403')]
	#[Group('mantle2/api_keys')]
	public function createRejectsApiKeyCaller(): void
	{
		$user = $this->member();
		$response = $this->controller()->create_(
			$this->apiKeyRequest($user, 'POST', '/v2/users/current/api-keys', $this->createBody()),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
		$this->assertStringContainsString('session token', $this->decode($response)['message']);
	}

	#[Test]
	#[TestDox('GET list returns only the caller keys with count/max/active metadata')]
	#[Group('mantle2/api_keys')]
	public function list(): void
	{
		$anon = $this->controller()->list($this->request('GET', '/v2/users/current/api-keys'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->member();
		$other = $this->member();
		ApiKeysHelper::issue($user, 'Mine A', null, [ApiKeyScope::USER_READ_PROFILE], null);
		ApiKeysHelper::issue($user, 'Mine B', null, [ApiKeyScope::EVENTS_READ], null);
		ApiKeysHelper::issue($other, 'Theirs', null, [ApiKeyScope::EVENTS_READ], null);

		$response = $this->controller()->list(
			$this->authRequest($user, 'GET', '/v2/users/current/api-keys'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame(2, $body['count']);
		$this->assertCount(2, $body['items']);
		$this->assertSame(2, $body['active']);
		$this->assertSame(ApiKeysHelper::TIER_LIMITS[AccountType::FREE->name], $body['max']);

		$names = array_column($body['items'], 'name');
		$this->assertContains('Mine A', $names);
		$this->assertNotContains('Theirs', $names);
	}

	#[Test]
	#[TestDox('GET listByUser is admin-only and 404s on unknown users')]
	#[Group('mantle2/api_keys')]
	public function listByUser(): void
	{
		$target = $this->member();
		ApiKeysHelper::issue($target, 'Target Key', null, [ApiKeyScope::USER_READ_PROFILE], null);

		$anon = $this->controller()->listByUser(
			$this->request('GET', '/v2/users/' . $target->id() . '/api-keys'),
			(string) $target->id(),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$normal = $this->member();
		$forbidden = $this->controller()->listByUser(
			$this->authRequest($normal, 'GET', '/v2/users/' . $target->id() . '/api-keys'),
			(string) $target->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
		$this->assertSame('Admin only', $this->decode($forbidden)['message']);

		$admin = $this->admin();
		$missing = $this->controller()->listByUser(
			$this->authRequest($admin, 'GET', '/v2/users/999999/api-keys'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
		$this->assertSame('User not found', $this->decode($missing)['message']);

		$ok = $this->controller()->listByUser(
			$this->authRequest($admin, 'GET', '/v2/users/' . $target->id() . '/api-keys'),
			(string) $target->id(),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame(1, $body['count']);
		$this->assertSame('Target Key', $body['items'][0]['name']);
	}

	#[Test]
	#[TestDox('GET get returns the caller key and 404s for unknown or foreign keys')]
	#[Group('mantle2/api_keys')]
	public function get(): void
	{
		$anon = $this->controller()->get(
			$this->request('GET', '/v2/users/current/api-keys/x'),
			'x',
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->member();
		$other = $this->member();
		$mine = ApiKeysHelper::issue($user, 'Mine', null, [ApiKeyScope::USER_READ_PROFILE], null);
		$theirs = ApiKeysHelper::issue($other, 'Theirs', null, [ApiKeyScope::EVENTS_READ], null);
		$this->assertIsArray($mine);
		$this->assertIsArray($theirs);
		$keyId = $mine['key']->getKeyId();

		$missing = $this->controller()->get(
			$this->authRequest($user, 'GET', '/v2/users/current/api-keys/nope'),
			'nope',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$foreign = $this->controller()->get(
			$this->authRequest(
				$user,
				'GET',
				'/v2/users/current/api-keys/' . $theirs['key']->getKeyId(),
			),
			$theirs['key']->getKeyId(),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $foreign->getStatusCode());

		$ok = $this->controller()->get(
			$this->authRequest($user, 'GET', '/v2/users/current/api-keys/' . $keyId),
			$keyId,
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame($keyId, $body['id']);
		$this->assertSame('Mine', $body['name']);
		$this->assertArrayNotHasKey('token', $body);
	}

	#[Test]
	#[TestDox('PATCH updates fields, validates, and 404s / 409s appropriately')]
	#[Group('mantle2/api_keys')]
	public function patch(): void
	{
		$user = $this->member();
		$issued = ApiKeysHelper::issue(
			$user,
			'Old Name',
			'old desc',
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$this->assertIsArray($issued);
		$keyId = $issued['key']->getKeyId();

		$anon = $this->controller()->patch(
			$this->request('PATCH', '/v2/users/current/api-keys/' . $keyId, [], '{}'),
			$keyId,
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$noBody = $this->controller()->patch(
			$this->authRequest($user, 'PATCH', '/v2/users/current/api-keys/' . $keyId, [], ''),
			$keyId,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noBody->getStatusCode());

		$missing = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/nope',
				[],
				'{"name":"Whatever"}',
			),
			'nope',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$badName = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				'{"name":"x"}',
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badName->getStatusCode());

		$badScopes = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				'{"scopes":["made:up"]}',
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badScopes->getStatusCode());

		$ok = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				json_encode([
					'name' => 'New Name',
					'description' => null,
					'scopes' => [ApiKeyScope::EVENTS_READ],
				]),
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('New Name', $body['name']);
		$this->assertNull($body['description']);
		$this->assertSame([ApiKeyScope::EVENTS_READ], $body['scopes']);

		$row = $this->rowFor($keyId);
		$this->assertSame('New Name', $row['name']);
		$this->assertNull($row['description']);
		$this->assertSame([ApiKeyScope::EVENTS_READ], json_decode($row['scopes'], true));

		// revoke then patch -> 409
		ApiKeysHelper::revoke($keyId, (int) $user->id());
		$conflict = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				'{"name":"Again Now"}',
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_CONFLICT, $conflict->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE revokes the key, is idempotent as 404, and persists revoked_at')]
	#[Group('mantle2/api_keys')]
	public function delete(): void
	{
		$user = $this->member();
		$issued = ApiKeysHelper::issue(
			$user,
			'Doomed Key',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$this->assertIsArray($issued);
		$keyId = $issued['key']->getKeyId();

		$anon = $this->controller()->delete(
			$this->request('DELETE', '/v2/users/current/api-keys/' . $keyId),
			$keyId,
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$ok = $this->controller()->delete(
			$this->authRequest($user, 'DELETE', '/v2/users/current/api-keys/' . $keyId),
			$keyId,
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());

		$row = $this->rowFor($keyId);
		$this->assertNotNull($row['revoked_at']);
		$this->assertSame(0, ApiKeysHelper::countActive((int) $user->id()));

		$again = $this->controller()->delete(
			$this->authRequest($user, 'DELETE', '/v2/users/current/api-keys/' . $keyId),
			$keyId,
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $again->getStatusCode());
	}

	#[Test]
	#[TestDox('POST revoke_all revokes every active key and reports the count')]
	#[Group('mantle2/api_keys')]
	public function revokeAll(): void
	{
		$anon = $this->controller()->revokeAll(
			$this->request('POST', '/v2/users/current/api-keys/revoke_all'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->admin();
		ApiKeysHelper::issue($user, 'Key One', null, [ApiKeyScope::USER_READ_PROFILE], null);
		ApiKeysHelper::issue($user, 'Key Two', null, [ApiKeyScope::EVENTS_READ], null);
		ApiKeysHelper::issue($user, 'Key Three', null, [ApiKeyScope::PROMPTS_READ], null);
		$this->assertSame(3, ApiKeysHelper::countActive((int) $user->id()));

		$response = $this->controller()->revokeAll(
			$this->authRequest($user, 'POST', '/v2/users/current/api-keys/revoke_all'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(3, $this->decode($response)['revoked']);
		$this->assertSame(0, ApiKeysHelper::countActive((int) $user->id()));

		$again = $this->controller()->revokeAll(
			$this->authRequest($user, 'POST', '/v2/users/current/api-keys/revoke_all'),
		);
		$this->assertSame(0, $this->decode($again)['revoked']);
	}

	#[Test]
	#[TestDox('create_ accepts never/absolute/empty expiry inputs and admins skip the email gate')]
	#[Group('mantle2/api_keys')]
	public function createExpiryVariants(): void
	{
		$user = $this->member();

		$never = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['name' => 'Never Key', 'expiry_preset' => 'never']),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $never->getStatusCode());
		$this->assertTrue($this->decode($never)['never_expires']);

		$absolute = $this->controller()->create_(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['name' => 'Absolute Key', 'expires_at' => time() + 7 * 86400]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $absolute->getStatusCode());
		$this->assertFalse($this->decode($absolute)['never_expires']);

		// admin without a verified email is not blocked (isAdmin bypasses the gate)
		$admin = $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
		$adminOk = $this->controller()->create_(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(['name' => 'Admin Key']),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $adminOk->getStatusCode());
	}

	#[Test]
	#[TestDox('create_ 403s a user with no email on file')]
	#[Group('mantle2/api_keys')]
	public function createNoEmail(): void
	{
		$noEmail = $this->createUser(['field_email_verified' => true, 'mail' => '']);
		$res = $this->controller()->create_(
			$this->authRequest(
				$noEmail,
				'POST',
				'/v2/users/current/api-keys',
				[],
				$this->createBody(),
			),
		);
		// email-verified but empty mail -> issue() returns no_email -> mapped to 403
		$this->assertSame(Response::HTTP_FORBIDDEN, $res->getStatusCode());
		$this->assertStringContainsString('email address', $this->decode($res)['message']);
	}

	#[Test]
	#[TestDox('PATCH validates description length and empty scopes')]
	#[Group('mantle2/api_keys')]
	public function patchMoreValidation(): void
	{
		$user = $this->member();
		$issued = ApiKeysHelper::issue(
			$user,
			'Patch Target',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$keyId = $issued['key']->getKeyId();

		$badDesc = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				json_encode(['description' => str_repeat('d', ApiKey::DESCRIPTION_MAX + 1)]),
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badDesc->getStatusCode());
		$this->assertStringContainsString('Description', $this->decode($badDesc)['message']);

		$emptyScopes = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				'{"scopes":[]}',
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $emptyScopes->getStatusCode());

		$badScopesType = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				'{"scopes":"nope"}',
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badScopesType->getStatusCode());
		$this->assertSame(
			'scopes must be an array of strings',
			$this->decode($badScopesType)['message'],
		);

		$badJson = $this->controller()->patch(
			$this->authRequest(
				$user,
				'PATCH',
				'/v2/users/current/api-keys/' . $keyId,
				[],
				'not json',
			),
			$keyId,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());
		$this->assertSame('Invalid JSON body', $this->decode($badJson)['message']);
	}
}
