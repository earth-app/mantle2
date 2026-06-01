<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\ApiKey;
use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\mantle2\Service\ApiKeysHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints that own the API key lifecycle. Every endpoint here is
 * session-only (see [[ApiKeysHelper::sessionOnly]]) — API keys cannot
 * manage other API keys.
 */
class ApiKeysController extends ControllerBase
{
	public static function create(ContainerInterface $container): static
	{
		return new static();
	}

	// GET /v2/api-keys/scopes
	public function scopes(Request $request): JsonResponse
	{
		// Public; describes the catalog. UI uses this to render the picker.
		return new JsonResponse([
			'scopes' => ApiKeyScope::hierarchy(),
			'leaves' => ApiKeyScope::leaves(),
			'tier_limits' => ApiKeysHelper::TIER_LIMITS,
			'expiry_presets' => array_map(
				fn(int $sec) => ['seconds' => $sec, 'days' => (int) ($sec / 86400)],
				ApiKeysHelper::EXPIRY_PRESETS,
			),
			'token' => [
				'prefix' => ApiKey::TOKEN_PREFIX,
				'total_length' => ApiKey::TOTAL_LENGTH,
				'random_hex_length' => ApiKey::RANDOM_HEX_LEN,
			],
			'name' => [
				'min' => ApiKey::NAME_MIN,
				'max' => ApiKey::NAME_MAX,
			],
			'description' => [
				'max' => ApiKey::DESCRIPTION_MAX,
			],
		]);
	}

	// GET /v2/users/current/api-keys
	public function list(Request $request): JsonResponse
	{
		$user = $this->ownSessionUser($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$keys = ApiKeysHelper::listForUser((int) $user->id());
		return new JsonResponse([
			'items' => array_map(fn(ApiKey $k) => $k->jsonSerialize(), $keys),
			'count' => count($keys),
			'max' => ApiKeysHelper::maxKeysFor($user),
			'active' => ApiKeysHelper::countActive((int) $user->id()),
		]);
	}

	// GET /v2/users/{id}/api-keys (admin only)
	public function listByUser(Request $request, string $id): JsonResponse
	{
		$caller = $this->ownSessionUser($request);
		if ($caller instanceof JsonResponse) {
			return $caller;
		}
		if (!UsersHelper::isAdmin($caller)) {
			return GeneralHelper::forbidden('Admin only');
		}

		$target = UsersHelper::findBy($id);
		if (!$target) {
			return GeneralHelper::notFound('User not found');
		}

		$keys = ApiKeysHelper::listForUser((int) $target->id());
		return new JsonResponse([
			'items' => array_map(fn(ApiKey $k) => $k->jsonSerialize(), $keys),
			'count' => count($keys),
			'max' => ApiKeysHelper::maxKeysFor($target),
			'active' => ApiKeysHelper::countActive((int) $target->id()),
		]);
	}

	// POST /v2/users/current/api-keys
	public function create_(Request $request): JsonResponse
	{
		$user = $this->ownSessionUser($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isEmailVerified($user) && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::emailVerificationRequired(
				'generate an API key',
				UsersHelper::hasEmail($user),
			);
		}

		$body = $this->jsonBody($request);
		if ($body instanceof JsonResponse) {
			return $body;
		}

		$name = isset($body['name']) ? (string) $body['name'] : '';
		$description = isset($body['description']) ? (string) $body['description'] : null;
		$scopes = $body['scopes'] ?? [];
		if (!is_array($scopes)) {
			return GeneralHelper::badRequest('scopes must be an array of strings');
		}

		// Expiration: accept a preset string, an absolute unix seconds value,
		// or null/"never". Reject combinations.
		$expiresAt = null;
		$preset = $body['expiry_preset'] ?? null;
		$expiresAtInput = $body['expires_at'] ?? null;

		if ($preset !== null && $preset !== '' && $preset !== 'never') {
			if (!is_string($preset) || !isset(ApiKeysHelper::EXPIRY_PRESETS[$preset])) {
				return GeneralHelper::badRequest(
					'Unknown expiry_preset; use one of: never, ' .
						implode(', ', array_keys(ApiKeysHelper::EXPIRY_PRESETS)),
				);
			}
			$expiresAt = time() + ApiKeysHelper::EXPIRY_PRESETS[$preset];
		} elseif ($preset === 'never') {
			$expiresAt = null;
		} elseif ($expiresAtInput !== null) {
			if (!is_int($expiresAtInput) && !ctype_digit((string) $expiresAtInput)) {
				return GeneralHelper::badRequest('expires_at must be a unix timestamp in seconds');
			}
			$expiresAt = (int) $expiresAtInput;
		}

		$result = ApiKeysHelper::issue($user, $name, $description, $scopes, $expiresAt);
		if (is_string($result)) {
			return $this->mapIssueError($result, $user);
		}

		Drupal::logger('mantle2')->notice('User %u created API key %k (%n scopes, expires %e)', [
			'%u' => $user->id(),
			'%k' => $result['key']->getKeyId(),
			'%n' => count($result['key']->getScopes()),
			'%e' => $result['key']->getExpiresAt() ?? 'never',
		]);

		// One-time return of the raw token — never persisted in plaintext,
		// never re-fetchable.
		return new JsonResponse(
			array_merge($result['key']->jsonSerialize(), [
				'token' => $result['token'],
				'warning' =>
					'Store this token now. It will not be shown again. Anyone with this token can act on your behalf within its scopes.',
			]),
			Response::HTTP_CREATED,
		);
	}

	// GET /v2/users/current/api-keys/{keyId}
	public function get(Request $request, string $keyId): JsonResponse
	{
		$user = $this->ownSessionUser($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$key = ApiKeysHelper::getByKeyId($keyId, (int) $user->id());
		if (!$key) {
			return GeneralHelper::notFound('API key not found');
		}

		return new JsonResponse($key->jsonSerialize());
	}

	// PATCH /v2/users/current/api-keys/{keyId}
	public function patch(Request $request, string $keyId): JsonResponse
	{
		$user = $this->ownSessionUser($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = $this->jsonBody($request);
		if ($body instanceof JsonResponse) {
			return $body;
		}

		$name = array_key_exists('name', $body) ? (string) $body['name'] : null;
		$description = array_key_exists('description', $body)
			? ($body['description'] === null
				? ''
				: (string) $body['description'])
			: null;
		$scopes = array_key_exists('scopes', $body) ? $body['scopes'] : null;
		if ($scopes !== null && !is_array($scopes)) {
			return GeneralHelper::badRequest('scopes must be an array of strings');
		}

		$result = ApiKeysHelper::update($keyId, (int) $user->id(), $name, $description, $scopes);
		if (is_string($result)) {
			return match ($result) {
				'not_found' => GeneralHelper::notFound('API key not found'),
				'revoked' => GeneralHelper::conflict('API key has been revoked'),
				'invalid_name' => GeneralHelper::badRequest(
					'Name must be ' . ApiKey::NAME_MIN . '-' . ApiKey::NAME_MAX . ' characters',
				),
				'invalid_description' => GeneralHelper::badRequest(
					'Description must be at most ' . ApiKey::DESCRIPTION_MAX . ' characters',
				),
				'invalid_scope' => GeneralHelper::badRequest('One or more scopes are invalid'),
				default => GeneralHelper::internalError('Failed to update API key'),
			};
		}

		return new JsonResponse($result->jsonSerialize());
	}

	// DELETE /v2/users/current/api-keys/{keyId}
	public function delete(Request $request, string $keyId): JsonResponse
	{
		$user = $this->ownSessionUser($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$ok = ApiKeysHelper::revoke($keyId, (int) $user->id());
		if (!$ok) {
			return GeneralHelper::notFound('API key not found or already revoked');
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// POST /v2/users/current/api-keys/revoke_all
	public function revokeAll(Request $request): JsonResponse
	{
		$user = $this->ownSessionUser($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$count = ApiKeysHelper::revokeAllForUser((int) $user->id());
		return new JsonResponse(['revoked' => $count]);
	}

	#region helpers

	/**
	 * Resolve the request to a session-authenticated user. API key callers
	 * are rejected because every endpoint in this controller is session-only.
	 */
	private function ownSessionUser(Request $request): UserInterface|JsonResponse
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		if (!$user) {
			return GeneralHelper::unauthorized('Authentication required');
		}
		$sessionGuard = UsersHelper::requireSessionToken($request);
		if ($sessionGuard) {
			return $sessionGuard;
		}
		return $user;
	}

	private function jsonBody(Request $request): array|JsonResponse
	{
		$raw = $request->getContent();
		if ($raw === '' || $raw === null) {
			return GeneralHelper::badRequest('Request body required');
		}
		$decoded = json_decode((string) $raw, true);
		if (!is_array($decoded)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}
		return $decoded;
	}

	private function mapIssueError(string $code, UserInterface $user): JsonResponse
	{
		return match ($code) {
			'no_email' => GeneralHelper::forbidden(
				'You must have an email address on file before generating an API key.',
			),
			'limit' => GeneralHelper::conflict(
				'You have reached the maximum number of active API keys (' .
					ApiKeysHelper::maxKeysFor($user) .
					'). Revoke an existing key first.',
			),
			'invalid_name' => GeneralHelper::badRequest(
				'Name must be ' . ApiKey::NAME_MIN . '-' . ApiKey::NAME_MAX . ' characters',
			),
			'invalid_description' => GeneralHelper::badRequest(
				'Description must be at most ' . ApiKey::DESCRIPTION_MAX . ' characters',
			),
			'invalid_scope' => GeneralHelper::badRequest(
				'At least one valid scope must be granted. Unknown scope names are rejected.',
			),
			'invalid_expiry' => GeneralHelper::badRequest(
				'expires_at must be at least one minute and at most 10 years in the future',
			),
			default => GeneralHelper::internalError('Failed to create API key'),
		};
	}

	#endregion
}
