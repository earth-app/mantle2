<?php

namespace Drupal\Tests\mantle2\Integration\Controller\Users;

use Drupal\mantle2\Controller\UsersController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\PointsHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SocialTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
	}

	private function controller(): UsersController
	{
		return UsersController::create($this->container);
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

	// PUBLIC so anonymous + cross-user reads pass checkVisibility
	private function publicUser(array $values = []): UserInterface
	{
		return $this->createUser(['field_visibility' => '0'] + $values);
	}

	private function seedActivity(string $id): void
	{
		$activity = Activity::fromArray([
			'id' => $id,
			'name' => "Name of $id",
			'description' => "Description of $id",
			'types' => ['HOBBY'],
			'aliases' => [],
			'fields' => ['icon' => 'mdi:star'],
		]);
		ActivityHelper::createActivity($activity, $this->admin());
	}

	// makes $a and $b friends both directions
	// friend ids are stored as strings (UsersHelper::addFriend appends $friend->id())
	private function makeFriends(UserInterface $a, UserInterface $b): void
	{
		$a->set('field_friends', json_encode([(string) $b->id()]));
		$a->save();
		$b->set('field_friends', json_encode([(string) $a->id()]));
		$b->save();
	}

	private function reload(UserInterface $user): UserInterface
	{
		return \Drupal\user\Entity\User::load($user->id());
	}

	private function user(): UserInterface
	{
		return $this->createUser();
	}

	private function proUser(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::PRO,
				AccountType::cases(),
				true,
			),
		]);
	}

	private function addToCircle(UserInterface $owner, UserInterface $member): void
	{
		$owner->set('field_circle', json_encode([$member->id()]));
		$owner->save();
	}

	private function expeditionBody(array $overrides = []): string
	{
		return json_encode(
			array_merge(
				['goal' => 'nature_minutes', 'target' => 600, 'ends_at' => '2026-12-31T00:00:00Z'],
				$overrides,
			),
		);
	}

	private function kudosBody(array $overrides = []): string
	{
		return json_encode(
			array_merge(['context_type' => 'trail', 'phrase' => 'nice_find'], $overrides),
		);
	}

	// #region User Friends

	#[Test]
	#[TestDox('GET friends returns 404 for missing user and 400 for a bad filter')]
	#[Group('mantle2/users')]
	public function userFriendsValidation(): void
	{
		$viewer = $this->publicUser();

		$missing = $this->controller()->userFriends(
			$this->authRequest($viewer, 'GET', '/v2/users/999999/friends'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$target = $this->publicUser();
		$badFilter = $this->controller()->userFriends(
			$this->authRequest($viewer, 'GET', '/v2/users/x/friends?filter=nope'),
			(string) $target->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badFilter->getStatusCode());
		$this->assertStringContainsString('Invalid filter', $this->decode($badFilter)['message']);
	}

	#[Test]
	#[TestDox('GET friends lists added friends with pagination and search')]
	#[Group('mantle2/users')]
	public function userFriendsAddedListing(): void
	{
		$owner = $this->publicUser();
		$alice = $this->publicUser(['name' => 'alice_friend']);
		$bob = $this->publicUser(['name' => 'bob_friend']);
		$owner->set('field_friends', json_encode([(string) $alice->id(), (string) $bob->id()]));
		$owner->save();

		$all = $this->controller()->userFriends(
			$this->authRequest($owner, 'GET', '/v2/users/current/friends?filter=added'),
		);
		$this->assertSame(Response::HTTP_OK, $all->getStatusCode());
		$body = $this->decode($all);
		$this->assertSame(2, $body['total']);
		$this->assertCount(2, $body['items']);
		$this->assertSame(1, $body['page']);

		$paged = $this->controller()->userFriends(
			$this->authRequest(
				$owner,
				'GET',
				'/v2/users/current/friends?filter=added&limit=1&page=2',
			),
		);
		$pagedBody = $this->decode($paged);
		$this->assertCount(1, $pagedBody['items']);
		$this->assertSame(2, $pagedBody['page']);
		$this->assertSame(2, $pagedBody['total']);

		$searched = $this->controller()->userFriends(
			$this->authRequest(
				$owner,
				'GET',
				'/v2/users/current/friends?filter=added&search=alice',
			),
		);
		$searchedBody = $this->decode($searched);
		$this->assertSame(1, $searchedBody['total']);
		$this->assertCount(1, $searchedBody['items']);
		$this->assertSame('alice_friend', $searchedBody['items'][0]['username']);
	}

	#[Test]
	#[TestDox('GET friends filters mutual, added_by, and non_mutual over the friend graph')]
	#[Group('mantle2/users')]
	public function userFriendsFilters(): void
	{
		// mutual/non_mutual count how many of owner's friends also friend a given user:
		//   owner -> a,b ; a -> x ; b -> x,y  => friend-of-friend counts x=2 (mutual), y=1 (non_mutual)
		//   added_by = every user whose friends list contains owner (a and b here)
		$owner = $this->publicUser(['name' => 'owner_u']);
		$a = $this->publicUser(['name' => 'a_u']);
		$b = $this->publicUser(['name' => 'b_u']);
		$x = $this->publicUser(['name' => 'x_u']);
		$y = $this->publicUser(['name' => 'y_u']);

		$owner->set('field_friends', json_encode([(string) $a->id(), (string) $b->id()]));
		$owner->save();
		$a->set('field_friends', json_encode([(string) $owner->id(), (string) $x->id()]));
		$a->save();
		$b->set(
			'field_friends',
			json_encode([(string) $owner->id(), (string) $x->id(), (string) $y->id()]),
		);
		$b->save();

		$mutual = $this->decode(
			$this->controller()->userFriends(
				$this->authRequest($owner, 'GET', '/v2/users/current/friends?filter=mutual'),
			),
		);
		$this->assertSame(['x_u'], array_column($mutual['items'], 'username'));

		$nonMutual = $this->decode(
			$this->controller()->userFriends(
				$this->authRequest($owner, 'GET', '/v2/users/current/friends?filter=non_mutual'),
			),
		);
		$this->assertSame(1, $nonMutual['total']);
		$this->assertSame(['y_u'], array_column($nonMutual['items'], 'username'));

		$addedBy = $this->decode(
			$this->controller()->userFriends(
				$this->authRequest($owner, 'GET', '/v2/users/current/friends?filter=added_by'),
			),
		);
		$addedByIds = array_column($addedBy['items'], 'username');
		sort($addedByIds);
		$this->assertSame(['a_u', 'b_u'], $addedByIds);
		$this->assertSame(2, $addedBy['total']);
	}

	// addFriend/removeFriend call addNotification -> CloudHelper::sendWebsocketMessage, so the
	// 200 success path is exercised in E2E; integration covers the local guard branches only
	#[Test]
	#[TestDox('PUT friends 404s unknown, 400s missing param, 400s self before any cloud call')]
	#[Group('mantle2/users')]
	public function addUserFriend(): void
	{
		$owner = $this->publicUser();

		$missing = $this->controller()->addUserFriend(
			$this->authRequest($owner, 'PUT', '/v2/users/current/friends?friend=999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$noParam = $this->controller()->addUserFriend(
			$this->authRequest($owner, 'PUT', '/v2/users/current/friends'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noParam->getStatusCode());
		$this->assertStringContainsString('Missing friend', $this->decode($noParam)['message']);

		$self = $this->controller()->addUserFriend(
			$this->authRequest($owner, 'PUT', '/v2/users/current/friends?friend=' . $owner->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $self->getStatusCode());
		$this->assertStringContainsString('Cannot add yourself', $this->decode($self)['message']);
	}

	#[Test]
	#[TestDox('DELETE friends 404s unknown, 400s missing param, 400s self, 409s when not a friend')]
	#[Group('mantle2/users')]
	public function removeUserFriend(): void
	{
		$owner = $this->publicUser();
		$stranger = $this->publicUser();

		$noParam = $this->controller()->removeUserFriend(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/friends'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noParam->getStatusCode());

		$missing = $this->controller()->removeUserFriend(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/friends?friend=999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$self = $this->controller()->removeUserFriend(
			$this->authRequest(
				$owner,
				'DELETE',
				'/v2/users/current/friends?friend=' . $owner->id(),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $self->getStatusCode());

		// not-a-friend + not-in-circle short-circuits to 409 before addNotification runs
		$notFriend = $this->controller()->removeUserFriend(
			$this->authRequest(
				$owner,
				'DELETE',
				'/v2/users/current/friends?friend=' . $stranger->id(),
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $notFriend->getStatusCode());
	}

	// #endregion

	// #region User Blocking

	#[Test]
	#[TestDox('GET blocked lists blocked users with a total')]
	#[Group('mantle2/users')]
	public function userBlocked(): void
	{
		$owner = $this->publicUser();
		$target = $this->publicUser(['name' => 'blocked_target']);
		$owner->set('field_blocked_users', json_encode([(int) $target->id()]));
		$owner->save();

		$res = $this->controller()->userBlocked(
			$this->authRequest($owner, 'GET', '/v2/users/current/blocked'),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$body = $this->decode($res);
		$this->assertSame(1, $body['total']);
		$this->assertSame('blocked_target', $body['items'][0]['username']);
	}

	#[Test]
	#[
		TestDox(
			'PUT blocked persists the block, 409s duplicates, 400s self, forbids admins and system users',
		),
	]
	#[Group('mantle2/users')]
	public function blockUser(): void
	{
		$owner = $this->publicUser();
		$target = $this->publicUser();
		$this->makeFriends($owner, $target);

		$self = $this->controller()->blockUser(
			$this->authRequest($owner, 'PUT', '/v2/users/current/blocked?user=' . $owner->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $self->getStatusCode());

		$system = $this->controller()->blockUser(
			$this->authRequest($owner, 'PUT', '/v2/users/current/blocked?user=1'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $system->getStatusCode());

		$adminTarget = $this->admin();
		$adminBlock = $this->controller()->blockUser(
			$this->authRequest(
				$owner,
				'PUT',
				'/v2/users/current/blocked?user=' . $adminTarget->id(),
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $adminBlock->getStatusCode());

		$ok = $this->controller()->blockUser(
			$this->authRequest($owner, 'PUT', '/v2/users/current/blocked?user=' . $target->id()),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());

		$reloadedOwner = $this->reload($owner);
		$reloadedTarget = $this->reload($target);
		// forward block list + reverse block index both persist
		$this->assertContains(
			(int) $target->id(),
			json_decode($reloadedOwner->get('field_blocked_users')->value, true),
		);
		$this->assertContains(
			(int) $owner->id(),
			json_decode($reloadedTarget->get('field_blocked_by')->value, true),
		);

		// blocking severs the friendship both directions (silent, no notification)
		$this->assertNotContains(
			(string) $target->id(),
			json_decode($reloadedOwner->get('field_friends')->value ?: '[]', true),
		);
		$this->assertNotContains(
			(string) $owner->id(),
			json_decode($reloadedTarget->get('field_friends')->value ?: '[]', true),
		);

		$dup = $this->controller()->blockUser(
			$this->authRequest($owner, 'PUT', '/v2/users/current/blocked?user=' . $target->id()),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $dup->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE blocked unblocks and 409s when the user was not blocked')]
	#[Group('mantle2/users')]
	public function unblockUser(): void
	{
		$owner = $this->publicUser();
		$target = $this->publicUser();

		$notBlocked = $this->controller()->unblockUser(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/blocked?user=' . $target->id()),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $notBlocked->getStatusCode());

		$owner->set('field_blocked_users', json_encode([(int) $target->id()]));
		$owner->save();
		$target->set('field_blocked_by', json_encode([(int) $owner->id()]));
		$target->save();

		$ok = $this->controller()->unblockUser(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/blocked?user=' . $target->id()),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertNotContains(
			(int) $target->id(),
			json_decode($this->reload($owner)->get('field_blocked_users')->value, true),
		);
		$this->assertNotContains(
			(int) $owner->id(),
			json_decode($this->reload($target)->get('field_blocked_by')->value, true),
		);
	}

	// #endregion

	// #region User Circles

	#[Test]
	#[TestDox('GET circle lists members with pagination')]
	#[Group('mantle2/users')]
	public function userCircle(): void
	{
		$owner = $this->publicUser();
		$a = $this->publicUser(['name' => 'circle_a']);
		$b = $this->publicUser(['name' => 'circle_b']);
		$owner->set('field_circle', json_encode([(string) $a->id(), (string) $b->id()]));
		$owner->save();

		$res = $this->controller()->userCircle(
			$this->authRequest($owner, 'GET', '/v2/users/current/circle'),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$body = $this->decode($res);
		$this->assertCount(2, $body['items']);
		$this->assertSame(1, $body['page']);

		$paged = $this->decode(
			$this->controller()->userCircle(
				$this->authRequest($owner, 'GET', '/v2/users/current/circle?limit=1&page=2'),
			),
		);
		$this->assertCount(1, $paged['items']);
		$this->assertSame(2, $paged['page']);
	}

	// addToCircle/removeFromCircle call addNotification -> CloudHelper::sendWebsocketMessage, so
	// the 200 success path is exercised in E2E; integration covers the local guard branches only
	#[Test]
	#[TestDox('PUT circle 400s self, 400s a non-friend, and 400s at the size limit')]
	#[Group('mantle2/users')]
	public function addUserToCircle(): void
	{
		$owner = $this->publicUser();
		$stranger = $this->publicUser();

		$self = $this->controller()->addUserToCircle(
			$this->authRequest($owner, 'PUT', '/v2/users/current/circle?friend=' . $owner->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $self->getStatusCode());
		$this->assertStringContainsString('Cannot add yourself', $this->decode($self)['message']);

		$notFriend = $this->controller()->addUserToCircle(
			$this->authRequest($owner, 'PUT', '/v2/users/current/circle?friend=' . $stranger->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $notFriend->getStatusCode());
		$this->assertStringContainsString('Only friends', $this->decode($notFriend)['message']);

		// free-tier circle cap is 25; fill it so the limit branch trips before any cloud call
		$friend = $this->publicUser();
		$this->makeFriends($owner, $friend);
		$filler = array_map(fn($n) => (string) (100000 + $n), range(1, 25));
		$owner->set('field_circle', json_encode($filler));
		$owner->save();

		$full = $this->controller()->addUserToCircle(
			$this->authRequest($owner, 'PUT', '/v2/users/current/circle?friend=' . $friend->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $full->getStatusCode());
		$this->assertStringContainsString('limit', $this->decode($full)['message']);
	}

	#[Test]
	#[
		TestDox(
			'DELETE circle 404s unknown, 400s self, and 400s when the friend is not in the circle',
		),
	]
	#[Group('mantle2/users')]
	public function removeUserFromCircle(): void
	{
		$owner = $this->publicUser();
		$friend = $this->publicUser();
		$this->makeFriends($owner, $friend);

		$noParam = $this->controller()->removeUserFromCircle(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/circle'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noParam->getStatusCode());

		$missing = $this->controller()->removeUserFromCircle(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/circle?friend=999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$self = $this->controller()->removeUserFromCircle(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/circle?friend=' . $owner->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $self->getStatusCode());

		// not-in-circle short-circuits to 400 before addNotification runs
		$notInCircle = $this->controller()->removeUserFromCircle(
			$this->authRequest(
				$owner,
				'DELETE',
				'/v2/users/current/circle?friend=' . $friend->id(),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $notInCircle->getStatusCode());
		$this->assertStringContainsString('not in circle', $this->decode($notInCircle)['message']);
	}

	// #endregion

	// #region User Leaderboards

	#[Test]
	#[TestDox('GET leaderboard validates type, scope, limit, and requires auth')]
	#[Group('mantle2/users')]
	#[DataProvider('leaderboardValidationProvider')]
	public function userLeaderboardValidation(string $query, int $expectedStatus): void
	{
		$viewer = $this->publicUser();
		$res = $this->controller()->userLeaderboard(
			$this->authRequest($viewer, 'GET', '/v2/users/current/leaderboard?' . $query),
		);
		$this->assertSame($expectedStatus, $res->getStatusCode());
	}

	public static function leaderboardValidationProvider(): array
	{
		return [
			'bad type' => ['type=bogus', Response::HTTP_BAD_REQUEST],
			'bad scope' => ['scope=bogus', Response::HTTP_BAD_REQUEST],
			'limit too low' => ['limit=0', Response::HTTP_BAD_REQUEST],
			'limit too high' => ['limit=999', Response::HTTP_BAD_REQUEST],
		];
	}

	#[Test]
	#[TestDox('GET leaderboard 404s a missing user and 401s anonymous')]
	#[Group('mantle2/users')]
	public function userLeaderboardAuthAndMissing(): void
	{
		$missing = $this->controller()->userLeaderboard(
			$this->request('GET', '/v2/users/999999/leaderboard'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$target = $this->publicUser();
		$anon = $this->controller()->userLeaderboard(
			$this->request('GET', '/v2/users/x/leaderboard'),
			(string) $target->id(),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'GET leaderboard (friends, points) ranks the seeded points cache with the viewer included',
		),
	]
	#[Group('mantle2/users')]
	public function userLeaderboardFriendsPoints(): void
	{
		$owner = $this->publicUser();
		$friend = $this->publicUser();
		$this->makeFriends($owner, $friend);

		// seed the points cache so leaderboardMetric('points') stays local (key uses formatId)
		RedisHelper::set(
			'cloud:points:' . GeneralHelper::formatId($owner->id()),
			['points' => 40, 'history' => []],
			180,
		);
		RedisHelper::set(
			'cloud:points:' . GeneralHelper::formatId($friend->id()),
			['points' => 90, 'history' => []],
			180,
		);

		$res = $this->controller()->userLeaderboard(
			$this->authRequest(
				$owner,
				'GET',
				'/v2/users/current/leaderboard?type=points&scope=friends&limit=10',
			),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$body = $this->decode($res);
		$this->assertSame('friends', $body['scope']);
		$this->assertSame('points', $body['type']);
		$this->assertSame(2, $body['total']);
		$this->assertSame(1, $body['items'][0]['rank']);
		$this->assertSame(90, $body['items'][0]['value']);
		$this->assertSame(40, $body['items'][1]['value']);
	}

	// #endregion

	// #region User Activities

	#[Test]
	#[TestDox('GET activities returns the stored list')]
	#[Group('mantle2/users')]
	public function userActivities(): void
	{
		$this->seedActivity('hiking');
		$owner = $this->publicUser();
		$owner->set(
			'field_activities',
			json_encode([ActivityHelper::getActivity('hiking')->jsonSerialize()]),
		);
		$owner->save();

		$res = $this->controller()->userActivities(
			$this->authRequest($owner, 'GET', '/v2/users/current/activities'),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$body = $this->decode($res);
		$this->assertCount(1, $body);
		$this->assertSame('hiking', $body[0]['id']);
	}

	#[Test]
	#[
		TestDox(
			'PATCH activities validates JSON, caps at 10, rejects unknown, and persists valid ids',
		),
	]
	#[Group('mantle2/users')]
	public function setUserActivities(): void
	{
		$this->seedActivity('hiking');
		$this->seedActivity('cycling');
		$owner = $this->publicUser();

		$empty = $this->controller()->setUserActivities(
			$this->authRequest($owner, 'PATCH', '/v2/users/current/activities', [], '[]'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $empty->getStatusCode());

		$tooMany = $this->controller()->setUserActivities(
			$this->authRequest(
				$owner,
				'PATCH',
				'/v2/users/current/activities',
				[],
				json_encode(range(1, 11)),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $tooMany->getStatusCode());
		$this->assertStringContainsString('more than 10', $this->decode($tooMany)['message']);

		$unknown = $this->controller()->setUserActivities(
			$this->authRequest($owner, 'PATCH', '/v2/users/current/activities', [], '["nope"]'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $unknown->getStatusCode());
		$this->assertStringContainsString(
			'No valid activities',
			$this->decode($unknown)['message'],
		);

		$ok = $this->controller()->setUserActivities(
			$this->authRequest(
				$owner,
				'PATCH',
				'/v2/users/current/activities',
				[],
				'["hiking","cycling"]',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$stored = json_decode($this->reload($owner)->get('field_activities')->value, true);
		$this->assertSame(['hiking', 'cycling'], array_column($stored, 'id'));
	}

	#[Test]
	#[TestDox('PUT activities adds one, 404s unknown, 409s duplicates')]
	#[Group('mantle2/users')]
	public function addUserActivity(): void
	{
		$this->seedActivity('hiking');
		$owner = $this->publicUser();

		$noParam = $this->controller()->addUserActivity(
			$this->authRequest($owner, 'PUT', '/v2/users/current/activities'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noParam->getStatusCode());

		$unknown = $this->controller()->addUserActivity(
			$this->authRequest($owner, 'PUT', '/v2/users/current/activities?activityId=nope'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());

		$ok = $this->controller()->addUserActivity(
			$this->authRequest($owner, 'PUT', '/v2/users/current/activities?activityId=hiking'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$stored = json_decode($this->reload($owner)->get('field_activities')->value, true);
		$this->assertSame(['hiking'], array_column($stored, 'id'));

		$dup = $this->controller()->addUserActivity(
			$this->authRequest($owner, 'PUT', '/v2/users/current/activities?activityId=hiking'),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $dup->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE activities 404s when not associated and returns 200 for an associated id')]
	#[Group('mantle2/users')]
	public function removeUserActivity(): void
	{
		$this->seedActivity('hiking');
		$owner = $this->publicUser();
		$owner->set(
			'field_activities',
			json_encode([ActivityHelper::getActivity('hiking')->jsonSerialize()]),
		);
		$owner->save();

		$notAssociated = $this->controller()->removeUserActivity(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/activities?activityId=cycling'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $notAssociated->getStatusCode());

		$ok = $this->controller()->removeUserActivity(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/activities?activityId=hiking'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());

		$stored = json_decode($this->reload($owner)->get('field_activities')->value, true);
		$this->assertSame([], array_column($stored, 'id'));
	}

	#[Test]
	#[
		TestDox(
			'GET activities/recommend validates pool_limit and returns the local pool for a fresh user',
		),
	]
	#[Group('mantle2/users')]
	public function recommendUserActivities(): void
	{
		foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $id) {
			$this->seedActivity($id);
		}
		$owner = $this->publicUser();

		$badLimit = $this->controller()->recommendUserActivities(
			$this->authRequest(
				$owner,
				'GET',
				'/v2/users/current/activities/recommend?pool_limit=0',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badLimit->getStatusCode());

		// no user activities -> local branch returns array_slice(pool, 0, 3), no cloud call
		$ok = $this->controller()->recommendUserActivities(
			$this->authRequest($owner, 'GET', '/v2/users/current/activities/recommend'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertLessThanOrEqual(3, count($body));
		$this->assertGreaterThan(0, count($body));
	}

	// #endregion

	// #region User Profile Photos + Cosmetics (local paths)

	#[Test]
	#[TestDox('GET cosmetics catalog returns every cosmetic with pricing and rarity')]
	#[Group('mantle2/users')]
	public function getCosmeticsCatalog(): void
	{
		$res = $this->controller()->getCosmeticsCatalog(
			$this->request('GET', '/v2/users/cosmetics'),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$body = $this->decode($res);
		$this->assertSameSize(PointsHelper::cosmetics(), $body['cosmetics']);
		$first = $body['cosmetics'][0];
		$this->assertArrayHasKey('key', $first);
		$this->assertArrayHasKey('price', $first);
		$this->assertArrayHasKey('rarity', $first);
		$this->assertArrayHasKey('animated', $first);
	}

	#[Test]
	#[TestDox('GET cosmetics catalog applies the account-type discount for an admin requester')]
	#[Group('mantle2/users')]
	public function getCosmeticsCatalogDiscount(): void
	{
		$admin = $this->admin();
		$body = $this->decode(
			$this->controller()->getCosmeticsCatalog(
				$this->authRequest($admin, 'GET', '/v2/users/cosmetics'),
			),
		);
		// admins get 100% off -> every price is 0 (discount 1.0 round-trips to 1 through json)
		foreach ($body['cosmetics'] as $entry) {
			$this->assertSame(0, $entry['price']);
			$this->assertEquals(1, $entry['discount']);
		}
	}

	#[Test]
	#[TestDox('GET profile_photo/cosmetic returns unlocked list and current selection')]
	#[Group('mantle2/users')]
	public function getUserCosmetics(): void
	{
		$owner = $this->publicUser([
			'field_available_cosmetics' => json_encode(['grayscale', 'invert']),
			'field_selected_cosmetic' => 'grayscale',
		]);

		$res = $this->controller()->getUserCosmetics(
			$this->authRequest($owner, 'GET', '/v2/users/current/profile_photo/cosmetic'),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$body = $this->decode($res);
		$this->assertSame(['grayscale', 'invert'], $body['unlocked']);
		$this->assertSame('grayscale', $body['current']);
	}

	#[Test]
	#[
		TestDox(
			'PUT profile_photo/cosmetic sets an owned cosmetic, rejects unowned, and resets on null',
		),
	]
	#[Group('mantle2/users')]
	public function setUserCosmetic(): void
	{
		$owner = $this->publicUser([
			'field_available_cosmetics' => json_encode(['grayscale']),
		]);

		$unowned = $this->controller()->setUserCosmetic(
			$this->authRequest(
				$owner,
				'PUT',
				'/v2/users/current/profile_photo/cosmetic',
				[],
				'{"current":"invert"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $unowned->getStatusCode());

		$ok = $this->controller()->setUserCosmetic(
			$this->authRequest(
				$owner,
				'PUT',
				'/v2/users/current/profile_photo/cosmetic',
				[],
				'{"current":"grayscale"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('grayscale', $this->decode($ok)['current']);
		$this->assertSame(
			'grayscale',
			$this->reload($owner)->get('field_selected_cosmetic')->value,
		);

		$reset = $this->controller()->setUserCosmetic(
			$this->authRequest(
				$owner,
				'PUT',
				'/v2/users/current/profile_photo/cosmetic',
				[],
				'{"current":null}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $reset->getStatusCode());
		$this->assertNull($this->decode($reset)['current']);
	}

	#[Test]
	#[TestDox('PUT profile_photo/cosmetic 400s on a non-object JSON body')]
	#[Group('mantle2/users')]
	public function setUserCosmeticBadBody(): void
	{
		$owner = $this->publicUser();
		$res = $this->controller()->setUserCosmetic(
			$this->authRequest(
				$owner,
				'PUT',
				'/v2/users/current/profile_photo/cosmetic',
				[],
				'"a string"',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
	}

	#[Test]
	#[TestDox('GET cosmetics/preview validates the cosmetic key and size before touching cloud')]
	#[Group('mantle2/users')]
	public function previewCosmeticValidation(): void
	{
		$missing = $this->controller()->previewCosmetic(
			$this->request('GET', '/v2/users/cosmetics/preview'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());
		$this->assertStringContainsString('Missing', $this->decode($missing)['message']);

		$badKey = $this->controller()->previewCosmetic(
			$this->request('GET', '/v2/users/cosmetics/preview?cosmetic=nope'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badKey->getStatusCode());
		$this->assertStringContainsString(
			'Invalid cosmetic key',
			$this->decode($badKey)['message'],
		);

		$badSize = $this->controller()->previewCosmetic(
			$this->request('GET', '/v2/users/cosmetics/preview?cosmetic=grayscale&size=999'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badSize->getStatusCode());
		$this->assertStringContainsString(
			'Size must be one of',
			$this->decode($badSize)['message'],
		);
	}

	// a non-admin purchase deducts points via PointsHelper::removePoints -> CloudHelper (E2E).
	// the admin path gets a 100% discount so price is 0 and no points call is made, keeping it
	// local; the points cache is seeded so the controller's post-purchase getPoints stays local
	#[Test]
	#[
		TestDox(
			'POST purchase_cosmetic (admin, price 0) unlocks the cosmetic and 409s on a repurchase',
		),
	]
	#[Group('mantle2/users')]
	public function purchaseCosmeticLocalPoints(): void
	{
		$admin = $this->admin();
		RedisHelper::set(
			'cloud:points:' . GeneralHelper::formatId($admin->id()),
			['points' => 0, 'history' => []],
			180,
		);

		$badKey = $this->controller()->purchaseCosmetic(
			$this->authRequest($admin, 'POST', '/v2/users/current/profile_photo/purchase_cosmetic'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badKey->getStatusCode());

		$ok = $this->controller()->purchaseCosmetic(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/users/current/profile_photo/purchase_cosmetic?key=grayscale',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertTrue($this->decode($ok)['success']);
		$this->assertContains(
			'grayscale',
			json_decode($this->reload($admin)->get('field_available_cosmetics')->value, true),
		);

		$dup = $this->controller()->purchaseCosmetic(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/users/current/profile_photo/purchase_cosmetic?key=grayscale',
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $dup->getStatusCode());
	}

	#[Test]
	#[TestDox('POST purchase_cosmetic 400s an invalid key and when the points cache is too low')]
	#[Group('mantle2/users')]
	public function purchaseCosmeticGuards(): void
	{
		$owner = $this->publicUser();

		$invalid = $this->controller()->purchaseCosmetic(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/current/profile_photo/purchase_cosmetic?key=not_a_cosmetic',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $invalid->getStatusCode());
		$this->assertStringContainsString(
			'Invalid cosmetic key',
			$this->decode($invalid)['message'],
		);

		RedisHelper::set(
			'cloud:points:' . GeneralHelper::formatId($owner->id()),
			['points' => 1, 'history' => []],
			180,
		);
		$broke = $this->controller()->purchaseCosmetic(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/current/profile_photo/purchase_cosmetic?key=grayscale',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $broke->getStatusCode());
		$this->assertStringContainsString('Not enough points', $this->decode($broke)['message']);
	}

	#[Test]
	#[TestDox('DELETE profile_photo/cache clears cached entries and requires authorization')]
	#[Group('mantle2/users')]
	public function clearProfilePhotoCache(): void
	{
		$owner = $this->publicUser();

		$anon = $this->controller()->clearProfilePhotoCache(
			$this->request('DELETE', '/v2/users/current/profile_photo/cache'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$ok = $this->controller()->clearProfilePhotoCache(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/profile_photo/cache'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertTrue($this->decode($ok)['success']);
	}

	#[Test]
	#[TestDox('social write routes 401 anonymous and 403 across-user without admin')]
	#[Group('mantle2/users')]
	public function socialWritesAuthorization(): void
	{
		$owner = $this->publicUser();
		$other = $this->publicUser();
		$friend = $this->publicUser();

		$anon = $this->controller()->addUserFriend(
			$this->request('PUT', '/v2/users/current/friends?friend=' . $friend->id()),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$forbidden = $this->controller()->addUserFriend(
			$this->authRequest(
				$owner,
				'PUT',
				'/v2/users/' . $other->id() . '/friends?friend=' . $friend->id(),
			),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	// #endregion

	// #region Friends / Blocked / Circle (missing-param, 404, anon, cross-user)

	#[Test]
	#[TestDox('PUT blocked 401s anonymous, 400s a missing param, and 404s an unknown target')]
	#[Group('mantle2/users')]
	public function blockUserGuards(): void
	{
		$owner = $this->publicUser();

		$anon = $this->controller()->blockUser(
			$this->request('PUT', '/v2/users/current/blocked?user=2'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$noParam = $this->controller()->blockUser(
			$this->authRequest($owner, 'PUT', '/v2/users/current/blocked'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noParam->getStatusCode());
		$this->assertStringContainsString('Missing user', $this->decode($noParam)['message']);

		$missing = $this->controller()->blockUser(
			$this->authRequest($owner, 'PUT', '/v2/users/current/blocked?user=999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE blocked 400s a missing param and 404s an unknown target')]
	#[Group('mantle2/users')]
	public function unblockUserGuards(): void
	{
		$owner = $this->publicUser();

		$noParam = $this->controller()->unblockUser(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/blocked'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noParam->getStatusCode());

		$missing = $this->controller()->unblockUser(
			$this->authRequest($owner, 'DELETE', '/v2/users/current/blocked?user=999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('PUT circle 400s a missing param and 404s an unknown friend')]
	#[Group('mantle2/users')]
	public function addUserToCircleGuards(): void
	{
		$owner = $this->publicUser();

		$noParam = $this->controller()->addUserToCircle(
			$this->authRequest($owner, 'PUT', '/v2/users/current/circle'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noParam->getStatusCode());
		$this->assertStringContainsString('Missing friend', $this->decode($noParam)['message']);

		$missing = $this->controller()->addUserToCircle(
			$this->authRequest($owner, 'PUT', '/v2/users/current/circle?friend=999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('GET blocked 401s anonymous and 403s a cross-user read without admin')]
	#[Group('mantle2/users')]
	public function userBlockedAuthorization(): void
	{
		$owner = $this->publicUser();
		$other = $this->publicUser();

		$anon = $this->controller()->userBlocked(
			$this->request('GET', '/v2/users/current/blocked'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$forbidden = $this->controller()->userBlocked(
			$this->authRequest($owner, 'GET', '/v2/users/' . $other->id() . '/blocked'),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	// #endregion

	// #region Read routes (visibility + missing user)

	#[Test]
	#[
		TestDox(
			'GET activities 404s an unknown user and hides a private target from an anonymous caller',
		),
	]
	#[Group('mantle2/users')]
	public function userActivitiesVisibility(): void
	{
		$missing = $this->controller()->userActivities(
			$this->request('GET', '/v2/users/999999/activities'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$private = $this->createUser(['field_visibility' => '2']);
		$hidden = $this->controller()->userActivities(
			$this->request('GET', '/v2/users/' . $private->id() . '/activities'),
			(string) $private->id(),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'GET circle 404s an unknown user and hides a private target from an anonymous caller',
		),
	]
	#[Group('mantle2/users')]
	public function userCircleVisibility(): void
	{
		$missing = $this->controller()->userCircle(
			$this->request('GET', '/v2/users/999999/circle'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$private = $this->createUser(['field_visibility' => '2']);
		$hidden = $this->controller()->userCircle(
			$this->request('GET', '/v2/users/' . $private->id() . '/circle'),
			(string) $private->id(),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());
	}

	#[Test]
	#[TestDox('GET activities/recommend 400s a pool_limit above the max')]
	#[Group('mantle2/users')]
	public function recommendUserActivitiesHighLimit(): void
	{
		$owner = $this->publicUser();
		$response = $this->controller()->recommendUserActivities(
			$this->authRequest(
				$owner,
				'GET',
				'/v2/users/current/activities/recommend?pool_limit=500',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertStringContainsString('pool_limit', $this->decode($response)['message']);
	}

	// #endregion

	// #region Cosmetics (authorization)

	#[Test]
	#[TestDox('GET/PUT profile_photo/cosmetic 401 anonymous and 403 cross-user')]
	#[Group('mantle2/users')]
	public function cosmeticsAuthorization(): void
	{
		$owner = $this->publicUser();
		$other = $this->publicUser();

		$anonGet = $this->controller()->getUserCosmetics(
			$this->request('GET', '/v2/users/current/profile_photo/cosmetic'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anonGet->getStatusCode());

		$crossGet = $this->controller()->getUserCosmetics(
			$this->authRequest(
				$owner,
				'GET',
				'/v2/users/' . $other->id() . '/profile_photo/cosmetic',
			),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $crossGet->getStatusCode());

		$crossSet = $this->controller()->setUserCosmetic(
			$this->authRequest(
				$owner,
				'PUT',
				'/v2/users/' . $other->id() . '/profile_photo/cosmetic',
				[],
				'{"current":null}',
			),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $crossSet->getStatusCode());
	}

	// #endregion

	// #region Expeditions

	#[Test]
	#[TestDox('POST /v2/users/current/expedition rejects anon and validates goal/ends_at/target')]
	#[Group('mantle2/circles')]
	public function startValidation(): void
	{
		$anon = $this->controller()->startExpedition(
			$this->request('POST', '/v2/users/current/expedition', [], $this->expeditionBody()),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->user();

		$badGoal = $this->controller()->startExpedition(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/expedition',
				[],
				$this->expeditionBody(['goal' => 'nope']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badGoal->getStatusCode());
		$this->assertSame('Invalid goal', $this->decode($badGoal)['message']);

		$noEnds = $this->controller()->startExpedition(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/expedition',
				[],
				json_encode(['goal' => 'quests', 'target' => 5]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noEnds->getStatusCode());

		$badTarget = $this->controller()->startExpedition(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/expedition',
				[],
				$this->expeditionBody(['target' => 0]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badTarget->getStatusCode());
	}

	#[Test]
	#[TestDox('Expedition target gates on rank: free 402s an oversized goal, pro does not')]
	#[Group('mantle2/circles')]
	public function startGatesOnRank(): void
	{
		// free cap = getMaxCircleCount(25) * 120 = 3000
		$free = $this->user();
		$gated = $this->controller()->startExpedition(
			$this->authRequest(
				$free,
				'POST',
				'/v2/users/current/expedition',
				[],
				$this->expeditionBody(['target' => 100000]),
			),
		);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $gated->getStatusCode());

		// pro cap = 500 * 120 = 60000; the same goal is allowed (cloud is dead so body is empty, 201)
		$pro = $this->proUser();
		$ok = $this->controller()->startExpedition(
			$this->authRequest(
				$pro,
				'POST',
				'/v2/users/current/expedition',
				[],
				$this->expeditionBody(['target' => 5000, 'title' => 'Big Trek']),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
	}

	#[Test]
	#[TestDox('GET expedition rejects anon and enforces owner/circle-member/admin for {id}')]
	#[Group('mantle2/circles')]
	public function getExpeditionAuth(): void
	{
		$anon = $this->controller()->getExpedition(
			$this->request('GET', '/v2/users/current/expedition'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$owner = $this->user();
		// current: passes auth, degraded cloud -> 404
		$own = $this->controller()->getExpedition(
			$this->authRequest($owner, 'GET', '/v2/users/current/expedition'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $own->getStatusCode());

		// a stranger cannot view the owner's expedition
		$stranger = $this->user();
		$forbidden = $this->controller()->getExpedition(
			$this->authRequest($stranger, 'GET', '/v2/users/' . $owner->id() . '/expedition'),
			(string) $owner->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		// an admin bypasses the circle check (reaches cloud -> 404)
		$admin = $this->admin();
		$asAdmin = $this->controller()->getExpedition(
			$this->authRequest($admin, 'GET', '/v2/users/' . $owner->id() . '/expedition'),
			(string) $owner->id(),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $asAdmin->getStatusCode());
	}

	#[Test]
	#[TestDox('A circle member may view the owner expedition (passes auth, reaches cloud)')]
	#[Group('mantle2/circles')]
	public function circleMemberMayView(): void
	{
		$owner = $this->user();
		$member = $this->user();
		$this->addToCircle($owner, $member);

		$view = $this->controller()->getExpedition(
			$this->authRequest($member, 'GET', '/v2/users/' . $owner->id() . '/expedition'),
			(string) $owner->id(),
		);
		// not forbidden — the member cleared the circle check; cloud is dead so 404
		$this->assertSame(Response::HTTP_NOT_FOUND, $view->getStatusCode());
	}

	#[Test]
	#[TestDox('The {username} owner variant enforces the same owner/circle-member gate as {id}')]
	#[Group('mantle2/circles')]
	public function getExpeditionByUsername(): void
	{
		$owner = $this->user();
		$ownerHandle = '@' . $owner->getAccountName();

		// a stranger cannot view the owner's expedition by handle
		$stranger = $this->user();
		$forbidden = $this->controller()->getExpedition(
			$this->authRequest($stranger, 'GET', '/v2/users/' . $ownerHandle . '/expedition'),
			null,
			$ownerHandle,
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		// a circle member clears the gate by handle (cloud dead -> 404)
		$member = $this->user();
		$this->addToCircle($owner, $member);
		$view = $this->controller()->getExpedition(
			$this->authRequest($member, 'GET', '/v2/users/' . $ownerHandle . '/expedition'),
			null,
			$ownerHandle,
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $view->getStatusCode());
	}

	#[Test]
	#[TestDox('POST contribute rejects anon, non-members, and a bad amount')]
	#[Group('mantle2/circles')]
	public function contributeAuth(): void
	{
		$owner = $this->user();

		$anon = $this->controller()->contribute(
			$this->request(
				'POST',
				'/v2/users/' . $owner->id() . '/expedition/contribute',
				[],
				json_encode(['amount' => 30]),
			),
			(string) $owner->id(),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$stranger = $this->user();
		$forbidden = $this->controller()->contribute(
			$this->authRequest(
				$stranger,
				'POST',
				'/v2/users/' . $owner->id() . '/expedition/contribute',
				[],
				json_encode(['amount' => 30]),
			),
			(string) $owner->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		// the owner may contribute to their own expedition; a bad amount 400s before cloud
		$badAmount = $this->controller()->contribute(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/' . $owner->id() . '/expedition/contribute',
				[],
				json_encode(['amount' => -1]),
			),
			(string) $owner->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badAmount->getStatusCode());
	}

	#[Test]
	#[TestDox('POST contribute by {username} resolves the owner and enforces membership')]
	#[Group('mantle2/circles')]
	public function contributeByUsername(): void
	{
		$owner = $this->user();
		$ownerHandle = '@' . $owner->getAccountName();

		$stranger = $this->user();
		$forbidden = $this->controller()->contribute(
			$this->authRequest(
				$stranger,
				'POST',
				'/v2/users/' . $ownerHandle . '/expedition/contribute',
				[],
				json_encode(['amount' => 30]),
			),
			null,
			$ownerHandle,
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		// owner contributes by their own handle; a bad amount 400s before cloud
		$badAmount = $this->controller()->contribute(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/' . $ownerHandle . '/expedition/contribute',
				[],
				json_encode(['amount' => 0]),
			),
			null,
			$ownerHandle,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badAmount->getStatusCode());
	}

	#[Test]
	#[TestDox('GET garden rejects anon and enforces circle membership for {id}')]
	#[Group('mantle2/circles')]
	public function gardenAuth(): void
	{
		$anon = $this->controller()->getGarden($this->request('GET', '/v2/users/current/garden'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$owner = $this->user();
		$own = $this->controller()->getGarden(
			$this->authRequest($owner, 'GET', '/v2/users/current/garden'),
		);
		$this->assertSame(Response::HTTP_OK, $own->getStatusCode());

		$stranger = $this->user();
		$forbidden = $this->controller()->getGarden(
			$this->authRequest($stranger, 'GET', '/v2/users/' . $owner->id() . '/garden'),
			(string) $owner->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	// #endregion

	// #region Kudos

	#[Test]
	#[TestDox('POST /v2/users/{id}/kudos rejects anon, self, and non-friends')]
	#[Group('mantle2/kudos')]
	public function kudosGuards(): void
	{
		$recipient = $this->user();

		$anon = $this->controller()->sendKudos(
			$this->request(
				'POST',
				'/v2/users/' . $recipient->id() . '/kudos',
				[],
				$this->kudosBody(),
			),
			(string) $recipient->id(),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$sender = $this->user();
		$self = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $sender->id() . '/kudos',
				[],
				$this->kudosBody(),
			),
			(string) $sender->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $self->getStatusCode());

		// not a friend / not in circle
		$notFriend = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $recipient->id() . '/kudos',
				[],
				$this->kudosBody(),
			),
			(string) $recipient->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $notFriend->getStatusCode());
	}

	#[Test]
	#[TestDox('Kudos validates context_type and phrase against the fixed sets')]
	#[Group('mantle2/kudos')]
	public function kudosValidation(): void
	{
		$sender = $this->user();
		$recipient = $this->user();
		$this->makeFriends($sender, $recipient);

		$badContext = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $recipient->id() . '/kudos',
				[],
				$this->kudosBody(['context_type' => 'gossip']),
			),
			(string) $recipient->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badContext->getStatusCode());
		$this->assertSame('Invalid context_type', $this->decode($badContext)['message']);

		$badPhrase = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $recipient->id() . '/kudos',
				[],
				$this->kudosBody(['phrase' => 'insult']),
			),
			(string) $recipient->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badPhrase->getStatusCode());
		$this->assertSame('Invalid phrase', $this->decode($badPhrase)['message']);
	}

	#[Test]
	#[
		TestDox(
			'Kudos sends a private notification, never a tally, and 409s a repeat for the same context',
		),
	]
	#[Group('mantle2/kudos')]
	public function kudosSendsThenDedupes(): void
	{
		$sender = $this->user();
		$recipient = $this->user();
		$this->makeFriends($sender, $recipient);

		$first = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $recipient->id() . '/kudos',
				[],
				$this->kudosBody(['context_type' => 'trail', 'context_ref' => 'forest_wonder']),
			),
			(string) $recipient->id(),
		);
		$this->assertSame(Response::HTTP_CREATED, $first->getStatusCode());
		$body = $this->decode($first);
		$this->assertArrayHasKey('kudos', $body);
		$this->assertArrayHasKey('notification', $body);
		$this->assertSame('trail', $body['kudos']['context_type']);
		$this->assertSame('nice_find', $body['kudos']['phrase']);
		$this->assertSame(GeneralHelper::formatId($recipient->id()), $body['kudos']['to_uid']);
		$this->assertSame(GeneralHelper::formatId($sender->id()), $body['kudos']['from_uid']);
		// never a tally
		$this->assertArrayNotHasKey('count', $body);
		$this->assertArrayNotHasKey('tally', $body);
		$this->assertArrayNotHasKey('total', $body);
		// the notification source is the sender handle
		$this->assertSame('@' . $sender->getAccountName(), $body['notification']['source']);

		$repeat = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $recipient->id() . '/kudos',
				[],
				$this->kudosBody(['context_type' => 'trail', 'context_ref' => 'forest_wonder']),
			),
			(string) $recipient->id(),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $repeat->getStatusCode());
	}

	#[Test]
	#[TestDox('Kudos is rate limited via the challenge-throttle pattern')]
	#[Group('mantle2/kudos')]
	public function kudosThrottled(): void
	{
		$sender = $this->user();
		$recipient = $this->user();
		$this->makeFriends($sender, $recipient);

		// seed the hourly throttle at the cap
		RedisHelper::set('kudos:throttle:' . $sender->id(), ['count' => 30], 3600);

		$throttled = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $recipient->id() . '/kudos',
				[],
				$this->kudosBody(['context_ref' => 'unique-ref']),
			),
			(string) $recipient->id(),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $throttled->getStatusCode());
	}

	#[Test]
	#[TestDox('Kudos is allowed to a circle member even when not an added friend')]
	#[Group('mantle2/kudos')]
	public function kudosToCircleMember(): void
	{
		$sender = $this->user();
		$member = $this->user();
		$this->addToCircle($sender, $member);

		$ok = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $member->id() . '/kudos',
				[],
				$this->kudosBody(['context_type' => 'expedition']),
			),
			(string) $member->id(),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
	}

	#[Test]
	#[TestDox('Kudos by {username} resolves the recipient and sends to a friend by handle')]
	#[Group('mantle2/kudos')]
	public function kudosByUsername(): void
	{
		$sender = $this->user();
		$recipient = $this->user();
		$this->makeFriends($sender, $recipient);
		$recipientHandle = '@' . $recipient->getAccountName();

		$ok = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $recipientHandle . '/kudos',
				[],
				$this->kudosBody(['context_type' => 'journey', 'context_ref' => 'by_handle']),
			),
			null,
			$recipientHandle,
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame(GeneralHelper::formatId($recipient->id()), $body['kudos']['to_uid']);

		// self-kudos by handle is still rejected
		$senderHandle = '@' . $sender->getAccountName();
		$self = $this->controller()->sendKudos(
			$this->authRequest(
				$sender,
				'POST',
				'/v2/users/' . $senderHandle . '/kudos',
				[],
				$this->kudosBody(),
			),
			null,
			$senderHandle,
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $self->getStatusCode());
	}

	// #endregion
}
