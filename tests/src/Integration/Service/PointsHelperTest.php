<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Quest;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\CloudHelper;
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
	protected function tearDown(): void
	{
		CloudHelper::setRequestOverride(null);
		parent::tearDown();
	}

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

	// #region Quest Step Progression

	/** @var array<int,array{path:string,method:string,data:array}> */
	private array $cloudCalls = [];

	/**
	 * Serves a quest-in-progress from the cloud and records every write, so the
	 * step-matching rules can be driven without a live cloud.
	 */
	private function questInProgress(mixed $currentStep, int $index = 0): void
	{
		$this->cloudCalls = [];
		CloudHelper::setRequestOverride(function (string $path, string $method, array $data) use (
			$currentStep,
			$index,
		) {
			$this->cloudCalls[] = ['path' => $path, 'method' => $method, 'data' => $data];

			if ($method === 'GET' && str_contains($path, '/quests/progress/')) {
				return [
					'questId' => 'q_walkabout',
					'currentStep' => $currentStep,
					'currentStepIndex' => $index,
				];
			}

			return [];
		});
	}

	/** the step payloads inside each PATCH to the quest progress update endpoint */
	private function questUpdates(): array
	{
		$updates = [];
		foreach ($this->cloudCalls as $call) {
			if ($call['method'] === 'PATCH' && str_ends_with($call['path'], '/update')) {
				$updates[] = $call['data']['response'] ?? [];
			}
		}
		return $updates;
	}

	private function step(string $type, array $parameters = []): array
	{
		return [
			'type' => $type,
			'description' => ucfirst(str_replace('_', ' ', $type)),
			'parameters' => $parameters,
		];
	}

	private function attendedEvent(array $activities = [], int $attendees = 0): Event
	{
		return new Event(
			1,
			'Trail Cleanup',
			'A cleanup',
			EventType::IN_PERSON,
			$activities,
			40.0,
			-75.0,
			time(),
			null,
			Visibility::PUBLIC,
			array_fill(0, max(0, $attendees - 1), 99),
			[],
			'evt_1',
		);
	}

	#[Test]
	#[TestDox('Attending an event advances an attend_event step')]
	#[Group('mantle2/points')]
	public function attendEventStepAdvances(): void
	{
		$user = $this->createUser();
		$this->questInProgress($this->step('attend_event'), 2);

		PointsHelper::checkQuestProgress($user, null, ['attend_event'], $this->attendedEvent());

		$this->assertSame(
			[
				[
					'event_id' => 'evt_1',
					'type' => 'attend_event',
					'index' => 2,
					'alt_index' => null,
				],
			],
			$this->questUpdates(),
		);
	}

	#[Test]
	#[TestDox('The quest update carries the device envelope and the user rank')]
	#[Group('mantle2/points')]
	public function questUpdateCarriesRank(): void
	{
		$user = $this->userOfType(AccountType::WRITER);
		$this->questInProgress($this->step('attend_event'));

		PointsHelper::checkQuestProgress($user, null, ['attend_event'], $this->attendedEvent());

		$patch = null;
		foreach ($this->cloudCalls as $call) {
			if ($call['method'] === 'PATCH') {
				$patch = $call['data'];
			}
		}
		$this->assertNotNull($patch);
		$this->assertSame('writer', $patch['rank']);
		$this->assertSame('@earth-app/mantle2', $patch['device']['make']);
	}

	#[Test]
	#[TestDox('An attend_event step below its attendee minimum does not advance')]
	#[Group('mantle2/points')]
	public function attendEventRespectsAttendeeMinimum(): void
	{
		$user = $this->createUser();
		$this->questInProgress($this->step('attend_event', [null, 5]));

		// getAttendeesCount counts the host too, so two attendees is short of five
		PointsHelper::checkQuestProgress(
			$user,
			null,
			['attend_event'],
			$this->attendedEvent([], 2),
		);

		$this->assertSame([], $this->questUpdates());
	}

	#[Test]
	#[TestDox('An attend_event step at its attendee minimum advances')]
	#[Group('mantle2/points')]
	public function attendEventMeetsAttendeeMinimum(): void
	{
		$user = $this->createUser();
		$this->questInProgress($this->step('attend_event', [null, 3]));

		PointsHelper::checkQuestProgress(
			$user,
			null,
			['attend_event'],
			$this->attendedEvent([], 3),
		);

		$this->assertCount(1, $this->questUpdates());
	}

	#[Test]
	#[TestDox('An attend_event step advances only for the required activity type')]
	#[Group('mantle2/points')]
	public function attendEventRequiresActivityType(): void
	{
		$user = $this->createUser();
		$requirement = ['type' => 'activity_type', 'value' => 'SPORT'];

		$this->questInProgress($this->step('attend_event', [$requirement]));
		PointsHelper::checkQuestProgress(
			$user,
			null,
			['attend_event'],
			$this->attendedEvent([new Activity('chess', 'Chess', ['HOBBY'])]),
		);
		$this->assertSame([], $this->questUpdates(), 'a hobby event must not satisfy a sport step');

		$this->questInProgress($this->step('attend_event', [$requirement]));
		PointsHelper::checkQuestProgress(
			$user,
			null,
			['attend_event'],
			$this->attendedEvent([new Activity('running', 'Running', ['SPORT'])]),
		);
		$this->assertCount(1, $this->questUpdates());
	}

	#[Test]
	#[TestDox('A bare activity type on the event satisfies an activity_type requirement')]
	#[Group('mantle2/points')]
	public function attendEventMatchesBareActivityType(): void
	{
		$user = $this->createUser();
		$this->questInProgress(
			$this->step('attend_event', [['type' => 'activity_type', 'value' => 'TRAVEL']]),
		);

		PointsHelper::checkQuestProgress(
			$user,
			null,
			['attend_event'],
			$this->attendedEvent([ActivityType::TRAVEL]),
		);

		$this->assertCount(1, $this->questUpdates());
	}

	#[Test]
	#[TestDox('An attend_event step advances only for the required activity id')]
	#[Group('mantle2/points')]
	public function attendEventRequiresSpecificActivity(): void
	{
		$user = $this->createUser();
		$requirement = ['type' => 'activity', 'id' => 'birding'];

		$this->questInProgress($this->step('attend_event', [$requirement]));
		PointsHelper::checkQuestProgress(
			$user,
			null,
			['attend_event'],
			$this->attendedEvent([new Activity('running', 'Running', ['SPORT'])]),
		);
		$this->assertSame([], $this->questUpdates());

		$this->questInProgress($this->step('attend_event', [$requirement]));
		PointsHelper::checkQuestProgress(
			$user,
			null,
			['attend_event'],
			$this->attendedEvent([new Activity('birding', 'Birding', ['HOBBY'])]),
		);
		$this->assertCount(1, $this->questUpdates());
	}

	#[Test]
	#[TestDox('A step of another type is left alone by the attend_event check')]
	#[Group('mantle2/points')]
	public function attendEventIgnoresOtherStepTypes(): void
	{
		$user = $this->createUser();
		$this->questInProgress($this->step('respond_to_prompt'));

		PointsHelper::checkQuestProgress($user, null, ['attend_event'], $this->attendedEvent());

		$this->assertSame([], $this->questUpdates());
	}

	#[Test]
	#[TestDox('Responding to a prompt advances a respond_to_prompt step')]
	#[Group('mantle2/points')]
	public function respondToPromptStepAdvances(): void
	{
		$user = $this->createUser();
		$this->questInProgress($this->step('respond_to_prompt'), 1);

		PointsHelper::checkQuestProgress($user, ['text' => 'I saw a heron'], ['respond_to_prompt']);

		$updates = $this->questUpdates();
		$this->assertCount(1, $updates);
		$this->assertSame('respond_to_prompt', $updates[0]['type']);
		$this->assertSame(1, $updates[0]['index']);
		$this->assertNull($updates[0]['alt_index']);
		$this->assertSame(['text' => 'I saw a heron'], $updates[0]['response_data']);
	}

	#[Test]
	#[TestDox('A respond_to_prompt keyword match is case-insensitive')]
	#[Group('mantle2/points')]
	public function respondToPromptMatchesKeywordIgnoringCase(): void
	{
		$user = $this->createUser();

		$this->questInProgress($this->step('respond_to_prompt', ['heron']));
		PointsHelper::checkQuestProgress(
			$user,
			['response' => 'A HERON flew past'],
			['respond_to_prompt'],
		);
		$this->assertCount(1, $this->questUpdates());

		$this->questInProgress($this->step('respond_to_prompt', ['heron']));
		PointsHelper::checkQuestProgress(
			$user,
			['content' => 'just a sparrow'],
			['respond_to_prompt'],
		);
		$this->assertSame([], $this->questUpdates());
	}

	#[Test]
	#[TestDox('A respond_to_prompt step scoped to an author accepts every author field shape')]
	#[Group('mantle2/points')]
	public function respondToPromptMatchesAuthorInAnyShape(): void
	{
		$user = $this->createUser();
		$step = $this->step('respond_to_prompt', [null, 42]);

		foreach (
			[
				['text' => 'hi', 'author_id' => 42],
				['text' => 'hi', 'owner_id' => 42],
				['text' => 'hi', 'owner' => ['id' => 42]],
			]
			as $data
		) {
			$this->questInProgress($step);
			PointsHelper::checkQuestProgress($user, $data, ['respond_to_prompt']);
			$this->assertCount(1, $this->questUpdates(), json_encode($data));
		}

		$this->questInProgress($step);
		PointsHelper::checkQuestProgress(
			$user,
			['text' => 'hi', 'author_id' => 7],
			['respond_to_prompt'],
		);
		$this->assertSame([], $this->questUpdates(), 'another author must not count');
	}

	#[Test]
	#[TestDox('A respond_to_prompt step with no response data does not advance')]
	#[Group('mantle2/points')]
	public function respondToPromptNeedsData(): void
	{
		$user = $this->createUser();
		$this->questInProgress($this->step('respond_to_prompt'));

		PointsHelper::checkQuestProgress($user, null, ['respond_to_prompt']);

		$this->assertSame([], $this->questUpdates());
	}

	#[Test]
	#[TestDox('Alternative steps are each checked and reported with their own alt index')]
	#[Group('mantle2/points')]
	public function alternativeStepsCarryTheirAltIndex(): void
	{
		$user = $this->createUser();
		$this->questInProgress([$this->step('attend_event'), $this->step('respond_to_prompt')], 4);

		PointsHelper::checkQuestProgress(
			$user,
			['text' => 'done'],
			['attend_event', 'respond_to_prompt'],
			$this->attendedEvent(),
		);

		$updates = $this->questUpdates();
		$this->assertCount(2, $updates);
		$this->assertSame(
			['attend_event', 0, 4],
			[$updates[0]['type'], $updates[0]['alt_index'], $updates[0]['index']],
		);
		$this->assertSame(
			['respond_to_prompt', 1, 4],
			[$updates[1]['type'], $updates[1]['alt_index'], $updates[1]['index']],
		);
	}

	#[Test]
	#[TestDox('The step-type filter decides which checks run at all')]
	#[Group('mantle2/points')]
	public function stepTypeFilterSelectsTheCheck(): void
	{
		$user = $this->createUser();

		$this->questInProgress($this->step('respond_to_prompt'));
		PointsHelper::checkQuestProgress($user, ['text' => 'hello'], ['attend_event']);
		$this->assertSame([], $this->questUpdates(), 'only the attend_event check was requested');

		$this->questInProgress($this->step('respond_to_prompt'));
		PointsHelper::checkQuestProgress($user, ['text' => 'hello'], ['respond_to_prompt']);
		$this->assertCount(1, $this->questUpdates());
	}

	#[Test]
	#[TestDox('A quest with no current step never writes progress')]
	#[Group('mantle2/points')]
	public function questWithoutACurrentStepDoesNothing(): void
	{
		$user = $this->createUser();
		$this->questInProgress(null);

		PointsHelper::checkQuestProgress(
			$user,
			['text' => 'hello'],
			['attend_event', 'respond_to_prompt'],
			$this->attendedEvent(),
		);

		$this->assertSame([], $this->questUpdates());
	}

	#[Test]
	#[TestDox('getQuestForUser scopes the catalog read to the requesting user')]
	#[Group('mantle2/points')]
	public function getQuestForUserScopesTheRead(): void
	{
		$captured = null;
		CloudHelper::setRequestOverride(function (string $path, string $method, array $data) use (
			&$captured,
		) {
			$captured = [$path, $data];
			return [
				'id' => 'q1',
				'title' => 'Walkabout',
				'description' => 'A walk',
				'icon' => 'boot',
			];
		});

		$quest = PointsHelper::getQuestForUser('q1', '7');

		$this->assertInstanceOf(Quest::class, $quest);
		$this->assertSame('/v1/users/quests/q1', $captured[0]);
		$this->assertSame(GeneralHelper::formatId('7'), $captured[1]['user_id']);
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
