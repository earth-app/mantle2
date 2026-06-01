<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\GeneralHelper;
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
			if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
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
}
