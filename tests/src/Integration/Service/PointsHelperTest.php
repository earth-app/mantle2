<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Quest;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\PointsHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class PointsHelperTest extends IntegrationTestBase
{
	private function userOfType(AccountType $type): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search($type, AccountType::cases(), true),
		]);
	}

	// small solid-color truetype PNG data url; big enough for ring/spiral geometry
	private function pngDataUrl(int $w = 64, int $h = 64): string
	{
		$img = imagecreatetruecolor($w, $h);
		imagesavealpha($img, true);
		$fill = imagecolorallocate($img, 120, 160, 200);
		imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $fill);
		ob_start();
		imagepng($img);
		$data = ob_get_clean();
		return 'data:image/png;base64,' . base64_encode($data);
	}

	private function decodePng(string $dataUrl): string
	{
		$raw = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl), true);
		return $raw === false ? '' : $raw;
	}

	private function seedPoints(UserInterface $user, int $points, array $history = []): void
	{
		RedisHelper::set(
			'cloud:points:' . GeneralHelper::formatId($user->id()),
			[
				'points' => $points,
				'history' => $history,
			],
			180,
		);
	}

	// #region Cosmetics List

	#[Test]
	#[
		TestDox(
			'cosmetics catalog: every key carries a positive price, a rarity, and an apply closure',
		),
	]
	#[Group('mantle2/points')]
	public function cosmeticsShape(): void
	{
		$cosmetics = PointsHelper::cosmetics();
		$this->assertNotEmpty($cosmetics);

		$rarities = ['normal', 'rare', 'amazing', 'green'];
		$animatedCount = 0;
		foreach ($cosmetics as $key => $data) {
			$this->assertIsString($key);
			$this->assertArrayHasKey('price', $data, "$key price");
			$this->assertIsInt($data['price']);
			$this->assertGreaterThan(0, $data['price']);
			$this->assertArrayHasKey('rarity', $data, "$key rarity");
			$this->assertContains($data['rarity'], $rarities, "$key rarity value");
			$this->assertArrayHasKey('apply', $data, "$key apply");
			$this->assertIsCallable($data['apply']);
			if (!empty($data['animated'])) {
				$animatedCount++;
				$this->assertTrue($data['animated']);
			}
		}

		$this->assertArrayHasKey('animated_gold_ring', $cosmetics);
		$this->assertTrue($cosmetics['animated_gold_ring']['animated']);
		$this->assertArrayNotHasKey('animated', $cosmetics['grayscale']);
		$this->assertSame(2, $animatedCount);
	}

	// #endregion

	// #region Discount + Quest Delay Math

	public static function accountTypes(): array
	{
		return [
			'free' => [AccountType::FREE, 0.0, 0.0],
			'pro' => [AccountType::PRO, 0.1, 0.1],
			'writer' => [AccountType::WRITER, 0.45, 0.25],
			'organizer' => [AccountType::ORGANIZER, 0.6, 0.5],
			'administrator' => [AccountType::ADMINISTRATOR, 1.0, 1.0],
		];
	}

	#[Test]
	#[TestDox('getPriceDiscount + getQuestDelayReduction return the per-account-type curves')]
	#[Group('mantle2/points')]
	#[DataProvider('accountTypes')]
	public function discountAndReduction(
		AccountType $type,
		float $expectedDiscount,
		float $expectedReduction,
	): void {
		$user = $this->userOfType($type);
		$this->assertSame($expectedDiscount, PointsHelper::getPriceDiscount($user));
		$this->assertSame($expectedReduction, PointsHelper::getQuestDelayReduction($user));
	}

	#[Test]
	#[
		TestDox(
			'getCosmeticsCatalog applies the account-type discount and preserves full price + rarity + animated',
		),
	]
	#[Group('mantle2/points')]
	#[DataProvider('accountTypes')]
	public function catalogDiscount(AccountType $type, float $expectedDiscount): void
	{
		$user = $this->userOfType($type);
		$catalog = PointsHelper::getCosmeticsCatalog($user);
		$raw = PointsHelper::cosmetics();

		$this->assertSameSize($raw, $catalog);
		foreach ($catalog as $entry) {
			$this->assertArrayHasKey('key', $entry);
			$this->assertArrayHasKey('price', $entry);
			$this->assertArrayHasKey('discount', $entry);
			$this->assertArrayHasKey('full_price', $entry);
			$this->assertArrayHasKey('rarity', $entry);
			$this->assertArrayHasKey('animated', $entry);

			$full = $raw[$entry['key']]['price'];
			$this->assertSame($full, $entry['full_price']);
			$this->assertSame($expectedDiscount, $entry['discount']);
			$this->assertSame((int) round($full * (1 - $expectedDiscount)), $entry['price']);
			$this->assertIsBool($entry['animated']);
			$this->assertSame(!empty($raw[$entry['key']]['animated']), $entry['animated']);
		}
	}

	#[Test]
	#[TestDox('getCosmeticsCatalog with a null user applies no discount (full price, discount 0)')]
	#[Group('mantle2/points')]
	public function catalogNoUser(): void
	{
		$catalog = PointsHelper::getCosmeticsCatalog(null);
		$raw = PointsHelper::cosmetics();
		$this->assertSameSize($raw, $catalog);
		foreach ($catalog as $entry) {
			$this->assertSame(0.0, $entry['discount']);
			$this->assertSame($raw[$entry['key']]['price'], $entry['price']);
			$this->assertSame($raw[$entry['key']]['price'], $entry['full_price']);
		}
	}

	public static function questDelayCases(): array
	{
		// delaySeconds, type, expected effective delay
		return [
			'zero delay short-circuits' => [0, AccountType::FREE, 0],
			'negative delay short-circuits' => [-500, AccountType::PRO, 0],
			'free keeps full delay' => [1000, AccountType::FREE, 1000],
			'pro 10 percent off' => [1000, AccountType::PRO, 900],
			'writer 25 percent off' => [1000, AccountType::WRITER, 750],
			'organizer 50 percent off' => [1000, AccountType::ORGANIZER, 500],
			'admin bypasses entirely' => [1000, AccountType::ADMINISTRATOR, 0],
			'rounding half up' => [101, AccountType::PRO, 91],
		];
	}

	#[Test]
	#[
		TestDox(
			'getEffectiveQuestStepDelay: zero/negative short-circuit, admin bypass, and rank rounding',
		),
	]
	#[Group('mantle2/points')]
	#[DataProvider('questDelayCases')]
	public function effectiveDelay(int $delay, AccountType $type, int $expected): void
	{
		$user = $this->userOfType($type);
		$this->assertSame($expected, PointsHelper::getEffectiveQuestStepDelay($delay, $user));
	}

	// #endregion

	// #region applyCosmetic (GD)

	public static function cosmeticKeys(): array
	{
		$keys = array_keys(PointsHelper::cosmetics());
		$cases = [];
		foreach ($keys as $key) {
			$cases[$key] = [$key];
		}
		return $cases;
	}

	#[Test]
	#[
		TestDox(
			'applyCosmetic runs every cosmetic key over a real PNG and returns a decodable PNG data url',
		),
	]
	#[Group('mantle2/points')]
	#[DataProvider('cosmeticKeys')]
	public function applyEachCosmetic(string $key): void
	{
		$out = PointsHelper::applyCosmetic($this->pngDataUrl(), $key);
		$this->assertNotNull($out, "$key returned null");
		$this->assertStringStartsWith('data:image/png;base64,', $out);

		$raw = $this->decodePng($out);
		$this->assertNotSame('', $raw);
		// PNG magic bytes
		$this->assertSame("\x89PNG\r\n\x1a\n", substr($raw, 0, 8), "$key not a PNG");
		$decoded = imagecreatefromstring($raw);
		$this->assertNotFalse($decoded, "$key output not a valid image");
		$this->assertSame(64, imagesx($decoded));
		$this->assertSame(64, imagesy($decoded));
	}

	#[Test]
	#[TestDox('applyCosmetic returns null on invalid key, malformed data url, and empty data')]
	#[Group('mantle2/points')]
	public function applyCosmeticRejections(): void
	{
		$png = $this->pngDataUrl();
		$this->assertNull(PointsHelper::applyCosmetic($png, 'does_not_exist'));
		$this->assertNull(
			PointsHelper::applyCosmetic('data:image/png;base64,not-base64!!!', 'grayscale'),
		);
		$this->assertNull(PointsHelper::applyCosmetic('', 'grayscale'));
		$this->assertNull(PointsHelper::applyCosmetic('data:image/png;base64,', 'grayscale'));
	}

	#[Test]
	#[TestDox('applyCosmetic rejects images over the 4096px dimension guard')]
	#[Group('mantle2/points')]
	public function applyCosmeticOversizedGuard(): void
	{
		$this->assertNull(PointsHelper::applyCosmetic($this->pngDataUrl(4100, 10), 'grayscale'));
	}

	// #endregion

	// #region getAvailableCosmetics / getAvatarCosmetic / setAvatarCosmetic

	#[Test]
	#[
		TestDox(
			'getAvailableCosmetics: empty, valid json array, and invalid json all resolve to arrays',
		),
	]
	#[Group('mantle2/points')]
	public function availableCosmetics(): void
	{
		$empty = $this->createUser();
		$this->assertSame([], PointsHelper::getAvailableCosmetics($empty));

		$valid = $this->createUser([
			'field_available_cosmetics' => json_encode(['grayscale', 'invert']),
		]);
		$this->assertSame(['grayscale', 'invert'], PointsHelper::getAvailableCosmetics($valid));

		$invalid = $this->createUser(['field_available_cosmetics' => 'not json {']);
		$this->assertSame([], PointsHelper::getAvailableCosmetics($invalid));

		// json that decodes to a non-array falls back to []
		$scalar = $this->createUser(['field_available_cosmetics' => '"grayscale"']);
		$this->assertSame([], PointsHelper::getAvailableCosmetics($scalar));
	}

	#[Test]
	#[TestDox('getAvatarCosmetic returns null when unset and the stored key otherwise')]
	#[Group('mantle2/points')]
	public function getAvatarCosmetic(): void
	{
		$none = $this->createUser();
		$this->assertNull(PointsHelper::getAvatarCosmetic($none));

		$set = $this->createUser(['field_selected_cosmetic' => 'grayscale']);
		$this->assertSame('grayscale', PointsHelper::getAvatarCosmetic($set));
	}

	#[Test]
	#[
		TestDox(
			'setAvatarCosmetic rejects an unavailable key, sets an available key, and clears with null',
		),
	]
	#[Group('mantle2/points')]
	public function setAvatarCosmetic(): void
	{
		$user = $this->createUser([
			'field_available_cosmetics' => json_encode(['grayscale']),
		]);

		// unavailable key is ignored
		PointsHelper::setAvatarCosmetic($user, 'gold_ring');
		$this->assertNull(PointsHelper::getAvatarCosmetic($user));

		// available key sticks
		PointsHelper::setAvatarCosmetic($user, 'grayscale');
		$this->assertSame('grayscale', PointsHelper::getAvatarCosmetic($user));

		// null clears
		PointsHelper::setAvatarCosmetic($user, null);
		$this->assertNull(PointsHelper::getAvatarCosmetic($user));
	}

	// #endregion

	// #region getAvatar

	#[Test]
	#[TestDox('getAvatar with no cosmetic key returns the base profile photo (degraded cloud)')]
	#[Group('mantle2/points')]
	public function getAvatarNoKey(): void
	{
		$user = $this->createUser();
		// dead cloud endpoint -> getProfilePhoto degrades to '' (falsy string, not null)
		$this->assertSame('', PointsHelper::getAvatar($user, null, 128));
	}

	#[Test]
	#[TestDox('getAvatar with a cosmetic key returns a cached cosmetic data url on a cache hit')]
	#[Group('mantle2/points')]
	public function getAvatarCacheHit(): void
	{
		$user = $this->createUser();
		$userId = GeneralHelper::formatId($user->id());
		$dataUrl = $this->pngDataUrl();
		RedisHelper::set(
			'cloud:user:photo:' . $userId . ':128:grayscale',
			['dataUrl' => $dataUrl],
			3600,
		);

		$this->assertSame($dataUrl, PointsHelper::getAvatar($user, 'grayscale', 128));
	}

	#[Test]
	#[
		TestDox(
			'getAvatar with a cosmetic key but no base photo returns null (degraded cloud, cache miss)',
		),
	]
	#[Group('mantle2/points')]
	public function getAvatarCacheMissNoPhoto(): void
	{
		$user = $this->createUser();
		$this->assertNull(PointsHelper::getAvatar($user, 'grayscale', 128));
	}

	// #endregion

	// #region getPoints (degraded cloud)

	#[Test]
	#[TestDox('getPoints returns [0, []] against a dead cloud and honors a seeded cache entry')]
	#[Group('mantle2/points')]
	public function getPointsDegradedAndCached(): void
	{
		$fresh = $this->createUser();
		$this->assertSame([0, []], PointsHelper::getPoints($fresh));

		$cached = $this->createUser();
		$this->seedPoints($cached, 42, [['delta' => 42]]);
		$this->assertSame([42, [['delta' => 42]]], PointsHelper::getPoints($cached));
	}

	#[Test]
	#[
		TestDox(
			'addPoints/removePoints/setPoints degrade to the getPoints tuple when the cloud is dead',
		),
	]
	#[Group('mantle2/points')]
	public function pointsMutationsDegrade(): void
	{
		$user = $this->createUser();
		$this->seedPoints($user, 77, [['delta' => 77]]);

		// dead cloud -> $newPoints null -> returns the current getPoints() tuple (regression)
		$this->assertSame([77, [['delta' => 77]]], PointsHelper::addPoints($user, 10, 'x'));
		$this->assertSame([77, [['delta' => 77]]], PointsHelper::removePoints($user, 10, 'x'));
		$this->assertSame([77, [['delta' => 77]]], PointsHelper::setPoints($user, 10, 'x'));
	}

	// #endregion

	// #region Quests (degraded cloud)

	#[Test]
	#[
		TestDox(
			'quest reads degrade cleanly against a dead cloud: empty lists, empty QuestData, no ongoing quest',
		),
	]
	#[Group('mantle2/points')]
	public function questReadsDegrade(): void
	{
		$user = $this->createUser();

		$this->assertSame([], PointsHelper::getAllQuests());
		$this->assertSame([], PointsHelper::getCompletedQuests($user));
		$this->assertNull(PointsHelper::getQuest('q1'));

		$current = PointsHelper::getCurrentQuest($user);
		$this->assertNull($current->questId);
		$this->assertFalse(PointsHelper::hasOngoingQuest($user));
	}

	#[Test]
	#[
		TestDox(
			'getCurrentQuestStepProgress and getCompletedQuestResponses degrade to [] against a dead cloud',
		),
	]
	#[Group('mantle2/points')]
	public function questStepAndResponseReadsDegrade(): void
	{
		$user = $this->createUser();
		$this->assertSame([], PointsHelper::getCurrentQuestStepProgress($user, 0));
		$this->assertSame([], PointsHelper::getCompletedQuestResponses($user, 'q1'));
	}

	#[Test]
	#[
		TestDox(
			'getCurrentQuest short-circuits to empty QuestData for the cloud (root) user without a request',
		),
	]
	#[Group('mantle2/points')]
	public function currentQuestCloudUserShortCircuit(): void
	{
		$cloud = UsersHelper::cloud();
		$data = PointsHelper::getCurrentQuest($cloud);
		$this->assertNull($data->questId);
		$this->assertNull($data->quest);
		$this->assertNull($data->currentStep);
	}

	#[Test]
	#[TestDox('checkQuestProgress no-ops with an empty stepTypes filter and against a dead cloud')]
	#[Group('mantle2/points')]
	public function checkQuestProgressNoOps(): void
	{
		$user = $this->createUser();
		// empty filter returns immediately; dead cloud yields no current quest
		PointsHelper::checkQuestProgress($user, null, []);
		PointsHelper::checkQuestProgress($user, ['text' => 'hi']);
		$this->assertFalse(PointsHelper::hasOngoingQuest($user));
	}

	// #endregion

	// #region purchaseCosmetic (local branches)

	#[Test]
	#[TestDox('purchaseCosmetic rejects an invalid key with 400')]
	#[Group('mantle2/points')]
	public function purchaseInvalidKey(): void
	{
		$user = $this->createUser();
		$res = PointsHelper::purchaseCosmetic($user, 'nope');
		$this->assertNotNull($res);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
	}

	#[Test]
	#[TestDox('purchaseCosmetic rejects an already-purchased cosmetic with 409')]
	#[Group('mantle2/points')]
	public function purchaseAlreadyOwned(): void
	{
		$user = $this->createUser([
			'field_available_cosmetics' => json_encode(['grayscale']),
		]);
		$res = PointsHelper::purchaseCosmetic($user, 'grayscale');
		$this->assertNotNull($res);
		$this->assertSame(Response::HTTP_CONFLICT, $res->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'purchaseCosmetic rejects a non-admin with too few points (seeded low balance) with 400',
		),
	]
	#[Group('mantle2/points')]
	public function purchaseNotEnoughPoints(): void
	{
		$user = $this->userOfType(AccountType::FREE);
		$this->seedPoints($user, 5);
		// grayscale costs 25 at 0% discount
		$res = PointsHelper::purchaseCosmetic($user, 'grayscale');
		$this->assertNotNull($res);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		// nothing unlocked on failure
		$this->assertSame([], PointsHelper::getAvailableCosmetics($user));
	}

	#[Test]
	#[
		TestDox(
			'purchaseCosmetic succeeds for an admin at 100% discount (price 0, no points spent) and unlocks it',
		),
	]
	#[Group('mantle2/points')]
	public function purchaseAdminFree(): void
	{
		$admin = $this->userOfType(AccountType::ADMINISTRATOR);
		$this->seedPoints($admin, 0);

		$res = PointsHelper::purchaseCosmetic($admin, 'gold_ring');
		$this->assertNull($res, 'admin purchase should succeed (null response)');
		$this->assertContains('gold_ring', PointsHelper::getAvailableCosmetics($admin));
	}

	#[Test]
	#[
		TestDox(
			'purchaseCosmetic lets a non-admin with enough points unlock a cosmetic (seeded balance)',
		),
	]
	#[Group('mantle2/points')]
	public function purchaseEnoughPoints(): void
	{
		$user = $this->userOfType(AccountType::FREE);
		$this->seedPoints($user, 100);

		$res = PointsHelper::purchaseCosmetic($user, 'grayscale');
		$this->assertNull($res, 'purchase with enough points should succeed');
		$this->assertContains('grayscale', PointsHelper::getAvailableCosmetics($user));
	}

	// #endregion

	// #region Quest Share Cards

	private function sampleQuest(string $rarity = 'green', int $reward = 500): Quest
	{
		return Quest::fromArray([
			'id' => 'q1',
			'title' => 'Plant One Hundred Trees Across the Whole Wide Region This Season',
			'description' => 'A long quest',
			'icon' => 'mdi:tree',
			'rarity' => $rarity,
			'reward' => $reward,
			'steps' => [],
		]);
	}

	#[Test]
	#[
		TestDox(
			'renderQuestShareCard returns a valid 1200x630 PNG data url (fonts present, degraded referral)',
		),
	]
	#[Group('mantle2/points')]
	public function renderShareCard(): void
	{
		$user = $this->createUser(['name' => 'tree_planter']);
		$card = PointsHelper::renderQuestShareCard($user, $this->sampleQuest());

		$this->assertStringStartsWith('data:image/png;base64,', $card);
		$raw = $this->decodePng($card);
		$this->assertSame("\x89PNG\r\n\x1a\n", substr($raw, 0, 8));
		$img = imagecreatefromstring($raw);
		$this->assertNotFalse($img);
		$this->assertSame(1200, imagesx($img));
		$this->assertSame(630, imagesy($img));
	}

	#[Test]
	#[
		TestDox(
			'renderQuestShareCard renders each rarity accent and a zero-reward quest without the points line',
		),
	]
	#[Group('mantle2/points')]
	public function renderShareCardVariants(): void
	{
		$user = $this->createUser(['name' => 'planter2']);
		foreach (['normal', 'rare', 'amazing', 'green'] as $rarity) {
			$card = PointsHelper::renderQuestShareCard($user, $this->sampleQuest($rarity, 0));
			$this->assertStringStartsWith('data:image/png;base64,', $card);
			$img = imagecreatefromstring($this->decodePng($card));
			$this->assertNotFalse($img, "rarity $rarity failed to render");
			$this->assertSame(1200, imagesx($img));
		}
	}

	// #endregion
}
