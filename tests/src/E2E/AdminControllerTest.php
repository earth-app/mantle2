<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Controller\AdminController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class AdminControllerTest extends E2ETestBase
{
	private function controller(): AdminController
	{
		return new AdminController();
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

	#[Test]
	#[TestDox('GET /v2/admin/blacklist gates anon 401, non-admin 403, admin 200')]
	#[Group('mantle2/admin')]
	public function listBlacklistGating(): void
	{
		$anon = $this->controller()->listBlacklist($this->request('GET', '/v2/admin/blacklist'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$normal = $this->controller()->listBlacklist(
			$this->authRequest($this->createUser(), 'GET', '/v2/admin/blacklist'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $normal->getStatusCode());

		$admin = $this->controller()->listBlacklist(
			$this->authRequest($this->admin(), 'GET', '/v2/admin/blacklist'),
		);
		$this->assertSame(Response::HTTP_OK, $admin->getStatusCode());
		$this->assertArrayHasKey('entries', $this->decode($admin));
	}

	#[Test]
	#[TestDox('POST then DELETE /v2/admin/blacklist round-trips an entry through cloud')]
	#[Group('mantle2/admin')]
	public function addAndRemoveBlacklistEntry(): void
	{
		$value = 'e2e_' . bin2hex(random_bytes(6)) . '@example.com';

		$add = $this->controller()->addBlacklist(
			$this->authRequest(
				$this->admin(),
				'POST',
				'/v2/admin/blacklist',
				[],
				json_encode(['kind' => 'email', 'value' => $value, 'reason' => 'e2e']),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $add->getStatusCode());
		$this->assertIsArray($this->decode($add));

		$remove = $this->controller()->removeBlacklist(
			$this->authRequest(
				$this->admin(),
				'DELETE',
				'/v2/admin/blacklist?kind=email&value=' . urlencode($value),
			),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $remove->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/admin/blacklist validates kind and value')]
	#[Group('mantle2/admin')]
	public function addBlacklistValidation(): void
	{
		$badJson = $this->controller()->addBlacklist(
			$this->authRequest($this->admin(), 'POST', '/v2/admin/blacklist', [], 'not json'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());

		$badKind = $this->controller()->addBlacklist(
			$this->authRequest(
				$this->admin(),
				'POST',
				'/v2/admin/blacklist',
				[],
				json_encode(['kind' => 'nope', 'value' => 'x']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badKind->getStatusCode());

		$emptyValue = $this->controller()->addBlacklist(
			$this->authRequest(
				$this->admin(),
				'POST',
				'/v2/admin/blacklist',
				[],
				json_encode(['kind' => 'email', 'value' => '']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $emptyValue->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE /v2/admin/blacklist validates kind and value')]
	#[Group('mantle2/admin')]
	public function removeBlacklistValidation(): void
	{
		$badKind = $this->controller()->removeBlacklist(
			$this->authRequest($this->admin(), 'DELETE', '/v2/admin/blacklist?kind=nope&value=x'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badKind->getStatusCode());

		$missingValue = $this->controller()->removeBlacklist(
			$this->authRequest($this->admin(), 'DELETE', '/v2/admin/blacklist?kind=email'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missingValue->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/admin/analytics returns the funnel snapshot for admins only')]
	#[Group('mantle2/admin')]
	public function analytics(): void
	{
		$normal = $this->controller()->analytics(
			$this->authRequest($this->createUser(), 'GET', '/v2/admin/analytics'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $normal->getStatusCode());

		$admin = $this->controller()->analytics(
			$this->authRequest($this->admin(), 'GET', '/v2/admin/analytics'),
		);
		$this->assertSame(Response::HTTP_OK, $admin->getStatusCode());
		$body = $this->decode($admin);
		$this->assertArrayHasKey('signup_funnel', $body);
	}
}
