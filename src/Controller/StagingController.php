<?php

namespace Drupal\mantle2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\StagingHelper;
use Drupal\mantle2\Service\UsersHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StagingController extends ControllerBase
{
	public static function create(ContainerInterface $container): static
	{
		return new static();
	}

	// POST /v2/activities/staged
	public function stageActivity(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if ($block = UsersHelper::requireEmailVerified($user, 'stage an activity')) {
			return $block;
		}
		if ($block = UsersHelper::requireVerifiedPublisher($user, 'stage activities')) {
			return $block;
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$activity = StagingHelper::validateActivityBody($body);
		if ($activity instanceof JsonResponse) {
			return $activity;
		}

		$note = $body['note'] ?? null;
		if ($note !== null && (!is_string($note) || strlen($note) > 512)) {
			return GeneralHelper::badRequest('Note must be a string of at most 512 characters');
		}

		$kind = StagingHelper::kindFor($user);
		if (
			$kind === StagingHelper::KIND_ORGANIZER &&
			StagingHelper::countPendingFor((int) $user->id()) >=
				StagingHelper::MAX_PENDING_PER_ORGANIZER
		) {
			return GeneralHelper::conflict(
				'You already have ' .
					StagingHelper::MAX_PENDING_PER_ORGANIZER .
					' submissions awaiting review.',
			);
		}

		if (StagingHelper::findPending($activity->getId())) {
			return GeneralHelper::conflict(
				"Activity with ID '{$activity->getId()}' is already awaiting review",
			);
		}

		// only the cloud service account may declare its own source; otherwise an organizer
		// could forge cloud_discovery to get fail-open treatment
		$source =
			$kind === StagingHelper::KIND_CLOUD ? $body['source'] ?? 'cloud_discovery' : 'api';

		$row = StagingHelper::stage($activity, $user, $note, $source);
		if (!$row) {
			return GeneralHelper::internalError('Failed to stage activity');
		}

		return new JsonResponse(StagingHelper::rowToArray($row), Response::HTTP_CREATED);
	}

	// GET /v2/activities/staged
	public function listStaged(Request $request): JsonResponse
	{
		if ($block = UsersHelper::requireAdmin($request)) {
			return $block;
		}

		return new JsonResponse(
			StagingHelper::listStaged(
				[
					'state' => $request->query->get('state'),
					'submitter_kind' => $request->query->get('submitter_kind'),
					'source' => $request->query->get('source'),
					'search' => $request->query->get('search'),
				],
				(int) $request->query->get('page', 1),
				(int) $request->query->get('limit', 25),
				(string) $request->query->get('sort', 'asc'),
			),
		);
	}

	// GET /v2/activities/staged/mine
	public function listMyStaged(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		return new JsonResponse(
			StagingHelper::listForUser(
				(int) $user->id(),
				$request->query->get('state'),
				(int) $request->query->get('page', 1),
				(int) $request->query->get('limit', 25),
			),
		);
	}

	// GET /v2/activities/staged/:stagedId
	public function getStaged(Request $request, string $stagedId): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$row = StagingHelper::get((int) $stagedId);
		if (!$row) {
			return GeneralHelper::notFound('Staged activity not found');
		}

		$isOwner = (int) $row['submitter_id'] === (int) $user->id();
		if (!$isOwner && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to view this submission.');
		}

		return new JsonResponse(StagingHelper::rowToArray($row, UsersHelper::isAdmin($user)));
	}

	// POST /v2/activities/staged/:stagedId/approve
	public function approveStaged(Request $request, string $stagedId): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}
		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Administrator access required');
		}

		$body = self::decodeBody($request);
		$result = StagingHelper::approve(
			(int) $stagedId,
			$user,
			$body['notes'] ?? null,
			(bool) ($body['force'] ?? false),
		);

		return self::decisionResponse($result);
	}

	// POST /v2/activities/staged/:stagedId/deny
	public function denyStaged(Request $request, string $stagedId): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}
		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Administrator access required');
		}

		$body = self::decodeBody($request);
		$result = StagingHelper::deny((int) $stagedId, $user, $body['notes'] ?? null);

		return self::decisionResponse($result);
	}

	private static function decodeBody(Request $request): array
	{
		$body = json_decode($request->getContent() ?: '{}', true);

		return is_array($body) ? $body : [];
	}

	// DELETE /v2/activities/staged/:stagedId
	public function withdrawStaged(Request $request, string $stagedId): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$result = StagingHelper::withdraw((int) $stagedId, $user);

		return match ($result) {
			false => GeneralHelper::notFound('Staged activity not found'),
			'forbidden' => GeneralHelper::forbidden(
				'You do not have permission to withdraw this submission.',
			),
			'already_decided' => GeneralHelper::conflict(
				'This submission has already been decided.',
			),
			default => new JsonResponse(null, Response::HTTP_NO_CONTENT),
		};
	}

	private static function decisionResponse(array|string|false $result): JsonResponse
	{
		return match (true) {
			$result === false => GeneralHelper::notFound('Staged activity not found'),
			$result === 'already_decided' => GeneralHelper::conflict(
				'This submission has already been decided.',
			),
			$result === 'catalog_conflict' => GeneralHelper::conflict(
				'An activity with this id already exists in the catalog.',
			),
			$result === 'not_verified' => GeneralHelper::conflict(
				'The submitter is no longer a Verified Publisher. Retry with force to publish anyway.',
			),
			default => new JsonResponse(StagingHelper::rowToArray($result, true)),
		};
	}
}
