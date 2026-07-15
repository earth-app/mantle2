<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\SubscriptionsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class IntegrationTestBase extends KernelTestBase
{
	// API/kernel tests never render config forms; skipping per-save config-schema
	protected $strictConfigSchema = false;

	// disable node content types (activity/event/prompt/article) + comment system installation
	protected bool $installContentTypes = false;

	protected static $modules = [
		'system',
		'user',
		'field',
		'text',
		'options',
		'datetime',
		'node',
		'comment',
		'json_field',
		'key',
		'mantle2',
	];

	protected function setUp(): void
	{
		parent::setUp();

		RedisHelper::reset();
		// isolate tests from any real data/subscriptions.yml on the box running the suite
		SubscriptionsHelper::setDataConfigOverride([]);

		$this->installEntitySchema('user');
		$this->installEntitySchema('node');
		$this->installEntitySchema('comment');
		$this->installSchema('mantle2', ['push_tokens', 'mantle2_api_keys']);
		$this->installSubscriptionTables();
		// node save/delete needs node_access; comment save needs comment_entity_statistics
		$this->installSchema('node', ['node_access']);
		$this->installSchema('comment', ['comment_entity_statistics']);
		$this->installConfig(['field', 'user']);

		// integration tier never talks to a real cloud; a dead endpoint makes every
		// CloudHelper::sendRequest degrade to [] instead of throwing (E2E overrides this)
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');

		// capture mail instead of sending it
		$this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();

		// user fields are always needed; content types are installed only when a test
		// opts in (see $installContentTypes) since they are the expensive half of install
		$this->container->get('module_handler')->loadInclude('mantle2', 'install');
		mantle2_install_user_fields();
		if ($this->installContentTypes) {
			mantle2_install_content();
		}

		// burn uid 0 (anonymous) + uid 1 (root superuser) so createUser() yields
		// unprivileged users; uid 1 bypasses every permission check in drupal
		$this->reserveSystemUsers();

		$this->setAdminKey('test_admin_key');
	}

	private function reserveSystemUsers(): void
	{
		$storage = $this->container->get('entity_type.manager')->getStorage('user');
		foreach (
			[
				['uid' => 0, 'name' => '', 'status' => 0],
				['uid' => 1, 'name' => 'root', 'status' => 1],
			]
			as $values
		) {
			if ($storage->load($values['uid'])) {
				continue;
			}
			$user = $storage->create($values);
			$user->enforceIsNew();
			$user->save();
		}
	}

	// stores the shared admin api key that CloudHelper + token auth check against
	protected function setAdminKey(string $value): void
	{
		$this->config('key.key.mantle2_api_key')->delete();
		$storage = $this->container->get('entity_type.manager')->getStorage('key');
		$existing = $storage->load('mantle2_api_key');
		if ($existing) {
			$existing->delete();
		}
		$storage
			->create([
				'id' => 'mantle2_api_key',
				'label' => 'Mantle2 API Key',
				'key_type' => 'authentication',
				'key_provider' => 'config',
				'key_provider_settings' => ['key_value' => $value],
			])
			->save();
	}

	// creates a persisted user with mantle2 fields defaulted
	protected function createUser(array $values = []): UserInterface
	{
		$suffix = bin2hex(random_bytes(4));
		$user = User::create(
			[
				'name' => $values['name'] ?? 'user_' . $suffix,
				'mail' => $values['mail'] ?? $suffix . '@example.com',
				'status' => 1,
			] + $values,
		);
		$user->save();
		return $user;
	}

	// mints a session token for the user and returns an authenticated request
	protected function authRequest(
		UserInterface $user,
		string $method = 'GET',
		string $uri = '/',
		array $server = [],
		?string $content = null,
	): Request {
		$token = UsersHelper::issueToken($user);
		$request = Request::create($uri, $method, [], [], [], $server, $content);
		$request->headers->set('Authorization', 'Bearer ' . $token);
		return $request;
	}

	// builds an anonymous request (no auth header)
	protected function request(
		string $method = 'GET',
		string $uri = '/',
		array $server = [],
		?string $content = null,
	): Request {
		return Request::create($uri, $method, [], [], [], $server, $content);
	}

	// switches the acting Drupal current_user (for permission-sensitive paths)
	protected function actAs(AccountInterface $user): void
	{
		$this->container->get('current_user')->setAccount($user);
	}

	protected function decode(JsonResponse $response): array
	{
		return json_decode($response->getContent(), true) ?? [];
	}

	#region Subscriptions Test Support

	// must match the value Mocks::mockDrupalContainer returns for mantle2_stripe_webhook_secret
	protected const STRIPE_WEBHOOK_SECRET = 'whsec_test';
	protected const STRIPE_SECRET_KEY = 'sk_test_x';

	// creates the three subscription tables using the DDL from the frozen contract; guarded so
	// it is a no-op if a future mantle2_schema() already created them under installSchema
	protected function installSubscriptionTables(): void
	{
		$schema = $this->container->get('database')->schema();

		if (!$schema->tableExists('mantle2_subscriptions')) {
			$schema->createTable('mantle2_subscriptions', [
				'description' => 'One billing subscription row per user.',
				'fields' => [
					'user_id' => ['type' => 'int', 'not null' => true],
					'provider' => ['type' => 'varchar', 'length' => 16, 'not null' => true],
					'external_customer_id' => [
						'type' => 'varchar',
						'length' => 255,
						'not null' => false,
					],
					'external_subscription_id' => [
						'type' => 'varchar',
						'length' => 255,
						'not null' => false,
					],
					'tier' => ['type' => 'varchar', 'length' => 16, 'not null' => true],
					'status' => ['type' => 'varchar', 'length' => 16, 'not null' => true],
					'current_period_end' => ['type' => 'int', 'not null' => false],
					'cancel_at_period_end' => [
						'type' => 'int',
						'size' => 'tiny',
						'not null' => true,
						'default' => 0,
					],
					'consent_at' => ['type' => 'int', 'not null' => false],
					'price_cents' => ['type' => 'int', 'not null' => true, 'default' => 0],
					'started_at' => ['type' => 'int', 'not null' => false],
					'created' => ['type' => 'int', 'not null' => true],
					'updated' => ['type' => 'int', 'not null' => true],
				],
				'primary key' => ['user_id'],
				'indexes' => [
					'provider' => ['provider'],
					'status' => ['status'],
					'external_subscription_id' => ['external_subscription_id'],
				],
			]);
		}

		if (!$schema->tableExists('mantle2_trial_codes')) {
			$schema->createTable('mantle2_trial_codes', [
				'description' => 'Redeemable trial codes.',
				'fields' => [
					'id' => ['type' => 'serial', 'unsigned' => true, 'not null' => true],
					'code' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
					'tier' => ['type' => 'varchar', 'length' => 16, 'not null' => true],
					'days' => ['type' => 'int', 'not null' => true],
					'max_redemptions' => ['type' => 'int', 'not null' => true, 'default' => 0],
					'redemptions' => ['type' => 'int', 'not null' => true, 'default' => 0],
					'expires_at' => ['type' => 'int', 'not null' => false],
					'active' => [
						'type' => 'int',
						'size' => 'tiny',
						'not null' => true,
						'default' => 1,
					],
					'created_by' => ['type' => 'int', 'not null' => true],
					'created' => ['type' => 'int', 'not null' => true],
				],
				'primary key' => ['id'],
				'unique keys' => ['code' => ['code']],
				'indexes' => ['active' => ['active']],
			]);
		}

		if (!$schema->tableExists('mantle2_trial_code_redemptions')) {
			$schema->createTable('mantle2_trial_code_redemptions', [
				'description' => 'One row per (code, user) redemption.',
				'fields' => [
					'id' => ['type' => 'serial', 'unsigned' => true, 'not null' => true],
					'code' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
					'user_id' => ['type' => 'int', 'not null' => true],
					'redeemed_at' => ['type' => 'int', 'not null' => true],
					'tier' => ['type' => 'varchar', 'length' => 16, 'not null' => false],
					'expires_at' => ['type' => 'int', 'not null' => false],
				],
				'primary key' => ['id'],
				'unique keys' => ['code_user' => ['code', 'user_id']],
				'indexes' => ['user_id' => ['user_id']],
			]);
		}
	}

	// inserts a subscription row with sane defaults; overrides win
	protected function seedSubscription(int $uid, array $fields = []): void
	{
		$now = \Drupal::time()->getCurrentTime();
		$row = array_merge(
			[
				'user_id' => $uid,
				'provider' => 'stripe',
				'external_customer_id' => 'cus_test_' . $uid,
				'external_subscription_id' => 'sub_test_' . $uid,
				'tier' => 'pro',
				'status' => 'active',
				'current_period_end' => $now + 30 * 86400,
				'cancel_at_period_end' => 0,
				'consent_at' => $now,
				'price_cents' => 599,
				'started_at' => $now,
				'created' => $now,
				'updated' => $now,
			],
			$fields,
		);
		\Drupal::database()->insert('mantle2_subscriptions')->fields($row)->execute();
	}

	// reads a subscription row as an assoc array (or null)
	protected function subscriptionRow(int $uid): ?array
	{
		$row = \Drupal::database()
			->select('mantle2_subscriptions', 's')
			->fields('s')
			->condition('user_id', $uid)
			->execute()
			->fetchAssoc();
		return $row ?: null;
	}

	// seeds a key.repository entity (mirror FCMHelperTest::seedFcmKey) for stripe secrets
	protected function seedKey(string $id, string $value): void
	{
		$storage = $this->container->get('entity_type.manager')->getStorage('key');
		$existing = $storage->load($id);
		if ($existing) {
			$existing->delete();
		}
		$storage
			->create([
				'id' => $id,
				'label' => $id,
				'key_type' => 'authentication',
				'key_provider' => 'config',
				'key_provider_settings' => ['key_value' => $value],
			])
			->save();
	}

	// wires the stripe secrets + price ids so SubscriptionsHelper is "configured"
	protected function configureStripe(): void
	{
		$this->seedKey('mantle2_stripe_secret_key', self::STRIPE_SECRET_KEY);
		$this->seedKey('mantle2_stripe_webhook_secret', self::STRIPE_WEBHOOK_SECRET);
		$this->setSetting('mantle2.stripe_price_pro', 'price_pro_test');
		$this->setSetting('mantle2.stripe_price_writer', 'price_writer_test');
		$this->setSetting('mantle2.stripe_price_organizer', 'price_organizer_test');
	}

	// computes a Stripe-Signature header the way Stripe does (HMAC-SHA256 over "t.payload")
	protected function signStripePayload(
		string $body,
		?int $timestamp = null,
		?string $secret = null,
	): string {
		$timestamp ??= time();
		$secret ??= self::STRIPE_WEBHOOK_SECRET;
		$signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
		return 't=' . $timestamp . ',v1=' . $signature;
	}

	// programmable fake Stripe client (instanceof StripeClient so setClientOverride accepts it)
	protected function newFakeStripe(): FakeStripeClient
	{
		return new FakeStripeClient();
	}

	#endregion
}

/**
 * Test double for \Stripe\StripeClient. Navigates any service path (e.g.
 * checkout->sessions->create) and returns canned responses registered with on(),
 * or throws a registered Throwable. Records every call for assertions.
 */
class FakeStripeClient extends StripeClient
{
	/** @var array<string,mixed> path => object|callable|Throwable */
	public array $responses = [];

	/** @var array<int,array{path:string,args:array}> */
	public array $calls = [];

	public $defaultResponse;

	public function __construct()
	{
		parent::__construct(['api_key' => 'sk_test_fake']);
		// generic object so unexpected property reads return a value, never fatal
		$this->defaultResponse = StripeObject::constructFrom([
			'id' => 'obj_default',
			'status' => 'succeeded',
			'url' => 'https://example.test/default',
		]);
	}

	public function __get($name)
	{
		return new FakeStripeNode($this, $name);
	}

	// register a response (Stripe object, callable(args):mixed, or Throwable) for a dotted path
	public function on(string $path, $response): self
	{
		$this->responses[$path] = $response;
		return $this;
	}

	public function resolve(string $path, array $args): mixed
	{
		$this->calls[] = ['path' => $path, 'args' => $args];
		if (!array_key_exists($path, $this->responses)) {
			return $this->defaultResponse;
		}
		$response = $this->responses[$path];
		if ($response instanceof \Throwable) {
			throw $response;
		}
		if (is_callable($response)) {
			return $response($args);
		}
		return $response;
	}

	public function calledPaths(): array
	{
		return array_column($this->calls, 'path');
	}
}

/** Lazy path-builder node for FakeStripeClient (checkout->sessions->create(...)). */
class FakeStripeNode
{
	public function __construct(private FakeStripeClient $client, private string $path) {}

	public function __get($name)
	{
		return new FakeStripeNode($this->client, $this->path . '.' . $name);
	}

	public function __call($method, $args)
	{
		// pass the full call-args list (first arg may be an id string, not an array)
		return $this->client->resolve($this->path . '.' . $method, $args);
	}
}
