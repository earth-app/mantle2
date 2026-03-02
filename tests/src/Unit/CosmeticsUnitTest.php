<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\Service\PointsHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class CosmeticsUnitTest extends TestCase
{
	private static string $outputDir = __DIR__ . '/../../out/cosmetics';
	private static array $cachedImages = [];

	public static function setUpBeforeClass(): void
	{
		// Pre-load all images once to avoid re-parsing PNGs 14 times per image
		$resourcesDir = __DIR__ . '/../../resources';
		$imageFiles = glob($resourcesDir . '/*.png');

		foreach ($imageFiles as $imagePath) {
			$filename = basename($imagePath, '.png');
			self::$cachedImages[$filename] = imagecreatefrompng($imagePath);
		}
	}

	public static function tearDownAfterClass(): void
	{
		self::$cachedImages = [];
	}

	private static function getTestImagePaths(): array
	{
		$resourcesDir = __DIR__ . '/../../resources';
		$imagePaths = [];
		$imageFiles = glob($resourcesDir . '/*.png');

		foreach ($imageFiles as $imagePath) {
			$filename = basename($imagePath, '.png');
			$imagePaths[$filename] = $imagePath;
		}

		return $imagePaths;
	}

	#[Test]
	#[TestDox('Validate cosmetics structure')]
	#[Group('mantle2/cosmetics')]
	public function testCosmeticsStructure(): void
	{
		$cosmetics = PointsHelper::cosmetics();
		$this->assertIsArray($cosmetics);
		$this->assertNotEmpty($cosmetics);

		$validRarities = ['normal', 'rare', 'amazing', 'green'];

		foreach ($cosmetics as $key => $cosmetic) {
			// Validate price
			$this->assertArrayHasKey('price', $cosmetic, "Cosmetic '$key' missing 'price' key");
			$this->assertIsInt($cosmetic['price'], "Cosmetic '$key' price must be an integer");
			$this->assertGreaterThan(
				0,
				$cosmetic['price'],
				"Cosmetic '$key' price must be positive",
			);

			// Validate rarity
			$this->assertArrayHasKey('rarity', $cosmetic, "Cosmetic '$key' missing 'rarity' key");
			$this->assertContains(
				$cosmetic['rarity'],
				$validRarities,
				"Cosmetic '$key' has invalid rarity: {$cosmetic['rarity']}",
			);

			// Validate apply function
			$this->assertArrayHasKey('apply', $cosmetic, "Cosmetic '$key' missing 'apply' key");
			$this->assertIsCallable($cosmetic['apply'], "Cosmetic '$key' 'apply' must be callable");

			// Validate apply function signature using reflection
			$reflection = new \ReflectionFunction($cosmetic['apply']);
			$params = $reflection->getParameters();
			$this->assertCount(
				1,
				$params,
				"Cosmetic '$key' apply function must accept exactly one parameter",
			);

			// Check parameter type
			$param = $params[0];
			$type = $param->getType();
			$this->assertNotNull($type, "Cosmetic '$key' apply function parameter must be typed");
			$this->assertEquals(
				'GdImage',
				$type->getName(),
				"Cosmetic '$key' apply function must accept GdImage parameter",
			);
		}
	}

	/**
	 * Data provider for cosmetic and image combinations
	 */
	public static function cosmeticImageProvider(): array
	{
		$cosmetics = PointsHelper::cosmetics();
		$imagePaths = self::getTestImagePaths();
		$data = [];

		foreach ($cosmetics as $cosmeticKey => $cosmeticData) {
			foreach ($imagePaths as $imageName => $imagePath) {
				$data["$cosmeticKey on $imageName"] = [
					$cosmeticKey,
					$cosmeticData,
					$imageName,
					$imagePath,
				];
			}
		}

		return $data;
	}

	#[Test]
	#[DataProvider('cosmeticImageProvider')]
	#[TestDox('Apply cosmetic "$_dataName"')]
	#[Group('mantle2/cosmetics')]
	public function testApplyCosmetic(
		string $cosmeticKey,
		array $cosmeticData,
		string $imageName,
		string $imagePath,
	): void {
		// Create output directory if needed
		$cosmeticDir = self::$outputDir . '/' . $cosmeticKey;
		if (!is_dir($cosmeticDir)) {
			mkdir($cosmeticDir, 0755, true);
		}

		// Use cached image instead of re-parsing PNG file
		$this->assertArrayHasKey(
			$imageName,
			self::$cachedImages,
			"Cached image not found: $imageName",
		);
		$cachedImage = self::$cachedImages[$imageName];
		$this->assertNotFalse($cachedImage, "Failed to load cached image: $imageName");

		$originalWidth = imagesx($cachedImage);
		$originalHeight = imagesy($cachedImage);

		// Create working copy with alpha support
		$testImage = imagecreatetruecolor($originalWidth, $originalHeight);
		imagesavealpha($testImage, true);
		imagealphablending($testImage, false);
		imagecopy($testImage, $cachedImage, 0, 0, 0, 0, $originalWidth, $originalHeight);
		imagealphablending($testImage, true);

		// Apply cosmetic
		$applyFunction = $cosmeticData['apply'];
		$resultImage = $applyFunction($testImage);

		// Validate result
		$this->assertInstanceOf(\GdImage::class, $resultImage);
		$this->assertEquals($originalWidth, imagesx($resultImage));
		$this->assertEquals($originalHeight, imagesy($resultImage));

		// Save result with no compression for speed
		$outputPath = $cosmeticDir . '/' . $imageName . '.png';
		imagesavealpha($resultImage, true);
		$this->assertTrue(imagepng($resultImage, $outputPath, 0));

		// Verify saved file exists and has correct size
		$this->assertFileExists($outputPath);
		$this->assertGreaterThan(0, filesize($outputPath));

		// Cleanup
		imagedestroy($testImage);
	}

	#[Test]
	#[TestDox('Verify output directory structure')]
	#[Group('mantle2/cosmetics')]
	public function testOutputDirectoryStructure(): void
	{
		$testImagePaths = $this->getTestImagePaths();
		$cosmetics = PointsHelper::cosmetics();
		$expectedImageCount = count($testImagePaths);

		foreach ($cosmetics as $cosmeticKey => $cosmeticData) {
			$cosmeticDir = self::$outputDir . '/' . $cosmeticKey;
			$this->assertDirectoryExists(
				$cosmeticDir,
				"Output directory for cosmetic '$cosmeticKey' does not exist",
			);

			$images = glob($cosmeticDir . '/*.png');
			$this->assertCount(
				$expectedImageCount,
				$images,
				"Cosmetic '$cosmeticKey' directory should contain $expectedImageCount images",
			);
		}
	}
}
