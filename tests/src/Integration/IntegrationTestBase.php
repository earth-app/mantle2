<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
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

		$this->installEntitySchema('user');
		$this->installEntitySchema('node');
		$this->installEntitySchema('comment');
		$this->installSchema('mantle2', ['push_tokens', 'mantle2_api_keys']);
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
}
