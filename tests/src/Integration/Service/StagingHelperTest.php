<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\StagingHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class StagingHelperTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	private function organizer(bool $verified = true): UserInterface
	{
		$user = $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ORGANIZER,
				AccountType::cases(),
				true,
			),
		]);
		$user->set('field_verified_publisher', $verified);
		$user->save();

		return $user;
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
	}

	private function activity(string $id = 'bouldering'): Activity
	{
		return new Activity(
			$id,
			ucfirst(str_replace('_', ' ', $id)),
			['SPORT'],
			'A long enough description of the proposed activity for review purposes.',
			['climbing'],
			['icon' => 'mdi:climbing'],
		);
	}

	// #region Classification

	public static function windowProvider(): array
	{
		return [
			'organizer gets 48h' => ['organizer', StagingHelper::WINDOW_ORGANIZER],
			'admin gets 24h' => ['admin', StagingHelper::WINDOW_PRIVILEGED],
			'cloud gets 24h' => ['cloud', StagingHelper::WINDOW_PRIVILEGED],
		];
	}

	#[Test]
	#[TestDox('The review window is 48h for organizers and 24h for privileged submitters')]
	#[Group('mantle2/staging')]
	#[DataProvider('windowProvider')]
	public function testWindowFor(string $kind, int $expected): void
	{
		$this->assertSame($expected, StagingHelper::windowFor($kind));
	}

	#[Test]
	#[TestDox('kindFor resolves cloud from uid 1, admin from the tier, organizer otherwise')]
	#[Group('mantle2/staging')]
	public function testKindFor(): void
	{
		$this->assertSame(StagingHelper::KIND_CLOUD, StagingHelper::kindFor(UsersHelper::cloud()));
		$this->assertSame(StagingHelper::KIND_ADMIN, StagingHelper::kindFor($this->admin()));
		$this->assertSame(
			StagingHelper::KIND_ORGANIZER,
			StagingHelper::kindFor($this->organizer()),
		);
	}

	#[Test]
	#[TestDox('failsOpen is a deny-list, so an unknown submitter kind fails CLOSED')]
	#[Group('mantle2/staging')]
	public function testFailsOpenIsADenyList(): void
	{
		$this->assertTrue(StagingHelper::failsOpen(StagingHelper::KIND_ADMIN));
		$this->assertTrue(StagingHelper::failsOpen(StagingHelper::KIND_CLOUD));
		$this->assertFalse(StagingHelper::failsOpen(StagingHelper::KIND_ORGANIZER));
		// anything corrupt must not auto-publish
		$this->assertFalse(StagingHelper::failsOpen('organizer'));
	}

	#[Test]
	#[TestDox('The dedup hash folds case so Rock_Climbing and rock_climbing collide')]
	#[Group('mantle2/staging')]
	public function testDedupHashFoldsCase(): void
	{
		$this->assertSame(
			StagingHelper::dedupHash('Rock_Climbing'),
			StagingHelper::dedupHash('  rock_climbing '),
		);
	}

	// #endregion

	// #region Staging

	#[Test]
	#[TestDox('stage stores the payload, kind, and deadline, and round-trips the activity')]
	#[Group('mantle2/staging')]
	public function testStagePersistsPayload(): void
	{
		$organizer = $this->organizer();
		$now = 1_800_000_000;

		$row = StagingHelper::stage($this->activity(), $organizer, 'please review', 'api', $now);

		$this->assertIsArray($row);
		$this->assertSame('bouldering', $row['activity_id']);
		$this->assertSame(StagingHelper::KIND_ORGANIZER, $row['submitter_kind']);
		$this->assertSame(StagingHelper::STATE_PENDING, $row['state']);
		$this->assertSame($now, (int) $row['submitted_at']);
		$this->assertSame($now + StagingHelper::WINDOW_ORGANIZER, (int) $row['expires_at']);
		$this->assertSame('please review', $row['note']);
		$this->assertNull($row['decided_at']);

		$activity = Activity::fromArray(json_decode($row['payload'], true));
		$this->assertSame(['SPORT'], $activity->getTypes());
		$this->assertSame(['climbing'], $activity->getAliases());
		$this->assertSame('mdi:climbing', $activity->getField('icon'));
	}

	#[Test]
	#[TestDox('A cloud submission is marked fails_open with a 24 hour window')]
	#[Group('mantle2/staging')]
	public function testCloudSubmissionFailsOpen(): void
	{
		$now = 1_800_000_000;
		$row = StagingHelper::stage(
			$this->activity('kayaking'),
			UsersHelper::cloud(),
			null,
			'cloud_discovery',
			$now,
		);

		$this->assertSame(StagingHelper::KIND_CLOUD, $row['submitter_kind']);
		$this->assertSame('cloud_discovery', $row['source']);
		$this->assertSame($now + StagingHelper::WINDOW_PRIVILEGED, (int) $row['expires_at']);
		$this->assertTrue(StagingHelper::rowToArray($row)['fails_open']);
	}

	#[Test]
	#[TestDox('An unknown source falls back to api rather than being stored verbatim')]
	#[Group('mantle2/staging')]
	public function testUnknownSourceIsRejected(): void
	{
		$row = StagingHelper::stage($this->activity('surfing'), $this->admin(), null, 'spoofed');

		$this->assertSame('api', $row['source']);
	}

	#[Test]
	#[TestDox('findPending matches a case variant of an already staged id')]
	#[Group('mantle2/staging')]
	public function testFindPendingIsCaseInsensitive(): void
	{
		StagingHelper::stage($this->activity('trail_running'), $this->organizer());

		$this->assertNotNull(StagingHelper::findPending('Trail_Running'));
		$this->assertNull(StagingHelper::findPending('something_else'));
	}

	// #endregion

	// #region Decisions

	#[Test]
	#[TestDox('approve publishes the activity and credits the submitter, not the reviewer')]
	#[Group('mantle2/staging')]
	public function testApprovePublishesCreditingSubmitter(): void
	{
		$organizer = $this->organizer();
		$reviewer = $this->admin();
		$row = StagingHelper::stage($this->activity(), $organizer);

		$result = StagingHelper::approve((int) $row['id'], $reviewer, 'looks good');

		$this->assertIsArray($result);
		$this->assertSame(StagingHelper::STATE_APPROVED, $result['state']);
		$this->assertSame((int) $reviewer->id(), (int) $result['reviewer_id']);
		$this->assertNotNull($result['published_nid']);

		$node = ActivityHelper::getNodeByActivityId('bouldering');
		$this->assertNotNull($node);
		$this->assertSame((int) $organizer->id(), (int) $node->getOwnerId());
	}

	#[Test]
	#[TestDox('A second approve is refused by the compare-and-swap and creates no second node')]
	#[Group('mantle2/staging')]
	public function testDoubleApproveIsImpossible(): void
	{
		$row = StagingHelper::stage($this->activity(), $this->organizer());
		$reviewer = $this->admin();

		$this->assertIsArray(StagingHelper::approve((int) $row['id'], $reviewer));
		$this->assertSame('already_decided', StagingHelper::approve((int) $row['id'], $reviewer));

		$nids = \Drupal::entityQuery('node')
			->accessCheck(false)
			->condition('type', 'activity')
			->condition('field_activity_id', 'bouldering')
			->execute();
		$this->assertCount(1, $nids);
	}

	#[Test]
	#[TestDox('approve corrects the row to denied when the catalog already has the id')]
	#[Group('mantle2/staging')]
	public function testApproveWithCatalogConflict(): void
	{
		$row = StagingHelper::stage($this->activity(), $this->organizer());

		// an admin creates it by hand after staging
		ActivityHelper::createActivity($this->activity());

		$this->assertSame(
			'catalog_conflict',
			StagingHelper::approve((int) $row['id'], $this->admin()),
		);

		$fresh = StagingHelper::get((int) $row['id']);
		$this->assertSame(StagingHelper::STATE_DENIED, $fresh['state']);
		$this->assertStringContainsString('already exists', $fresh['review_notes']);
	}

	#[Test]
	#[TestDox('approve refuses a revoked organizer unless force is set')]
	#[Group('mantle2/staging')]
	public function testApproveBlocksRevokedPublisher(): void
	{
		$organizer = $this->organizer();
		$row = StagingHelper::stage($this->activity(), $organizer);

		$organizer->set('field_verified_publisher', false);
		$organizer->save();

		$this->assertSame('not_verified', StagingHelper::approve((int) $row['id'], $this->admin()));
		// the row must be returned to pending, not left half-decided
		$this->assertSame(
			StagingHelper::STATE_PENDING,
			StagingHelper::get((int) $row['id'])['state'],
		);

		$forced = StagingHelper::approve((int) $row['id'], $this->admin(), null, true);
		$this->assertIsArray($forced);
		$this->assertNotNull(ActivityHelper::getNodeByActivityId('bouldering'));
	}

	#[Test]
	#[TestDox('deny records the decision and publishes nothing')]
	#[Group('mantle2/staging')]
	public function testDeny(): void
	{
		$row = StagingHelper::stage($this->activity(), $this->organizer());
		$reviewer = $this->admin();

		$result = StagingHelper::deny((int) $row['id'], $reviewer, 'not a real activity');

		$this->assertSame(StagingHelper::STATE_DENIED, $result['state']);
		$this->assertSame('not a real activity', $result['review_notes']);
		$this->assertSame((int) $reviewer->id(), (int) $result['reviewer_id']);
		$this->assertNotNull($result['decided_at']);
		$this->assertNull(ActivityHelper::getNodeByActivityId('bouldering'));

		$this->assertSame('already_decided', StagingHelper::deny((int) $row['id'], $reviewer));
	}

	#[Test]
	#[TestDox('withdraw is limited to the submitter or an admin and only while pending')]
	#[Group('mantle2/staging')]
	public function testWithdraw(): void
	{
		$organizer = $this->organizer();
		$stranger = $this->organizer();
		$row = StagingHelper::stage($this->activity(), $organizer);

		$this->assertSame('forbidden', StagingHelper::withdraw((int) $row['id'], $stranger));
		$this->assertTrue(StagingHelper::withdraw((int) $row['id'], $organizer));
		$this->assertSame(
			StagingHelper::STATE_WITHDRAWN,
			StagingHelper::get((int) $row['id'])['state'],
		);
		$this->assertSame('already_decided', StagingHelper::withdraw((int) $row['id'], $organizer));
		$this->assertFalse(StagingHelper::withdraw(999999, $organizer));
	}

	#[Test]
	#[TestDox('revokePendingFor denies only that user pending organizer rows')]
	#[Group('mantle2/staging')]
	public function testRevokePendingFor(): void
	{
		$target = $this->organizer();
		$other = $this->organizer();

		StagingHelper::stage($this->activity('a_one'), $target);
		StagingHelper::stage($this->activity('a_two'), $target);
		StagingHelper::stage($this->activity('b_one'), $other);
		$cloudRow = StagingHelper::stage($this->activity('c_one'), UsersHelper::cloud());

		$count = StagingHelper::revokePendingFor((int) $target->id(), 'revoked');

		$this->assertSame(2, $count);
		$this->assertSame(0, StagingHelper::countPendingFor((int) $target->id()));
		$this->assertSame(1, StagingHelper::countPendingFor((int) $other->id()));
		// cloud rows are never collateral
		$this->assertSame(
			StagingHelper::STATE_PENDING,
			StagingHelper::get((int) $cloudRow['id'])['state'],
		);
	}

	// #endregion

	// #region Expiry

	#[Test]
	#[TestDox('Expiry publishes admin and cloud rows and denies organizer rows')]
	#[Group('mantle2/staging')]
	public function testCheckExpirationsSplitsByKind(): void
	{
		// must clear the longest window so the organizer row is due too
		$past = time() - StagingHelper::WINDOW_ORGANIZER - 100;

		$cloud = StagingHelper::stage(
			$this->activity('cloud_one'),
			UsersHelper::cloud(),
			null,
			'cloud_discovery',
			$past,
		);
		$admin = StagingHelper::stage(
			$this->activity('admin_one'),
			$this->admin(),
			null,
			'api',
			$past,
		);
		$organizer = StagingHelper::stage(
			$this->activity('org_one'),
			$this->organizer(),
			null,
			'api',
			$past,
		);

		StagingHelper::checkExpirations();

		$this->assertSame(
			StagingHelper::STATE_EXPIRED_PUBLISHED,
			StagingHelper::get((int) $cloud['id'])['state'],
		);
		$this->assertSame(
			StagingHelper::STATE_EXPIRED_PUBLISHED,
			StagingHelper::get((int) $admin['id'])['state'],
		);
		$this->assertSame(
			StagingHelper::STATE_EXPIRED_DENIED,
			StagingHelper::get((int) $organizer['id'])['state'],
		);

		$this->assertNotNull(ActivityHelper::getNodeByActivityId('cloud_one'));
		$this->assertNotNull(ActivityHelper::getNodeByActivityId('admin_one'));
		$this->assertNull(ActivityHelper::getNodeByActivityId('org_one'));
	}

	#[Test]
	#[TestDox('Expiry is idempotent across repeated cron ticks')]
	#[Group('mantle2/staging')]
	public function testCheckExpirationsIsIdempotent(): void
	{
		$past = time() - 100000;
		StagingHelper::stage(
			$this->activity('cloud_two'),
			UsersHelper::cloud(),
			null,
			'cloud_discovery',
			$past,
		);

		StagingHelper::checkExpirations();
		StagingHelper::checkExpirations();

		$nids = \Drupal::entityQuery('node')
			->accessCheck(false)
			->condition('type', 'activity')
			->condition('field_activity_id', 'cloud_two')
			->execute();
		$this->assertCount(1, $nids);
	}

	#[Test]
	#[TestDox('A row that is not yet due is left alone')]
	#[Group('mantle2/staging')]
	public function testFutureRowsAreUntouched(): void
	{
		$row = StagingHelper::stage($this->activity('future_one'), UsersHelper::cloud());

		StagingHelper::checkExpirations();

		$this->assertSame(
			StagingHelper::STATE_PENDING,
			StagingHelper::get((int) $row['id'])['state'],
		);
	}

	#[Test]
	#[TestDox('The 12 hour warning fires once per row and only for organizer submissions')]
	#[Group('mantle2/staging')]
	public function testUrgentWarningIsStickyAndScoped(): void
	{
		$soon = time() - StagingHelper::WINDOW_ORGANIZER + 6 * 3600;
		$organizer = StagingHelper::stage(
			$this->activity('urgent_one'),
			$this->organizer(),
			null,
			'api',
			$soon,
		);
		$cloud = StagingHelper::stage(
			$this->activity('urgent_cloud'),
			UsersHelper::cloud(),
			null,
			'cloud_discovery',
			time() - StagingHelper::WINDOW_PRIVILEGED + 6 * 3600,
		);

		StagingHelper::checkExpirations();
		$this->assertSame(1, (int) StagingHelper::get((int) $organizer['id'])['warned_12h']);
		$this->assertSame(0, (int) StagingHelper::get((int) $cloud['id'])['warned_12h']);

		$before = count($this->collectedMail());
		StagingHelper::checkExpirations();
		$this->assertCount($before, $this->collectedMail());
	}

	private function collectedMail(): array
	{
		return array_values(
			array_filter(
				\Drupal::state()->get('system.test_mail_collector') ?? [],
				fn(array $mail) => $mail['id'] === 'mantle2_activity_staged_urgent',
			),
		);
	}

	#[Test]
	#[TestDox('pruneDecided removes rows past the retention window and keeps recent ones')]
	#[Group('mantle2/staging')]
	public function testPruneDecided(): void
	{
		$old = StagingHelper::stage($this->activity('old_one'), $this->organizer());
		$recent = StagingHelper::stage($this->activity('recent_one'), $this->organizer());
		$pending = StagingHelper::stage($this->activity('pending_one'), $this->organizer());

		StagingHelper::deny((int) $old['id'], $this->admin());
		StagingHelper::deny((int) $recent['id'], $this->admin());

		$this->container
			->get('database')
			->update(StagingHelper::TABLE)
			->fields(['decided_at' => time() - StagingHelper::RETENTION - 10])
			->condition('id', (int) $old['id'])
			->execute();

		StagingHelper::pruneDecided();

		$this->assertNull(StagingHelper::get((int) $old['id']));
		$this->assertNotNull(StagingHelper::get((int) $recent['id']));
		$this->assertNotNull(StagingHelper::get((int) $pending['id']));
	}

	// #endregion

	// #region Digest and listing

	#[Test]
	#[TestDox('The digest throttles to once a day and sends nothing when the queue is empty')]
	#[Group('mantle2/staging')]
	public function testRunDailyDigest(): void
	{
		$this->admin();
		StagingHelper::stage($this->activity('digest_one'), $this->organizer());

		StagingHelper::runDailyDigest();
		$first = $this->digestMail();
		$this->assertNotEmpty($first);

		// second run inside the window is throttled
		StagingHelper::runDailyDigest();
		$this->assertCount(count($first), $this->digestMail());

		// an empty queue sends nothing even once the window reopens
		\Drupal::state()->set(StagingHelper::DIGEST_STATE_KEY, 0);
		$this->container
			->get('database')
			->update(StagingHelper::TABLE)
			->fields(['state' => StagingHelper::STATE_DENIED, 'decided_at' => time()])
			->execute();

		StagingHelper::runDailyDigest();
		$this->assertCount(count($first), $this->digestMail());
	}

	private function digestMail(): array
	{
		return array_values(
			array_filter(
				\Drupal::state()->get('system.test_mail_collector') ?? [],
				fn(array $mail) => $mail['id'] === 'mantle2_activity_staged_digest',
			),
		);
	}

	#[Test]
	#[TestDox('Listing filters by state and kind, paginates, and counts pending')]
	#[Group('mantle2/staging')]
	public function testListing(): void
	{
		$organizer = $this->organizer();
		StagingHelper::stage($this->activity('list_one'), $organizer);
		StagingHelper::stage($this->activity('list_two'), $organizer);
		StagingHelper::stage($this->activity('list_cloud'), UsersHelper::cloud());

		$all = StagingHelper::listStaged(['state' => StagingHelper::STATE_PENDING]);
		$this->assertSame(3, $all['total']);

		$byKind = StagingHelper::listStaged([
			'submitter_kind' => StagingHelper::KIND_ORGANIZER,
		]);
		$this->assertSame(2, $byKind['total']);

		$paged = StagingHelper::listStaged([], 1, 2);
		$this->assertCount(2, $paged['items']);
		$this->assertSame(2, $paged['limit']);

		$mine = StagingHelper::listForUser((int) $organizer->id());
		$this->assertSame(2, $mine['total']);

		$this->assertSame(3, StagingHelper::countPending());
		$this->assertSame(1, StagingHelper::countPending(StagingHelper::KIND_CLOUD));
		$this->assertNotNull(StagingHelper::soonestDeadline());
	}

	#[Test]
	#[TestDox('rowToArray exposes the ISO deadline, fails_open, and the published activity id')]
	#[Group('mantle2/staging')]
	public function testRowToArray(): void
	{
		$organizer = $this->organizer();
		$row = StagingHelper::stage($this->activity(), $organizer);

		$payload = StagingHelper::rowToArray($row);
		$this->assertSame('bouldering', $payload['activity']['id']);
		$this->assertFalse($payload['fails_open']);
		$this->assertSame($organizer->getAccountName(), $payload['submitter']['username']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $payload['expires_at']);
		$this->assertGreaterThan(0, $payload['expires_in_seconds']);
		$this->assertNull($payload['published_activity_id']);

		StagingHelper::approve((int) $row['id'], $this->admin());
		$approved = StagingHelper::rowToArray(StagingHelper::get((int) $row['id']));
		$this->assertSame('bouldering', $approved['published_activity_id']);
		$this->assertNotNull($approved['reviewer']);
	}

	// #endregion

	// #region Validation

	#[Test]
	#[TestDox('validateActivityBody keeps the exact error strings the create path relies on')]
	#[Group('mantle2/staging')]
	public function testValidateActivityBody(): void
	{
		$this->assertSame(
			'Invalid JSON',
			$this->decode(StagingHelper::validateActivityBody(['a', 'b']))['message'],
		);
		$this->assertSame(
			'Missing required fields',
			$this->decode(StagingHelper::validateActivityBody(['id' => 'x']))['message'],
		);
		$this->assertSame(
			'Invalid activity type: NOPE',
			$this->decode(
				StagingHelper::validateActivityBody([
					'id' => 'x',
					'name' => 'X',
					'description' => 'd',
					'types' => ['NOPE'],
				]),
			)['message'],
		);

		$activity = StagingHelper::validateActivityBody([
			'id' => 'valid_one',
			'name' => 'Valid One',
			'description' => 'A description',
			'types' => ['SPORT'],
		]);
		$this->assertInstanceOf(Activity::class, $activity);
	}

	// #endregion
}
