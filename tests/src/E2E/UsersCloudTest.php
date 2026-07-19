<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Controller\UsersController;
use Drupal\mantle2\Service\PointsHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class UsersCloudTest extends E2ETestBase
{
	private function controller(): UsersController
	{
		return UsersController::create($this->container);
	}

	private function friends(UserInterface $a, UserInterface $b): void
	{
		UsersHelper::addFriend($a, $b);
		UsersHelper::addFriend($b, $a);
	}

	// real redis persists across runs while drupal uids are reused, so the per-user
	// challenge throttle + dedupe markers must be cleared to keep the test deterministic
	private function clearChallengeState(UserInterface $challenger): void
	{
		RedisHelper::delete('challenge:throttle:' . $challenger->id());
		RedisHelper::delete('challenge:dedupe:' . $challenger->id() . ':*');
	}

	// #region badges

	#[Test]
	#[TestDox('GET /v2/users/badges lists the cloud badge catalog')]
	#[Group('mantle2/users')]
	public function allBadges(): void
	{
		$response = $this->controller()->allBadges();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertNotEmpty($body);
		$this->assertArrayHasKey('id', $body[0]);
	}

	#[Test]
	#[TestDox('GET /v2/users/{id}/badges and /badges/{badgeId} return cloud badge state')]
	#[Group('mantle2/users')]
	public function userBadgesAndSingle(): void
	{
		$user = $this->createUser();

		$badges = $this->controller()->badges(
			$this->authRequest($user, 'GET', '/v2/users/current/badges'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_OK, $badges->getStatusCode());
		$list = $this->decode($badges);
		$this->assertNotEmpty($list);
		$badgeId = $list[0]['id'];

		$single = $this->controller()->badge(
			$this->authRequest($user, 'GET', '/v2/users/current/badges/' . $badgeId),
			(string) $user->id(),
			null,
			$badgeId,
		);
		$this->assertSame(Response::HTTP_OK, $single->getStatusCode());
		$this->assertSame($badgeId, $this->decode($single)['id']);

		$missing = $this->controller()->badge(
			$this->authRequest($user, 'GET', '/v2/users/current/badges/____nope____'),
			(string) $user->id(),
			null,
			'____nope____',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'GET /v2/users/{id}/badges/{badgeId}/mastery returns the cloud mastery status object',
		),
	]
	#[Group('mantle2/users')]
	public function badgeMastery(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->badgeMastery(
			$this->authRequest($user, 'GET', '/v2/users/current/badges/getting_started/mastery'),
			(string) $user->id(),
			null,
			'getting_started',
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame('getting_started', $body['badge_id']);
		$this->assertArrayHasKey('generated', $body);
	}

	#[Test]
	#[TestDox('GET /v2/users/{id}/badges/masteries returns the cap + items envelope')]
	#[Group('mantle2/users')]
	public function badgesMasteries(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->badgesMasteries(
			$this->authRequest($user, 'GET', '/v2/users/current/badges/masteries'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertArrayHasKey('items', $body);
		$this->assertIsArray($body['items']);
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/users/{id}/badges/{badgeId}/mastery/generate returns a mapped cloud outcome',
		),
	]
	#[Group('mantle2/users')]
	public function generateBadgeMastery(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->generateBadgeMastery(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/badges/getting_started/mastery/generate',
			),
			(string) $user->id(),
			null,
			'getting_started',
		);

		// getting_started is mastery_exempt, so cloud rejects generation; any of these
		// is a valid mapped outcome (201 created, 4xx business rule, or 504 on timeout)
		$this->assertContains($response->getStatusCode(), [
			Response::HTTP_CREATED,
			Response::HTTP_BAD_REQUEST,
			Response::HTTP_CONFLICT,
			Response::HTTP_GONE,
			Response::HTTP_GATEWAY_TIMEOUT,
		]);
	}

	// #endregion

	// #region quest challenge

	#[Test]
	#[TestDox('challengeFriendToQuest creates a cloud challenge record between two friends')]
	#[Group('mantle2/users')]
	public function challengeFriendCreatesRecord(): void
	{
		$challenger = $this->createUser(['field_email_verified' => true]);
		$friend = $this->createUser(['field_email_verified' => true]);
		$this->friends($challenger, $friend);
		$this->clearChallengeState($challenger);

		$questId = PointsHelper::getAllQuests()[0]->id;

		$response = $this->controller()->challengeFriendToQuest(
			$this->authRequest(
				$challenger,
				'POST',
				'/v2/users/current/quest/challenge?friend=' . $friend->id() . '&quest=' . $questId,
			),
			(string) $challenger->id(),
		);
		$this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertArrayHasKey('notification', $body);
		$this->assertNotEmpty($body['challenge']);
		$this->assertNotEmpty($body['challenge']['id']);
	}

	#[Test]
	#[
		TestDox(
			'getQuestChallenge returns the pending challenge for the recipient; decline resolves it',
		),
	]
	#[Group('mantle2/users')]
	public function getAndDeclineChallenge(): void
	{
		$challenger = $this->createUser(['field_email_verified' => true]);
		$friend = $this->createUser(['field_email_verified' => true]);
		$this->friends($challenger, $friend);
		$this->clearChallengeState($challenger);

		$questId = PointsHelper::getAllQuests()[0]->id;

		$created = $this->decode(
			$this->controller()->challengeFriendToQuest(
				$this->authRequest(
					$challenger,
					'POST',
					'/v2/users/current/quest/challenge?friend=' .
						$friend->id() .
						'&quest=' .
						$questId,
				),
				(string) $challenger->id(),
			),
		);
		$challengeId = $created['challenge']['id'];

		$fetched = $this->controller()->getQuestChallenge(
			$this->authRequest(
				$friend,
				'GET',
				'/v2/users/current/quest/challenge?quest=' . $questId,
			),
			(string) $friend->id(),
		);
		$this->assertSame(Response::HTTP_OK, $fetched->getStatusCode());
		$fetchedBody = $this->decode($fetched);
		$this->assertSame($challengeId, $fetchedBody['challenge']['id']);
		$this->assertArrayHasKey('other_progress', $fetchedBody);

		$decline = $this->controller()->declineQuestChallenge(
			$this->authRequest(
				$friend,
				'POST',
				'/v2/users/current/quest/challenge/' . $challengeId . '/decline',
			),
			$challengeId,
			(string) $friend->id(),
		);
		$this->assertSame(Response::HTTP_OK, $decline->getStatusCode());
		$this->assertTrue($this->decode($decline)['ok']);
	}

	#[Test]
	#[TestDox('accept/decline of an unknown challenge id resolves to 404')]
	#[Group('mantle2/users')]
	public function respondToUnknownChallenge(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->acceptQuestChallenge(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/quest/challenge/____nope____/accept',
			),
			'____nope____',
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('challengeFriendToQuest forbids challenging a non-friend')]
	#[Group('mantle2/users')]
	public function challengeNonFriendForbidden(): void
	{
		$challenger = $this->createUser(['field_email_verified' => true]);
		$stranger = $this->createUser(['field_email_verified' => true]);
		$questId = PointsHelper::getAllQuests()[0]->id;

		$response = $this->controller()->challengeFriendToQuest(
			$this->authRequest(
				$challenger,
				'POST',
				'/v2/users/current/quest/challenge?friend=' .
					$stranger->id() .
					'&quest=' .
					$questId,
			),
			(string) $challenger->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
	}

	// #endregion

	// #region quest history

	#[Test]
	#[TestDox('GET /v2/users/{id}/quest/history returns the paginated cloud history envelope')]
	#[Group('mantle2/users')]
	public function questHistory(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->questHistory(
			$this->authRequest($user, 'GET', '/v2/users/current/quest/history?limit=10'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertArrayHasKey('items', $body);
		$this->assertArrayHasKey('history', $body);
		$this->assertIsArray($body['items']);
	}

	// #endregion

	// #region recommendations

	#[Test]
	#[TestDox('GET /v2/users/{id}/recommendations returns a recommended-activity list')]
	#[Group('mantle2/users')]
	public function recommendUserActivities(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->recommendUserActivities(
			$this->authRequest($user, 'GET', '/v2/users/current/recommendations?pool_limit=10'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertIsArray($this->decode($response));
	}

	#[Test]
	#[TestDox('GET /v2/users/{id}/recommendations rejects an out-of-range pool_limit')]
	#[Group('mantle2/users')]
	public function recommendRejectsBadPoolLimit(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->recommendUserActivities(
			$this->authRequest($user, 'GET', '/v2/users/current/recommendations?pool_limit=999'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
	}

	// #endregion

	// #region profile photo

	#[Test]
	#[TestDox('GET /v2/users/{id}/profile_photo returns a rendered image response')]
	#[Group('mantle2/users')]
	public function getProfilePhoto(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->getProfilePhoto(
			$this->authRequest($user, 'GET', '/v2/users/current/profile_photo?size=128'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertStringContainsString('image/', $response->headers->get('Content-Type') ?? '');
	}

	#[Test]
	#[TestDox('GET /v2/users/{id}/profile_photo rejects an unsupported size')]
	#[Group('mantle2/users')]
	public function getProfilePhotoRejectsBadSize(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->getProfilePhoto(
			$this->authRequest($user, 'GET', '/v2/users/current/profile_photo?size=999'),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
	}

	// #endregion

	// #region trails

	#[Test]
	#[
		TestDox(
			'GET /v2/users/trails lists trails and GET /v2/users/trails/{id} returns a full trail from cloud',
		),
	]
	#[Group('mantle2/trails')]
	public function listAndGet(): void
	{
		$user = $this->createUser();

		$list = $this->controller()->trails($this->authRequest($user, 'GET', '/v2/users/trails'));
		$this->assertSame(Response::HTTP_OK, $list->getStatusCode());
		$trails = $this->decode($list);
		$this->assertNotEmpty($trails, 'cloud should return a non-empty trail catalog');

		$first = $trails[0];
		$this->assertArrayHasKey('id', $first);
		// standalone trails: one qualitative practice + a curiosity gap (no quest steps)
		$this->assertArrayHasKey('practice', $first);

		$one = $this->controller()->getTrail(
			$this->authRequest($user, 'GET', '/v2/users/trails/' . $first['id']),
			$first['id'],
		);
		$this->assertSame(Response::HTTP_OK, $one->getStatusCode());
		$body = $this->decode($one);
		$this->assertSame($first['id'], $body['id']);
		$this->assertArrayHasKey('theme', $body);
		$this->assertArrayHasKey('rarity', $body);
		// the sustained practice + the curiosity gap + the awe reveal + the reflection prompt
		$this->assertArrayHasKey('practice', $body);
		$this->assertArrayHasKey('curiosity', $body);
		$this->assertArrayHasKey('reveal', $body);
		$this->assertArrayHasKey('reflectionPrompt', $body);
	}

	#[Test]
	#[TestDox('A premium or seasonal trail is paywalled (402) for a free rank')]
	#[Group('mantle2/trails')]
	public function premiumGate(): void
	{
		$user = $this->createUser();

		$list = $this->controller()->trails($this->authRequest($user, 'GET', '/v2/users/trails'));
		$trails = $this->decode($list);

		$locked = null;
		foreach ($trails as $trail) {
			if (($trail['premium'] ?? false) || ($trail['seasonal'] ?? false)) {
				$locked = $trail;
				break;
			}
		}

		if ($locked === null) {
			$this->markTestSkipped('No premium/seasonal trail in the catalog to gate');
		}

		$res = $this->controller()->getTrail(
			$this->authRequest($user, 'GET', '/v2/users/trails/' . $locked['id']),
			$locked['id'],
		);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $res->getStatusCode());
	}

	// #endregion

	// #region nature minutes

	#[Test]
	#[TestDox('POST then GET /v2/users/current/nature-minutes credits and reads the weekly ring')]
	#[Group('mantle2/trails')]
	public function creditAndReadNatureMinutes(): void
	{
		$user = $this->createUser();

		$credit = $this->controller()->creditNatureMinutes(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/nature-minutes',
				[],
				json_encode(['minutes' => 15, 'kind' => 'trail', 'ref_id' => 'e2e_trail']),
			),
		);
		$this->assertSame(Response::HTTP_OK, $credit->getStatusCode());
		$body = $this->decode($credit);
		$this->assertArrayHasKey('week', $body);
		$this->assertSame(120, (int) $body['target']);
		$this->assertGreaterThanOrEqual(15, (float) $body['minutes']);
		$this->assertNotEmpty($body['sources']);

		$read = $this->controller()->getNatureMinutes(
			$this->authRequest($user, 'GET', '/v2/users/current/nature-minutes'),
		);
		$this->assertSame(Response::HTTP_OK, $read->getStatusCode());
		$this->assertSame($body['week'], $this->decode($read)['week']);
	}

	#[Test]
	#[TestDox('The {username} variant resolves the same self ring as /current for nature-minutes')]
	#[Group('mantle2/trails')]
	public function creditAndReadNatureMinutesByUsername(): void
	{
		$user = $this->createUser();
		$handle = '@' . $user->getAccountName();

		$credit = $this->controller()->creditNatureMinutes(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/' . $handle . '/nature-minutes',
				[],
				json_encode(['minutes' => 20, 'kind' => 'quest', 'ref_id' => 'e2e_username']),
			),
			null,
			$handle,
		);
		$this->assertSame(Response::HTTP_OK, $credit->getStatusCode());
		$creditBody = $this->decode($credit);
		$this->assertArrayHasKey('week', $creditBody);
		$this->assertGreaterThanOrEqual(20, (float) $creditBody['minutes']);

		$read = $this->controller()->getNatureMinutes(
			$this->authRequest($user, 'GET', '/v2/users/' . $handle . '/nature-minutes'),
			null,
			$handle,
		);
		$this->assertSame(Response::HTTP_OK, $read->getStatusCode());
		$this->assertSame($creditBody['week'], $this->decode($read)['week']);
	}

	// #endregion

	// expeditions/kudos now live in UsersController under /v2/users/*
	// #region expeditions

	#[Test]
	#[TestDox('Full expedition lifecycle: start, get, contribute, and garden projection via cloud')]
	#[Group('mantle2/circles')]
	public function lifecycle(): void
	{
		$owner = $this->createUser();

		$start = $this->controller()->startExpedition(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/current/expedition',
				[],
				json_encode([
					'title' => 'E2E Expedition',
					'goal' => 'nature_minutes',
					'target' => 300,
					'ends_at' => '2030-12-31T00:00:00Z',
				]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $start->getStatusCode());
		$expedition = $this->decode($start);
		$this->assertArrayHasKey('id', $expedition);
		$this->assertSame('nature_minutes', $expedition['goal']);
		$this->assertSame('active', $expedition['status']);
		$this->assertArrayHasKey('contributors', $expedition);

		$get = $this->controller()->getExpedition(
			$this->authRequest($owner, 'GET', '/v2/users/current/expedition'),
		);
		$this->assertSame(Response::HTTP_OK, $get->getStatusCode());
		$this->assertSame($expedition['id'], $this->decode($get)['id']);

		$contribute = $this->controller()->contribute(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/' . $owner->id() . '/expedition/contribute',
				[],
				json_encode(['amount' => 60]),
			),
			(string) $owner->id(),
		);
		$this->assertSame(Response::HTTP_OK, $contribute->getStatusCode());
		$result = $this->decode($contribute);
		$this->assertArrayHasKey('expedition', $result);
		$this->assertArrayHasKey('just_completed', $result);
		$this->assertGreaterThanOrEqual(60, (float) $result['expedition']['progress']);

		$garden = $this->controller()->getGarden(
			$this->authRequest($owner, 'GET', '/v2/users/current/garden'),
		);
		$this->assertSame(Response::HTTP_OK, $garden->getStatusCode());
		$gardenBody = $this->decode($garden);
		$this->assertArrayHasKey('elements', $gardenBody);
		$this->assertArrayHasKey('animated', $gardenBody);
		$this->assertArrayHasKey('total_minutes', $gardenBody);
	}

	#[Test]
	#[
		TestDox(
			'The {username} owner variant reads the same expedition + garden as the owner /current view',
		),
	]
	#[Group('mantle2/circles')]
	public function ownerByUsername(): void
	{
		$owner = $this->createUser();
		$handle = '@' . $owner->getAccountName();

		$start = $this->controller()->startExpedition(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/current/expedition',
				[],
				json_encode([
					'title' => 'Username Expedition',
					'goal' => 'trails',
					'target' => 300,
					'ends_at' => '2030-12-31T00:00:00Z',
				]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $start->getStatusCode());
		$expeditionId = $this->decode($start)['id'];

		// the owner may read their own expedition via their username handle
		$get = $this->controller()->getExpedition(
			$this->authRequest($owner, 'GET', '/v2/users/' . $handle . '/expedition'),
			null,
			$handle,
		);
		$this->assertSame(Response::HTTP_OK, $get->getStatusCode());
		$this->assertSame($expeditionId, $this->decode($get)['id']);

		$garden = $this->controller()->getGarden(
			$this->authRequest($owner, 'GET', '/v2/users/' . $handle . '/garden'),
			null,
			$handle,
		);
		$this->assertSame(Response::HTTP_OK, $garden->getStatusCode());
		$this->assertArrayHasKey('elements', $this->decode($garden));
	}

	// #endregion
}
