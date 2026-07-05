<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\GeneralController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GeneralControllerTest extends IntegrationTestBase
{
	private function controller(): GeneralController
	{
		return GeneralController::create($this->container);
	}

	private function admin(): \Drupal\user\UserInterface
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
	#[TestDox('GET /v2 returns plaintext hello')]
	#[Group('mantle2/general')]
	public function hi(): void
	{
		$response = $this->controller()->hi();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame('Hello!', $response->getContent());
	}

	#[Test]
	#[TestDox('GET /v2/info returns module metadata')]
	#[Group('mantle2/general')]
	public function getInfo(): void
	{
		$response = $this->controller()->getInfo();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame('mantle2', $body['name']);
		$this->assertSame('The drupal backend for The Earth App', $body['description']);
		$this->assertSame('active', $body['status']);
	}

	#[Test]
	#[TestDox('GET /v2/motd returns 404 when unset and the stored motd once set')]
	#[Group('mantle2/general')]
	public function getMotd(): void
	{
		$missing = $this->controller()->getMotd();
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
		$this->assertSame('No MOTD set', $this->decode($missing)['message']);

		RedisHelper::set(
			'motd',
			[
				'motd' => 'Hello world',
				'icon' => 'mdi:star',
				'type' => 'warning',
				'link' => 'https://x',
			],
			3600,
		);

		$present = $this->controller()->getMotd();
		$this->assertSame(Response::HTTP_OK, $present->getStatusCode());
		$body = $this->decode($present);
		$this->assertSame('Hello world', $body['motd']);
		$this->assertSame('mdi:star', $body['icon']);
		$this->assertSame('warning', $body['type']);
		$this->assertSame('https://x', $body['link']);
		$this->assertIsInt($body['ttl']);
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/motd requires auth, rejects non-admins, validates body, and persists for admins',
		),
	]
	#[Group('mantle2/general')]
	public function setMotd(): void
	{
		$anon = $this->controller()->setMotd($this->request('POST', '/v2/motd', [], '{}'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$normal = $this->createUser();
		$forbidden = $this->controller()->setMotd(
			$this->authRequest($normal, 'POST', '/v2/motd', [], '{"motd":"hi"}'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
		$this->assertSame('Only admins can set the MOTD', $this->decode($forbidden)['message']);

		$admin = $this->admin();
		$missing = $this->controller()->setMotd(
			$this->authRequest($admin, 'POST', '/v2/motd', [], '{}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());
		$this->assertSame('MOTD is required', $this->decode($missing)['message']);

		$ok = $this->controller()->setMotd(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/motd',
				[],
				'{"motd":"Server news","ttl":120,"icon":"mdi:bell","type":"error","link":"https://a"}',
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('Server news', $body['motd']);
		$this->assertSame(120, $body['ttl']);
		$this->assertSame('mdi:bell', $body['icon']);
		$this->assertSame('error', $body['type']);

		$stored = RedisHelper::get('motd');
		$this->assertSame('Server news', $stored['motd']);
		$this->assertSame('https://a', $stored['link']);
		$setBy = RedisHelper::get('motd_set_by');
		$this->assertSame((int) $admin->id(), (int) $setBy['value']);
	}

	#[Test]
	#[TestDox('GET /v2/motd fills icon/type/link defaults when they are absent')]
	#[Group('mantle2/general')]
	public function getMotdDefaults(): void
	{
		RedisHelper::set('motd', ['motd' => 'Bare message'], 3600);

		$response = $this->controller()->getMotd();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame('Bare message', $body['motd']);
		$this->assertSame('mdi:earth', $body['icon']);
		$this->assertSame('info', $body['type']);
		$this->assertNull($body['link']);
	}

	#[Test]
	#[TestDox('POST /v2/motd applies icon/type/link defaults when omitted')]
	#[Group('mantle2/general')]
	public function setMotdDefaults(): void
	{
		$admin = $this->admin();
		$ok = $this->controller()->setMotd(
			$this->authRequest($admin, 'POST', '/v2/motd', [], '{"motd":"Minimal"}'),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('Minimal', $body['motd']);
		$this->assertSame(86400, $body['ttl']);
		$this->assertSame('mdi:earth', $body['icon']);
		$this->assertSame('info', $body['type']);

		$stored = RedisHelper::get('motd');
		$this->assertNull($stored['link']);
	}
}
