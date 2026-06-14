<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\ReportsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\UserInterface;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportsController extends ControllerBase
{
	public static function create(
		\Symfony\Component\DependencyInjection\ContainerInterface $container,
	): ReportsController|static {
		return new static();
	}

	// POST /v2/reports — anonymous allowed
	public function createReport(Request $request): JsonResponse
	{
		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$contentType = $body['content_type'] ?? null;
		if (
			!is_string($contentType) ||
			!in_array($contentType, ReportsHelper::CONTENT_TYPES, true)
		) {
			return GeneralHelper::badRequest('Invalid content_type');
		}

		$contentId = $body['content_id'] ?? null;
		if (is_int($contentId)) {
			$contentId = (string) $contentId;
		}
		if (!is_string($contentId) || trim($contentId) === '') {
			return GeneralHelper::badRequest('Missing content_id');
		}
		$contentId = trim($contentId);

		$parentId = $body['parent_id'] ?? null;
		if ($parentId !== null) {
			if (is_int($parentId)) {
				$parentId = (string) $parentId;
			}
			if (!is_string($parentId) || trim($parentId) === '') {
				$parentId = null;
			} else {
				$parentId = trim($parentId);
			}
		}

		// prompt_response + event_image require their parent id
		if (
			in_array($contentType, ['prompt_response', 'event_image'], true) &&
			$parentId === null
		) {
			return GeneralHelper::badRequest('parent_id is required for ' . $contentType);
		}

		$reason = $body['reason'] ?? null;
		if (!is_string($reason) || !in_array($reason, ReportsHelper::REASONS, true)) {
			return GeneralHelper::badRequest('Invalid reason');
		}

		$description = $body['description'] ?? null;
		if ($description !== null) {
			if (!is_string($description)) {
				return GeneralHelper::badRequest('Field description must be a string');
			}
			if (strlen($description) > 1024) {
				return GeneralHelper::badRequest(
					'Field description must be at most 1024 characters',
				);
			}

			// censor (true) so reports are never rejected for their own quoted content
			$validated = GeneralHelper::validateUserContent($description, true, 'report details');
			if ($validated instanceof JsonResponse) {
				return $validated;
			}
			$description = $validated;
		}

		// verify the content exists + resolve its owner
		$resolved = ReportsHelper::resolveContent($contentType, $contentId, $parentId);
		if ($resolved === null) {
			return GeneralHelper::notFound('Reported content not found');
		}

		$reporter = UsersHelper::getOwnerOfRequest($request);
		$reporterId = $reporter instanceof UserInterface ? (int) $reporter->id() : null;

		$payload = [
			'content_type' => $contentType,
			'content_id' => $contentId,
			'reason' => $reason,
			'source' => 'user',
			'reporter_ip_hash' => self::ipHash($request),
		];
		if ($parentId !== null) {
			$payload['parent_id'] = $parentId;
		}
		if ($description !== null && $description !== '') {
			$payload['description'] = $description;
		}
		if ($resolved['owner_id'] !== null) {
			$payload['content_owner_id'] = $resolved['owner_id'];
		}
		if ($reporterId !== null) {
			$payload['reporter_id'] = $reporterId;
		}

		try {
			$result = CloudHelper::sendRequest('/v1/reports', 'POST', $payload);
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to submit report');
		}

		return new JsonResponse(
			[
				'report' => $result['report'] ?? $result,
				'deduped' => (bool) ($result['deduped'] ?? false),
			],
			Response::HTTP_CREATED,
		);
	}

	// GET /v2/reports — admin only
	public function listReports(Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		$query = [];
		$status = $request->query->get('status');
		if (is_string($status) && in_array($status, ReportsHelper::STATUSES, true)) {
			$query['status'] = $status;
		}

		$limit = $request->query->getInt('limit', 50);
		if ($limit < 1 || $limit > 100) {
			$limit = 50;
		}
		$query['limit'] = $limit;

		$page = $request->query->getInt('page', 1);
		if ($page < 1) {
			$page = 1;
		}

		// cloud paginates by cursor (a numeric offset); derive it from page when no explicit cursor
		$cursor = $request->query->get('cursor');
		if (!is_string($cursor) || $cursor === '') {
			$cursor = $page > 1 ? (string) (($page - 1) * $limit) : null;
		}
		if ($cursor !== null) {
			$query['cursor'] = $cursor;
		}

		try {
			$data = CloudHelper::sendRequest('/v1/reports', 'GET', $query);
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to fetch reports');
		}

		$reports = $data['reports'] ?? [];
		$hydrated = array_map(fn($r) => $this->hydrate($r), is_array($reports) ? $reports : []);

		$response = [
			'reports' => $hydrated,
			'page' => $page,
			'limit' => $limit,
		];
		if (isset($data['total'])) {
			$response['total'] = (int) $data['total'];
		}
		if (isset($data['cursor'])) {
			$response['cursor'] = $data['cursor'];
		}

		return new JsonResponse($response, Response::HTTP_OK);
	}

	// GET /v2/reports/{id} — admin only
	public function getReport(string $id, Request $request): JsonResponse
	{
		if ($block = $this->requireAdmin($request)) {
			return $block;
		}

		try {
			$report = CloudHelper::sendRequest('/v1/reports/' . urlencode($id));
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to fetch report');
		}

		if (empty($report)) {
			return GeneralHelper::notFound('Report not found');
		}

		return new JsonResponse($this->hydrate($report), Response::HTTP_OK);
	}

	// PATCH /v2/reports/{id} — admin only
	public function patchReport(string $id, Request $request): JsonResponse
	{
		$admin = UsersHelper::findByRequest($request);
		if ($admin instanceof JsonResponse) {
			return $admin;
		}
		if (!UsersHelper::isAdmin($admin)) {
			return GeneralHelper::forbidden('Administrator access required');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$action = $body['action'] ?? null;
		if (!in_array($action, ['dismiss', 'delete_content', 'ban_user'], true)) {
			return GeneralHelper::badRequest('Invalid action');
		}

		$notes = $body['notes'] ?? null;
		if ($notes !== null && (!is_string($notes) || strlen($notes) > 1024)) {
			return GeneralHelper::badRequest(
				'Field notes must be a string of at most 1024 characters',
			);
		}

		$notifyReporter = (bool) ($body['notify_reporter'] ?? false);
		$notifyAuthor = (bool) ($body['notify_author'] ?? false);

		// load the report from cloud so we know what content/owner to act on
		try {
			$report = CloudHelper::sendRequest('/v1/reports/' . urlencode($id));
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to fetch report');
		}
		if (empty($report)) {
			return GeneralHelper::notFound('Report not found');
		}

		$contentType = (string) ($report['content_type'] ?? '');
		$contentId = (string) ($report['content_id'] ?? '');
		$parentId = isset($report['parent_id']) ? (string) $report['parent_id'] : null;
		$ownerId = isset($report['content_owner_id']) ? (int) $report['content_owner_id'] : 0;
		$reason = (string) ($report['reason'] ?? 'other');

		$enforcedAction = 'none';

		try {
			switch ($action) {
				case 'dismiss':
					$this->cloudPatchStatus($id, 'dismissed', $admin, $notes);
					break;

				case 'delete_content':
					$deleted = ReportsHelper::deleteContent($contentType, $contentId, $parentId);
					if (!$deleted) {
						return GeneralHelper::notFound('Reported content could not be removed');
					}

					if ($contentType === 'user') {
						// deleting a user as moderation is a permanent ban (see ReportsHelper::deleteContent)
						$enforcedAction = 'permanent_ban';
					} elseif ($ownerId > 0) {
						$enforcedAction = ReportsHelper::recordStrikeAndEnforce(
							$ownerId,
							$contentType,
							$contentId,
							$reason,
						);
					}

					$this->cloudPatchStatus($id, 'actioned', $admin, $notes);
					break;

				case 'ban_user':
					if ($ownerId <= 0) {
						return GeneralHelper::badRequest('Report has no content owner to ban');
					}
					$owner = \Drupal\user\Entity\User::load($ownerId);
					if (!$owner instanceof UserInterface) {
						return GeneralHelper::notFound('Content owner not found');
					}
					ReportsHelper::banUser($owner);
					$enforcedAction = 'permanent_ban';
					$this->cloudPatchStatus($id, 'actioned', $admin, $notes);
					break;
			}
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to action report');
		}

		// notifications (best-effort, never block the moderation result)
		if (($notifyAuthor || $notifyReporter) && $action !== 'dismiss') {
			if ($notifyAuthor && $ownerId > 0) {
				$owner = \Drupal\user\Entity\User::load($ownerId);
				if ($owner instanceof UserInterface) {
					ReportsHelper::notifyUser($owner, 'author', $contentType, $action, $notes);
				}
			}
		}
		if ($notifyReporter && isset($report['reporter_id']) && (int) $report['reporter_id'] > 0) {
			$reporter = \Drupal\user\Entity\User::load((int) $report['reporter_id']);
			if ($reporter instanceof UserInterface) {
				ReportsHelper::notifyUser($reporter, 'reporter', $contentType, $action, null);
			}
		}

		// return the updated, hydrated report
		try {
			$updated = CloudHelper::sendRequest('/v1/reports/' . urlencode($id));
		} catch (Exception $e) {
			$updated = $report;
		}
		$hydrated = $this->hydrate(empty($updated) ? $report : $updated);
		$hydrated['enforced_action'] = $enforcedAction;

		return new JsonResponse($hydrated, Response::HTTP_OK);
	}

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

	private function cloudPatchStatus(
		string $id,
		string $status,
		UserInterface $admin,
		?string $notes,
	): void {
		$payload = [
			'status' => $status,
			'reviewed_by' => $admin->getAccountName(),
		];
		if ($notes !== null && $notes !== '') {
			$payload['action_notes'] = $notes;
		}
		CloudHelper::sendRequest('/v1/reports/' . urlencode($id), 'PATCH', $payload);
	}

	// add content preview + reporter/author usernames to a cloud report
	private function hydrate(array $report): array
	{
		$contentType = (string) ($report['content_type'] ?? '');
		$contentId = (string) ($report['content_id'] ?? '');
		$parentId = isset($report['parent_id']) ? (string) $report['parent_id'] : null;

		$preview = '';
		if ($contentType !== '' && $contentId !== '') {
			$resolved = ReportsHelper::resolveContent($contentType, $contentId, $parentId);
			$preview = $resolved['preview'] ?? '';
		}

		$report['content_preview'] = $preview;
		$report['reporter_username'] = ReportsHelper::usernameFor(
			isset($report['reporter_id']) ? (int) $report['reporter_id'] : null,
		);
		$report['author_username'] = ReportsHelper::usernameFor(
			isset($report['content_owner_id']) ? (int) $report['content_owner_id'] : null,
		);

		return $report;
	}

	private static function ipHash(Request $request): string
	{
		$ip =
			$request->headers->get('CF-Connecting-IP') ??
			(($request->headers->get('X-Forwarded-For')
				? trim(explode(',', $request->headers->get('X-Forwarded-For'))[0])
				: null) ??
				($request->getClientIp() ?? 'anonymous'));

		return hash('sha256', $ip);
	}
}
