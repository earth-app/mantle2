<?php

namespace Drupal\Tests\mantle2\Integration\Plugin;

use Drupal\Core\Form\FormState;
use Drupal\mantle2\Plugin\OpenIDConnectClient\Discord;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;

class DiscordClientTest extends IntegrationTestBase
{
	private function makeClient(array $queue): Discord
	{
		$mock = new MockHandler($queue);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);

		$ref = new ReflectionClass(Discord::class);
		$client = $ref->newInstanceWithoutConstructor();

		$http = $ref->getProperty('httpClient');
		$http->setValue($client, $httpClient);

		$log = $ref->getProperty('loggerFactory');
		$log->setValue($client, $this->container->get('logger.factory'));

		$id = $ref->getProperty('pluginId');
		$id->setValue($client, 'discord');

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
	#[TestDox('getEndpoints returns the Discord authorization, token, and userinfo URLs')]
	#[Group('mantle2/oauth')]
	public function endpoints(): void
	{
		$endpoints = $this->makeClient([])->getEndpoints();
		$this->assertSame('https://discord.com/api/oauth2/authorize', $endpoints['authorization']);
		$this->assertSame('https://discord.com/api/oauth2/token', $endpoints['token']);
		$this->assertSame('https://discord.com/api/users/@me', $endpoints['userinfo']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo maps Discord user fields and builds the avatar URL')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoMapsWithAvatar(): void
	{
		$payload = [
			'id' => '99',
			'username' => 'grace',
			'global_name' => 'Grace H',
			'email' => 'grace@example.com',
			'verified' => true,
			'avatar' => 'abcdef',
		];
		$client = $this->makeClient([new Response(200, [], json_encode($payload))]);

		$info = $client->retrieveUserInfo('access-token');
		$this->assertSame('99', $info['sub']);
		$this->assertSame('99', $info['id']);
		$this->assertSame('grace', $info['name']);
		$this->assertSame('Grace H', $info['given_name']);
		$this->assertSame('grace@example.com', $info['email']);
		$this->assertTrue($info['email_verified']);
		$this->assertSame('https://cdn.discordapp.com/avatars/99/abcdef.png', $info['picture']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo falls back to username for given_name and null avatar')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoNoAvatarNoGlobalName(): void
	{
		$payload = [
			'id' => '100',
			'username' => 'bob',
		];
		$client = $this->makeClient([new Response(200, [], json_encode($payload))]);

		$info = $client->retrieveUserInfo('access-token');
		$this->assertSame('100', $info['sub']);
		$this->assertSame('bob', $info['given_name']);
		$this->assertNull($info['email']);
		$this->assertFalse($info['email_verified']);
		$this->assertNull($info['picture']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo returns null when the userinfo request fails')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoOnError(): void
	{
		$client = $this->makeClient([new Response(401, [], 'unauthorized')]);
		$this->assertNull($client->retrieveUserInfo('access-token'));
	}

	#[Test]
	#[TestDox('buildConfigurationForm adds the Discord endpoint fields with their defaults')]
	#[Group('mantle2/oauth')]
	public function buildConfigurationForm(): void
	{
		$form = $this->makeClient([])->buildConfigurationForm([], new FormState());
		$this->assertArrayHasKey('authorization_endpoint', $form);
		$this->assertArrayHasKey('token_endpoint', $form);
		$this->assertArrayHasKey('userinfo_endpoint', $form);
		$this->assertSame(
			'https://discord.com/api/oauth2/authorize',
			$form['authorization_endpoint']['#default_value'],
		);
		$this->assertSame(
			'https://discord.com/api/users/@me',
			$form['userinfo_endpoint']['#default_value'],
		);
	}
}
