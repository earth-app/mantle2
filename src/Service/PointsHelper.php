<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\user\UserInterface;

class PointsHelper
{
	public static function getPoints(UserInterface $user): int
	{
		$data = CloudHelper::sendRequest(
			'/v1/users/impact_points' . GeneralHelper::formatId($user->id()),
		);
		if (empty($data) || !is_array($data)) {
			return 0;
		}

		return $data['points'] ?? 0;
	}

	public static function addPoints(UserInterface $user, int $points, string $reason = ''): int
	{
		$data = CloudHelper::sendRequest(
			'/v1/users/impact_points/' . GeneralHelper::formatId($user->id()) . '/add',
			'POST',
			[
				'points' => $points,
			],
		);

		$newPoints = $data['points'] ?? null;
		if ($newPoints === null) {
			Drupal::logger('mantle2')->warning('Failed to add points for user %uid: %message', [
				'%uid' => $user->id(),
				'%message' => json_encode($data),
			]);
			return self::getPoints($user);
		}

		// optionally log the reason for adding points
		Drupal::logger('mantle2')->info('Added %points points to user %uid: %reason', [
			'%points' => $points,
			'%uid' => $user->id(),
			'%reason' => $reason ?: 'No reason provided',
		]);

		return $newPoints;
	}

	public static function removePoints(UserInterface $user, int $points, string $reason = ''): int
	{
		$data = CloudHelper::sendRequest(
			'/v1/users/impact_points/' . GeneralHelper::formatId($user->id()) . '/remove',
			'POST',
			[
				'points' => $points,
			],
		);

		$newPoints = $data['points'] ?? null;
		if ($newPoints === null) {
			Drupal::logger('mantle2')->warning('Failed to remove points for user %uid: %message', [
				'%uid' => $user->id(),
				'%message' => json_encode($data),
			]);
			return self::getPoints($user);
		}

		// optionally log the reason for removing points
		Drupal::logger('mantle2')->info('Removed %points points from user %uid: %reason', [
			'%points' => $points,
			'%uid' => $user->id(),
			'%reason' => $reason ?: 'No reason provided',
		]);

		return $newPoints;
	}

	public static function setPoints(UserInterface $user, int $points, string $reason = ''): int
	{
		$data = CloudHelper::sendRequest(
			'/v1/users/impact_points/' . GeneralHelper::formatId($user->id()) . '/set',
			'POST',
			[
				'points' => $points,
			],
		);

		$newPoints = $data['points'] ?? null;
		if ($newPoints === null) {
			Drupal::logger('mantle2')->warning('Failed to set points for user %uid: %message', [
				'%uid' => $user->id(),
				'%message' => json_encode($data),
			]);
			return self::getPoints($user);
		}

		// optionally log the reason for setting points
		Drupal::logger('mantle2')->info('Set points for user %uid to %points: %reason', [
			'%points' => $points,
			'%uid' => $user->id(),
			'%reason' => $reason ?: 'No reason provided',
		]);

		return $newPoints;
	}
}
