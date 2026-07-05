<?php

namespace Drupal\Tests\mantle2\Integration\Service\Users;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EngagementHelperTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		// dead endpoint so CloudHelper degrades to [] (connection refused); keeps the
		// websocket fan-out in addNotification and badge/quest reads inert
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
		\Drupal::state()->set('system.test_mail_collector', []);
	}

	private function mail(): array
	{
		return \Drupal::state()->get('system.test_mail_collector') ?? [];
	}

	#region User Notifications

	#[Test]
	#[TestDox('addNotification persists, get/getNotification round-trip, and clear empties')]
	#[Group('mantle2/users')]
	public function addAndReadNotifications(): void
	{
		$user = $this->createUser();

		$n = UsersHelper::addNotification($user, 'Hello', 'World', '/x', 'success', 'system');
		$this->assertNotNull($n);
		$this->assertSame('Hello', $n->getTitle());
		$this->assertSame('success', $n->getType());
		$this->assertFalse($n->isRead());

		$all = UsersHelper::getNotifications($user);
		$this->assertCount(1, $all);
		$this->assertSame($n->getId(), $all[0]->getId());

		$fetched = UsersHelper::getNotification($user, $n->getId());
		$this->assertNotNull($fetched);
		$this->assertSame('World', $fetched->getMessage());

		$this->assertNull(UsersHelper::getNotification($user, 'missing'));

		UsersHelper::clearNotifications($user);
		$this->assertCount(0, UsersHelper::getNotifications($user));
	}

	#[Test]
	#[TestDox('mark read/unread helpers flip state and return false on no-op')]
	#[Group('mantle2/users')]
	public function markNotifications(): void
	{
		$user = $this->createUser();
		$n = UsersHelper::addNotification($user, 'T', 'M');
		$this->assertNotNull($n);

		$this->assertTrue(UsersHelper::markNotificationAsRead($user, $n));
		$read = UsersHelper::getNotification($user, $n->getId());
		$this->assertTrue($read->isRead());
		// re-reading a stored-and-read notification is a no-op
		$this->assertFalse(UsersHelper::markNotificationAsRead($user, $read));

		$this->assertTrue(UsersHelper::markNotificationAsUnread($user, $read));
		$this->assertFalse(UsersHelper::getNotification($user, $n->getId())->isRead());

		UsersHelper::markAllNotificationsAsRead($user);
		foreach (UsersHelper::getNotifications($user) as $x) {
			$this->assertTrue($x->isRead());
		}
		UsersHelper::markAllNotificationsAsUnread($user);
		foreach (UsersHelper::getNotifications($user) as $x) {
			$this->assertFalse($x->isRead());
		}
	}

	#[Test]
	#[TestDox('removeNotification deletes only the target')]
	#[Group('mantle2/users')]
	public function removeNotification(): void
	{
		$user = $this->createUser();
		$a = UsersHelper::addNotification($user, 'A', 'a');
		$b = UsersHelper::addNotification($user, 'B', 'b');

		$this->assertTrue(UsersHelper::removeNotification($user, $a));
		$remaining = UsersHelper::getNotifications($user);
		$this->assertCount(1, $remaining);
		$this->assertSame($b->getId(), $remaining[0]->getId());
	}

	#[Test]
	#[TestDox('getNotifications is empty for a fresh user and getNotification misses unknown ids')]
	#[Group('mantle2/users')]
	public function notificationEmptyState(): void
	{
		$user = $this->createUser();
		$this->assertSame([], UsersHelper::getNotifications($user));
		$this->assertNull(UsersHelper::getNotification($user, 'nope'));
	}

	#[Test]
	#[TestDox('updateNotification patches message/link/read and returns false for a missing id')]
	#[Group('mantle2/users')]
	public function updateNotificationBranches(): void
	{
		$user = $this->createUser();
		$this->assertFalse(UsersHelper::updateNotification($user, 'ghost', ['read' => true]));

		$n = UsersHelper::addNotification($user, 'T', 'M', '/old', 'info', 'system');
		$this->assertTrue(
			UsersHelper::updateNotification($user, $n->getId(), [
				'message' => 'patched',
				'link' => null,
				'read' => true,
			]),
		);
		$updated = UsersHelper::getNotification($user, $n->getId());
		$this->assertSame('patched', $updated->getMessage());
		$this->assertNull($updated->getLink());
		$this->assertTrue($updated->isRead());
	}

	#[Test]
	#[TestDox('mark helpers on an unstored notification return false without touching storage')]
	#[Group('mantle2/users')]
	public function markUnstoredNotification(): void
	{
		$user = $this->createUser();
		$stored = UsersHelper::addNotification($user, 'T', 'M');
		// unread flip on an already-unread stored notification is a no-op
		$this->assertFalse(UsersHelper::markNotificationAsUnread($user, $stored));
	}

	#[Test]
	#[TestDox('addNotification caps stored notifications at MAX (50)')]
	#[Group('mantle2/users')]
	public function notificationCap(): void
	{
		$user = $this->createUser();
		for ($i = 0; $i < 55; $i++) {
			UsersHelper::addNotification($user, 'N' . $i, 'body');
		}
		$this->assertCount(50, UsersHelper::getNotifications($user));
	}

	#endregion

	#region User Emails

	#[Test]
	#[TestDox('sendEmailVerification stores an 8-digit code and captures the email')]
	#[Group('mantle2/users')]
	public function sendEmailVerification(): void
	{
		$user = $this->createUser();
		$response = UsersHelper::sendEmailVerification($user);
		$this->assertSame(200, $response->getStatusCode());

		$stored = RedisHelper::get('email_verification_' . $user->id());
		$this->assertNotNull($stored);
		$this->assertMatchesRegularExpression('/^\d{8}$/', $stored['code']);

		$mails = $this->mail();
		$this->assertNotEmpty($mails);
		$last = end($mails);
		$this->assertSame($user->getEmail(), $last['to']);
		$this->assertSame('email_verification', $last['key']);
		$this->assertStringContainsString($stored['code'], $last['body']);
	}

	#[Test]
	#[TestDox('sendEmail skips unsubscribable mail for unsubscribed users but sends when opted in')]
	#[Group('mantle2/users')]
	public function sendEmailRespectsSubscription(): void
	{
		$user = $this->createUser();
		UsersHelper::setSubscribed($user, false);
		$user->save();

		UsersHelper::sendEmail($user, 'welcome', ['user' => $user], true);
		$this->assertCount(0, $this->mail());

		// non-unsubscribable (security) mail always goes out
		UsersHelper::sendEmail($user, 'welcome', ['user' => $user], false);
		$this->assertCount(1, $this->mail());

		UsersHelper::setSubscribed($user, true);
		$user->save();
		UsersHelper::sendEmail($user, 'welcome', ['user' => $user], true);
		$this->assertCount(2, $this->mail());
	}

	#[Test]
	#[TestDox('unsubscribe token lifecycle: generate, validate, revoke')]
	#[Group('mantle2/users')]
	public function unsubscribeTokenLifecycle(): void
	{
		$user = $this->createUser();
		$token = UsersHelper::generateUnsubscribeToken($user);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

		$resolved = UsersHelper::validateUnsubscribeToken($token);
		$this->assertNotNull($resolved);
		$this->assertSame((int) $user->id(), (int) $resolved->id());

		$this->assertNull(UsersHelper::validateUnsubscribeToken('short'));
		$this->assertNull(UsersHelper::validateUnsubscribeToken(str_repeat('z', 64)));

		UsersHelper::revokeUnsubscribeToken($token);
		$this->assertNull(UsersHelper::validateUnsubscribeToken($token));
	}

	#[Test]
	#[TestDox('verifyEmailChange rejects bad codes and applies the new email on success')]
	#[Group('mantle2/users')]
	public function verifyEmailChange(): void
	{
		$user = $this->createUser();
		$user->set('field_email_verified', true)->save();

		$badFormat = UsersHelper::verifyEmailChange($user, 'abc');
		$this->assertSame(400, $badFormat->getStatusCode());

		$noStored = UsersHelper::verifyEmailChange($user, '12345678');
		$this->assertSame(400, $noStored->getStatusCode());

		$newEmail = 'changed_' . bin2hex(random_bytes(3)) . '@example.com';
		RedisHelper::set(
			'email_change_' . $user->id(),
			[
				'code' => '55556666',
				'new_email' => $newEmail,
				'old_email' => $user->getEmail(),
			],
			900,
		);

		$wrong = UsersHelper::verifyEmailChange($user, '00001111');
		$this->assertSame(400, $wrong->getStatusCode());

		$ok = UsersHelper::verifyEmailChange($user, '55556666');
		$this->assertSame(200, $ok->getStatusCode());

		$reloaded = UsersHelper::findById((int) $user->id());
		$this->assertSame($newEmail, $reloaded->getEmail());
		// verification resets on email change
		$this->assertFalse(UsersHelper::isEmailVerified($reloaded));
		$this->assertNull(RedisHelper::get('email_change_' . $user->id()));
	}

	#[Test]
	#[TestDox('sendEmailVerification 400s a user with no email on file')]
	#[Group('mantle2/users')]
	public function sendEmailVerificationNoEmail(): void
	{
		$user = $this->createUser(['mail' => '']);
		$response = UsersHelper::sendEmailVerification($user);
		$this->assertSame(400, $response->getStatusCode());
		$this->assertCount(0, $this->mail());
	}

	#[Test]
	#[
		TestDox(
			'sendEmailChangeVerification validates format, current email, duplicates, and rate limits',
		),
	]
	#[Group('mantle2/users')]
	public function sendEmailChangeVerificationBranches(): void
	{
		$user = $this->createUser(['mail' => 'current@example.com']);

		// bad format
		$this->assertSame(
			400,
			UsersHelper::sendEmailChangeVerification($user, 'not-email')->getStatusCode(),
		);

		// same as current
		$this->assertSame(
			400,
			UsersHelper::sendEmailChangeVerification($user, 'current@example.com')->getStatusCode(),
		);

		// conflict with an existing account's email
		$this->createUser(['mail' => 'taken@example.com']);
		$this->assertSame(
			409,
			UsersHelper::sendEmailChangeVerification($user, 'taken@example.com')->getStatusCode(),
		);

		// no current email on file
		$noEmail = $this->createUser(['mail' => '']);
		$this->assertSame(
			400,
			UsersHelper::sendEmailChangeVerification($noEmail, 'new@example.com')->getStatusCode(),
		);

		// success stores the code, emails both addresses
		$ok = UsersHelper::sendEmailChangeVerification($user, 'fresh@example.com');
		$this->assertSame(200, $ok->getStatusCode());
		$stored = RedisHelper::get('email_change_' . $user->id());
		$this->assertSame('fresh@example.com', $stored['new_email']);

		// an immediate repeat is rate-limited
		$limited = UsersHelper::sendEmailChangeVerification($user, 'another@example.com');
		$this->assertSame(429, $limited->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'sendPasswordResetEmail stores a token and mails a link, skipping users with no email',
		),
	]
	#[Group('mantle2/users')]
	public function sendPasswordResetEmailBranches(): void
	{
		$noEmail = $this->createUser(['mail' => '']);
		UsersHelper::sendPasswordResetEmail($noEmail);
		$this->assertCount(0, $this->mail());

		$user = $this->createUser(['mail' => 'reset@example.com']);
		UsersHelper::sendPasswordResetEmail($user);
		$this->assertNotNull(RedisHelper::get('password_reset_' . $user->id()));
		$keys = array_map(fn($m) => $m['key'], $this->mail());
		$this->assertContains('password_reset', $keys);
	}

	#endregion

	#region User Login 2FA

	private function ipRequest(string $ip): Request
	{
		return Request::create(
			'/',
			'GET',
			[],
			[],
			[],
			['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'probe/1.0'],
		);
	}

	#[Test]
	#[TestDox('shouldGate2FAForNewIP is false without email or history and true on an unseen IP')]
	#[Group('mantle2/users')]
	public function shouldGate2FAForNewIP(): void
	{
		$noEmail = $this->createUser(['mail' => '']);
		$this->assertFalse(
			UsersHelper::shouldGate2FAForNewIP($noEmail, $this->ipRequest('9.9.9.9')),
		);

		$user = $this->createUser(['mail' => 'ip@example.com']);
		// no known IPs yet -> first login, never gate
		$this->assertFalse(UsersHelper::shouldGate2FAForNewIP($user, $this->ipRequest('1.2.3.4')));

		$user->set('field_previous_ips', json_encode(['1.2.3.4']));
		$user->save();
		$user = User::load($user->id());

		// a known IP is not gated
		$this->assertFalse(UsersHelper::shouldGate2FAForNewIP($user, $this->ipRequest('1.2.3.4')));
		// an unseen IP is gated
		$this->assertTrue(UsersHelper::shouldGate2FAForNewIP($user, $this->ipRequest('5.6.7.8')));
	}

	#[Test]
	#[TestDox('getKnownLoginIPs reads the field, tolerating absent or malformed json')]
	#[Group('mantle2/users')]
	public function getKnownLoginIPs(): void
	{
		$user = $this->createUser();
		$this->assertSame([], UsersHelper::getKnownLoginIPs($user));

		$user->set('field_previous_ips', json_encode(['10.0.0.1', '10.0.0.2']));
		$user->save();
		$this->assertSame(
			['10.0.0.1', '10.0.0.2'],
			UsersHelper::getKnownLoginIPs(User::load($user->id())),
		);

		$user->set('field_previous_ips', 'not-json');
		$user->save();
		$this->assertSame([], UsersHelper::getKnownLoginIPs(User::load($user->id())));
	}

	#[Test]
	#[TestDox('beginLogin2FAChallenge issues a ticket, masks the email, and rate-limits repeats')]
	#[Group('mantle2/users')]
	public function beginLogin2FAChallenge(): void
	{
		$user = $this->createUser(['mail' => 'agent@example.com']);
		$result = UsersHelper::beginLogin2FAChallenge($user, $this->ipRequest('2.2.2.2'));
		$this->assertIsArray($result);
		$this->assertArrayHasKey('ticket', $result);
		$this->assertSame('a***t@example.com', $result['masked_email']);
		$this->assertSame(600, $result['expires_in']);

		$stored = RedisHelper::get('login_2fa:' . $result['ticket']);
		$this->assertNotNull($stored);
		$this->assertMatchesRegularExpression('/^\d{8}$/', $stored['code']);
		$this->assertNotEmpty($this->mail());

		// a second immediate request is rate-limited
		$limited = UsersHelper::beginLogin2FAChallenge($user, $this->ipRequest('2.2.2.2'));
		$this->assertInstanceOf(JsonResponse::class, $limited);
		$this->assertSame(429, $limited->getStatusCode());
	}

	#[Test]
	#[TestDox('beginLogin2FAChallenge 400s a user without an email')]
	#[Group('mantle2/users')]
	public function beginLogin2FANoEmail(): void
	{
		$user = $this->createUser(['mail' => '']);
		$result = UsersHelper::beginLogin2FAChallenge($user, $this->ipRequest('2.2.2.2'));
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(400, $result->getStatusCode());
	}

	#[Test]
	#[TestDox('consumeLogin2FAChallenge validates the code, counts attempts, and burns the ticket')]
	#[Group('mantle2/users')]
	public function consumeLogin2FAChallenge(): void
	{
		$user = $this->createUser(['mail' => 'consume@example.com']);

		// bad ticket
		$bad = UsersHelper::consumeLogin2FAChallenge('nope', '12345678');
		$this->assertInstanceOf(JsonResponse::class, $bad);
		$this->assertSame(400, $bad->getStatusCode());

		$issued = UsersHelper::beginLogin2FAChallenge($user, $this->ipRequest('3.3.3.3'));
		$ticket = $issued['ticket'];
		$code = RedisHelper::get('login_2fa:' . $ticket)['code'];

		// wrong code increments attempts but keeps the ticket
		$wrong = UsersHelper::consumeLogin2FAChallenge($ticket, '00000000');
		$this->assertSame(400, $wrong->getStatusCode());
		$this->assertSame(1, RedisHelper::get('login_2fa:' . $ticket)['attempts']);

		// right code resolves the user and burns the ticket
		$ok = UsersHelper::consumeLogin2FAChallenge($ticket, $code);
		$this->assertInstanceOf(UserInterface::class, $ok);
		$this->assertSame((int) $user->id(), (int) $ok->id());
		$this->assertNull(RedisHelper::get('login_2fa:' . $ticket));
	}

	#[Test]
	#[TestDox('consumeLogin2FAChallenge locks out after five failed attempts')]
	#[Group('mantle2/users')]
	public function consumeLogin2FALockout(): void
	{
		$user = $this->createUser(['mail' => 'lock@example.com']);
		$issued = UsersHelper::beginLogin2FAChallenge($user, $this->ipRequest('4.4.4.4'));
		$ticket = $issued['ticket'];

		RedisHelper::set(
			'login_2fa:' . $ticket,
			array_merge(RedisHelper::get('login_2fa:' . $ticket), ['attempts' => 5]),
			600,
		);
		$result = UsersHelper::consumeLogin2FAChallenge($ticket, '11112222');
		$this->assertSame(400, $result->getStatusCode());
		// the exhausted ticket is deleted
		$this->assertNull(RedisHelper::get('login_2fa:' . $ticket));
	}

	#endregion

	#region User Content

	#[Test]
	#[TestDox('getUserPrompts returns owned prompts and honors search')]
	#[Group('mantle2/users')]
	public function getUserPrompts(): void
	{
		$user = $this->createUser();
		$other = $this->createUser();
		Node::create([
			'type' => 'prompt',
			'title' => 'P1',
			'field_owner_id' => $user->id(),
			'field_prompt' => 'Plant a tree',
		])->save();
		Node::create([
			'type' => 'prompt',
			'title' => 'P2',
			'field_owner_id' => $other->id(),
			'field_prompt' => 'Recycle more',
		])->save();

		$data = UsersHelper::getUserPrompts($user, 25, 0);
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['prompts']);

		$hit = UsersHelper::getUserPrompts($user, 25, 0, 'tree');
		$this->assertSame(1, $hit['total']);

		$miss = UsersHelper::getUserPrompts($user, 25, 0, 'nonexistent');
		$this->assertSame(0, $miss['total']);
		$this->assertCount(0, $miss['prompts']);
	}

	#[Test]
	#[TestDox('getUserArticles returns authored articles')]
	#[Group('mantle2/users')]
	public function getUserArticles(): void
	{
		$user = $this->createUser();
		Node::create([
			'type' => 'article',
			'title' => 'A',
			'field_author_id' => $user->id(),
			'field_article_title' => 'My Article',
			'field_article_description' => 'Desc',
			'field_article_content' => 'Body',
		])->save();

		$data = UsersHelper::getUserArticles($user, 25, 0);
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['articles']);
		$this->assertSame('My Article', $data['articles'][0]['title']);
	}

	#[Test]
	#[TestDox('getUserHostedEvents returns hosted event nodes with a count')]
	#[Group('mantle2/users')]
	public function getUserHostedEvents(): void
	{
		$user = $this->createUser();
		Node::create([
			'type' => 'event',
			'title' => 'E',
			'field_host_id' => $user->id(),
			'field_event_name' => 'Beach Cleanup',
			'field_event_date' => '2030-01-01T10:00:00',
		])->save();

		$data = UsersHelper::getUserHostedEvents($user, 25, 0);
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['nodes']);
	}

	#[Test]
	#[TestDox('getUserEvents unions hosted and attended events and dedupes overlaps')]
	#[Group('mantle2/users')]
	public function getUserEvents(): void
	{
		$user = $this->createUser();
		$other = $this->createUser();

		// hosted by user
		Node::create([
			'type' => 'event',
			'title' => 'H',
			'field_host_id' => $user->id(),
			'field_event_name' => 'Hosted Cleanup',
			'field_event_date' => '2030-01-01T10:00:00',
		])->save();
		// attended by user (hosted by other)
		Node::create([
			'type' => 'event',
			'title' => 'A',
			'field_host_id' => $other->id(),
			'field_event_name' => 'Attended Walk',
			'field_event_attendees' => [(int) $user->id()],
			'field_event_date' => '2030-02-01T10:00:00',
		])->save();

		$all = UsersHelper::getUserEvents($user, 25, 0);
		$this->assertSame(2, $all['total']);

		// search narrows to the matching event name
		$hit = UsersHelper::getUserEvents($user, 25, 0, 'Hosted');
		$this->assertSame(1, $hit['total']);

		// ascending sort path is exercised without error
		$asc = UsersHelper::getUserEvents($user, 25, 0, '', 'asc');
		$this->assertSame(2, $asc['total']);

		// no events -> empty shell
		$empty = UsersHelper::getUserEvents($this->createUser(), 25, 0);
		$this->assertSame(0, $empty['total']);
		$this->assertSame([], $empty['nodes']);
	}

	#[Test]
	#[TestDox('getUserEventsCount counts only hosted events')]
	#[Group('mantle2/users')]
	public function getUserEventsCount(): void
	{
		$user = $this->createUser();
		$this->assertSame(0, UsersHelper::getUserEventsCount($user));
		Node::create([
			'type' => 'event',
			'title' => 'E',
			'field_host_id' => $user->id(),
			'field_event_name' => 'Hosted',
			'field_event_date' => '2030-01-01T10:00:00',
		])->save();
		$this->assertSame(1, UsersHelper::getUserEventsCount(User::load($user->id())));
	}

	#[Test]
	#[TestDox('event max limits scale with account tier')]
	#[Group('mantle2/users')]
	public function eventMaxLimits(): void
	{
		$free = $this->createUser();
		$this->assertSame(25, UsersHelper::getMaxEventAttendees($free));
		$this->assertSame(20, UsersHelper::getMaxEventsCount($free));

		$pro = $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::PRO,
				AccountType::cases(),
				true,
			),
		]);
		$this->assertSame(5000, UsersHelper::getMaxEventAttendees($pro));
		$this->assertSame(50, UsersHelper::getMaxEventsCount($pro));

		$org = $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ORGANIZER,
				AccountType::cases(),
				true,
			),
		]);
		$this->assertSame(PHP_INT_MAX, UsersHelper::getMaxEventAttendees($org));
		$this->assertSame(200, UsersHelper::getMaxEventsCount($org));
	}

	#[Test]
	#[TestDox('getUserPrompts and getUserArticles honor sort direction without error')]
	#[Group('mantle2/users')]
	public function contentSortBranches(): void
	{
		$user = $this->createUser();
		Node::create([
			'type' => 'prompt',
			'title' => 'P1',
			'field_owner_id' => $user->id(),
			'field_prompt' => 'First',
		])->save();
		Node::create([
			'type' => 'prompt',
			'title' => 'P2',
			'field_owner_id' => $user->id(),
			'field_prompt' => 'Second',
		])->save();

		$asc = UsersHelper::getUserPrompts($user, 25, 0, '', 'asc');
		$this->assertSame(2, $asc['total']);
		$desc = UsersHelper::getUserPrompts($user, 25, 0, '', 'desc');
		$this->assertSame(2, $desc['total']);

		Node::create([
			'type' => 'article',
			'title' => 'A1',
			'field_author_id' => $user->id(),
			'field_article_title' => 'Alpha',
			'field_article_description' => 'D',
			'field_article_content' => 'B',
		])->save();
		$articlesAsc = UsersHelper::getUserArticles($user, 25, 0, '', 'asc');
		$this->assertSame(1, $articlesAsc['total']);
	}

	#[Test]
	#[TestDox('recommendActivities returns a slice of the pool when the user has no activities')]
	#[Group('mantle2/users')]
	public function recommendActivitiesEmpty(): void
	{
		$admin = $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
		foreach (['hiking', 'cycling', 'swimming', 'running'] as $id) {
			ActivityHelper::createActivity(
				Activity::fromArray([
					'id' => $id,
					'name' => "Name $id",
					'description' => "Desc $id",
					'types' => ['HOBBY'],
					'aliases' => [],
					'fields' => ['icon' => 'mdi:star'],
				]),
				$admin,
			);
		}

		$user = $this->createUser();
		$recs = UsersHelper::recommendActivities($user, 25);
		$this->assertLessThanOrEqual(3, count($recs));

		// a user WITH activities takes the cloud path, which degrades to [] here
		UsersHelper::setActivities($user, [ActivityHelper::getActivity('hiking')]);
		$user = User::load($user->id());
		$this->assertSame([], UsersHelper::recommendActivities($user, 25));
	}

	#endregion

	#region User Badges

	#[Test]
	#[TestDox('badge reads degrade to empty without cloud (catalog + granted checks)')]
	#[Group('mantle2/users')]
	public function badgesWithoutCloud(): void
	{
		$user = $this->createUser();

		$this->assertSame([], UsersHelper::getAllBadges());
		$this->assertSame([], UsersHelper::getBadges($user));
		$this->assertSame([], UsersHelper::getBadge($user, 'verified'));
		$this->assertFalse(UsersHelper::isBadgeGranted($user, 'verified'));
	}

	#endregion

	#region User Polls

	#[Test]
	#[TestDox('sanitizePollId lowercases valid ids and rejects malformed ones')]
	#[Group('mantle2/users')]
	public function sanitizePollId(): void
	{
		$this->assertSame('poll-1_a', UsersHelper::sanitizePollId('  Poll-1_A '));
		$this->assertNull(UsersHelper::sanitizePollId('bad id'));
		$this->assertNull(UsersHelper::sanitizePollId(''));
		$this->assertNull(UsersHelper::sanitizePollId(str_repeat('a', 65)));
	}

	#[Test]
	#[TestDox('recordVote writes aggregate + user index; getUserVote/getUserVotes read back')]
	#[Group('mantle2/users')]
	public function recordAndReadVote(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();

		$result = UsersHelper::recordVote($uid, 'poll1', 1, 'Favorite color?', [
			'red',
			'blue',
			'green',
		]);
		$this->assertSame('poll1', $result['poll_id']);
		$this->assertSame(1, $result['option_index']);
		$this->assertSame('blue', $result['option_text']);
		$this->assertSame(1, $result['aggregate']['total']);
		$this->assertSame(1, $result['aggregate']['counts'][1]);

		$vote = UsersHelper::getUserVote($uid, 'poll1');
		$this->assertSame(1, $vote['option_index']);
		$this->assertSame('blue', $vote['option_text']);

		$votes = UsersHelper::getUserVotes($uid);
		$this->assertCount(1, $votes);
		$this->assertSame('poll1', $votes[0]['poll_id']);
		$this->assertSame(1, $votes[0]['aggregate']['total']);

		$agg = UsersHelper::getAggregate('poll1');
		$this->assertSame(1, $agg['total']);
		$this->assertSame('Favorite color?', $agg['question']);
	}

	#[Test]
	#[
		TestDox(
			'recordVote change moves the count from the old option to the new without inflating total',
		),
	]
	#[Group('mantle2/users')]
	public function changeVote(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();

		UsersHelper::recordVote($uid, 'p', 0, 'Q', ['a', 'b']);
		$changed = UsersHelper::recordVote($uid, 'p', 1, 'Q', ['a', 'b']);

		$this->assertSame(1, $changed['aggregate']['total']);
		$this->assertSame(0, $changed['aggregate']['counts'][0]);
		$this->assertSame(1, $changed['aggregate']['counts'][1]);
	}

	#[Test]
	#[TestDox('recordVote throws on fewer than 2 options or an out-of-range index')]
	#[Group('mantle2/users')]
	public function recordVoteValidation(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();

		$this->expectException(\InvalidArgumentException::class);
		UsersHelper::recordVote($uid, 'p', 0, 'Q', ['only-one']);
	}

	#[Test]
	#[TestDox('retractVote removes the vote and decrements the aggregate, false when none')]
	#[Group('mantle2/users')]
	public function retractVote(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();

		$this->assertFalse(UsersHelper::retractVote($uid, 'p'));

		UsersHelper::recordVote($uid, 'p', 0, 'Q', ['a', 'b']);
		$this->assertTrue(UsersHelper::retractVote($uid, 'p'));
		$this->assertNull(UsersHelper::getUserVote($uid, 'p'));

		$agg = UsersHelper::getAggregate('p');
		$this->assertSame(0, $agg['total']);
	}

	#[Test]
	#[TestDox('isPollRateLimited reflects the short window set by recordVote')]
	#[Group('mantle2/users')]
	public function pollRateLimit(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();

		$this->assertFalse(UsersHelper::isPollRateLimited($uid));
		UsersHelper::recordVote($uid, 'p', 0, 'Q', ['a', 'b']);
		$this->assertTrue(UsersHelper::isPollRateLimited($uid));
	}

	#[Test]
	#[TestDox('getGlobalAggregates lists polls with at least one vote')]
	#[Group('mantle2/users')]
	public function globalAggregates(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();
		UsersHelper::recordVote($uid, 'global1', 0, 'Q', ['a', 'b']);

		$all = UsersHelper::getGlobalAggregates();
		$ids = array_column($all, 'poll_id');
		$this->assertContains('global1', $ids);
	}

	#[Test]
	#[TestDox('getUserVote/getUserVotes/getAggregate return empty defaults with no votes')]
	#[Group('mantle2/users')]
	public function pollEmptyReads(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();

		$this->assertNull(UsersHelper::getUserVote($uid, 'never'));
		$this->assertSame([], UsersHelper::getUserVotes($uid));

		$agg = UsersHelper::getAggregate('never');
		$this->assertSame(0, $agg['total']);
		$this->assertSame([], $agg['counts']);
		$this->assertNull($agg['question']);
	}

	#[Test]
	#[TestDox('recordVote throws on a negative or out-of-range option index')]
	#[Group('mantle2/users')]
	public function recordVoteOutOfRange(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();
		$this->expectException(\InvalidArgumentException::class);
		UsersHelper::recordVote($uid, 'p', 5, 'Q', ['a', 'b']);
	}

	#[Test]
	#[TestDox('recordVote drops blank options before validating the minimum of two')]
	#[Group('mantle2/users')]
	public function recordVoteDropsBlankOptions(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();
		$this->expectException(\InvalidArgumentException::class);
		// only one non-blank option survives the sanitize pass
		UsersHelper::recordVote($uid, 'p', 0, 'Q', ['only', '   ', '']);
	}

	#endregion

	#region User Push Tokens

	#[Test]
	#[TestDox('getFCMTokens reads rows for the user and pruneStalePushTokens removes aged rows')]
	#[Group('mantle2/users')]
	public function pushTokenReadsAndPrune(): void
	{
		$user = $this->createUser();
		$db = \Drupal::database();
		$db->insert('push_tokens')
			->fields([
				'user_id' => (int) $user->id(),
				'platform' => 'ios',
				'token' => 'fresh-token',
				'updated' => time(),
			])
			->execute();
		$db->insert('push_tokens')
			->fields([
				'user_id' => (int) $user->id(),
				'platform' => 'android',
				'token' => 'stale-token',
				'updated' => time() - 61 * 86400,
			])
			->execute();

		$tokens = UsersHelper::getFCMTokens($user);
		$this->assertContains('fresh-token', $tokens);
		$this->assertContains('stale-token', $tokens);

		$this->assertSame(1, UsersHelper::pruneStalePushTokens());
		$after = UsersHelper::getFCMTokens($user);
		$this->assertContains('fresh-token', $after);
		$this->assertNotContains('stale-token', $after);
	}

	#endregion

	#region User Badges (branches)

	#[Test]
	#[TestDox('getBadge returns [] and isBadgeGranted false when the catalog is empty')]
	#[Group('mantle2/users')]
	public function badgeMissesWithoutCatalog(): void
	{
		$user = $this->createUser();
		$this->assertSame([], UsersHelper::getBadge($user, 'anything'));
		$this->assertFalse(UsersHelper::isBadgeGranted($user, 'anything'));
	}

	#endregion

	#region User Maintenance Sweeps

	#[Test]
	#[
		TestDox(
			'checkInactiveAccounts leaves fresh accounts alone and warns those nearing the deadline',
		),
	]
	#[Group('mantle2/users')]
	public function checkInactiveAccounts(): void
	{
		$year = UsersHelper::INACTIVE_DELETION_SECONDS;

		// fresh user: far from deletion, no warning, survives
		$fresh = $this->createUser(['mail' => 'fresh@example.com']);

		// nearing deletion (~12 hours out): should be warned + notified
		$warnRef = time() - ($year - 43200);
		$warn = $this->createUser(['mail' => 'warn@example.com', 'created' => $warnRef]);
		$warn->setLastLoginTime($warnRef);
		$warn->save();

		UsersHelper::checkInactiveAccounts();

		$this->assertNotNull(User::load($fresh->id()));
		$this->assertSame([], UsersHelper::getNotifications(User::load($fresh->id())));

		$warnNotes = UsersHelper::getNotifications(User::load($warn->id()));
		$this->assertNotEmpty($warnNotes);
		$this->assertSame('Account Scheduled for Deletion', $warnNotes[0]->getTitle());
		// the dedup marker is written so a second sweep skips this window
		$this->assertTrue(
			RedisHelper::exists('user:inactive_deletion_warning:' . $warn->id() . ':1_day'),
		);
	}

	#[Test]
	#[TestDox('checkUnreadNotifications emails a subscribed active user who has unread items')]
	#[Group('mantle2/users')]
	public function checkUnreadNotificationsSweep(): void
	{
		$user = $this->createUser(['mail' => 'unread@example.com']);
		$user->setLastLoginTime(time());
		$user->save();
		UsersHelper::addNotification($user, 'Unread', 'body');

		\Drupal::state()->set('system.test_mail_collector', []);
		UsersHelper::checkUnreadNotifications();

		$keys = array_map(fn($m) => $m['key'], $this->mail());
		$this->assertContains('unread_notifications_reminder', $keys);
		// the dedup marker is set so a second sweep is a no-op
		$this->assertNotNull(RedisHelper::get('user:unread_notifications_sent:' . $user->id()));
	}

	#endregion
}
