<?php

namespace Drupal\Tests\mantle2\Integration\Controller\Users;

use Drupal\mantle2\Controller\UsersController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class EngagementTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		// dead endpoint so CloudHelper degrades to [] (connection refused) instead of
		// hitting an ambient worker; keeps notification/quest/badge local branches inert
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
		// prompt comment counts read this table
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

	// seeds a notification directly through the helper and returns its id
	private function seedNotification(UserInterface $user, string $type = 'info'): string
	{
		$notification = UsersHelper::addNotification(
			$user,
			'Title',
			'Body message',
			'/link',
			$type,
			'system',
		);
		$this->assertNotNull($notification);
		return $notification->getId();
	}

	#region User Notifications

	#[Test]
	#[TestDox('GET notifications requires auth and returns aggregate + items')]
	#[Group('mantle2/users')]
	public function userNotifications(): void
	{
		$anon = $this->controller()->userNotifications(
			$this->request('GET', '/v2/users/current/notifications'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->createUser();
		$this->seedNotification($user, 'warning');
		$this->seedNotification($user, 'error');

		$response = $this->controller()->userNotifications(
			$this->authRequest($user, 'GET', '/v2/users/current/notifications'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame(2, $body['unread_count']);
		$this->assertTrue($body['has_warnings']);
		$this->assertTrue($body['has_errors']);
		$this->assertCount(2, $body['items']);
		$this->assertArrayHasKey('id', $body['items'][0]);
		$this->assertArrayHasKey('read', $body['items'][0]);
	}

	#[Test]
	#[TestDox('GET single notification returns it or 404')]
	#[Group('mantle2/users')]
	public function getUserNotification(): void
	{
		$user = $this->createUser();
		$id = $this->seedNotification($user);

		$missing = $this->controller()->getUserNotification(
			$this->authRequest($user, 'GET', '/v2/users/current/notifications/nope'),
			'nope',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$response = $this->controller()->getUserNotification(
			$this->authRequest($user, 'GET', '/v2/users/current/notifications/' . $id),
			$id,
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame($id, $body['id']);
		$this->assertSame('Title', $body['title']);
		$this->assertFalse($body['read']);
	}

	#[Test]
	#[TestDox('POST create notification is admin-only and persists a row')]
	#[Group('mantle2/users')]
	public function createUserNotification(): void
	{
		$target = $this->createUser();

		$anon = $this->controller()->createUserNotification(
			$this->request('POST', '/v2/users/' . $target->id() . '/notifications', [], '{}'),
			(string) $target->id(),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		// a non-admin acting on themselves passes authorization but fails the admin gate
		$forbidden = $this->controller()->createUserNotification(
			$this->authRequest(
				$target,
				'POST',
				'/v2/users/' . $target->id() . '/notifications',
				[],
				'{"title":"T","description":"D"}',
			),
			(string) $target->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->admin();
		$missing = $this->controller()->createUserNotification(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/users/' . $admin->id() . '/notifications',
				[],
				'{"title":"T"}',
			),
			(string) $admin->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());

		$ok = $this->controller()->createUserNotification(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/users/' . $admin->id() . '/notifications',
				[],
				'{"title":"Hi","description":"There","type":"success","link":"/x","source":"admin"}',
			),
			(string) $admin->id(),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('Hi', $body['title']);
		$this->assertSame('success', $body['type']);
		$this->assertSame('admin', $body['source']);

		$reloaded = UsersHelper::findById((int) $admin->id());
		$this->assertCount(1, UsersHelper::getNotifications($reloaded));
	}

	#[Test]
	#[TestDox('POST mark_all_read / mark_all_unread flip every notification')]
	#[Group('mantle2/users')]
	public function markAllUserNotifications(): void
	{
		$user = $this->createUser();
		$this->seedNotification($user);
		$this->seedNotification($user);

		$read = $this->controller()->markAllUserNotificationsRead(
			$this->authRequest($user, 'POST', '/v2/users/current/notifications/mark_all_read'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $read->getStatusCode());
		foreach (UsersHelper::getNotifications(UsersHelper::findById((int) $user->id())) as $n) {
			$this->assertTrue($n->isRead());
		}

		$unread = $this->controller()->markAllUserNotificationsUnread(
			$this->authRequest($user, 'POST', '/v2/users/current/notifications/mark_all_unread'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $unread->getStatusCode());
		foreach (UsersHelper::getNotifications(UsersHelper::findById((int) $user->id())) as $n) {
			$this->assertFalse($n->isRead());
		}
	}

	#[Test]
	#[TestDox('POST mark_read / mark_unread toggle a single notification with 409 + 404 guards')]
	#[Group('mantle2/users')]
	public function markUserNotification(): void
	{
		$user = $this->createUser();
		$id = $this->seedNotification($user);

		$missing = $this->controller()->markUserNotificationRead(
			$this->authRequest($user, 'POST', '/v2/users/current/notifications/nope/mark_read'),
			'nope',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$read = $this->controller()->markUserNotificationRead(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/notifications/' . $id . '/mark_read',
			),
			$id,
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $read->getStatusCode());
		$this->assertTrue(
			UsersHelper::getNotification(UsersHelper::findById((int) $user->id()), $id)->isRead(),
		);

		$already = $this->controller()->markUserNotificationRead(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/notifications/' . $id . '/mark_read',
			),
			$id,
		);
		$this->assertSame(Response::HTTP_CONFLICT, $already->getStatusCode());

		$unread = $this->controller()->markUserNotificationUnread(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/notifications/' . $id . '/mark_unread',
			),
			$id,
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $unread->getStatusCode());

		$alreadyUnread = $this->controller()->markUserNotificationUnread(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/notifications/' . $id . '/mark_unread',
			),
			$id,
		);
		$this->assertSame(Response::HTTP_CONFLICT, $alreadyUnread->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE a notification removes it, 404 when unknown')]
	#[Group('mantle2/users')]
	public function deleteUserNotification(): void
	{
		$user = $this->createUser();
		$id = $this->seedNotification($user);

		$missing = $this->controller()->deleteUserNotification(
			$this->authRequest($user, 'DELETE', '/v2/users/current/notifications/nope'),
			'nope',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$deleted = $this->controller()->deleteUserNotification(
			$this->authRequest($user, 'DELETE', '/v2/users/current/notifications/' . $id),
			$id,
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $deleted->getStatusCode());
		$this->assertCount(
			0,
			UsersHelper::getNotifications(UsersHelper::findById((int) $user->id())),
		);
	}

	#[Test]
	#[TestDox('DELETE clear wipes all notifications')]
	#[Group('mantle2/users')]
	public function clearUserNotifications(): void
	{
		$user = $this->createUser();
		$this->seedNotification($user);
		$this->seedNotification($user);

		$response = $this->controller()->clearUserNotifications(
			$this->authRequest($user, 'DELETE', '/v2/users/current/notifications/clear'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
		$this->assertCount(
			0,
			UsersHelper::getNotifications(UsersHelper::findById((int) $user->id())),
		);
	}

	#endregion

	#region Subscription Management

	#[Test]
	#[TestDox('POST subscribe flips the flag and is idempotent')]
	#[Group('mantle2/users')]
	public function subscribe(): void
	{
		$anon = $this->controller()->subscribe(
			$this->request('POST', '/v2/users/current/subscribe'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->createUser();
		UsersHelper::setSubscribed($user, false);
		$user->save();

		$first = $this->controller()->subscribe(
			$this->authRequest($user, 'POST', '/v2/users/current/subscribe'),
		);
		$this->assertSame(Response::HTTP_CREATED, $first->getStatusCode());
		$this->assertTrue($this->decode($first)['subscribed']);
		$this->assertTrue(UsersHelper::isSubscribed(UsersHelper::findById((int) $user->id())));

		$again = $this->controller()->subscribe(
			$this->authRequest($user, 'POST', '/v2/users/current/subscribe'),
		);
		$this->assertSame(Response::HTTP_OK, $again->getStatusCode());
		$this->assertTrue($this->decode($again)['subscribed']);
	}

	#[Test]
	#[TestDox('POST unsubscribe clears the flag and is idempotent')]
	#[Group('mantle2/users')]
	public function unsubscribe(): void
	{
		$user = $this->createUser();

		$first = $this->controller()->unsubscribe(
			$this->authRequest($user, 'POST', '/v2/users/current/unsubscribe'),
		);
		$this->assertSame(Response::HTTP_CREATED, $first->getStatusCode());
		$this->assertFalse($this->decode($first)['subscribed']);
		$this->assertFalse(UsersHelper::isSubscribed(UsersHelper::findById((int) $user->id())));

		$again = $this->controller()->unsubscribe(
			$this->authRequest($user, 'POST', '/v2/users/current/unsubscribe'),
		);
		$this->assertSame(Response::HTTP_OK, $again->getStatusCode());
		$this->assertFalse($this->decode($again)['subscribed']);
	}

	#[Test]
	#[TestDox('GET public unsubscribe validates the token and unsubscribes')]
	#[Group('mantle2/users')]
	public function publicUnsubscribe(): void
	{
		$missing = $this->controller()->publicUnsubscribe(
			$this->request('GET', '/v2/users/unsubscribe'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());

		$bad = $this->controller()->publicUnsubscribe(
			$this->request('GET', '/v2/users/unsubscribe?token=deadbeef'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $bad->getStatusCode());

		$user = $this->createUser();
		$token = UsersHelper::generateUnsubscribeToken($user);

		$ok = $this->controller()->publicUnsubscribe(
			$this->request('GET', '/v2/users/unsubscribe?token=' . $token),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertFalse($this->decode($ok)['subscribed']);
		$this->assertFalse(UsersHelper::isSubscribed(UsersHelper::findById((int) $user->id())));

		// token is single-use: revoked after consumption
		$this->assertNull(UsersHelper::validateUnsubscribeToken($token));
	}

	#endregion

	#region Email Verification

	#[Test]
	#[TestDox('POST send_email_verification stores a code, mails it, and 409s once verified')]
	#[Group('mantle2/users')]
	public function sendEmailVerification(): void
	{
		\Drupal::state()->set('system.test_mail_collector', []);

		$anon = $this->controller()->sendEmailVerification(
			$this->request('POST', '/v2/users/current/send_email_verification'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->createUser();
		$response = $this->controller()->sendEmailVerification(
			$this->authRequest($user, 'POST', '/v2/users/current/send_email_verification'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame($user->getEmail(), $this->decode($response)['email']);

		$stored = RedisHelper::get('email_verification_' . $user->id());
		$this->assertNotNull($stored);
		$this->assertMatchesRegularExpression('/^\d{8}$/', $stored['code']);

		$mails = \Drupal::state()->get('system.test_mail_collector');
		$this->assertNotEmpty($mails);
		$last = end($mails);
		$this->assertSame($user->getEmail(), $last['to']);
		$this->assertStringContainsString('Verification Code', $last['subject']);

		// verified users get a 409
		$user->set('field_email_verified', true)->save();
		$conflict = $this->controller()->sendEmailVerification(
			$this->authRequest($user, 'POST', '/v2/users/current/send_email_verification'),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $conflict->getStatusCode());
	}

	#[Test]
	#[TestDox('POST verify_email validates the code and flips the verified field')]
	#[Group('mantle2/users')]
	public function verifyEmail(): void
	{
		$user = $this->createUser();

		$noCode = $this->controller()->verifyEmail(
			$this->authRequest($user, 'POST', '/v2/users/current/verify_email'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noCode->getStatusCode());

		$badFormat = $this->controller()->verifyEmail(
			$this->authRequest($user, 'POST', '/v2/users/current/verify_email?code=12'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badFormat->getStatusCode());

		$noStored = $this->controller()->verifyEmail(
			$this->authRequest($user, 'POST', '/v2/users/current/verify_email?code=12345678'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noStored->getStatusCode());

		RedisHelper::set('email_verification_' . $user->id(), ['code' => '87654321'], 900);
		$wrong = $this->controller()->verifyEmail(
			$this->authRequest($user, 'POST', '/v2/users/current/verify_email?code=11112222'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $wrong->getStatusCode());

		$ok = $this->controller()->verifyEmail(
			$this->authRequest($user, 'POST', '/v2/users/current/verify_email?code=87654321'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertTrue($this->decode($ok)['email_verified']);
		$this->assertTrue(UsersHelper::isEmailVerified(UsersHelper::findById((int) $user->id())));
		$this->assertNull(RedisHelper::get('email_verification_' . $user->id()));

		$already = $this->controller()->verifyEmail(
			$this->authRequest($user, 'POST', '/v2/users/current/verify_email?code=87654321'),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $already->getStatusCode());
	}

	#endregion

	#region User Content

	#[Test]
	#[TestDox('GET prompts lists owned prompts with pagination envelope')]
	#[Group('mantle2/users')]
	public function userPrompts(): void
	{
		$user = $this->createUser();
		Node::create([
			'type' => 'prompt',
			'title' => 'P',
			'field_owner_id' => $user->id(),
			'field_prompt' => 'What is your goal?',
		])->save();

		$response = $this->controller()->userPrompts(
			$this->authRequest($user, 'GET', '/v2/users/current/prompts'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame(1, $body['total']);
		$this->assertSame(1, $body['page']);
		$this->assertCount(1, $body['items']);

		$missing = $this->controller()->userPrompts(
			$this->request('GET', '/v2/users/999999/prompts'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('GET articles lists authored articles with pagination envelope')]
	#[Group('mantle2/users')]
	public function userArticles(): void
	{
		$user = $this->createUser();
		Node::create([
			'type' => 'article',
			'title' => 'A',
			'field_author_id' => $user->id(),
			'field_article_title' => 'Hello World',
			'field_article_description' => 'Desc',
			'field_article_content' => 'Body',
		])->save();

		$response = $this->controller()->userArticles(
			$this->authRequest($user, 'GET', '/v2/users/current/articles'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame(1, $body['total']);
		$this->assertSame(1, $body['page']);
		$this->assertCount(1, $body['items']);
	}

	#[Test]
	#[TestDox('GET hosted events lists events the user hosts with pagination envelope')]
	#[Group('mantle2/users')]
	public function userHostedEvents(): void
	{
		$user = $this->createUser();
		Node::create([
			'type' => 'event',
			'title' => 'E',
			'field_host_id' => $user->id(),
			'field_event_name' => 'Cleanup',
			'field_event_date' => '2030-01-01T10:00:00',
		])->save();

		$response = $this->controller()->userHostedEvents(
			$this->authRequest($user, 'GET', '/v2/users/current/events'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame(1, $body['total']);
		$this->assertSame(1, $body['page']);
		$this->assertCount(1, $body['items']);
	}

	#endregion

	#region User Badges (local guard branches; catalog + data are cloud/E2E)

	#[Test]
	#[TestDox('GET all badges returns the catalog array (empty without cloud)')]
	#[Group('mantle2/users')]
	public function allBadges(): void
	{
		$response = $this->controller()->allBadges();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertIsArray($this->decode($response));
	}

	#[Test]
	#[TestDox('GET user badges enforces visibility before the cloud fetch')]
	#[Group('mantle2/users')]
	public function badges(): void
	{
		$missing = $this->controller()->badges(
			$this->request('GET', '/v2/users/999999/badges'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$user = $this->createUser();
		$response = $this->controller()->badges(
			$this->authRequest($user, 'GET', '/v2/users/current/badges'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertIsArray($this->decode($response));
	}

	#[Test]
	#[TestDox('GET single badge requires badgeId and 404s when absent')]
	#[Group('mantle2/users')]
	public function badge(): void
	{
		$user = $this->createUser();

		$noId = $this->controller()->badge(
			$this->authRequest($user, 'GET', '/v2/users/current/badges/'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noId->getStatusCode());

		// without cloud getBadge returns null -> 404
		$absent = $this->controller()->badge(
			$this->authRequest($user, 'GET', '/v2/users/current/badges/verified'),
			null,
			null,
			'verified',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $absent->getStatusCode());
	}

	#endregion

	#region Quest Routes (local state only; challenge/history are cloud/E2E)

	#[Test]
	#[TestDox('GET current quest returns a serialized quest-state object')]
	#[Group('mantle2/users')]
	public function userQuests(): void
	{
		$anon = $this->controller()->userQuests($this->request('GET', '/v2/users/current/quest'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->createUser();
		$response = $this->controller()->userQuests(
			$this->authRequest($user, 'GET', '/v2/users/current/quest'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertIsArray($this->decode($response));
	}

	#[Test]
	#[TestDox('GET quest step requires a step parameter but accepts step "0"')]
	#[Group('mantle2/users')]
	public function userQuestStep(): void
	{
		$user = $this->createUser();

		$missing = $this->controller()->userQuestStep(
			$this->authRequest($user, 'GET', '/v2/users/current/quest/step/'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());

		// step "0" is a valid index (not "missing"); reaches the cloud-backed lookup
		$zero = $this->controller()->userQuestStep(
			$this->authRequest($user, 'GET', '/v2/users/current/quest/step/0'),
			null,
			null,
			'0',
		);
		$this->assertSame(Response::HTTP_OK, $zero->getStatusCode());
		$this->assertIsArray($this->decode($zero));

		$response = $this->controller()->userQuestStep(
			$this->authRequest($user, 'GET', '/v2/users/current/quest/step/1'),
			null,
			null,
			'1',
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertIsArray($this->decode($response));
	}

	// start quest success/failure is decided by cloud (PointsHelper::startQuest posts to
	// /v1/users/quests/progress/.../start), so only the local missing-quest_id 400 is asserted here
	#[Test]
	#[TestDox('POST start quest requires the quest_id parameter (start itself is cloud/E2E)')]
	#[Group('mantle2/users')]
	public function startQuest(): void
	{
		$user = $this->createUser();

		$anon = $this->controller()->startQuest($this->request('POST', '/v2/users/current/quest'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$missing = $this->controller()->startQuest(
			$this->authRequest($user, 'POST', '/v2/users/current/quest'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE quest 409s when there is no ongoing quest')]
	#[Group('mantle2/users')]
	public function cancelQuest(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->cancelQuest(
			$this->authRequest($user, 'DELETE', '/v2/users/current/quest'),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
	}

	#endregion

	#region User Polls

	#[Test]
	#[TestDox('GET polls returns the voted-poll list for self')]
	#[Group('mantle2/users')]
	public function getUserPolls(): void
	{
		$anon = $this->controller()->getUserPolls($this->request('GET', '/v2/users/current/poll'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->createUser();
		$response = $this->controller()->getUserPolls(
			$this->authRequest($user, 'GET', '/v2/users/current/poll'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame([], $this->decode($response)['items']);
	}

	#[Test]
	#[TestDox('POST vote validates the body, records the vote, and forbids impersonation')]
	#[Group('mantle2/users')]
	public function submitVote(): void
	{
		$user = $this->createUser();
		$other = $this->createUser();

		$forbidden = $this->controller()->submitVote(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/' . $other->id() . '/poll',
				[],
				'{"poll_id":"p1","option_index":0,"question":"Q","options":["a","b"]}',
			),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$badBody = $this->controller()->submitVote(
			$this->authRequest($user, 'POST', '/v2/users/current/poll', [], 'not json'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badBody->getStatusCode());

		$badPoll = $this->controller()->submitVote(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/poll',
				[],
				'{"poll_id":"BAD ID","option_index":0,"question":"Q","options":["a","b"]}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badPoll->getStatusCode());

		$outOfRange = $this->controller()->submitVote(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/poll',
				[],
				'{"poll_id":"p1","option_index":5,"question":"Q","options":["a","b"]}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $outOfRange->getStatusCode());

		$ok = $this->controller()->submitVote(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/poll',
				[],
				'{"poll_id":"p1","option_index":1,"question":"Favorite?","options":["a","b"]}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('p1', $body['poll_id']);
		$this->assertSame(1, $body['option_index']);
		$this->assertSame('b', $body['option_text']);
		$this->assertSame(1, $body['aggregate']['total']);

		$stored = UsersHelper::getUserVote((int) $user->id(), 'p1');
		$this->assertSame(1, $stored['option_index']);
	}

	#[Test]
	#[TestDox('DELETE vote retracts an existing vote, 404 when none exists')]
	#[Group('mantle2/users')]
	public function retractVote(): void
	{
		$user = $this->createUser();

		$none = $this->controller()->retractVote(
			$this->authRequest($user, 'DELETE', '/v2/users/current/poll?poll_id=p1'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $none->getStatusCode());

		UsersHelper::recordVote((int) $user->id(), 'p1', 0, 'Q', ['a', 'b']);
		// clear the rate-limit set by recordVote so the retract path is exercised
		RedisHelper::delete('poll:rate:' . $user->id());

		$removed = $this->controller()->retractVote(
			$this->authRequest($user, 'DELETE', '/v2/users/current/poll?poll_id=p1'),
		);
		$this->assertSame(Response::HTTP_OK, $removed->getStatusCode());
		$body = $this->decode($removed);
		$this->assertTrue($body['removed']);
		$this->assertSame('p1', $body['poll_id']);
		$this->assertNull(UsersHelper::getUserVote((int) $user->id(), 'p1'));
	}

	#[Test]
	#[TestDox('GET polls and DELETE vote 403 a cross-user actor')]
	#[Group('mantle2/users')]
	public function pollsCrossUser(): void
	{
		$owner = $this->createUser();
		$other = $this->createUser();

		$retract = $this->controller()->retractVote(
			$this->authRequest($owner, 'DELETE', '/v2/users/' . $other->id() . '/poll?poll_id=p1'),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $retract->getStatusCode());

		// getUserPolls uses resolveAuthorizedUser, so a non-admin viewing another user is 403
		$polls = $this->controller()->getUserPolls(
			$this->authRequest($owner, 'GET', '/v2/users/' . $other->id() . '/poll'),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $polls->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE retract vote 400s a missing poll_id')]
	#[Group('mantle2/users')]
	public function retractVoteMissingPollId(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->retractVote(
			$this->authRequest($user, 'DELETE', '/v2/users/current/poll'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertStringContainsString(
			'poll_id is required',
			$this->decode($response)['message'],
		);
	}

	#endregion

	#region createUserNotification (bad body + authorization)

	#[Test]
	#[
		TestDox(
			'POST create notification 403s a cross-user non-admin and 400s malformed JSON for an admin',
		),
	]
	#[Group('mantle2/users')]
	public function createUserNotificationGuards(): void
	{
		$owner = $this->createUser();
		$other = $this->createUser();

		// a non-admin acting on another user is refused by resolveAuthorizedUser (403)
		$crossUser = $this->controller()->createUserNotification(
			$this->authRequest(
				$owner,
				'POST',
				'/v2/users/' . $other->id() . '/notifications',
				[],
				'{"title":"T","description":"D"}',
			),
			(string) $other->id(),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $crossUser->getStatusCode());

		$admin = $this->admin();
		$badJson = $this->controller()->createUserNotification(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/users/' . $admin->id() . '/notifications',
				[],
				'{bad json',
			),
			(string) $admin->id(),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());
	}

	#endregion

	#region verify_email (email-change path)

	#[Test]
	#[TestDox('POST verify_email routes a matching email-change code and persists the new email')]
	#[Group('mantle2/users')]
	public function verifyEmailChangeCode(): void
	{
		$user = $this->createUser(['mail' => 'old.addr@example.com']);

		// a stored email_change_<uid> code whose value matches routes to verifyEmailChange, which
		// swaps in the pending new_email; the confirmation email/notification fan out to the dead
		// cloud, so tolerate that and assert the local email swap persisted
		RedisHelper::set(
			'email_change_' . $user->id(),
			[
				'code' => '55554444',
				'new_email' => 'new.addr@example.com',
				'old_email' => 'old.addr@example.com',
			],
			900,
		);

		try {
			$this->controller()->verifyEmail(
				$this->authRequest($user, 'POST', '/v2/users/current/verify_email?code=55554444'),
			);
		} catch (\Throwable $e) {
			$this->assertStringContainsString('HTTP Error', $e->getMessage());
		}

		$this->assertSame(
			'new.addr@example.com',
			\Drupal\user\Entity\User::load($user->id())->getEmail(),
		);
	}

	#endregion

	#region quest challenge (local guard branches; cloud send is E2E)

	#[Test]
	#[TestDox('POST challenge 400s missing friend, 404s unknown friend, and forbids a non-friend')]
	#[Group('mantle2/users')]
	public function challengeFriendGuards(): void
	{
		$user = $this->createUser();
		$stranger = $this->createUser();

		$noFriend = $this->controller()->challengeFriendToQuest(
			$this->authRequest($user, 'POST', '/v2/users/current/quest/challenge'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noFriend->getStatusCode());
		$this->assertStringContainsString('Missing friend', $this->decode($noFriend)['message']);

		$unknown = $this->controller()->challengeFriendToQuest(
			$this->authRequest($user, 'POST', '/v2/users/current/quest/challenge?friend=999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());

		$notFriend = $this->controller()->challengeFriendToQuest(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/quest/challenge?friend=' . $stranger->id(),
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $notFriend->getStatusCode());
		$this->assertStringContainsString(
			'only challenge friends',
			$this->decode($notFriend)['message'],
		);
	}

	#[Test]
	#[TestDox('GET quest challenge 400s a missing quest parameter')]
	#[Group('mantle2/users')]
	public function getQuestChallengeMissingQuest(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->getQuestChallenge(
			$this->authRequest($user, 'GET', '/v2/users/current/quest/challenge'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertStringContainsString('Missing quest', $this->decode($response)['message']);
	}

	#endregion

	#region quests catalog + badge/mastery guards

	#[Test]
	#[TestDox('GET quests badge-mastery id requires auth')]
	#[Group('mantle2/users')]
	public function questsBadgeMasteryRequiresAuth(): void
	{
		$anon = $this->request('GET', '/v2/users/quests');
		$anon->query->replace(['id' => 'badge_mastery_recycler']);
		$response = $this->controller()->quests($anon);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('GET quests returns the catalog envelope when no id is given')]
	#[Group('mantle2/users')]
	public function questsCatalog(): void
	{
		$response = $this->controller()->quests($this->request('GET', '/v2/users/quests'));
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertArrayHasKey('total', $body);
		$this->assertArrayHasKey('quests', $body);
	}

	#[Test]
	#[TestDox('GET badge/{badgeId}/mastery and generate 400 a missing badgeId')]
	#[Group('mantle2/users')]
	public function badgeMasteryMissingBadgeId(): void
	{
		$user = $this->createUser();

		$mastery = $this->controller()->badgeMastery(
			$this->authRequest($user, 'GET', '/v2/users/current/badges//mastery'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $mastery->getStatusCode());

		$generate = $this->controller()->generateBadgeMastery(
			$this->authRequest($user, 'POST', '/v2/users/current/badges//mastery/generate'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $generate->getStatusCode());
	}

	#[Test]
	#[TestDox('GET quest history entry 400s a missing quest_id')]
	#[Group('mantle2/users')]
	public function questHistoryEntryMissingId(): void
	{
		$user = $this->createUser();
		$response = $this->controller()->questHistoryEntry(
			$this->authRequest($user, 'GET', '/v2/users/current/quest/history/'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('GET share quest card 404s an unknown user, a disabled user, and an unknown quest')]
	#[Group('mantle2/users')]
	public function shareQuestCard(): void
	{
		$missingUser = $this->controller()->shareQuestCard(
			$this->request('GET', '/v2/users/999999/share/quest/q1'),
			'999999',
			'q1',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missingUser->getStatusCode());

		$disabled = $this->createUser();
		$disabled->block();
		$disabled->save();
		$disabledRes = $this->controller()->shareQuestCard(
			$this->request('GET', '/v2/users/' . $disabled->id() . '/share/quest/q1'),
			(string) $disabled->id(),
			'q1',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $disabledRes->getStatusCode());

		$user = $this->createUser();
		$badQuest = $this->controller()->shareQuestCard(
			$this->request('GET', '/v2/users/' . $user->id() . '/share/quest/not_a_quest'),
			(string) $user->id(),
			'not_a_quest',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $badQuest->getStatusCode());
	}

	#endregion
}
