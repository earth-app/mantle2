<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\mantle2\Custom\Quest;
use Drupal\mantle2\Custom\QuestData;
use Drupal\user\UserInterface;
use Exception;
use GdImage;
use Symfony\Component\HttpFoundation\JsonResponse;

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
				'reason' => $reason,
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
				'reason' => $reason,
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
				'reason' => $reason,
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

	// Drawing Utilities

	private static function ring(GDImage $image, int $color): GDImage
	{
		imagesavealpha($image, true);
		imagealphablending($image, true);

		$width = imagesx($image);
		$height = imagesy($image);

		$centerX = $width / 2;
		$centerY = $height / 2;
		$diameter = min($width, $height);

		$ringThickness = max(10, (int) ($diameter * 0.04));

		$red = ($color >> 16) & 0xff;
		$green = ($color >> 8) & 0xff;
		$blue = $color & 0xff;

		$outerRadius = $diameter / 2 - 2;
		$innerRadius = $outerRadius - $ringThickness;

		// Anti-aliasing threshold
		$aaThreshold = 1.5;
		$outerRadiusSq = $outerRadius * $outerRadius;
		$innerRadiusSq = $innerRadius * $innerRadius;
		$aaThresholdSq = $aaThreshold * $aaThreshold;
		$searchRadiusSq = ($outerRadius + $aaThreshold) * ($outerRadius + $aaThreshold);

		// Only scan the annulus region to avoid checking entire image
		$minX = max(0, (int) floor($centerX - $outerRadius - $aaThreshold));
		$maxX = min($width - 1, (int) ceil($centerX + $outerRadius + $aaThreshold));
		$minY = max(0, (int) floor($centerY - $outerRadius - $aaThreshold));
		$maxY = min($height - 1, (int) ceil($centerY + $outerRadius + $aaThreshold));

		for ($x = $minX; $x <= $maxX; $x++) {
			$dx = $x - $centerX;
			$dxSq = $dx * $dx;

			for ($y = $minY; $y <= $maxY; $y++) {
				$dy = $y - $centerY;
				$distSq = $dxSq + $dy * $dy;

				// Early exit for pixels far outside threshold
				if (
					$distSq > $searchRadiusSq ||
					$distSq < ($innerRadius - $aaThreshold) * ($innerRadius - $aaThreshold)
				) {
					continue;
				}

				$distance = sqrt($distSq);

				// Calculate alpha based on distance from ring edges
				$alpha = 0;

				if ($distance >= $innerRadius && $distance <= $outerRadius) {
					// Fully inside the ring
					$alpha = 127;
				} elseif ($distance < $innerRadius) {
					// Near inner edge - fade in
					$alpha = (int) (127 * (1 - ($innerRadius - $distance) / $aaThreshold));
				} elseif ($distance <= $outerRadius + $aaThreshold) {
					// Near outer edge - fade out
					$alpha = (int) (127 * (1 - ($distance - $outerRadius) / $aaThreshold));
				}

				if ($alpha > 0) {
					$colorWithAlpha = imagecolorallocatealpha(
						$image,
						$red,
						$green,
						$blue,
						127 - $alpha,
					);
					imagesetpixel($image, $x, $y, $colorWithAlpha);
				}
			}
		}

		return $image;
	}

	private static function overlay(GdImage $image, int $hexColor, float $strength)
	{
		$strength = max(0.0, min(1.0, $strength));

		imagesavealpha($image, true);
		imagealphablending($image, false);

		$width = imagesx($image);
		$height = imagesy($image);

		// Extract RGB from hex color
		$targetRed = ($hexColor >> 16) & 0xff;
		$targetGreen = ($hexColor >> 8) & 0xff;
		$targetBlue = $hexColor & 0xff;

		for ($x = 0; $x < $width; $x++) {
			for ($y = 0; $y < $height; $y++) {
				$colorIndex = imagecolorat($image, $x, $y);
				$color = imagecolorsforindex($image, $colorIndex);

				// Skip transparent pixels
				if ($color['alpha'] >= 127) {
					continue;
				}

				// Apply overlay to non-transparent pixels
				// Mix: original * (1 - strength) + targetColor * strength
				$r = max(
					0,
					min(255, (int) ($color['red'] * (1 - $strength) + $targetRed * $strength)),
				);
				$g = max(
					0,
					min(255, (int) ($color['green'] * (1 - $strength) + $targetGreen * $strength)),
				);
				$b = max(
					0,
					min(255, (int) ($color['blue'] * (1 - $strength) + $targetBlue * $strength)),
				);
				$a = $color['alpha'];

				$newColor = imagecolorallocatealpha($image, $r, $g, $b, $a);
				if ($newColor !== false) {
					imagesetpixel($image, $x, $y, $newColor);
				}
			}
		}

		imagealphablending($image, true);
		return $image;
	}

	private static function stripes(GdImage $image, int $color, int $spacing = 20): GdImage
	{
		imagesavealpha($image, true);
		imagealphablending($image, true);

		$width = imagesx($image);
		$height = imagesy($image);

		$red = ($color >> 16) & 0xff;
		$green = ($color >> 8) & 0xff;
		$blue = $color & 0xff;

		// Anti-aliasing threshold for stripe edges
		$aaThreshold = 1.5;
		$spacingDouble = $spacing * 2;

		for ($x = 0; $x < $width; $x++) {
			// Calculate position within stripe period
			$posInPeriod = fmod($x, $spacingDouble);
			$inStripe = $posInPeriod < $spacing;

			// Calculate distance from stripe edge for anti-aliasing
			if ($inStripe) {
				$distFromEdge = min($posInPeriod, $spacing - $posInPeriod);
			} else {
				$distFromEdge = min($posInPeriod - $spacing, $spacingDouble - $posInPeriod);
			}

			// Skip columns far from edges
			if (!$inStripe && $distFromEdge >= $aaThreshold) {
				continue;
			}

			for ($y = 0; $y < $height; $y++) {
				$colorIndex = imagecolorat($image, $x, $y);
				$pixelColor = imagecolorsforindex($image, $colorIndex);

				if ($pixelColor['alpha'] < 127) {
					// Calculate alpha based on distance from stripe edge
					if ($inStripe) {
						$alpha = 127; // Full stripe
					} else {
						$alpha = (int) (127 * (1 - $distFromEdge / $aaThreshold));
					}

					if ($alpha > 0) {
						$newColor = imagecolorallocatealpha(
							$image,
							min(255, (int) ($pixelColor['red'] + $red * 0.5)),
							min(255, (int) ($pixelColor['green'] + $green * 0.5)),
							min(255, (int) ($pixelColor['blue'] + $blue * 0.5)),
							max(0, $pixelColor['alpha'] - (int) ($alpha * 0.5)),
						);
						if ($newColor !== false) {
							imagesetpixel($image, $x, $y, $newColor);
						}
					}
				}
			}
		}

		return $image;
	}

	private static function spiral(
		GdImage $image,
		int $color,
		float $turns = 3,
		int $thickness = 10,
	): GdImage {
		imagesavealpha($image, true);
		imagealphablending($image, true);

		$width = imagesx($image);
		$height = imagesy($image);

		$centerX = $width / 2;
		$centerY = $height / 2;
		$maxRadius = min($centerX, $centerY);

		$red = ($color >> 16) & 0xff;
		$green = ($color >> 8) & 0xff;
		$blue = $color & 0xff;

		$points = [];
		$totalPoints = (int) ($turns * 360);

		for ($i = 0; $i < $totalPoints; $i++) {
			$angle = deg2rad($i);
			$radius = ($i / $totalPoints) * $maxRadius;
			$x = $centerX + cos($angle) * $radius;
			$y = $centerY + sin($angle) * $radius;
			$points[] = ['x' => $x, 'y' => $y];
		}

		$aaThreshold = 1.5;
		$searchRadius = $thickness + $aaThreshold;
		$searchRadiusSq = $searchRadius * $searchRadius;
		$thicknessSq = $thickness * $thickness;

		// Draw spiral with squared distance checks for performance
		$processed = [];
		foreach ($points as $point) {
			$px0 = (int) round($point['x']);
			$py0 = (int) round($point['y']);

			// Skip if we've already processed this pixel location
			$key = "$px0,$py0";
			if (isset($processed[$key])) {
				continue;
			}
			$processed[$key] = true;

			// Only process pixels within bounding box
			$minX = max(0, (int) floor($point['x'] - $searchRadius));
			$maxX = min($width - 1, (int) ceil($point['x'] + $searchRadius));
			$minY = max(0, (int) floor($point['y'] - $searchRadius));
			$maxY = min($height - 1, (int) ceil($point['y'] + $searchRadius));

			for ($px = $minX; $px <= $maxX; $px++) {
				for ($py = $minY; $py <= $maxY; $py++) {
					$colorIndex = imagecolorat($image, $px, $py);
					$pixelColor = imagecolorsforindex($image, $colorIndex);

					if ($pixelColor['alpha'] < 127) {
						// Use squared distances to avoid sqrt in inner loop
						$distX = $px - $point['x'];
						$distY = $py - $point['y'];
						$distSq = $distX * $distX + $distY * $distY;

						if ($distSq > $searchRadiusSq) {
							continue; // Outside threshold
						}

						$distance = sqrt($distSq);

						// Calculate alpha with smooth falloff
						if ($distance <= $thickness) {
							$alpha = 127;
						} else {
							$falloff = ($distance - $thickness) / $aaThreshold;
							$alpha = (int) (127 * max(0, 1 - $falloff * $falloff));
						}

						if ($alpha > 0) {
							$newColor = imagecolorallocatealpha(
								$image,
								min(255, (int) ($pixelColor['red'] + $red * 0.5)),
								min(255, (int) ($pixelColor['green'] + $green * 0.5)),
								min(255, (int) ($pixelColor['blue'] + $blue * 0.5)),
								max(0, $pixelColor['alpha'] - (int) ($alpha * 0.5)),
							);
							if ($newColor !== false) {
								imagesetpixel($image, $px, $py, $newColor);
							}
						}
					}
				}
			}
		}

		return $image;
	}

	// Cosmetics List

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
			'red_stripes' => [
				'price' => 40,
				'rarity' => 'normal',
				'apply' => function (GdImage $image) {
					return self::stripes($image, 0xff0000, 50);
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
			'blur' => [
				'price' => 75,
				'rarity' => 'rare',
				'apply' => function (GdImage $image) {
					for ($i = 0; $i < 30; $i++) {
						imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR, 999);
					}
					imagefilter($image, IMG_FILTER_SMOOTH, 99);
					return $image;
				},
			],
			'dark_green_ring' => [
				'price' => 60,
				'rarity' => 'rare',
				'apply' => function (GdImage $image) {
					return self::ring($image, 0x006400);
				},
			],
			'purple_stripes' => [
				'price' => 65,
				'rarity' => 'rare',
				'apply' => function (GdImage $image) {
					return self::stripes($image, 0x800080);
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
			'gold_ring' => [
				'price' => 125,
				'rarity' => 'amazing',
				'apply' => function (GdImage $image) {
					return self::ring($image, 0xffd700);
				},
			],
			'orange_ring' => [
				'price' => 125,
				'rarity' => 'amazing',
				'apply' => function (GdImage $image) {
					return self::ring($image, 0xffa500);
				},
			],
			'red_overlay' => [
				'price' => 150,
				'rarity' => 'amazing',
				'apply' => function (GdImage $image) {
					return self::overlay($image, 0xff0000, 0.5);
				},
			],
			'blue_overlay' => [
				'price' => 150,
				'rarity' => 'amazing',
				'apply' => function (GdImage $image) {
					return self::overlay($image, 0x0000ff, 0.5);
				},
			],
			'yellow_spiral' => [
				'price' => 175,
				'rarity' => 'amazing',
				'apply' => function (GdImage $image) {
					return self::spiral($image, 0xffff00);
				},
			],
			// green cosmetics
			'green_overlay' => [
				'price' => 200,
				'rarity' => 'green',
				'apply' => function (GdImage $image) {
					return self::overlay($image, 0x00ff00, 0.5);
				},
			],
			'pink_ring' => [
				'price' => 175,
				'rarity' => 'green',
				'apply' => function (GdImage $image) {
					return self::ring($image, 0xff69b4);
				},
			],
			'green_stripes' => [
				'price' => 180,
				'rarity' => 'green',
				'apply' => function (GdImage $image) {
					return self::stripes($image, 0x00ff00);
				},
			],
			'green_spiral' => [
				'price' => 225,
				'rarity' => 'green',
				'apply' => function (GdImage $image) {
					return self::spiral($image, 0x00ff00, 5, 15);
				},
			],
			'fog' => [
				'price' => 250,
				'rarity' => 'green',
				'apply' => function (GdImage $image) {
					for ($i = 0; $i < 20; $i++) {
						imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR, 999);
					}
					imagefilter($image, IMG_FILTER_SMOOTH, 99);

					return self::overlay($image, 0xcccccc, 0.3);
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
			// null value will reset to default
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
		if (!$availableCosmetics) {
			return [];
		}
		$decoded = json_decode($availableCosmetics, true);
		return is_array($decoded) ? $decoded : [];
	}

	public static function purchaseCosmetic(UserInterface $user, string $cosmeticKey): ?JsonResponse
	{
		$cosmetics = self::cosmetics();
		if (!isset($cosmetics[$cosmeticKey])) {
			return GeneralHelper::badRequest('Invalid cosmetic key');
		}

		$availableCosmetics = self::getAvailableCosmetics($user);
		if (in_array($cosmeticKey, $availableCosmetics, true)) {
			return GeneralHelper::conflict('Cosmetic already purchased');
		}

		$price = $cosmetics[$cosmeticKey]['price'];
		$currentPoints = self::getPoints($user)[0];
		if ($currentPoints < $price && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::badRequest('Not enough points to purchase this cosmetic');
		}

		$availableCosmetics[] = $cosmeticKey;
		$user->set('field_available_cosmetics', json_encode($availableCosmetics));
		try {
			$user->save();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error(
				'Failed to save user cosmetics for user %uid: %message',
				[
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
			return GeneralHelper::internalError('Failed to save user cosmetics');
		}

		self::removePoints($user, $price, 'Purchased cosmetic: ' . $cosmeticKey);
		self::invalidateUserCache($user);

		// users are likely to view/use the cosmetic they just purchased
		try {
			$userId = GeneralHelper::formatId($user->id());
			$baseDataUrl = UsersHelper::getProfilePhoto($user, 1024);
			if ($baseDataUrl) {
				foreach ([32, 128, 1024] as $size) {
					$cacheKey = 'cloud:user:photo:' . $userId . ':' . $size . ':' . $cosmeticKey;
					// only generate if not already cached
					if (RedisHelper::get($cacheKey) === null) {
						$sizedDataUrl = UsersHelper::getProfilePhoto($user, $size);
						if ($sizedDataUrl) {
							$cosmeticDataUrl = self::applyCosmetic($sizedDataUrl, $cosmeticKey);
							if ($cosmeticDataUrl) {
								RedisHelper::set($cacheKey, ['dataUrl' => $cosmeticDataUrl], 900);
							}
						}
					}
				}
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'Failed to pre-cache cosmetic previews for user %uid: %message',
				[
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
		}

		return null;
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
			return null;
		}

		$applyCosmetic = $cosmetics[$cosmeticKey]['apply'];

		$imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl), true);
		if ($imageData === false || strlen($imageData) === 0) {
			return null;
		}

		// image size safety check (max 10MB)
		if (strlen($imageData) > 10 * 1024 * 1024) {
			Drupal::logger('mantle2')->warning('Image too large for cosmetic processing');
			return null;
		}

		$image = imagecreatefromstring($imageData);
		if ($image === false) {
			return null;
		}

		// Additional safety: check image dimensions
		$width = imagesx($image);
		$height = imagesy($image);
		if ($width === false || $height === false || $width > 4096 || $height > 4096) {
			return null;
		}

		try {
			$modifiedImage = $applyCosmetic($image);
			if (!$modifiedImage) {
				return null;
			}
			ob_start();
			imagepng($modifiedImage);
			$modifiedData = ob_get_clean();

			if ($modifiedData === false || strlen($modifiedData) === 0) {
				return null;
			}

			return 'data:image/png;base64,' . base64_encode($modifiedData);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to apply cosmetic: %message', [
				'%message' => $e->getMessage(),
			]);
			return null;
		}
	}

	public static function getAvatar(
		UserInterface $user,
		?string $cosmeticKey = null,
		int $size = 1024,
	): ?string {
		$userId = GeneralHelper::formatId($user->id());

		// if no key provided, return base photo
		if (!$cosmeticKey) {
			return UsersHelper::getProfilePhoto($user, $size);
		}

		// check cache for cosmetic-applied image
		$cacheKey = 'cloud:user:photo:' . $userId . ':' . $size . ':' . $cosmeticKey;
		$cached = RedisHelper::get($cacheKey);
		if ($cached !== null && isset($cached['dataUrl'])) {
			return $cached['dataUrl'];
		}

		$dataUrl = UsersHelper::getProfilePhoto($user, $size);
		if (!$dataUrl) {
			return null;
		}

		// Apply cosmetic using centralized method
		$result = self::applyCosmetic($dataUrl, $cosmeticKey);

		// Cache the result (or the original if cosmetic failed)
		$finalResult = $result ?? $dataUrl;
		RedisHelper::set($cacheKey, ['dataUrl' => $finalResult], 3600); // Cache for 1 hour

		return $finalResult;
	}

	// Quests

	/** @return Quest[] */
	public static function getAllQuests(): array
	{
		$cacheKey = 'cloud:quests:all';
		return array_map(
			fn($questData) => Quest::fromArray($questData),
			RedisHelper::cache(
				$cacheKey,
				function () {
					$data = CloudHelper::sendRequest('/v1/users/quests');
					return is_array($data) ? $data : [];
				},
				3600,
			),
		);
	}

	public static function getQuest(string $questId): ?Quest
	{
		$allQuests = self::getAllQuests();
		foreach ($allQuests as $quest) {
			if ($quest->id === $questId) {
				return $quest;
			}
		}
		return null;
	}

	public static function getCurrentQuest(UserInterface $user): QuestData
	{
		$data =
			CloudHelper::sendRequest(
				'/v1/users/quests/progress/' . GeneralHelper::formatId($user->id()),
			) ?? [];

		// avoid caching since updating is not handled over mantle2
		return QuestData::fromArray($data);
	}

	public static function getCurrentQuestStepProgress(UserInterface $user, int $index): array
	{
		$cacheKey = 'cloud:quest:' . GeneralHelper::formatId($user->id()) . ':step:' . $index;
		return RedisHelper::cache(
			$cacheKey,
			function () use ($user, $index) {
				$data = CloudHelper::sendRequest(
					'/v1/users/quests/progress/' .
						GeneralHelper::formatId($user->id()) .
						'/step/' .
						$index,
				);

				return is_array($data) ? $data : [];
			},
			10,
		);
	}

	public static function hasOngoingQuest(UserInterface $user): bool
	{
		$currentQuest = self::getCurrentQuest($user);
		return $currentQuest->questId !== null;
	}

	public static function startQuest(UserInterface $user, string $questId): bool
	{
		try {
			CloudHelper::sendRequest(
				'/v1/users/quests/progress/' . GeneralHelper::formatId($user->id()),
				'POST',
				[
					'quest_id' => $questId,
				],
			);

			// Invalidate quest-related caches
			RedisHelper::delete('cloud:quest:' . GeneralHelper::formatId($user->id()) . ':current');
			return true;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error(
				'Failed to start quest %questId for user %uid: %message',
				[
					'%questId' => $questId,
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
			return false;
		}
	}

	public static function resetQuest(UserInterface $user): bool
	{
		try {
			CloudHelper::sendRequest(
				'/v1/users/quests/progress/' . GeneralHelper::formatId($user->id()),
				'DELETE',
			);

			// Invalidate quest-related caches
			RedisHelper::delete('cloud:quest:' . GeneralHelper::formatId($user->id()) . ':current');
			return true;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to reset quest for user %uid: %message', [
				'%uid' => $user->id(),
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * @return Quest[]
	 */
	public static function getCompletedQuests(UserInterface $user): array
	{
		$cacheKey = 'cloud:quest:' . GeneralHelper::formatId($user->id()) . ':completed';
		return array_map(
			fn($questData) => Quest::fromArray($questData['quest'] ?? []),
			RedisHelper::cache(
				$cacheKey,
				function () use ($user) {
					$data = CloudHelper::sendRequest(
						'/v1/users/quests/history/' . GeneralHelper::formatId($user->id()),
					);
					return is_array($data) ? $data : [];
				},
				15,
			),
		);
	}

	public static function getCompletedQuestResponses(UserInterface $user, string $questId): array
	{
		$cacheKey = 'cloud:quest:' . GeneralHelper::formatId($user->id()) . ':history:' . $questId;
		return RedisHelper::cache(
			$cacheKey,
			function () use ($user, $questId) {
				$data = CloudHelper::sendRequest(
					'/v1/users/quests/history/' .
						GeneralHelper::formatId($user->id()) .
						'/' .
						$questId,
				);
				return is_array($data) ? $data : [];
			},
			1800,
		);
	}
}
