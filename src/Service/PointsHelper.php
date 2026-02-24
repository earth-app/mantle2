<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\user\UserInterface;
use Exception;
use GdImage;

class PointsHelper
{
	public static function getPoints(UserInterface $user): array
	{
		$cacheKey = 'cloud:points:' . GeneralHelper::formatId($user->id());
		$cached = RedisHelper::get($cacheKey);
		if ($cached !== null) {
			return [$cached['points'] ?? 0, $cached['history'] ?? []];
		}

		$data = CloudHelper::sendRequest(
			'/v1/users/impact_points/' . GeneralHelper::formatId($user->id()),
		);
		if (empty($data) || !is_array($data)) {
			return [0, []];
		}

		$points = $data['points'] ?? 0;
		$history = $data['history'] ?? [];
		RedisHelper::set($cacheKey, ['points' => $points, 'history' => $history], 180);
		return [$points, $history];
	}

	public static function addPoints(UserInterface $user, int $points, string $reason = ''): array
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
			return self::getPoints($user)[0];
		}

		$newHistory = $data['history'] ?? [];
		Drupal::logger('mantle2')->info('Added %points points to user %uid: %reason', [
			'%points' => $points,
			'%uid' => $user->id(),
			'%reason' => $reason ?: 'No reason provided',
		]);

		$cacheKey = 'cloud:points:' . GeneralHelper::formatId($user->id());
		RedisHelper::set($cacheKey, ['points' => $newPoints, 'history' => $newHistory], 180);

		return [$newPoints, $newHistory];
	}

	public static function removePoints(
		UserInterface $user,
		int $points,
		string $reason = '',
	): array {
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
			return self::getPoints($user)[0];
		}

		$newHistory = $data['history'] ?? [];

		Drupal::logger('mantle2')->info('Removed %points points from user %uid: %reason', [
			'%points' => $points,
			'%uid' => $user->id(),
			'%reason' => $reason ?: 'No reason provided',
		]);

		$cacheKey = 'cloud:points:' . GeneralHelper::formatId($user->id());
		RedisHelper::set($cacheKey, ['points' => $newPoints, 'history' => $newHistory], 180);

		return [$newPoints, $newHistory];
	}

	public static function setPoints(UserInterface $user, int $points, string $reason = ''): array
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
			return self::getPoints($user)[0];
		}

		$newHistory = $data['history'] ?? [];

		Drupal::logger('mantle2')->info('Set points for user %uid to %points: %reason', [
			'%points' => $points,
			'%uid' => $user->id(),
			'%reason' => $reason ?: 'No reason provided',
		]);

		$cacheKey = 'cloud:points:' . GeneralHelper::formatId($user->id());
		RedisHelper::set($cacheKey, ['points' => $newPoints, 'history' => $newHistory], 180);

		return [$newPoints, $newHistory];
	}

	// User Cosmetics

	private static function invalidateUserCache(UserInterface $user): void
	{
		$userId = $user->id();
		RedisHelper::delete('user:' . $userId . ':public');
		RedisHelper::delete('user:' . $userId . ':private');

		$cosmetics = array_keys(self::cosmetics());
		foreach ([32, 128, 1024] as $size) {
			RedisHelper::delete('cloud:user:photo:' . $userId . ':' . $size);
			foreach ($cosmetics as $cosmeticKey) {
				RedisHelper::delete(
					'cloud:user:photo:' . $userId . ':' . $size . ':' . $cosmeticKey,
				);
			}
		}
	}

	public static function cosmetics(): array
	{
		return [
			'grayscale' => [
				'price' => 25,
				'rarity' => 'normal',
				'apply' => function (GdImage $image) {
					imagefilter($image, IMG_FILTER_GRAYSCALE);
					return $image;
				},
			],
			'invert' => [
				'price' => 30,
				'rarity' => 'normal',
				'apply' => function (GdImage $image) {
					imagefilter($image, IMG_FILTER_NEGATE);
					return $image;
				},
			],
			// rare cosmetics
			'sepia' => [
				'price' => 50,
				'rarity' => 'rare',
				'apply' => function (GdImage $image) {
					imagefilter($image, IMG_FILTER_GRAYSCALE);
					imagefilter($image, IMG_FILTER_COLORIZE, 100, 50, 0);
					return $image;
				},
			],
			// amazing cosmetics
			'pixelate' => [
				'price' => 100,
				'rarity' => 'amazing',
				'apply' => function (GdImage $image) {
					imagefilter($image, IMG_FILTER_PIXELATE, 10, true);
					return $image;
				},
			],
			// green cosmetics
			'green_overlay' => [
				'price' => 150,
				'rarity' => 'green',
				'apply' => function (GdImage $image) {
					$width = imagesx($image);
					$height = imagesy($image);
					$overlay = imagecreatetruecolor($width, $height);
					$green = imagecolorallocate($overlay, 0, 255, 0);
					imagefill($overlay, 0, 0, $green);
					imagecopymerge($image, $overlay, 0, 0, 0, 0, $width, $height, 50);
					return $image;
				},
			],
		];
	}

	public static function getAvatarCosmetic(UserInterface $user): ?string
	{
		$selectedCosmetic = $user->get('field_selected_cosmetic')->value;
		return $selectedCosmetic ? $selectedCosmetic : null;
	}

	public static function setAvatarCosmetic(UserInterface $user, ?string $cosmeticKey): void
	{
		$availableCosmetics = self::getAvailableCosmetics($user);
		if ($cosmeticKey && !in_array($cosmeticKey, $availableCosmetics)) {
			Drupal::logger('mantle2')->warning(
				'User %uid attempted to set unavailable cosmetic %cosmeticKey',
				[
					'%uid' => $user->id(),
					'%cosmeticKey' => $cosmeticKey,
				],
			);
			return;
		}

		try {
			$user->set('field_selected_cosmetic', $cosmeticKey);
			$user->save();
			self::invalidateUserCache($user);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error(
				'Failed to set avatar cosmetic for user %uid: %message',
				[
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
		}
	}

	public static function getAvailableCosmetics(UserInterface $user): array
	{
		$availableCosmetics = $user->get('field_available_cosmetics')->value ?? '[]';
		return $availableCosmetics ? json_decode($availableCosmetics, true) : [];
	}

	public static function purchaseCosmetic(UserInterface $user, string $cosmeticKey): bool
	{
		$cosmetics = self::cosmetics();
		if (!isset($cosmetics[$cosmeticKey])) {
			return false;
		}

		$availableCosmetics = self::getAvailableCosmetics($user);
		if (in_array($cosmeticKey, $availableCosmetics)) {
			return false;
		}

		$price = $cosmetics[$cosmeticKey]['price'];
		$currentPoints = self::getPoints($user)[0];
		if ($currentPoints < $price) {
			return false;
		}

		self::removePoints($user, $price, 'Purchased cosmetic: ' . $cosmeticKey);

		$availableCosmetics[] = $cosmeticKey;
		$user->set('field_available_cosmetics', json_encode($availableCosmetics));
		$user->save();
		self::invalidateUserCache($user);

		return true;
	}

	public static function getCosmeticsCatalog(): array
	{
		$cosmetics = self::cosmetics();
		$catalog = [];
		foreach ($cosmetics as $key => $data) {
			$catalog[] = [
				'key' => $key,
				'price' => $data['price'],
				'rarity' => $data['rarity'],
			];
		}
		return $catalog;
	}

	public static function applyCosmetic(string $dataUrl, string $cosmeticKey): ?string
	{
		$cosmetics = self::cosmetics();
		if (!isset($cosmetics[$cosmeticKey])) {
			return $dataUrl;
		}

		$applyCosmetic = $cosmetics[$cosmeticKey]['apply'];

		$imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl));
		if (!$imageData) {
			return null;
		}

		$image = imagecreatefromstring($imageData);
		if (!$image) {
			return null;
		}

		$modifiedImage = $applyCosmetic($image);
		ob_start();
		imagepng($modifiedImage);
		$modifiedData = ob_get_clean();

		return 'data:image/png;base64,' . base64_encode($modifiedData);
	}

	public static function getAvatar(
		UserInterface $user,
		?string $cosmeticKey = null,
		int $size = 1024,
	) {
		$dataUrl = UsersHelper::getProfilePhoto($user, $size);
		if (!$dataUrl) {
			return null;
		}

		// if no key provided, return base photo
		if (!$cosmeticKey) {
			return $dataUrl;
		}

		$cosmetics = self::cosmetics();
		if (!isset($cosmetics[$cosmeticKey])) {
			return $dataUrl;
		}

		$applyCosmetic = $cosmetics[$cosmeticKey]['apply'];
		$imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl));
		if (!$imageData) {
			return null;
		}

		$image = imagecreatefromstring($imageData);
		if (!$image) {
			return null;
		}

		$modifiedImage = $applyCosmetic($image);
		ob_start();
		imagepng($modifiedImage);
		$modifiedData = ob_get_clean();

		return 'data:image/png;base64,' . base64_encode($modifiedData);
	}
}
