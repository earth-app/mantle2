<?php

namespace Drupal\Tests\mantle2\Integration\Plugin;

use Drupal\Core\Form\FormState;
use Drupal\mantle2\Plugin\OpenIDConnectClient\Microsoft;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;

class MicrosoftClientTest extends IntegrationTestBase
{
	// builds a Microsoft plugin with a mocked guzzle client and the container logger,
	// bypassing the heavy openid_connect constructor via reflection
	private function makeClient(array $queue): Microsoft
	{
		$mock = new MockHandler($queue);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);

		$ref = new ReflectionClass(Microsoft::class);
		$client = $ref->newInstanceWithoutConstructor();

		$http = $ref->getProperty('httpClient');
		$http->setValue($client, $httpClient);

		$log = $ref->getProperty('loggerFactory');
		$log->setValue($client, $this->container->get('logger.factory'));

		$id = $ref->getProperty('pluginId');
		$id->setValue($client, 'microsoft');

		$config = $ref->getProperty('configuration');
		$config->setValue($client, [
			'client_id' => 'cid',
			'client_secret' => 'secret',
			'iss_allowed_domains' => '',
			'prompt' => [],
		]);

		return $client;
	}

	#[Test]
	#[TestDox('getEndpoints returns the Microsoft authorization, token, and userinfo URLs')]
	#[Group('mantle2/oauth')]
	public function endpoints(): void
	{
		$endpoints = $this->makeClient([])->getEndpoints();
		$this->assertSame(
			'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
			$endpoints['authorization'],
		);
		$this->assertSame(
			'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			$endpoints['token'],
		);
		$this->assertSame('https://graph.microsoft.com/oidc/userinfo', $endpoints['userinfo']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo maps a Microsoft Graph userinfo payload to the standard claims')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoMaps(): void
	{
		$payload = [
			'sub' => 'ms-1',
			'email' => 'grace@example.com',
			'name' => 'Grace Hopper',
			'given_name' => 'Grace',
			'family_name' => 'Hopper',
			'email_verified' => true,
			'picture' => 'https://img/p.png',
		];
		$client = $this->makeClient([new Response(200, [], json_encode($payload))]);

		$info = $client->retrieveUserInfo('access-token');
		$this->assertSame('ms-1', $info['sub']);
		$this->assertSame('grace@example.com', $info['email']);
		$this->assertSame('Grace Hopper', $info['name']);
		$this->assertSame('Grace', $info['given_name']);
		$this->assertSame('Hopper', $info['family_name']);
		$this->assertTrue($info['email_verified']);
		$this->assertSame('https://img/p.png', $info['picture']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo defaults missing claims and treats email_verified as false')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoDefaults(): void
	{
		$client = $this->makeClient([new Response(200, [], json_encode(['sub' => 'ms-2']))]);

		$info = $client->retrieveUserInfo('access-token');
		$this->assertSame('ms-2', $info['sub']);
		$this->assertNull($info['email']);
		$this->assertNull($info['name']);
		$this->assertFalse($info['email_verified']);
		$this->assertNull($info['picture']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo returns null when the userinfo request fails')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoOnError(): void
	{
		$client = $this->makeClient([new Response(500, [], 'boom')]);
		$this->assertNull($client->retrieveUserInfo('access-token'));
	}

	#[Test]
	#[TestDox('buildConfigurationForm adds the Microsoft endpoint fields with their defaults')]
	#[Group('mantle2/oauth')]
	public function buildConfigurationForm(): void
	{
		$form = $this->makeClient([])->buildConfigurationForm([], new FormState());
		$this->assertArrayHasKey('authorization_endpoint', $form);
		$this->assertArrayHasKey('token_endpoint', $form);
		$this->assertArrayHasKey('userinfo_endpoint', $form);
		$this->assertSame(
			'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
			$form['authorization_endpoint']['#default_value'],
		);
		$this->assertSame(
			'https://graph.microsoft.com/oidc/userinfo',
			$form['userinfo_endpoint']['#default_value'],
		);
	}
}
