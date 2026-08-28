<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\AdminController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\GeneralHelper;
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

	#region userInternalId

	#[Test]
	#[TestDox('Resolves a public uuid to the internal numeric id')]
	#[Group('mantle2/admin')]
	public function userInternalIdResolvesBothWays(): void
	{
		$admin = $this->admin();
		$target = $this->userOf(AccountType::FREE);
		$hex = GeneralHelper::publicId($target);

		$response = $this->controller()->userInternalId(
			$this->authRequest($admin, 'GET', '/v2/admin/users/' . $hex . '/internal_id'),
			$hex,
		);

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame($hex, $body['uuid']);
		$this->assertSame(GeneralHelper::formatId($target->id()), $body['id']);

		// the round trip is what cloud depends on: hex in, the same account back out
		$this->assertSame((int) $target->id(), (int) UsersHelper::findByPublicId($hex)?->id());
	}

	#[Test]
	#[TestDox('Rejects a malformed or unknown public id')]
	#[Group('mantle2/admin')]
	public function userInternalIdRejectsBadInput(): void
	{
		$admin = $this->admin();

		foreach (
			['a1b2c3d4-e5f6-0718-293a-4b5c6d7e8f90', 'nope', '', str_repeat('f', 32)]
			as $candidate
		) {
			$response = $this->controller()->userInternalId(
				$this->authRequest($admin, 'GET', '/v2/admin/users/' . $candidate . '/internal_id'),
				$candidate,
			);
			$this->assertSame(
				Response::HTTP_NOT_FOUND,
				$response->getStatusCode(),
				"'$candidate' must not resolve to a user.",
			);
		}
	}

	#[Test]
	#[TestDox('Only an administrator may translate an id')]
	#[Group('mantle2/admin')]
	public function userInternalIdRequiresAdmin(): void
	{
		$target = $this->userOf(AccountType::FREE);
		$hex = GeneralHelper::publicId($target);

		$response = $this->controller()->userInternalId(
			$this->authRequest($target, 'GET', '/v2/admin/users/' . $hex . '/internal_id'),
			$hex,
		);

		$this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('The user payload carries nid as the legacy id compatibility point')]
	#[Group('mantle2/admin')]
	public function serializedUserCarriesNid(): void
	{
		$user = $this->userOf(AccountType::FREE);
		$payload = UsersHelper::serializeUser($user, $user);

		// `nid` is what a client must hand to anything talking to cloud directly, because cloud
		// keys its storage on the numeric id and `id` is going to become the 32-hex public one
		$this->assertSame(GeneralHelper::formatId($user->id()), $payload['nid']);
		$this->assertSame(GeneralHelper::formatId($user->id()), $payload['account']['nid']);
		$this->assertTrue(GeneralHelper::isInternalId($payload['nid']));

		// `id` is the public shape now and the two are no longer interchangeable
		$this->assertSame(GeneralHelper::publicId($user), $payload['id']);
		$this->assertSame(GeneralHelper::publicId($user), $payload['account']['id']);
		$this->assertTrue(GeneralHelper::isPublicId($payload['id']));
		$this->assertNotSame($payload['id'], $payload['nid']);

		// both shapes still resolve to the same account on every read path
		$this->assertSame((int) $user->id(), (int) UsersHelper::findBy($payload['id'])?->id());
		$this->assertSame((int) $user->id(), (int) UsersHelper::findBy($payload['nid'])?->id());
		$this->assertSame(
			(int) $user->id(),
			(int) UsersHelper::findBy((string) $user->id())?->id(),
		);
	}

	#[Test]
	#[TestDox('A public id is a stripped uuid and is stable for the account')]
	#[Group('mantle2/admin')]
	public function publicIdIsAStrippedUuid(): void
	{
		$user = $this->userOf(AccountType::FREE);
		$hex = GeneralHelper::publicId($user);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hex);
		$this->assertSame(str_replace('-', '', strtolower($user->uuid())), $hex);
		// re-reading the account cannot change it
		$this->assertSame($hex, GeneralHelper::publicId(User::load($user->id())));
	}

	#endregion

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

	#region contentInternalId

	#[Test]
	#[TestDox('Only an administrator may translate a content id')]
	#[Group('mantle2/admin')]
	public function contentInternalIdRequiresAdmin(): void
	{
		$member = $this->userOf(AccountType::FREE);
		$hex = str_repeat('a', 32);

		$anon = $this->controller()->contentInternalId(
			$this->request('GET', '/v2/admin/article/' . $hex . '/internal_id'),
			'article',
			$hex,
		);
		$this->assertContains($anon->getStatusCode(), [
			Response::HTTP_UNAUTHORIZED,
			Response::HTTP_FORBIDDEN,
		]);

		$asMember = $this->controller()->contentInternalId(
			$this->authRequest($member, 'GET', '/v2/admin/article/' . $hex . '/internal_id'),
			'article',
			$hex,
		);
		$this->assertContains($asMember->getStatusCode(), [
			Response::HTTP_UNAUTHORIZED,
			Response::HTTP_FORBIDDEN,
		]);
	}

	#endregion
}
