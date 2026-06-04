<?php

namespace Drupal\mantle2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\RedisHelper;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// MoodSpark — anonymous emoji-vote aggregator. routes are public; rate limits are by IP+topic+date
class MoodController extends ControllerBase
{
	private const TOPIC_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';
	private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
	private const ALLOWED_EMOJIS = ['😍', '😊', '🤔', '😐', '😟', '😤'];

	// one vote per IP per topic per day. defense in depth alongside the client-side localStorage gate
	private const RATE_LIMIT_SECONDS = 86400;

	// GET /v2/mood/{topic}/{date}
	public function getMood(Request $request, string $topic, string $date): JsonResponse
	{
		$topic = self::sanitizeTopic($topic);
		if (!$topic) {
			return GeneralHelper::badRequest('Invalid topic');
		}
		if (!preg_match(self::DATE_PATTERN, $date)) {
			return GeneralHelper::badRequest('Invalid date (expected YYYY-MM-DD)');
		}

		try {
			$data = CloudHelper::sendRequest('/v1/mood/' . $topic . '/' . $date);
			return new JsonResponse($data, Response::HTTP_OK);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to fetch mood snapshot');
		}
	}

	// POST /v2/mood/{topic}/{date} { emoji }
	public function postMood(Request $request, string $topic, string $date): JsonResponse
	{
		$topic = self::sanitizeTopic($topic);
		if (!$topic) {
			return GeneralHelper::badRequest('Invalid topic');
		}
		if (!preg_match(self::DATE_PATTERN, $date)) {
			return GeneralHelper::badRequest('Invalid date (expected YYYY-MM-DD)');
		}

		$body = json_decode($request->getContent(), true);
		$emoji = is_array($body) ? $body['emoji'] ?? null : null;
		if (!is_string($emoji) || !in_array($emoji, self::ALLOWED_EMOJIS, true)) {
			return GeneralHelper::badRequest('Invalid emoji');
		}

		$ip = $request->getClientIp() ?? 'unknown';
		$throttleKey = self::throttleKey($ip, $topic, $date);
		if (RedisHelper::exists($throttleKey)) {
			return GeneralHelper::conflict(
				'You have already recorded a mood for this topic today.',
			);
		}

		try {
			$data = CloudHelper::sendRequest('/v1/mood/' . $topic . '/' . $date, 'POST', [
				'emoji' => $emoji,
			]);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to record mood');
		}

		// only mark the throttle after a successful cloud write — otherwise transient
		// cloud errors would lock the IP out for the rest of the day
		RedisHelper::set(
			$throttleKey,
			['timestamp' => time(), 'emoji' => $emoji],
			self::RATE_LIMIT_SECONDS,
		);

		return new JsonResponse($data, Response::HTTP_OK);
	}

	private static function sanitizeTopic(string $input): ?string
	{
		$trimmed = strtolower(trim($input));
		if (!preg_match(self::TOPIC_PATTERN, $trimmed)) {
			return null;
		}
		return $trimmed;
	}

	private static function throttleKey(string $ip, string $topic, string $date): string
	{
		// hash the ip so a redis dump doesn't leak addresses in plaintext
		return 'mood:throttle:' . hash('sha256', $ip) . ':' . $topic . ':' . $date;
	}
}
