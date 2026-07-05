<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal;
use Drupal\comment\Entity\Comment;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\PromptsHelper;
use Drupal\mantle2\Service\ReportsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ReportsHelperTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
		Drupal::state()->set('system.test_mail_collector', []);
	}

	private function ordinal(AccountType $type): string
	{
		return (string) array_search($type, AccountType::cases(), true);
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => $this->ordinal(AccountType::ADMINISTRATOR),
		]);
	}

	private function seedPrompt(
		UserInterface $owner,
		string $text = 'A prompt body to preview',
	): Node {
		$obj = new Prompt(0, $text, (int) $owner->id(), Visibility::PUBLIC);
		return PromptsHelper::createPrompt($obj, null);
	}

	private function seedArticle(UserInterface $author, string $title = 'On Tides'): Node
	{
		return ArticlesHelper::createArticle(
			$title,
			'A short primer',
			['ocean'],
			'body content here',
			$author,
			'#3366FF',
			null,
		);
	}

	private function seedEvent(UserInterface $host, string $name = 'Beach Cleanup'): Node
	{
		$node = Node::create(['type' => 'event', 'title' => $name, 'uid' => $host->id()]);
		$node->set('field_event_name', $name);
		$node->set('field_event_description', 'desc');
		$node->set('field_event_type', 0);
		$node->set('field_visibility', 1);
		$node->set('field_host_id', $host->id());
		$node->set('field_event_date', gmdate('Y-m-d\TH:i:s', time() + 3600));
		$node->save();
		return $node;
	}

	private function seedResponse(
		UserInterface $author,
		Node $prompt,
		string $body = 'a reply',
	): Comment {
		return PromptsHelper::addComment($author, $prompt, $body);
	}

	private function mails(): array
	{
		return Drupal::state()->get('system.test_mail_collector') ?? [];
	}

	#region resolveContent

	#[Test]
	#[TestDox('resolveContent maps each content type to owner_id and preview')]
	#[Group('mantle2/reports')]
	public function resolveContentPrompt(): void
	{
		$owner = $this->createUser();
		$node = $this->seedPrompt($owner, 'Hello world prompt');
		$resolved = ReportsHelper::resolveContent('prompt', (string) $node->id());
		$this->assertSame((int) $owner->id(), $resolved['owner_id']);
		$this->assertSame('Hello world prompt', $resolved['preview']);
	}

	#[Test]
	#[TestDox('resolveContent resolves a prompt_response comment against its parent prompt')]
	#[Group('mantle2/reports')]
	public function resolveContentPromptResponse(): void
	{
		$owner = $this->createUser();
		$responder = $this->createUser();
		$prompt = $this->seedPrompt($owner);
		$comment = $this->seedResponse($responder, $prompt, 'my thoughtful reply');

		$resolved = ReportsHelper::resolveContent(
			'prompt_response',
			(string) $comment->id(),
			(string) $prompt->id(),
		);
		$this->assertSame((int) $responder->id(), $resolved['owner_id']);
		$this->assertSame('my thoughtful reply', $resolved['preview']);
	}

	#[Test]
	#[TestDox('resolveContent resolves article title and author')]
	#[Group('mantle2/reports')]
	public function resolveContentArticle(): void
	{
		$author = $this->createUser();
		$node = $this->seedArticle($author, 'Deep Ocean');
		$resolved = ReportsHelper::resolveContent('article', (string) $node->id());
		$this->assertSame((int) $author->id(), $resolved['owner_id']);
		$this->assertSame('Deep Ocean', $resolved['preview']);
	}

	#[Test]
	#[TestDox('resolveContent resolves event name and host')]
	#[Group('mantle2/reports')]
	public function resolveContentEvent(): void
	{
		$host = $this->createUser();
		$node = $this->seedEvent($host, 'Trail Run');
		$resolved = ReportsHelper::resolveContent('event', (string) $node->id());
		$this->assertSame((int) $host->id(), $resolved['owner_id']);
		$this->assertSame('Trail Run', $resolved['preview']);
	}

	#[Test]
	#[TestDox('resolveContent resolves a user to its own id and @handle preview')]
	#[Group('mantle2/reports')]
	public function resolveContentUser(): void
	{
		$user = $this->createUser(['name' => 'targetuser']);
		$resolved = ReportsHelper::resolveContent('user', (string) $user->id());
		$this->assertSame((int) $user->id(), $resolved['owner_id']);
		$this->assertSame('@targetuser', $resolved['preview']);
	}

	#[Test]
	#[TestDox('resolveContent returns null for event_image (cloud endpoint dead)')]
	#[Group('mantle2/reports')]
	public function resolveContentEventImageDegrades(): void
	{
		$host = $this->createUser();
		$event = $this->seedEvent($host);
		$resolved = ReportsHelper::resolveContent(
			'event_image',
			str_repeat('a', 32),
			(string) $event->id(),
		);
		$this->assertNull($resolved);
	}

	public static function resolveNullProvider(): array
	{
		return [
			'prompt not found' => ['prompt', '999999', null],
			'article not found' => ['article', '999999', null],
			'event not found' => ['event', '999999', null],
			'user not found' => ['user', '999999', null],
			'prompt_response missing parent' => ['prompt_response', '1', null],
			'event_image missing parent' => ['event_image', 'abc', null],
			'unknown type' => ['nonsense', '1', null],
		];
	}

	#[Test]
	#[
		TestDox(
			'resolveContent returns null for missing content, missing parents, and unknown types',
		),
	]
	#[Group('mantle2/reports')]
	#[DataProvider('resolveNullProvider')]
	public function resolveContentNull(string $type, string $id, ?string $parentId): void
	{
		$this->assertNull(ReportsHelper::resolveContent($type, $id, $parentId));
	}

	#[Test]
	#[TestDox('resolveContent returns null when the id is the wrong bundle')]
	#[Group('mantle2/reports')]
	public function resolveContentWrongBundle(): void
	{
		$author = $this->createUser();
		$article = $this->seedArticle($author);
		$this->assertNull(ReportsHelper::resolveContent('prompt', (string) $article->id()));
		$this->assertNull(ReportsHelper::resolveContent('event', (string) $article->id()));

		$owner = $this->createUser();
		$prompt = $this->seedPrompt($owner);
		$this->assertNull(ReportsHelper::resolveContent('article', (string) $prompt->id()));
	}

	#[Test]
	#[TestDox('resolveContent returns null when the comment does not belong to the given prompt')]
	#[Group('mantle2/reports')]
	public function resolveContentResponseWrongParent(): void
	{
		$owner = $this->createUser();
		$promptA = $this->seedPrompt($owner);
		$promptB = $this->seedPrompt($owner);
		$comment = $this->seedResponse($this->createUser(), $promptA);

		$this->assertNull(
			ReportsHelper::resolveContent(
				'prompt_response',
				(string) $comment->id(),
				(string) $promptB->id(),
			),
		);
	}

	#[Test]
	#[TestDox('resolveContent returns null when the parent prompt is not a prompt bundle')]
	#[Group('mantle2/reports')]
	public function resolveContentResponseParentNotPrompt(): void
	{
		$author = $this->createUser();
		$article = $this->seedArticle($author);
		$this->assertNull(
			ReportsHelper::resolveContent('prompt_response', '1', (string) $article->id()),
		);
	}

	#endregion

	#region deleteContent

	#[Test]
	#[TestDox('deleteContent removes a prompt node')]
	#[Group('mantle2/reports')]
	public function deleteContentPrompt(): void
	{
		$node = $this->seedPrompt($this->createUser());
		$id = (int) $node->id();
		$this->assertTrue(ReportsHelper::deleteContent('prompt', (string) $id));
		$this->assertNull(Node::load($id));
	}

	#[Test]
	#[TestDox('deleteContent removes an article node')]
	#[Group('mantle2/reports')]
	public function deleteContentArticle(): void
	{
		$node = $this->seedArticle($this->createUser());
		$id = (int) $node->id();
		$this->assertTrue(ReportsHelper::deleteContent('article', (string) $id));
		$this->assertNull(Node::load($id));
	}

	#[Test]
	#[TestDox('deleteContent removes an event node')]
	#[Group('mantle2/reports')]
	public function deleteContentEvent(): void
	{
		$node = $this->seedEvent($this->createUser());
		$id = (int) $node->id();
		$this->assertTrue(ReportsHelper::deleteContent('event', (string) $id));
		$this->assertNull(Node::load($id));
	}

	#[Test]
	#[TestDox('deleteContent removes a prompt_response comment')]
	#[Group('mantle2/reports')]
	public function deleteContentResponse(): void
	{
		$owner = $this->createUser();
		$prompt = $this->seedPrompt($owner);
		$comment = $this->seedResponse($this->createUser(), $prompt);
		$id = (int) $comment->id();
		$this->assertTrue(ReportsHelper::deleteContent('prompt_response', (string) $id));
		$this->assertNull(Comment::load($id));
	}

	#[Test]
	#[TestDox('deleteContent bans the user when type is user')]
	#[Group('mantle2/reports')]
	public function deleteContentUserBans(): void
	{
		$user = $this->createUser();
		$this->assertTrue($user->isActive());
		$this->assertTrue(ReportsHelper::deleteContent('user', (string) $user->id()));
		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isBlocked());
	}

	public static function deleteNotFoundProvider(): array
	{
		return [
			'prompt missing' => ['prompt', '999999', null],
			'article missing' => ['article', '999999', null],
			'event missing' => ['event', '999999', null],
			'response missing' => ['prompt_response', '999999', null],
			'user missing' => ['user', '999999', null],
			'event_image missing parent' => ['event_image', 'abc', null],
			'unknown type' => ['nonsense', '1', null],
		];
	}

	#[Test]
	#[TestDox('deleteContent returns false when the target does not exist')]
	#[Group('mantle2/reports')]
	#[DataProvider('deleteNotFoundProvider')]
	public function deleteContentNotFound(string $type, string $id, ?string $parentId): void
	{
		$this->assertFalse(ReportsHelper::deleteContent($type, $id, $parentId));
	}

	#endregion

	#region recordStrikeAndEnforce

	#[Test]
	#[TestDox('recordStrikeAndEnforce skips uid <= 1 without touching cloud')]
	#[Group('mantle2/reports')]
	public function recordStrikeSkipsSystem(): void
	{
		$this->assertSame('none', ReportsHelper::recordStrikeAndEnforce(1, 'prompt', '5', 'spam'));
		$this->assertSame('none', ReportsHelper::recordStrikeAndEnforce(0, 'prompt', '5', 'spam'));
	}

	#[Test]
	#[TestDox('recordStrikeAndEnforce skips administrator accounts')]
	#[Group('mantle2/reports')]
	public function recordStrikeSkipsAdmin(): void
	{
		$admin = $this->admin();
		$this->assertSame(
			'none',
			ReportsHelper::recordStrikeAndEnforce((int) $admin->id(), 'prompt', '5', 'spam'),
		);
		$this->assertTrue($admin->isActive());
	}

	#[Test]
	#[TestDox('recordStrikeAndEnforce degrades to none when cloud is unreachable')]
	#[Group('mantle2/reports')]
	public function recordStrikeDegrades(): void
	{
		$user = $this->createUser();
		$action = ReportsHelper::recordStrikeAndEnforce(
			(int) $user->id(),
			'prompt',
			'5',
			'spam',
			'a note',
			'a preview',
			'report-123',
		);
		$this->assertSame('none', $action);
		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isActive());
	}

	#endregion

	#region enforceAction

	#[Test]
	#[TestDox('enforceAction dispatches disable_1_month to a 30-day disable')]
	#[Group('mantle2/reports')]
	public function enforceActionDisable(): void
	{
		$user = $this->createUser();
		ReportsHelper::enforceAction($user, 'disable_1_month');
		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isBlocked());
		$at = Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id());
		$this->assertGreaterThan(time() * 1000, (int) $at);
	}

	#[Test]
	#[TestDox('enforceAction dispatches permanent_ban to a ban')]
	#[Group('mantle2/reports')]
	public function enforceActionBan(): void
	{
		$user = $this->createUser();
		ReportsHelper::enforceAction($user, 'permanent_ban');
		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isBlocked());
		$this->assertNull(
			Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id()),
		);
	}

	#[Test]
	#[TestDox('enforceAction none leaves the account untouched')]
	#[Group('mantle2/reports')]
	public function enforceActionNone(): void
	{
		$user = $this->createUser();
		ReportsHelper::enforceAction($user, 'none');
		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isActive());
	}

	#endregion

	#region disableForOneMonth

	#[Test]
	#[TestDox('disableForOneMonth blocks the user, sets reenable_at, and indexes the uid')]
	#[Group('mantle2/reports')]
	public function disableForOneMonth(): void
	{
		$user = $this->createUser();
		ReportsHelper::disableForOneMonth($user);

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isBlocked());

		$at = (int) Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id());
		$expected = (time() + 30 * 24 * 60 * 60) * 1000;
		$this->assertEqualsWithDelta($expected, $at, 5000);

		$index = Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.index', []);
		$this->assertContains((int) $user->id(), $index);
	}

	#endregion

	#region banUser

	#[Test]
	#[TestDox('banUser blocks the user, clears reenable, and degrades on blacklist calls')]
	#[Group('mantle2/reports')]
	public function banUser(): void
	{
		$user = $this->createUser();
		Drupal::state()->set(ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id(), 123);
		ReportsHelper::banUser($user);

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isBlocked());
		$this->assertNull(
			Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id()),
		);
	}

	#endregion

	#region notifyUser

	#[Test]
	#[TestDox('notifyUser sends the reporter a thank-you and captures email')]
	#[Group('mantle2/reports')]
	public function notifyReporter(): void
	{
		$user = $this->createUser();
		ReportsHelper::notifyUser($user, 'reporter', 'prompt_response', 'delete_content');

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$notifications = UsersHelper::getNotifications($reloaded);
		$this->assertCount(1, $notifications);
		$this->assertSame('Report Reviewed', $notifications[0]->getTitle());
		$this->assertStringContainsString('Prompt Response', $notifications[0]->getMessage());

		$mails = $this->mails();
		$this->assertNotEmpty($mails);
		$last = end($mails);
		$this->assertSame('content_moderation', $last['key']);
	}

	public static function ownerMessageProvider(): array
	{
		return [
			'ban' => ['ban_user', 'account has been suspended'],
			'delete' => ['delete_content', 'was removed for violating'],
			'other' => ['other', 'A moderation decision was made'],
		];
	}

	#[Test]
	#[TestDox('notifyUser tailors the owner message to the action')]
	#[Group('mantle2/reports')]
	#[DataProvider('ownerMessageProvider')]
	public function notifyOwner(string $action, string $needle): void
	{
		$user = $this->createUser();
		ReportsHelper::notifyUser($user, 'owner', 'article', $action);

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$notifications = UsersHelper::getNotifications($reloaded);
		$this->assertCount(1, $notifications);
		$this->assertSame('Content Moderation Notice', $notifications[0]->getTitle());
		$this->assertStringContainsString($needle, $notifications[0]->getMessage());
	}

	#[Test]
	#[TestDox('notifyUser appends moderator notes to the message')]
	#[Group('mantle2/reports')]
	public function notifyAppendsNotes(): void
	{
		$user = $this->createUser();
		ReportsHelper::notifyUser($user, 'owner', 'event', 'delete_content', 'Please be kind');

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$notifications = UsersHelper::getNotifications($reloaded);
		$this->assertStringContainsString(
			'Note from moderators: Please be kind',
			$notifications[0]->getMessage(),
		);
	}

	#endregion

	#region runDailyDigest

	#[Test]
	#[TestDox('runDailyDigest throttles inside the 24h window')]
	#[Group('mantle2/reports')]
	public function digestThrottles(): void
	{
		$last = time() - 100;
		Drupal::state()->set(ReportsHelper::DIGEST_STATE_KEY, $last);
		ReportsHelper::runDailyDigest();
		$this->assertSame($last, (int) Drupal::state()->get(ReportsHelper::DIGEST_STATE_KEY));
		$this->assertEmpty($this->mails());
	}

	#[Test]
	#[
		TestDox(
			'runDailyDigest past the window updates the timestamp and skips email on degraded count',
		),
	]
	#[Group('mantle2/reports')]
	public function digestDegradedSkipsEmail(): void
	{
		$old = time() - 2 * 24 * 60 * 60;
		Drupal::state()->set(ReportsHelper::DIGEST_STATE_KEY, $old);
		$this->admin();

		ReportsHelper::runDailyDigest();

		$this->assertGreaterThan($old, (int) Drupal::state()->get(ReportsHelper::DIGEST_STATE_KEY));
		$this->assertEmpty($this->mails());
	}

	#endregion

	#region reenableExpiredDisables

	#[Test]
	#[TestDox('reenableExpiredDisables no-ops on an empty index')]
	#[Group('mantle2/reports')]
	public function reenableEmptyIndex(): void
	{
		ReportsHelper::reenableExpiredDisables();
		$this->assertSame(
			[],
			Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.index', []),
		);
	}

	#[Test]
	#[TestDox('reenableExpiredDisables re-enables a user past reenable_at and clears its state')]
	#[Group('mantle2/reports')]
	public function reenablePast(): void
	{
		$user = $this->createUser();
		ReportsHelper::disableForOneMonth($user);
		Drupal::state()->set(
			ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id(),
			(time() - 100) * 1000,
		);

		ReportsHelper::reenableExpiredDisables();

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isActive());
		$this->assertSame(
			0,
			(int) Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id(), 0),
		);
		$this->assertSame(
			[],
			Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.index', []),
		);
	}

	#[Test]
	#[TestDox('reenableExpiredDisables keeps a user whose reenable_at is still in the future')]
	#[Group('mantle2/reports')]
	public function reenableFuture(): void
	{
		$user = $this->createUser();
		ReportsHelper::disableForOneMonth($user);

		ReportsHelper::reenableExpiredDisables();

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isBlocked());
		$this->assertContains(
			(int) $user->id(),
			Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.index', []),
		);
	}

	#[Test]
	#[TestDox('reenableExpiredDisables drops a permanently-banned uid whose state was cleared')]
	#[Group('mantle2/reports')]
	public function reenableClearedPermanentBan(): void
	{
		$user = $this->createUser();
		ReportsHelper::disableForOneMonth($user);
		// simulate a permanent ban clearing the per-user state but leaving the index entry
		Drupal::state()->delete(ReportsHelper::REENABLE_STATE_KEY . '.' . $user->id());

		ReportsHelper::reenableExpiredDisables();

		$reloaded = Drupal::entityTypeManager()->getStorage('user')->load($user->id());
		$this->assertTrue($reloaded->isBlocked());
		$this->assertSame(
			[],
			Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.index', []),
		);
	}

	#endregion

	#region trackReenable

	#[Test]
	#[TestDox('trackReenable dedupes uids in the index')]
	#[Group('mantle2/reports')]
	public function trackReenableDedup(): void
	{
		ReportsHelper::trackReenable(42);
		ReportsHelper::trackReenable(42);
		ReportsHelper::trackReenable(43);

		$index = Drupal::state()->get(ReportsHelper::REENABLE_STATE_KEY . '.index', []);
		$this->assertSame([42, 43], $index);
	}

	#endregion

	#region getAdminUsers

	#[Test]
	#[TestDox('getAdminUsers returns role and account-type admins and excludes uid 1')]
	#[Group('mantle2/reports')]
	public function getAdminUsers(): void
	{
		$roleAdmin = $this->createUser();
		$roleAdmin->addRole('administrator');
		$roleAdmin->save();

		$typeAdmin = $this->admin();
		$regular = $this->createUser();

		$admins = ReportsHelper::getAdminUsers();
		$ids = array_map(fn($u) => (int) $u->id(), $admins);

		$this->assertContains((int) $roleAdmin->id(), $ids);
		$this->assertContains((int) $typeAdmin->id(), $ids);
		$this->assertNotContains((int) $regular->id(), $ids);
		$this->assertNotContains(1, $ids);
	}

	#endregion

	#region usernameFor

	#[Test]
	#[TestDox('usernameFor returns null, the name, or null for a missing uid')]
	#[Group('mantle2/reports')]
	public function usernameFor(): void
	{
		$this->assertNull(ReportsHelper::usernameFor(null));
		$this->assertNull(ReportsHelper::usernameFor(0));

		$user = $this->createUser(['name' => 'someone']);
		$this->assertSame('someone', ReportsHelper::usernameFor((int) $user->id()));

		$this->assertNull(ReportsHelper::usernameFor(999999));
	}

	#endregion

	#region snippet (via resolveContent preview)

	public static function snippetProvider(): array
	{
		$long = str_repeat('a', 200);
		return [
			'short passthrough' => ['a short prompt', 'a short prompt'],
			'whitespace collapsed' => ["multi   \n line", 'multi line'],
			'long truncated' => [$long, mb_substr($long, 0, 139) . '…'],
		];
	}

	#[Test]
	#[TestDox('snippet passes short text through and truncates long text with an ellipsis')]
	#[Group('mantle2/reports')]
	#[DataProvider('snippetProvider')]
	public function snippet(string $input, string $expected): void
	{
		$node = $this->seedPrompt($this->createUser(), $input);
		$resolved = ReportsHelper::resolveContent('prompt', (string) $node->id());
		$this->assertSame($expected, $resolved['preview']);
	}

	#endregion
}
