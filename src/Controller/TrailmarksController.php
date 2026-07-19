<?php

namespace Drupal\mantle2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TrailmarksController extends ControllerBase
{
	// GET /v2/trailmarks
	public function nearby(Request $request): JsonResponse
	{
		$viewer = UsersHelper::findByRequest($request);
		if ($viewer instanceof JsonResponse) {
			return $viewer;
		}

		$lat = $request->query->get('lat');
		$lng = $request->query->get('lng');
		if (!is_numeric($lat) || !is_numeric($lng)) {
			return GeneralHelper::badRequest('Valid lat and lng are required');
		}

		$query = [
			'lat' => (float) $lat,
			'lng' => (float) $lng,
			'viewer' => GeneralHelper::formatId($viewer->id()),
		];
		$radius = $request->query->get('radius');
		if (is_numeric($radius) && (float) $radius > 0) {
			$query['radius'] = (float) $radius;
		}

		try {
			$data = CloudHelper::sendRequest('/v1/trailmarks', 'GET', $query);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to fetch trailmarks');
		}

		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/trailmarks
	public function createTrailmark(Request $request): JsonResponse
	{
		$author = UsersHelper::findByRequest($request);
		if ($author instanceof JsonResponse) {
			return $author;
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid request body');
		}

		$geo = $body['geo'] ?? null;
		if (
			!is_array($geo) ||
			!is_numeric($geo['lat'] ?? null) ||
			!is_numeric($geo['lng'] ?? null)
		) {
			return GeneralHelper::badRequest('Valid geo (lat, lng) is required');
		}

		$note = $body['note'] ?? null;
		if (!is_string($note) || trim($note) === '') {
			return GeneralHelper::badRequest('Note is required');
		}

		// censor the note before it leaves mantle2 (cloud censors again; the check is idempotent)
		$censored = GeneralHelper::censorText(trim($note));
		if (trim($censored) === '') {
			return GeneralHelper::badRequest('Note is empty after censoring');
		}

		$geoPayload = ['lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng']];
		if (
			isset($geo['place_label']) &&
			is_string($geo['place_label']) &&
			$geo['place_label'] !== ''
		) {
			$geoPayload['place_label'] = $geo['place_label'];
		}

		$payload = [
			'author_uid' => GeneralHelper::formatId($author->id()),
			'author_username' => $author->getAccountName(),
			'geo' => $geoPayload,
			'note' => $censored,
		];
		// optional: also surface this note under a daily prompt (cloud indexes it by prompt id)
		if (
			isset($body['prompt_id']) &&
			is_string($body['prompt_id']) &&
			$body['prompt_id'] !== ''
		) {
			$payload['prompt_id'] = $body['prompt_id'];
		}

		try {
			$data = CloudHelper::sendRequest('/v1/trailmarks', 'POST', $payload);
		} catch (Exception $e) {
			$code = (int) $e->getCode();
			// cloud 422 = the sentiment gate rejected an unkind note; keep the nudge gentle
			if ($code === 422) {
				return GeneralHelper::badRequest(
					"Let's keep trailmarks kind and encouraging - try rephrasing.",
				);
			}
			if ($code === 400) {
				return GeneralHelper::badRequest(
					CloudHelper::extractCloudMessage($e) ?: 'Invalid trailmark',
				);
			}
			return CloudHelper::mapCloudException($e, 'Failed to create trailmark');
		}

		return new JsonResponse($data, Response::HTTP_CREATED);
	}

	// GET /v2/prompts/{prompt}/trailmarks
	public function nearbyForPrompt(Request $request, string $prompt): JsonResponse
	{
		$viewer = UsersHelper::findByRequest($request);
		if ($viewer instanceof JsonResponse) {
			return $viewer;
		}

		$promptId = trim($prompt);
		if ($promptId === '') {
			return GeneralHelper::badRequest('Invalid prompt id');
		}

		try {
			$data = CloudHelper::sendRequest(
				'/v1/prompts/' . rawurlencode($promptId) . '/trailmarks',
				'GET',
				['viewer' => GeneralHelper::formatId($viewer->id())],
			);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to fetch prompt trailmarks');
		}

		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/trailmarks/{id}/thank
	public function thank(Request $request, string $id): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$trailmarkId = trim($id);
		if ($trailmarkId === '') {
			return GeneralHelper::badRequest('Invalid trailmark id');
		}

		try {
			// cloud fires the private thank notification to the author itself (one-thank gate)
			$data = CloudHelper::sendRequest(
				'/v1/trailmarks/' . rawurlencode($trailmarkId) . '/thank',
				'POST',
				[
					'uid' => GeneralHelper::formatId($user->id()),
					'username' => $user->getAccountName(),
				],
			);
		} catch (Exception $e) {
			$code = (int) $e->getCode();
			$message = CloudHelper::extractCloudMessage($e);
			return match ($code) {
				400 => GeneralHelper::badRequest($message ?: 'Invalid thank request'),
				409 => GeneralHelper::conflict($message ?: 'Already thanked'),
				default => GeneralHelper::internalError('Failed to thank trailmark'),
			};
		}

		// sendRequest folds a 404 into an empty array
		if (empty($data)) {
			return GeneralHelper::notFound('Trailmark not found');
		}

		return new JsonResponse($data, Response::HTTP_OK);
	}
}
