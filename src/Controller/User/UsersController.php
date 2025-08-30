<?php

namespace Drupal\mantle2\Controller\User;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\migrate\Plugin\migrate\process\Get;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UsersController extends ControllerBase
{
	public static function create(ContainerInterface $container)
	{
		$instance = new static();
		return $instance;
	}

	// GET /v2/users
	public function users(Request $request): JsonResponse
	{
		$requester = UsersHelper::getOwnerOfRequest($request);
		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$storage = \Drupal::entityTypeManager()->getStorage('user');
		$query = $storage->getQuery()->accessCheck(false)->condition('uid', 0, '!='); // Exclude anonymous user

		if ($search) {
			$group = $query
				->orConditionGroup()
				->condition('name', $search, 'CONTAINS')
				->condition('field_first_name', $search, 'CONTAINS')
				->condition('field_last_name', $search, 'CONTAINS');
			$query->condition($group);
		}

		$countQuery = clone $query;
		$total = $countQuery->count()->execute();
		$uids = $query
			->range($page * $limit, $limit)
			->sort('created', 'DESC')
			->execute();
		$users = array_filter($storage->loadMultiple($uids), function ($user) use ($request) {
			$res = UsersHelper::checkVisibility($user, $request);
			if ($res instanceof JsonResponse) {
				return false;
			}
			return true;
		});
		$data = array_map(fn($user) => UsersHelper::serializeUser($user, $requester), $users);
		return new JsonResponse([
			'page' => $page + 1,
			'total' => $total,
			'limit' => $limit,
			'items' => $data,
		]);
	}

	// POST /v2/users/login (Basic auth expected)
	public function login(Request $request): JsonResponse
	{
		$auth = $request->headers->get('Authorization');
		if (!$auth || stripos($auth, 'Basic ') !== 0) {
			return GeneralHelper::unauthorized();
		}
		$decoded = base64_decode(substr($auth, 6) ?: '', true);
		if (!$decoded || !str_contains($decoded, ':')) {
			return GeneralHelper::unauthorized();
		}
		[$name, $pass] = explode(':', $decoded, 2);
		if (!$name || !$pass) {
			return GeneralHelper::badRequest('Invalid login credentials');
		}

		/** @var UserAuthInterface $userAuth */
		$userAuth = \Drupal::service('user.auth');
		$uid = $userAuth->authenticate($name, $pass);
		if (!$uid) {
			return GeneralHelper::unauthorized();
		}

		/** @var UserInterface $account */
		$account = User::load($uid);
		if (!$account || $account->isBlocked()) {
			return GeneralHelper::unauthorized();
		}

		// Log the user in and migrate session.
		$this->finalizeLogin($account);

		// Use Drupal session ID as session_token per requirements.
		$session = \Drupal::service('session');
		$session_id = method_exists($session, 'getId')
			? $session->getId()
			: ($request->getSession()
				? $request->getSession()->getId()
				: null);

		$data = [
			'id' => UsersHelper::formatId($account->id()),
			'username' => $account->getAccountName(),
			'session_token' => $session_id,
		];
		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/users/logout
	public function logout(Request $request): JsonResponse
	{
		$sessionId = GeneralHelper::getBearerToken($request);
		if (!$sessionId) {
			return GeneralHelper::unauthorized();
		}

		$payloadUser = UsersHelper::withSessionId($sessionId, function ($session) {
			$uid = $session->get('uid');
			if (!$uid) {
				return null;
			}
			$u = User::load($uid);
			return $u ? UsersHelper::serializeUser($u) : null;
		});

		// Destroy that session.
		UsersHelper::withSessionId($sessionId, function ($session) {
			\Drupal::service('session_manager')->destroy();
			return null;
		});

		$data = [
			'message' => 'Logout successful',
			'session_token' => $sessionId,
		];
		if ($payloadUser) {
			$data['user'] = $payloadUser;
		}
		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/users/create
	public function createUser(Request $request): JsonResponse
	{
		$body = json_decode((string) $request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}
		$username = $body['username'] ?? null;
		$password = $body['password'] ?? null;
		$email = $body['email'] ?? null;
		if (!$username || !$password) {
			return GeneralHelper::badRequest('username and password are required');
		}

		if (UsersHelper::findByUsername($username) !== null) {
			return GeneralHelper::badRequest('Username already exists');
		}
		if ($email && UsersHelper::findByEmail($email) !== null) {
			return GeneralHelper::badRequest('Email already in use');
		}

		$user = User::create();
		$user->setUsername($username);
		if ($email) {
			$user->setEmail($email);
		}
		$user->activate();
		$user->setPassword($password);
		$user->enforceIsNew();
		$user->save();

		// Immediately log user in and return session token
		$this->finalizeLogin($user);
		$session = \Drupal::service('session');
		$session_id = method_exists($session, 'getId')
			? $session->getId()
			: ($request->getSession()
				? $request->getSession()->getId()
				: null);

		$data = [
			'user' => UsersHelper::serializeUser($user),
			'session_token' => $session_id,
		];
		return new JsonResponse($data, Response::HTTP_CREATED);
	}

	// GET /v2/users/current
	public function currentGet(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		return new JsonResponse(UsersHelper::serializeUser($user), Response::HTTP_OK);
	}

	// PATCH /v2/users/current
	public function currentPatch(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchUser($user, $body);
	}

	// DELETE /v2/users/current
	public function currentDelete(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$user->delete();

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// PATCH /v2/users/current/field_privacy
	public function currentFieldPrivacy(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchFieldPrivacy($user, $body);
	}

	// GET /v2/users/:id
	// GET /v2/users/:username
	public function getUser(string $identifier, Request $request)
	{
		$user = UsersHelper::findBy($identifier);
		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$user = UsersHelper::checkVisibility($user, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		return UsersHelper::serializeUser($user);
	}

	// PATCH /v2/users/:id
	// PATCH /v2/users/:username
	public function patchUser(string $identifier, Request $request): JsonResponse
	{
		$user = UsersHelper::findByAuthorized($identifier, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchUser($user, $body);
	}

	// DELETE /v2/users/:id
	// DELETE /v2/users/:username
	public function deleteUser(string $identifier, Request $request): JsonResponse
	{
		$user = UsersHelper::findByAuthorized($identifier, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$user->delete();

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// PATCH /v2/users/:id/field_privacy
	// PATCH /v2/users/:username/field_privacy
	public function patchFieldPrivacy(string $identifier, Request $request): JsonResponse
	{
		$user = UsersHelper::findByAuthorized($identifier, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchFieldPrivacy($user, $body);
	}

	// Utility Functions

	private function finalizeLogin(UserInterface $account): void
	{
		\Drupal::currentUser()->setAccount($account);
		\Drupal::logger('user')->info('Session opened for %name.', [
			'%name' => $account->getAccountName(),
		]);
		$account->setLastLoginTime(\Drupal::time()->getRequestTime());
		// Persist the last login time; storage-specific optimization omitted to avoid static analysis issues.
		$account->save();
		$session = \Drupal::service('session');
		if (method_exists($session, 'migrate')) {
			$session->migrate();
		}
		$session->set('uid', $account->id());
		$session->set('check_logged_in', true);
		\Drupal::moduleHandler()->invokeAll('user_login', [$account]);
	}
}
