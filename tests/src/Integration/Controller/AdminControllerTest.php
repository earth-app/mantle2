<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\AdminController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminControllerTest extends IntegrationTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		// dead endpoint so CloudHelper degrades to [] instead of reaching a live worker
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
	}

	private function controller(): AdminController
	{
		return AdminController::create($this->container);
	}

	private function userOf(AccountType $type, array $values = []): UserInterface
	{
		return $this->createUser(
			['field_account_type' => (string) array_search($type, AccountType::cases(), true)] +
				$values,
		);
	}

	private function admin(): UserInterface
	{
		return $this->userOf(AccountType::ADMINISTRATOR);
	}

	// an organizer with a pending verified-publisher application on file
	private function applicant(): UserInterface
	{
		$user = $this->userOf(AccountType::ORGANIZER, ['field_email_verified' => true]);
		UsersHelper::applyForVerifiedPublisher($user, [
			'reason' => str_repeat('We run a large local climbing chapter. ', 2),
			'organization' => 'Bay Area Climbing Collective',
			'links' => ['https://example.org'],
		]);
		return User::load($user->id());
	}

	private function decision(UserInterface $reviewer, string $id, array $body): JsonResponse
	{
		return $this->controller()->patchVerifiedPublisherApplication(
			$this->authRequest(
				$reviewer,
				'PATCH',
				'/v2/admin/verified_publishers/' . $id,
				[],
				json_encode($body),
			),
			$id,
		);
	}

	#region listVerifiedPublisherApplications

	#[Test]
	#[TestDox('GET /v2/admin/verified_publishers gates anon 401 and non-admin 403')]
	#[Group('mantle2/users')]
	public function listApplicationsIsAdminOnly(): void
	{
		$anon = $this->controller()->listVerifiedPublisherApplications(
			$this->request('GET', '/v2/admin/verified_publishers'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$member = $this->createUser();
		$forbidden = $this->controller()->listVerifiedPublisherApplications(
			$this->authRequest($member, 'GET', '/v2/admin/verified_publishers'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	#[Test]
	#[TestDox('The admin list returns pending applications by default')]
	#[Group('mantle2/users')]
	public function listApplicationsDefaultsToPending(): void
	{
		$applicant = $this->applicant();

		$response = $this->controller()->listVerifiedPublisherApplications(
			$this->authRequest($this->admin(), 'GET', '/v2/admin/verified_publishers'),
		);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$ids = array_column($body['items'] ?? [], 'user');
		$this->assertContains(
			(string) $applicant->id(),
			array_column($ids, 'id'),
			'a pending applicant must appear in the default list',
		);
	}

	#[Test]
	#[TestDox('The admin list honours the state filter and pagination')]
	#[Group('mantle2/users')]
	public function listApplicationsHonoursTheStateFilter(): void
	{
		$this->applicant();

		$approvedOnly = $this->controller()->listVerifiedPublisherApplications(
			$this->authRequest(
				$this->admin(),
				'GET',
				'/v2/admin/verified_publishers?state=approved&page=1&limit=5',
			),
		);

		$this->assertSame(Response::HTTP_OK, $approvedOnly->getStatusCode());
		$this->assertSame([], $this->decode($approvedOnly)['items'] ?? []);
	}

	#endregion

	#region patchVerifiedPublisherApplication

	#[Test]
	#[TestDox('PATCH /v2/admin/verified_publishers/{id} gates anon 401 and non-admin 403')]
	#[Group('mantle2/users')]
	public function patchApplicationIsAdminOnly(): void
	{
		$applicant = $this->applicant();

		$anon = $this->controller()->patchVerifiedPublisherApplication(
			$this->request(
				'PATCH',
				'/v2/admin/verified_publishers/' . $applicant->id(),
				[],
				'{"action":"approve"}',
			),
			(string) $applicant->id(),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$forbidden = $this->decision($this->createUser(), (string) $applicant->id(), [
			'action' => 'approve',
		]);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	#[Test]
	#[TestDox('Approving an application flags the applicant as a verified publisher')]
	#[Group('mantle2/users')]
	public function approvingAnApplication(): void
	{
		$applicant = $this->applicant();

		$response = $this->decision($this->admin(), (string) $applicant->id(), [
			'action' => 'approve',
			'notes' => 'Looks legitimate',
		]);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$fresh = User::load($applicant->id());
		$this->assertTrue(UsersHelper::isVerifiedPublisher($fresh));
		$this->assertSame('approved', UsersHelper::getVerifiedPublisherState($fresh)->value);
	}

	#[Test]
	#[TestDox('Denying an application records the decision without granting the flag')]
	#[Group('mantle2/users')]
	public function denyingAnApplication(): void
	{
		$applicant = $this->applicant();

		$response = $this->decision($this->admin(), (string) $applicant->id(), [
			'action' => 'deny',
			'notes' => 'Not enough detail',
		]);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$fresh = User::load($applicant->id());
		$this->assertFalse(UsersHelper::isVerifiedPublisher($fresh));
		$this->assertSame('denied', UsersHelper::getVerifiedPublisherState($fresh)->value);
	}

	#[Test]
	#[TestDox('Revoking takes the flag back off an approved publisher')]
	#[Group('mantle2/users')]
	public function revokingAnApproval(): void
	{
		$applicant = $this->applicant();
		$admin = $this->admin();
		$this->decision($admin, (string) $applicant->id(), ['action' => 'approve']);

		$response = $this->decision($admin, (string) $applicant->id(), ['action' => 'revoke']);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertFalse(UsersHelper::isVerifiedPublisher(User::load($applicant->id())));
	}

	#[Test]
	#[TestDox('An unknown action is rejected before any state change')]
	#[Group('mantle2/users')]
	public function unknownActionIsRejected(): void
	{
		$applicant = $this->applicant();

		$response = $this->decision($this->admin(), (string) $applicant->id(), [
			'action' => 'maybe',
		]);

		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertSame(
			'pending',
			UsersHelper::getVerifiedPublisherState(User::load($applicant->id()))->value,
		);
	}

	#[Test]
	#[TestDox('A decision for an unknown applicant is a 404')]
	#[Group('mantle2/users')]
	public function unknownApplicantIsNotFound(): void
	{
		$response = $this->decision($this->admin(), '999999', ['action' => 'approve']);

		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('A malformed decision body is a bad request')]
	#[Group('mantle2/users')]
	public function malformedDecisionBodyIsRejected(): void
	{
		$applicant = $this->applicant();

		$response = $this->controller()->patchVerifiedPublisherApplication(
			$this->authRequest(
				$this->admin(),
				'PATCH',
				'/v2/admin/verified_publishers/' . $applicant->id(),
				[],
				'"just a string"',
			),
			(string) $applicant->id(),
		);

		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
	}

	#endregion
}
