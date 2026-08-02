<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\ReportsController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportsControllerTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	/** @var array<int,array{path:string,method:string,data:array}> */
	private array $cloudCalls = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
		$this->cloudCalls = [];
	}

	protected function tearDown(): void
	{
		CloudHelper::setRequestOverride(null);
		parent::tearDown();
	}

	private function controller(): ReportsController
	{
		return ReportsController::create($this->container);
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

	/** serves one report from the cloud and records every call mantle2 makes */
	private function cloudServing(?array $report, mixed $onPatch = null): void
	{
		CloudHelper::setRequestOverride(function (string $path, string $method, array $data) use (
			$report,
			$onPatch,
		) {
			$this->cloudCalls[] = ['path' => $path, 'method' => $method, 'data' => $data];

			if ($method === 'PATCH') {
				if ($onPatch instanceof Exception) {
					throw $onPatch;
				}
				return [];
			}

			return $report ?? [];
		});
	}

	private function report(array $overrides = []): array
	{
		return $overrides + [
			'id' => 'rep_1',
			'content_type' => 'prompt',
			'content_id' => '77',
			'reason' => 'spam',
			'status' => 'pending',
			'content_owner_id' => 0,
			'reporter_id' => 0,
		];
	}

	private function patch(UserInterface $actor, array $body, string $id = 'rep_1'): JsonResponse
	{
		return $this->controller()->patchReport(
			$id,
			$this->authRequest($actor, 'PATCH', '/v2/reports/' . $id, [], json_encode($body)),
		);
	}

	/** the status values pushed back to the cloud report */
	private function patchedStatuses(): array
	{
		$statuses = [];
		foreach ($this->cloudCalls as $call) {
			if ($call['method'] === 'PATCH' && isset($call['data']['status'])) {
				$statuses[] = $call['data']['status'];
			}
		}
		return $statuses;
	}

	#region Authorization

	#[Test]
	#[TestDox('PATCH /v2/reports/{id} refuses anonymous and non-admin callers')]
	#[Group('mantle2/reports')]
	public function patchReportIsAdminOnly(): void
	{
		$this->cloudServing($this->report());

		$anon = $this->controller()->patchReport(
			'rep_1',
			$this->request('PATCH', '/v2/reports/rep_1', [], '{"action":"dismiss"}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$member = $this->patch($this->createUser(), ['action' => 'dismiss']);
		$this->assertSame(Response::HTTP_FORBIDDEN, $member->getStatusCode());

		$this->assertSame([], $this->patchedStatuses(), 'no unauthorized call may reach the cloud');
	}

	#endregion

	#region Body Validation

	#[Test]
	#[TestDox('A malformed body or unknown action is rejected before the report is loaded')]
	#[Group('mantle2/reports')]
	public function patchReportValidatesTheBody(): void
	{
		$this->cloudServing($this->report());
		$admin = $this->admin();

		$badJson = $this->controller()->patchReport(
			'rep_1',
			$this->authRequest($admin, 'PATCH', '/v2/reports/rep_1', [], 'nope{'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());

		foreach ([[], ['action' => 'explode'], ['action' => null]] as $body) {
			$this->assertSame(
				Response::HTTP_BAD_REQUEST,
				$this->patch($admin, $body)->getStatusCode(),
				json_encode($body),
			);
		}

		$this->assertSame([], $this->patchedStatuses());
	}

	#[Test]
	#[TestDox('Moderator notes are capped at 1024 characters')]
	#[Group('mantle2/reports')]
	public function patchReportValidatesNotes(): void
	{
		$this->cloudServing($this->report());
		$admin = $this->admin();

		$tooLong = $this->patch($admin, [
			'action' => 'dismiss',
			'notes' => str_repeat('n', 1025),
		]);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $tooLong->getStatusCode());

		$wrongType = $this->patch($admin, ['action' => 'dismiss', 'notes' => 42]);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $wrongType->getStatusCode());
	}

	#endregion

	#region Dismiss

	#[Test]
	#[TestDox('Dismissing marks the report dismissed and records the reviewer')]
	#[Group('mantle2/reports')]
	public function dismissRecordsTheReviewer(): void
	{
		$this->cloudServing($this->report());
		$admin = $this->admin();

		$response = $this->patch($admin, [
			'action' => 'dismiss',
			'notes' => 'Not a violation',
		]);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(['dismissed'], $this->patchedStatuses());
		$this->assertSame('none', $this->decode($response)['enforced_action']);

		$patch = null;
		foreach ($this->cloudCalls as $call) {
			if ($call['method'] === 'PATCH') {
				$patch = $call['data'];
			}
		}
		$this->assertSame($admin->getAccountName(), $patch['reviewed_by']);
		$this->assertSame('Not a violation', $patch['action_notes']);
	}

	#[Test]
	#[TestDox('A dismissal never notifies the author or the reporter')]
	#[Group('mantle2/reports')]
	public function dismissNotifiesNobody(): void
	{
		// regression: the reporter branch sat outside the guard and told them action
		// had been taken on a report that was dismissed
		$author = $this->createUser();
		$reporter = $this->createUser();
		$this->cloudServing(
			$this->report([
				'content_owner_id' => (int) $author->id(),
				'reporter_id' => (int) $reporter->id(),
			]),
		);

		$this->patch($this->admin(), [
			'action' => 'dismiss',
			'notify_author' => true,
			'notify_reporter' => true,
		]);

		$this->assertSame([], UsersHelper::getNotifications(User::load($author->id())));
		$this->assertSame([], UsersHelper::getNotifications(User::load($reporter->id())));
	}

	#endregion

	#region Ban

	#[Test]
	#[TestDox('Banning blocks the content owner and marks the report actioned')]
	#[Group('mantle2/reports')]
	public function banBlocksTheOwner(): void
	{
		$owner = $this->createUser();
		$this->cloudServing($this->report(['content_owner_id' => (int) $owner->id()]));

		$response = $this->patch($this->admin(), ['action' => 'ban_user']);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(['actioned'], $this->patchedStatuses());
		$this->assertSame('permanent_ban', $this->decode($response)['enforced_action']);
		$this->assertTrue(UsersHelper::isDisabled(User::load($owner->id())));
	}

	#[Test]
	#[TestDox('Banning needs a content owner on the report')]
	#[Group('mantle2/reports')]
	public function banNeedsAnOwner(): void
	{
		$this->cloudServing($this->report(['content_owner_id' => 0]));

		$response = $this->patch($this->admin(), ['action' => 'ban_user']);

		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertSame([], $this->patchedStatuses());
	}

	#[Test]
	#[TestDox('Banning an owner who no longer exists is a 404')]
	#[Group('mantle2/reports')]
	public function banUnknownOwnerIsNotFound(): void
	{
		$this->cloudServing($this->report(['content_owner_id' => 999999]));

		$response = $this->patch($this->admin(), ['action' => 'ban_user']);

		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
		$this->assertSame([], $this->patchedStatuses());
	}

	#[Test]
	#[TestDox('A ban notifies the author and the reporter when asked')]
	#[Group('mantle2/reports')]
	public function banNotifiesBothParties(): void
	{
		$owner = $this->createUser();
		$reporter = $this->createUser();
		$this->cloudServing(
			$this->report([
				'content_owner_id' => (int) $owner->id(),
				'reporter_id' => (int) $reporter->id(),
			]),
		);

		$this->patch($this->admin(), [
			'action' => 'ban_user',
			'notify_author' => true,
			'notify_reporter' => true,
		]);

		$this->assertNotEmpty(UsersHelper::getNotifications(User::load($owner->id())));
		$this->assertNotEmpty(UsersHelper::getNotifications(User::load($reporter->id())));
	}

	#[Test]
	#[TestDox('Neither party is notified unless the moderator opts in')]
	#[Group('mantle2/reports')]
	public function notificationsAreOptIn(): void
	{
		$owner = $this->createUser();
		$reporter = $this->createUser();
		$this->cloudServing(
			$this->report([
				'content_owner_id' => (int) $owner->id(),
				'reporter_id' => (int) $reporter->id(),
			]),
		);

		$this->patch($this->admin(), ['action' => 'ban_user']);

		$this->assertSame([], UsersHelper::getNotifications(User::load($owner->id())));
		$this->assertSame([], UsersHelper::getNotifications(User::load($reporter->id())));
	}

	#endregion

	#region Delete Content

	#[Test]
	#[TestDox('Deleting content that cannot be resolved is a 404')]
	#[Group('mantle2/reports')]
	public function deleteMissingContentIsNotFound(): void
	{
		$this->cloudServing($this->report(['content_type' => 'prompt', 'content_id' => '424242']));

		$response = $this->patch($this->admin(), ['action' => 'delete_content']);

		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
		$this->assertSame([], $this->patchedStatuses());
	}

	#[Test]
	#[TestDox('Deleting a reported user account counts as a permanent ban')]
	#[Group('mantle2/reports')]
	public function deletingAUserIsAPermanentBan(): void
	{
		$target = $this->createUser();
		$this->cloudServing(
			$this->report([
				'content_type' => 'user',
				'content_id' => (string) $target->id(),
				'content_owner_id' => (int) $target->id(),
			]),
		);

		$response = $this->patch($this->admin(), ['action' => 'delete_content']);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(['actioned'], $this->patchedStatuses());
		$this->assertSame('permanent_ban', $this->decode($response)['enforced_action']);
	}

	#endregion

	#region Cloud Failures

	#[Test]
	#[TestDox('A report the cloud does not know is a 404')]
	#[Group('mantle2/reports')]
	public function unknownReportIsNotFound(): void
	{
		$this->cloudServing([]);

		$response = $this->patch($this->admin(), ['action' => 'dismiss']);

		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('A cloud failure while actioning is mapped, not leaked')]
	#[Group('mantle2/reports')]
	public function cloudFailureWhileActioningIsMapped(): void
	{
		$this->cloudServing($this->report(), new Exception('cloud down', 503));

		$response = $this->patch($this->admin(), ['action' => 'dismiss']);

		$this->assertGreaterThanOrEqual(400, $response->getStatusCode());
		$this->assertArrayHasKey('message', $this->decode($response));
	}

	#[Test]
	#[TestDox('The response is hydrated with the content preview and both usernames')]
	#[Group('mantle2/reports')]
	public function responseIsHydrated(): void
	{
		$owner = $this->createUser(['name' => 'content_owner']);
		$reporter = $this->createUser(['name' => 'the_reporter']);
		$this->cloudServing(
			$this->report([
				'content_owner_id' => (int) $owner->id(),
				'reporter_id' => (int) $reporter->id(),
			]),
		);

		$body = $this->decode($this->patch($this->admin(), ['action' => 'dismiss']));

		$this->assertSame('content_owner', $body['author_username']);
		$this->assertSame('the_reporter', $body['reporter_username']);
		$this->assertArrayHasKey('content_preview', $body);
	}

	#endregion
}
