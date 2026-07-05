<?php

namespace Drupal\Tests\mantle2\Integration\Plugin;

use Drupal\Core\Form\FormState;
use Drupal\mantle2\Plugin\OpenIDConnectClient\Apple;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use ReflectionMethod;

class AppleClientTest extends IntegrationTestBase
{
	private const KID = 'test-key-1';
	private const ISSUER = 'https://appleid.apple.com';
	private const JWKS_CACHE_KEY = 'oauth:apple:jwks';

	private string $privateKeyPem;

	protected function setUp(): void
	{
		parent::setUp();
		$this->generateKeypairAndSeedJwks();
	}

	// builds an Apple plugin via reflection, wiring the container logger
	private function makeClient(): Apple
	{
		$ref = new ReflectionClass(Apple::class);
		$client = $ref->newInstanceWithoutConstructor();

		$log = $ref->getProperty('loggerFactory');
		$log->setValue($client, $this->container->get('logger.factory'));

		$id = $ref->getProperty('pluginId');
		$id->setValue($client, 'apple');

		$config = $ref->getProperty('configuration');
		$config->setValue($client, [
			'client_id' => 'cid',
			'client_secret' => 'secret',
			'iss_allowed_domains' => '',
			'prompt' => [],
		]);

		return $client;
	}

	// generates an RSA keypair, publishes its public half as a cached JWKS so
	// getAppleJwks() resolves from RedisHelper's cache fallback (no network)
	private function generateKeypairAndSeedJwks(): void
	{
		$res = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);
		$pem = '';
		openssl_pkey_export($res, $pem);
		$this->privateKeyPem = $pem;
		$details = openssl_pkey_get_details($res);

		$jwks = [
			'keys' => [
				[
					'kty' => 'RSA',
					'alg' => 'RS256',
					'use' => 'sig',
					'kid' => self::KID,
					'n' => $this->base64url($details['rsa']['n']),
					'e' => $this->base64url($details['rsa']['e']),
				],
			],
		];

		RedisHelper::set(self::JWKS_CACHE_KEY, $jwks, 3600);
	}

	private function base64url(string $bin): string
	{
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	private function signIdToken(array $claims): string
	{
		return JWT::encode($claims, $this->privateKeyPem, 'RS256', self::KID);
	}

	// retrieveUserInfo happy path

	#[Test]
	#[TestDox('getEndpoints exposes the Apple auth and token URLs and an empty userinfo endpoint')]
	#[Group('mantle2/oauth')]
	public function endpoints(): void
	{
		$endpoints = $this->makeClient()->getEndpoints();
		$this->assertSame('https://appleid.apple.com/auth/authorize', $endpoints['authorization']);
		$this->assertSame('https://appleid.apple.com/auth/token', $endpoints['token']);
		$this->assertSame('', $endpoints['userinfo']);
	}

	#[Test]
	#[TestDox('buildConfigurationForm adds the Apple auth and token endpoint fields')]
	#[Group('mantle2/oauth')]
	public function buildConfigurationForm(): void
	{
		$form = $this->makeClient()->buildConfigurationForm([], new FormState());
		$this->assertArrayHasKey('authorization_endpoint', $form);
		$this->assertArrayHasKey('token_endpoint', $form);
		$this->assertSame(
			'https://appleid.apple.com/auth/authorize',
			$form['authorization_endpoint']['#default_value'],
		);
		$this->assertSame(
			'https://appleid.apple.com/auth/token',
			$form['token_endpoint']['#default_value'],
		);
	}

	#[Test]
	#[TestDox('retrieveUserInfo verifies a well-formed id_token and maps its claims')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoVerifies(): void
	{
		$token = $this->signIdToken([
			'iss' => self::ISSUER,
			'aud' => 'com.earthapp.crust',
			'sub' => 'apple-sub-1',
			'email' => 'grace@example.com',
			'email_verified' => 'true',
			'iat' => time(),
			'exp' => time() + 300,
		]);

		$info = $this->makeClient()->retrieveUserInfo($token);
		$this->assertNotNull($info);
		$this->assertSame('apple-sub-1', $info['sub']);
		$this->assertSame('grace@example.com', $info['email']);
		$this->assertTrue($info['email_verified']);
		$this->assertNull($info['name']);
		$this->assertNull($info['given_name']);
		$this->assertNull($info['picture']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo accepts the sky audience and a boolean email_verified')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoSkyAudience(): void
	{
		$token = $this->signIdToken([
			'iss' => self::ISSUER,
			'aud' => 'com.earthapp.sky',
			'sub' => 'apple-sub-2',
			'email_verified' => true,
			'iat' => time(),
			'exp' => time() + 300,
		]);

		$info = $this->makeClient()->retrieveUserInfo($token);
		$this->assertNotNull($info);
		$this->assertSame('apple-sub-2', $info['sub']);
		$this->assertTrue($info['email_verified']);
		$this->assertNull($info['email']);
	}

	#[Test]
	#[TestDox('retrieveUserInfo accepts an audience array containing an allowed value')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoAudienceArray(): void
	{
		$token = $this->signIdToken([
			'iss' => self::ISSUER,
			'aud' => ['some.other.app', 'com.earthapp.crust'],
			'sub' => 'apple-sub-3',
			'iat' => time(),
			'exp' => time() + 300,
		]);

		$info = $this->makeClient()->retrieveUserInfo($token);
		$this->assertNotNull($info);
		$this->assertSame('apple-sub-3', $info['sub']);
	}

	// guard branches

	#[Test]
	#[TestDox('retrieveUserInfo rejects an id_token with the wrong issuer')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoRejectsIssuer(): void
	{
		$token = $this->signIdToken([
			'iss' => 'https://evil.example.com',
			'aud' => 'com.earthapp.crust',
			'sub' => 'apple-sub-4',
			'iat' => time(),
			'exp' => time() + 300,
		]);

		$this->assertNull($this->makeClient()->retrieveUserInfo($token));
	}

	#[Test]
	#[TestDox('retrieveUserInfo rejects a disallowed audience')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoRejectsAudience(): void
	{
		$token = $this->signIdToken([
			'iss' => self::ISSUER,
			'aud' => 'com.someone.else',
			'sub' => 'apple-sub-5',
			'iat' => time(),
			'exp' => time() + 300,
		]);

		$this->assertNull($this->makeClient()->retrieveUserInfo($token));
	}

	#[Test]
	#[TestDox('retrieveUserInfo rejects an id_token missing the sub claim')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoRejectsMissingSub(): void
	{
		$token = $this->signIdToken([
			'iss' => self::ISSUER,
			'aud' => 'com.earthapp.crust',
			'iat' => time(),
			'exp' => time() + 300,
		]);

		$this->assertNull($this->makeClient()->retrieveUserInfo($token));
	}

	#[Test]
	#[TestDox('retrieveUserInfo returns null when the token is not a verifiable JWT')]
	#[Group('mantle2/oauth')]
	public function retrieveUserInfoRejectsGarbage(): void
	{
		$this->assertNull($this->makeClient()->retrieveUserInfo('not.a.jwt'));
	}

	// helper units

	#[Test]
	#[TestDox('audienceAllowed accepts allowed strings and arrays, rejects everything else')]
	#[DataProvider('audienceCases')]
	#[Group('mantle2/oauth')]
	public function audienceAllowed(mixed $aud, bool $expected): void
	{
		$m = new ReflectionMethod(Apple::class, 'audienceAllowed');
		$this->assertSame($expected, $m->invoke($this->makeClient(), $aud));
	}

	public static function audienceCases(): array
	{
		return [
			'crust string' => ['com.earthapp.crust', true],
			'sky string' => ['com.earthapp.sky', true],
			'unknown string' => ['com.other', false],
			'array with allowed' => [['com.other', 'com.earthapp.sky'], true],
			'array without allowed' => [['a', 'b'], false],
			'null' => [null, false],
			'int' => [42, false],
		];
	}

	#[Test]
	#[TestDox('coerceBool normalizes bools, string true, and truthy values')]
	#[DataProvider('coerceCases')]
	#[Group('mantle2/oauth')]
	public function coerceBool(mixed $value, bool $expected): void
	{
		$m = new ReflectionMethod(Apple::class, 'coerceBool');
		$this->assertSame($expected, $m->invoke($this->makeClient(), $value));
	}

	public static function coerceCases(): array
	{
		return [
			'bool true' => [true, true],
			'bool false' => [false, false],
			'string true' => ['true', true],
			'string TRUE' => ['TRUE', true],
			'string false' => ['false', false],
			'int one' => [1, true],
			'int zero' => [0, false],
		];
	}
}
