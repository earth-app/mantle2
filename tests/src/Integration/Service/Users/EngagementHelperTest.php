<?php

namespace Drupal\Tests\mantle2\Integration\Service\Users;

use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class EngagementHelperTest extends IntegrationTestBase
{
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

	#endregion
}
