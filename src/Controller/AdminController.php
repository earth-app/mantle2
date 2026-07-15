<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\SubscriptionsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends ControllerBase
{
	private function requireAdmin(Request $request): ?JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}
		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Administrator access required');
		}
		return null;
	}

	// GET /v2/admin/blacklist?kind=username|email
	public function listBlacklist(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$kind = $request->query->get('kind');
		$query = '';
		if ($kind === 'username' || $kind === 'email') {
			$query = '?kind=' . $kind;
		}

		try {
			$data = CloudHelper::sendRequest('/v1/admin/blacklist' . $query);
			if (!isset($data['entries']) || !is_array($data['entries'])) {
				$data = ['entries' => []];
			}
			return new JsonResponse($data, Response::HTTP_OK);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to fetch blacklist');
		}
	}

	// POST /v2/admin/blacklist
	public function addBlacklist(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}
		$user = UsersHelper::findByRequest($request);

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$kind = $body['kind'] ?? null;
		if ($kind !== 'username' && $kind !== 'email') {
			return GeneralHelper::badRequest('Field kind must be "username" or "email"');
		}

		$value = $body['value'] ?? null;
		if (!is_string($value) || trim($value) === '' || strlen($value) > 128) {
			return GeneralHelper::badRequest(
				'Field value must be a non-empty string under 128 chars',
			);
		}

		$reason = (string) ($body['reason'] ?? '');

		try {
			$data = CloudHelper::sendRequest('/v1/admin/blacklist', 'POST', [
				'kind' => $kind,
				'value' => $value,
				'reason' => $reason,
				'added_by' => $user->getAccountName(),
			]);
			return new JsonResponse($data, Response::HTTP_CREATED);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to add to blacklist');
		}
	}

	// DELETE /v2/admin/blacklist?kind=...&value=...
	public function removeBlacklist(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$kind = $request->query->get('kind');
		$value = $request->query->get('value');
		if ($kind !== 'username' && $kind !== 'email') {
			return GeneralHelper::badRequest('Invalid kind');
		}
		if (!$value) {
			return GeneralHelper::badRequest('Missing value');
		}

		try {
			CloudHelper::sendRequest(
				'/v1/admin/blacklist?kind=' . $kind . '&value=' . urlencode($value),
				'DELETE',
			);
			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to remove from blacklist');
		}
	}

	// GET /v2/admin/analytics?since=...&until=...
	public function analytics(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$query = [];
		if ($since = $request->query->get('since')) {
			$query['since'] = $since;
		}
		if ($until = $request->query->get('until')) {
			$query['until'] = $until;
		}
		$qs = empty($query) ? '' : '?' . http_build_query($query);

		try {
			// CF analytics call can be slow; bump cloud timeout
			$data = CloudHelper::sendRequest('/v1/admin/analytics' . $qs, 'GET', [], 30);
			return new JsonResponse($data, Response::HTTP_OK);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to fetch analytics');
		}
	}

	#region Trial Codes

	// POST /v2/admin/trial-codes
	public function createTrialCode(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}
		$admin = UsersHelper::findByRequest($request);

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$tier = AccountType::tryFrom(strtolower((string) ($body['tier'] ?? '')));
		if (
			!$tier ||
			!in_array($tier, [AccountType::PRO, AccountType::WRITER, AccountType::ORGANIZER], true)
		) {
			return GeneralHelper::badRequest('Invalid tier; must be pro, writer, or organizer');
		}

		$days = (int) ($body['days'] ?? 0);
		if ($days < 1) {
			return GeneralHelper::badRequest('Field days must be a positive integer');
		}

		$maxRedemptions = max(0, (int) ($body['max_redemptions'] ?? 0));

		$expiresAt = $this->parseTimestamp($body['expires_at'] ?? null);
		if ($expiresAt === false) {
			return GeneralHelper::badRequest('Invalid expires_at');
		}

		$code = null;
		if (isset($body['code']) && is_string($body['code']) && trim($body['code']) !== '') {
			$code = trim($body['code']);
			if (!preg_match('/^[A-Za-z0-9-]{1,32}$/', $code)) {
				return GeneralHelper::badRequest('Invalid code format');
			}
			if (SubscriptionsHelper::getTrialCode($code) !== null) {
				return GeneralHelper::conflict('A code with that value already exists');
			}
		}

		$created = SubscriptionsHelper::createTrialCode(
			$tier,
			$days,
			$maxRedemptions,
			$expiresAt,
			(int) $admin->id(),
			$code,
		);
		return new JsonResponse($created, Response::HTTP_CREATED);
	}

	// GET /v2/admin/trial-codes
	public function listTrialCodes(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}
		return new JsonResponse(
			['codes' => SubscriptionsHelper::listTrialCodes()],
			Response::HTTP_OK,
		);
	}

	// PATCH /v2/admin/trial-codes/{code}
	public function patchTrialCode(Request $request, string $code): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$patch = [];
		if (array_key_exists('active', $body)) {
			$patch['active'] = (bool) $body['active'];
		}
		if (array_key_exists('max_redemptions', $body)) {
			$patch['max_redemptions'] = (int) $body['max_redemptions'];
		}
		if (array_key_exists('days', $body)) {
			$patch['days'] = (int) $body['days'];
		}
		if (array_key_exists('tier', $body)) {
			$tier = AccountType::tryFrom(strtolower((string) $body['tier']));
			if (
				!$tier ||
				!in_array(
					$tier,
					[AccountType::PRO, AccountType::WRITER, AccountType::ORGANIZER],
					true,
				)
			) {
				return GeneralHelper::badRequest('Invalid tier');
			}
			$patch['tier'] = $tier->value;
		}
		if (array_key_exists('expires_at', $body)) {
			$expiresAt = $this->parseTimestamp($body['expires_at']);
			if ($expiresAt === false) {
				return GeneralHelper::badRequest('Invalid expires_at');
			}
			$patch['expires_at'] = $expiresAt;
		}

		$updated = SubscriptionsHelper::updateTrialCode($code, $patch);
		if ($updated === null) {
			return GeneralHelper::notFound('Unknown trial code');
		}
		return new JsonResponse($updated, Response::HTTP_OK);
	}

	// DELETE /v2/admin/trial-codes/{code}
	public function deleteTrialCode(Request $request, string $code): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		if (!SubscriptionsHelper::deleteTrialCode($code)) {
			return GeneralHelper::notFound('Unknown trial code');
		}
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/admin/trial-codes/{code}/redemptions
	public function listRedemptions(Request $request, string $code): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		if (SubscriptionsHelper::getTrialCode($code) === null) {
			return GeneralHelper::notFound('Unknown trial code');
		}

		return new JsonResponse(SubscriptionsHelper::listRedemptions($code), Response::HTTP_OK);
	}

	// POST /v2/admin/trial-codes/{code}/notify
	public function notifyRedeemers(Request $request, string $code): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
		if ($title === '' || strlen($title) > 120) {
			return GeneralHelper::badRequest(
				'Field title must be a non-empty string up to 120 chars',
			);
		}

		$message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
		if ($message === '' || strlen($message) > 2000) {
			return GeneralHelper::badRequest(
				'Field message must be a non-empty string up to 2000 chars',
			);
		}

		if (SubscriptionsHelper::getTrialCode($code) === null) {
			return GeneralHelper::notFound('Unknown trial code');
		}

		$notified = SubscriptionsHelper::notifyRedeemers($code, $title, $message);
		return new JsonResponse(['notified' => $notified], Response::HTTP_OK);
	}

	// GET /v2/admin/users/lookup?q=...
	public function lookupUsers(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$q = trim((string) $request->query->get('q', ''));
		if (strlen($q) < 2) {
			return GeneralHelper::badRequest('Query parameter q must be at least 2 characters');
		}

		return new JsonResponse(SubscriptionsHelper::lookupUsersForAdmin($q), Response::HTTP_OK);
	}

	// POST /v2/admin/users/{id}/refund
	public function refundUser(Request $request, string $id): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$user = UsersHelper::findBy($id);
		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$body = json_decode($request->getContent(), true);
		$reason = is_array($body) && is_string($body['reason'] ?? null) ? $body['reason'] : '';

		$result = SubscriptionsHelper::refundUser($user, $reason);
		if (isset($result['error'])) {
			return GeneralHelper::notFound('No active subscription to refund');
		}
		return new JsonResponse($result, Response::HTTP_OK);
	}

	// accepts null, a unix int, or an ISO-8601 string; returns int|null, or false on bad input
	private function parseTimestamp(mixed $value): int|null|false
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (is_int($value) || (is_string($value) && ctype_digit($value))) {
			return (int) $value;
		}
		if (is_string($value)) {
			$ts = strtotime($value);
			return $ts === false ? false : $ts;
		}
		return false;
	}

	#endregion
}
