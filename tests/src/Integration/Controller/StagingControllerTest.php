<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\StagingController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\StagingHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StagingControllerTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	private function controller(): StagingController
	{
		return StagingController::create($this->container);
	}

	private function user(
		AccountType $type,
		bool $verified = false,
		bool $email = true,
	): UserInterface {
		$user = $this->createUser([
			'field_account_type' => (string) array_search($type, AccountType::cases(), true),
		]);
		$user->set('field_email_verified', $email);
		$user->set('field_verified_publisher', $verified);
		$user->save();

		return $user;
	}

	private function body(array $overrides = []): string
	{
		return json_encode(
			$overrides + [
				'id' => 'bouldering',
				'name' => 'Bouldering',
				'description' => 'A long enough description of the proposed activity.',
				'types' => ['SPORT'],
			],
		);
	}

	private function seed(string $id, ?UserInterface $submitter = null): array
	{
		return StagingHelper::stage(
			new Activity($id, ucfirst($id), ['SPORT'], 'Seeded description for review.'),
			$submitter ?? $this->user(AccountType::ORGANIZER, true),
		);
	}

	// #region POST /v2/activities/staged

	#[Test]
	#[TestDox('Staging is gated on auth, email verification, and Verified Publisher status')]
	#[Group('mantle2/staging')]
	public function testStageGates(): void
	{
		$anon = $this->controller()->stageActivity(
			$this->request('POST', '/v2/activities/staged', [], $this->body()),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$free = $this->controller()->stageActivity(
			$this->authRequest(
				$this->user(AccountType::FREE),
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(),
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $free->getStatusCode());
		$this->assertStringContainsString('Organizer account', $this->decode($free)['message']);

		$unverifiedPublisher = $this->controller()->stageActivity(
			$this->authRequest(
				$this->user(AccountType::ORGANIZER, false),
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(),
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $unverifiedPublisher->getStatusCode());
		$this->assertStringContainsString(
			'Verified Publisher',
			$this->decode($unverifiedPublisher)['message'],
		);

		$unverifiedEmail = $this->controller()->stageActivity(
			$this->authRequest(
				$this->user(AccountType::ORGANIZER, true, false),
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(),
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $unverifiedEmail->getStatusCode());
	}

	#[Test]
	#[TestDox('A verified organizer stages successfully and gets a fail-closed two-week deadline')]
	#[Group('mantle2/staging')]
	public function testOrganizerStages(): void
	{
		$response = $this->controller()->stageActivity(
			$this->authRequest(
				$this->user(AccountType::ORGANIZER, true),
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(['note' => 'from our chapter']),
			),
		);

		$this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
		$data = $this->decode($response);
		$this->assertSame('pending', $data['state']);
		$this->assertSame('organizer', $data['submitter_kind']);
		$this->assertFalse($data['fails_open']);
		$this->assertSame('from our chapter', $data['note']);
		$this->assertSame('bouldering', $data['activity']['id']);
		$this->assertSame(['SPORT'], $data['activity']['types']);
	}

	#[Test]
	#[TestDox('The cloud admin key stages as kind cloud over both bearer and X-Admin-Key')]
	#[Group('mantle2/staging')]
	public function testCloudStagesViaBothCredentials(): void
	{
		$bearer = Request::create('/v2/activities/staged', 'POST', [], [], [], [], $this->body());
		$bearer->headers->set('Authorization', 'Bearer test_admin_key');
		$viaBearer = $this->controller()->stageActivity($bearer);

		$this->assertSame(Response::HTTP_CREATED, $viaBearer->getStatusCode());
		$data = $this->decode($viaBearer);
		$this->assertSame('cloud', $data['submitter_kind']);
		// nothing fails open any more, cloud rows included
		$this->assertFalse($data['fails_open']);

		$header = Request::create(
			'/v2/activities/staged',
			'POST',
			[],
			[],
			[],
			[],
			$this->body(['id' => 'kayaking']),
		);
		$header->headers->set('X-Admin-Key', 'test_admin_key');
		$viaHeader = $this->controller()->stageActivity($header);

		$this->assertSame(Response::HTTP_CREATED, $viaHeader->getStatusCode());
		$this->assertSame('cloud', $this->decode($viaHeader)['submitter_kind']);
	}

	#[Test]
	#[TestDox('Only the cloud account may declare its own source')]
	#[Group('mantle2/staging')]
	public function testSourceCannotBeForged(): void
	{
		$organizer = $this->controller()->stageActivity(
			$this->authRequest(
				$this->user(AccountType::ORGANIZER, true),
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(['source' => 'cloud_discovery']),
			),
		);
		$this->assertSame('api', $this->decode($organizer)['source']);

		$cloud = Request::create(
			'/v2/activities/staged',
			'POST',
			[],
			[],
			[],
			[],
			$this->body(['id' => 'surfing', 'source' => 'cloud_discovery']),
		);
		$cloud->headers->set('Authorization', 'Bearer test_admin_key');
		$this->assertSame(
			'cloud_discovery',
			$this->decode($this->controller()->stageActivity($cloud))['source'],
		);
	}

	#[Test]
	#[TestDox('Staging validates the body and rejects duplicates, quota overruns, and long notes')]
	#[Group('mantle2/staging')]
	public function testStageValidation(): void
	{
		$organizer = $this->user(AccountType::ORGANIZER, true);

		$badJson = $this->controller()->stageActivity(
			$this->authRequest($organizer, 'POST', '/v2/activities/staged', [], '{nope'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());

		$missing = $this->controller()->stageActivity(
			$this->authRequest($organizer, 'POST', '/v2/activities/staged', [], '{"id":"x"}'),
		);
		$this->assertSame('Missing required fields', $this->decode($missing)['message']);

		$longNote = $this->controller()->stageActivity(
			$this->authRequest(
				$organizer,
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(['note' => str_repeat('a', 513)]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $longNote->getStatusCode());

		// already in the catalog
		ActivityHelper::createActivity(
			new Activity('existing_one', 'Existing One', ['SPORT'], 'Already published.'),
		);
		$catalogDupe = $this->controller()->stageActivity(
			$this->authRequest(
				$organizer,
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(['id' => 'existing_one']),
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $catalogDupe->getStatusCode());

		// already awaiting review
		$this->seed('pending_dupe', $organizer);
		$pendingDupe = $this->controller()->stageActivity(
			$this->authRequest(
				$organizer,
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(['id' => 'pending_dupe']),
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $pendingDupe->getStatusCode());
		$this->assertStringContainsString(
			'awaiting review',
			$this->decode($pendingDupe)['message'],
		);
	}

	#[Test]
	#[TestDox('An organizer cannot exceed the pending submission quota')]
	#[Group('mantle2/staging')]
	public function testPendingQuota(): void
	{
		$organizer = $this->user(AccountType::ORGANIZER, true);
		for ($i = 0; $i < StagingHelper::MAX_PENDING_PER_ORGANIZER; $i++) {
			$this->seed("quota_$i", $organizer);
		}

		$response = $this->controller()->stageActivity(
			$this->authRequest(
				$organizer,
				'POST',
				'/v2/activities/staged',
				[],
				$this->body(['id' => 'one_too_many']),
			),
		);

		$this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
		$this->assertStringContainsString('awaiting review', $this->decode($response)['message']);
	}

	// #endregion

	// #region Reads

	#[Test]
	#[TestDox('The staged list is admin only and supports state and kind filters')]
	#[Group('mantle2/staging')]
	public function testListStaged(): void
	{
		$organizer = $this->user(AccountType::ORGANIZER, true);
		$this->seed('list_one', $organizer);
		$this->seed('list_cloud', UsersHelper::cloud());

		$forbidden = $this->controller()->listStaged(
			$this->authRequest($organizer, 'GET', '/v2/activities/staged'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->user(AccountType::ADMINISTRATOR);
		$all = $this->decode(
			$this->controller()->listStaged(
				$this->authRequest($admin, 'GET', '/v2/activities/staged'),
			),
		);
		$this->assertSame(2, $all['total']);

		$request = $this->authRequest($admin, 'GET', '/v2/activities/staged');
		$request->query->set('submitter_kind', StagingHelper::KIND_CLOUD);
		$this->assertSame(1, $this->decode($this->controller()->listStaged($request))['total']);
	}

	#[Test]
	#[TestDox('The mine list returns only the caller submissions')]
	#[Group('mantle2/staging')]
	public function testListMine(): void
	{
		$mine = $this->user(AccountType::ORGANIZER, true);
		$theirs = $this->user(AccountType::ORGANIZER, true);
		$this->seed('mine_one', $mine);
		$this->seed('theirs_one', $theirs);

		$anon = $this->controller()->listMyStaged(
			$this->request('GET', '/v2/activities/staged/mine'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$data = $this->decode(
			$this->controller()->listMyStaged(
				$this->authRequest($mine, 'GET', '/v2/activities/staged/mine'),
			),
		);
		$this->assertSame(1, $data['total']);
		$this->assertSame('mine_one', $data['items'][0]['activity']['id']);
	}

	#[Test]
	#[TestDox('A single staged row is readable by its submitter and any admin, nobody else')]
	#[Group('mantle2/staging')]
	public function testGetStaged(): void
	{
		$owner = $this->user(AccountType::ORGANIZER, true);
		$stranger = $this->user(AccountType::ORGANIZER, true);
		$row = $this->seed('get_one', $owner);
		$id = (string) $row['id'];

		$this->assertSame(
			Response::HTTP_OK,
			$this->controller()
				->getStaged($this->authRequest($owner, 'GET', "/v2/activities/staged/$id"), $id)
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_FORBIDDEN,
			$this->controller()
				->getStaged($this->authRequest($stranger, 'GET', "/v2/activities/staged/$id"), $id)
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_OK,
			$this->controller()
				->getStaged(
					$this->authRequest(
						$this->user(AccountType::ADMINISTRATOR),
						'GET',
						"/v2/activities/staged/$id",
					),
					$id,
				)
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_NOT_FOUND,
			$this->controller()
				->getStaged(
					$this->authRequest($owner, 'GET', '/v2/activities/staged/999999'),
					'999999',
				)
				->getStatusCode(),
		);
	}

	// #endregion

	// #region Decisions

	#[Test]
	#[TestDox('Approve publishes, is admin only, and 409s a repeat')]
	#[Group('mantle2/staging')]
	public function testApprove(): void
	{
		$organizer = $this->user(AccountType::ORGANIZER, true);
		$admin = $this->user(AccountType::ADMINISTRATOR);
		$row = $this->seed('approve_one', $organizer);
		$id = (string) $row['id'];
		$uri = "/v2/activities/staged/$id/approve";

		$this->assertSame(
			Response::HTTP_FORBIDDEN,
			$this->controller()
				->approveStaged($this->authRequest($organizer, 'POST', $uri, [], '{}'), $id)
				->getStatusCode(),
		);

		$ok = $this->controller()->approveStaged(
			$this->authRequest($admin, 'POST', $uri, [], '{"notes":"great"}'),
			$id,
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$data = $this->decode($ok);
		$this->assertSame('approved', $data['state']);
		$this->assertSame('great', $data['review_notes']);
		$this->assertSame('approve_one', $data['published_activity_id']);
		$this->assertNotNull(ActivityHelper::getNodeByActivityId('approve_one'));

		$this->assertSame(
			Response::HTTP_CONFLICT,
			$this->controller()
				->approveStaged($this->authRequest($admin, 'POST', $uri, [], '{}'), $id)
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_NOT_FOUND,
			$this->controller()
				->approveStaged($this->authRequest($admin, 'POST', $uri, [], '{}'), '999999')
				->getStatusCode(),
		);
	}

	#[Test]
	#[TestDox('Deny records notes, publishes nothing, and is admin only')]
	#[Group('mantle2/staging')]
	public function testDeny(): void
	{
		$organizer = $this->user(AccountType::ORGANIZER, true);
		$admin = $this->user(AccountType::ADMINISTRATOR);
		$row = $this->seed('deny_one', $organizer);
		$id = (string) $row['id'];
		$uri = "/v2/activities/staged/$id/deny";

		$this->assertSame(
			Response::HTTP_FORBIDDEN,
			$this->controller()
				->denyStaged($this->authRequest($organizer, 'POST', $uri, [], '{}'), $id)
				->getStatusCode(),
		);

		$ok = $this->controller()->denyStaged(
			$this->authRequest($admin, 'POST', $uri, [], '{"notes":"not an activity"}'),
			$id,
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('denied', $this->decode($ok)['state']);
		$this->assertSame('not an activity', $this->decode($ok)['review_notes']);
		$this->assertNull(ActivityHelper::getNodeByActivityId('deny_one'));
	}

	#[Test]
	#[TestDox('Withdraw is 204 for the owner, 403 for a stranger, 409 once decided')]
	#[Group('mantle2/staging')]
	public function testWithdraw(): void
	{
		$owner = $this->user(AccountType::ORGANIZER, true);
		$stranger = $this->user(AccountType::ORGANIZER, true);
		$row = $this->seed('withdraw_one', $owner);
		$id = (string) $row['id'];
		$uri = "/v2/activities/staged/$id";

		$this->assertSame(
			Response::HTTP_FORBIDDEN,
			$this->controller()
				->withdrawStaged($this->authRequest($stranger, 'DELETE', $uri), $id)
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_NO_CONTENT,
			$this->controller()
				->withdrawStaged($this->authRequest($owner, 'DELETE', $uri), $id)
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_CONFLICT,
			$this->controller()
				->withdrawStaged($this->authRequest($owner, 'DELETE', $uri), $id)
				->getStatusCode(),
		);
	}

	// #endregion

	// #region Cloud discovery contract

	/**
	 * The request cloud's postStagedActivity() actually builds, byte for byte.
	 *
	 * @see cloud/src/util/mantle2.ts
	 */
	private function cloudStageRequest(string $id): Request
	{
		$request = Request::create(
			'/v2/activities/staged',
			'POST',
			[],
			[],
			[],
			[],
			json_encode([
				'id' => $id,
				'name' => ucfirst(str_replace('_', ' ', $id)),
				'description' => 'A generated description of the proposed activity.',
				'aliases' => [],
				'types' => ['SPORT'],
				'fields' => ['icon' => 'mdi:run'],
				'source' => 'cloud_discovery',
			]),
		);
		$request->headers->set('Authorization', 'Bearer test_admin_key');
		$request->headers->set('Content-Type', 'application/json');

		return $request;
	}

	#[Test]
	#[TestDox('A cloud discovery submission persists, queues, expires closed, and blocklists')]
	#[Group('mantle2/staging')]
	#[Group('mantle2/cloud')]
	public function testCloudDiscoveryLifecycle(): void
	{
		$admin = $this->user(AccountType::ADMINISTRATOR);

		// 1. cloud stages. an insert naming a column production does not have is caught and
		// turned into a 500, which is exactly how the queue went silently empty
		$created = $this->controller()->stageActivity($this->cloudStageRequest('sea_kayaking'));
		$this->assertSame(Response::HTTP_CREATED, $created->getStatusCode());

		$staged = $this->decode($created);
		$this->assertSame('cloud', $staged['submitter_kind']);
		$this->assertSame('cloud_discovery', $staged['source']);
		$this->assertFalse($staged['fails_open']);

		// 2. the row is really on disk, with every column the helper writes
		$row = StagingHelper::get((int) $staged['id']);
		$this->assertIsArray($row, 'the staged row was never persisted');
		foreach (['warned_urgent', 'notified_pending', 'dedup_hash', 'payload'] as $column) {
			$this->assertArrayHasKey($column, $row, "the staged row has no $column");
		}
		$this->assertSame(0, (int) $row['warned_urgent']);

		// 3. it shows up in the queue crust renders
		$queue = $this->authRequest($admin, 'GET', '/v2/activities/staged');
		$queue->query->set('state', StagingHelper::STATE_PENDING);
		$pending = $this->decode($this->controller()->listStaged($queue));
		$this->assertSame(1, $pending['total']);
		$this->assertSame('sea_kayaking', $pending['items'][0]['activity']['id']);

		// 4. restaging the same id is a 409, which cloud treats as an idempotent skip
		$this->assertSame(
			Response::HTTP_CONFLICT,
			$this->controller()
				->stageActivity($this->cloudStageRequest('sea_kayaking'))
				->getStatusCode(),
		);

		// 5. unreviewed, it auto-denies and publishes nothing
		$this->container
			->get('database')
			->update(StagingHelper::TABLE)
			->fields(['expires_at' => time() - 10])
			->condition('id', (int) $staged['id'])
			->execute();
		StagingHelper::checkExpirations();

		$this->assertSame(
			StagingHelper::STATE_EXPIRED_DENIED,
			StagingHelper::get((int) $staged['id'])['state'],
		);
		$this->assertNull(ActivityHelper::getNodeByActivityId('sea_kayaking'));

		// 6. cloud's blocklist poll must see the auto-denial, or discovery re-proposes it
		// on the next hourly run forever
		$poll = $this->authRequest($admin, 'GET', '/v2/activities/staged');
		$poll->query->set('state', 'denied,expired_denied');
		$poll->query->set('sort', 'desc');
		$denied = $this->decode($this->controller()->listStaged($poll));

		$this->assertSame(1, $denied['total']);
		$this->assertSame('sea_kayaking', $denied['items'][0]['activity']['id']);
	}

	#[Test]
	#[TestDox('An approved cloud submission publishes and drops out of the blocklist poll')]
	#[Group('mantle2/staging')]
	#[Group('mantle2/cloud')]
	public function testCloudSubmissionApprovalPath(): void
	{
		$admin = $this->user(AccountType::ADMINISTRATOR);
		$staged = $this->decode(
			$this->controller()->stageActivity($this->cloudStageRequest('sea_kayaking')),
		);

		$approve = $this->authRequest(
			$admin,
			'POST',
			'/v2/activities/staged/' . $staged['id'] . '/approve',
			[],
			'{}',
		);
		$approved = $this->controller()->approveStaged($approve, (string) $staged['id']);

		$this->assertSame(Response::HTTP_OK, $approved->getStatusCode());
		$this->assertSame('sea_kayaking', $this->decode($approved)['published_activity_id']);
		$this->assertNotNull(ActivityHelper::getNodeByActivityId('sea_kayaking'));

		// approved is not denied, so discovery must not blocklist it
		$poll = $this->authRequest($admin, 'GET', '/v2/activities/staged');
		$poll->query->set('state', 'denied,expired_denied');
		$this->assertSame(0, $this->decode($this->controller()->listStaged($poll))['total']);
	}

	// #endregion
}
