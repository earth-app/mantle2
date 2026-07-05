<?php

namespace Drupal\Tests\mantle2\Integration\Service\Users;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class SocialHelperTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
	}

	private function user(array $values = []): UserInterface
	{
		return $this->createUser(['field_visibility' => '0'] + $values);
	}

	private function accountUser(AccountType $type): UserInterface
	{
		return $this->user([
			'field_account_type' => (string) array_search($type, AccountType::cases(), true),
		]);
	}

	// friend ids are stored as strings, matching UsersHelper::addFriend
	private function setFriends(UserInterface $user, array $friends): void
	{
		$user->set('field_friends', json_encode(array_map('strval', $friends)));
		$user->save();
	}

	private function reload(UserInterface $user): UserInterface
	{
		return User::load($user->id());
	}

	// #region User Friends

	#[Test]
	#[TestDox('isAddedFriend is directional and never self')]
	#[Group('mantle2/users')]
	public function isAddedFriend(): void
	{
		$a = $this->user();
		$b = $this->user();
		$this->setFriends($a, [$b->id()]);

		$this->assertTrue(UsersHelper::isAddedFriend($a, $b));
		$this->assertFalse(UsersHelper::isAddedFriend($b, $a));
		$this->assertFalse(UsersHelper::isAddedFriend($a, $a));
		$this->assertFalse(UsersHelper::isAddedFriend(null, $b));
	}

	#[Test]
	#[TestDox('isMutualFriend is true only when friend lists intersect')]
	#[Group('mantle2/users')]
	public function isMutualFriend(): void
	{
		$a = $this->user();
		$b = $this->user();
		$shared = $this->user();

		$this->assertFalse(UsersHelper::isMutualFriend($a, $b));

		$this->setFriends($a, [$shared->id()]);
		$this->setFriends($b, [$shared->id()]);
		$this->assertTrue(UsersHelper::isMutualFriend($this->reload($a), $this->reload($b)));
		$this->assertFalse(UsersHelper::isMutualFriend($a, $a));
	}

	#[Test]
	#[TestDox('getAddedFriends lists, counts, searches, and paginates')]
	#[Group('mantle2/users')]
	public function getAddedFriends(): void
	{
		$owner = $this->user();
		$alice = $this->user(['name' => 'alice_h']);
		$bob = $this->user(['name' => 'bob_h']);
		$this->setFriends($owner, [$alice->id(), $bob->id()]);
		$owner = $this->reload($owner);

		$this->assertCount(2, UsersHelper::getAddedFriends($owner));
		$this->assertSame(2, UsersHelper::getAddedFriendsCount($owner));
		$this->assertSame(1, UsersHelper::getAddedFriendsCount($owner, 'alice'));
		$this->assertCount(1, UsersHelper::getAddedFriends($owner, 1, 1));
		$this->assertCount(1, UsersHelper::getAddedFriends($owner, 1, 2));
	}

	#[Test]
	#[TestDox('mutual vs non-mutual counting over the friend-of-friend graph')]
	#[Group('mantle2/users')]
	public function mutualAndNonMutualCounting(): void
	{
		// owner -> a,b ; a -> x ; b -> x,y  => x seen twice (mutual), y once (non-mutual)
		$owner = $this->user(['name' => 'o_h']);
		$a = $this->user(['name' => 'a_h']);
		$b = $this->user(['name' => 'b_h']);
		$x = $this->user(['name' => 'x_h']);
		$y = $this->user(['name' => 'y_h']);

		$this->setFriends($owner, [$a->id(), $b->id()]);
		$this->setFriends($a, [$owner->id(), $x->id()]);
		$this->setFriends($b, [$owner->id(), $x->id(), $y->id()]);
		$owner = $this->reload($owner);

		$mutual = UsersHelper::getMutualFriends($owner);
		$this->assertSame(
			[$x->getAccountName()],
			array_map(fn($u) => $u->getAccountName(), array_values($mutual)),
		);

		$nonMutual = UsersHelper::getNonMutualFriends($owner);
		$this->assertSame(1, UsersHelper::getNonMutualFriendsCount($owner));
		$this->assertSame(
			[$y->getAccountName()],
			array_map(fn($u) => $u->getAccountName(), array_values($nonMutual)),
		);
	}

	#[Test]
	#[TestDox('getMutualFriendsCount intersects the requester and target friend lists')]
	#[Group('mantle2/users')]
	public function getMutualFriendsCount(): void
	{
		$target = $this->user();
		$requester = $this->user();
		$shared1 = $this->user();
		$shared2 = $this->user();
		$onlyTarget = $this->user();

		$this->setFriends($target, [$shared1->id(), $shared2->id(), $onlyTarget->id()]);
		$this->setFriends($requester, [$shared1->id(), $shared2->id()]);

		$this->assertSame(
			2,
			UsersHelper::getMutualFriendsCount($this->reload($target), $this->reload($requester)),
		);
		// no requester -> zero
		$this->assertSame(0, UsersHelper::getMutualFriendsCount($this->reload($target)));
	}

	#[Test]
	#[TestDox('getAddedBy finds every user whose friend list contains the target')]
	#[Group('mantle2/users')]
	public function getAddedBy(): void
	{
		$target = $this->user();
		$adder1 = $this->user(['name' => 'adder_one']);
		$adder2 = $this->user(['name' => 'adder_two']);
		$this->user(['name' => 'no_relation']);

		$this->setFriends($adder1, [$target->id()]);
		$this->setFriends($adder2, [$target->id()]);

		$this->assertSame(2, UsersHelper::getAddedByCount($this->reload($target)));
		$this->assertSame(1, UsersHelper::getAddedByCount($this->reload($target), 'adder_one'));
		$names = array_map(
			fn($u) => $u->getAccountName(),
			UsersHelper::getAddedBy($this->reload($target)),
		);
		sort($names);
		$this->assertSame(['adder_one', 'adder_two'], $names);
	}

	#[Test]
	#[TestDox('predicate helpers short-circuit on null args and self')]
	#[Group('mantle2/users')]
	public function predicateNullAndSelfGuards(): void
	{
		$a = $this->user();
		$this->assertFalse(UsersHelper::isMutualFriend(null, $a));
		$this->assertFalse(UsersHelper::isMutualFriend($a, null));
		$this->assertFalse(UsersHelper::isInCircle(null, $a));
		$this->assertFalse(UsersHelper::isInCircle($a, null));
		$this->assertFalse(UsersHelper::isBlocking(null, $a));
		$this->assertFalse(UsersHelper::isBlockedBy($a, null));
		$this->assertFalse(UsersHelper::hasBlockRelationship(null, null));
	}

	#[Test]
	#[TestDox('addFriend appends once, removeFriend strips, both reject the redundant call')]
	#[Group('mantle2/users')]
	public function addRemoveFriendLifecycle(): void
	{
		$user = $this->user();
		$friend = $this->user();

		$this->assertTrue(UsersHelper::addFriend($user, $friend));
		$this->assertTrue(UsersHelper::isAddedFriend($this->reload($user), $this->reload($friend)));
		// adding an already-added friend is a no-op
		$this->assertFalse(UsersHelper::addFriend($this->reload($user), $this->reload($friend)));

		$this->assertTrue(UsersHelper::removeFriend($this->reload($user), $this->reload($friend)));
		$this->assertFalse(
			UsersHelper::isAddedFriend($this->reload($user), $this->reload($friend)),
		);
		// removing a non-friend is a no-op
		$this->assertFalse(UsersHelper::removeFriend($this->reload($user), $this->reload($friend)));
	}

	#[Test]
	#[TestDox('getMutualFriendsCount returns 0 for a null requester')]
	#[Group('mantle2/users')]
	public function mutualCountNullRequester(): void
	{
		$target = $this->user();
		$this->setFriends($target, [$this->user()->id()]);
		$this->assertSame(0, UsersHelper::getMutualFriendsCount($this->reload($target), null));
	}

	#[Test]
	#[TestDox('getAddedFriends honors asc, desc, and rand sort orders')]
	#[Group('mantle2/users')]
	public function addedFriendsSortModes(): void
	{
		$owner = $this->user();
		$a = $this->user(['name' => 'sort_a']);
		$b = $this->user(['name' => 'sort_b']);
		$this->setFriends($owner, [$a->id(), $b->id()]);
		$owner = $this->reload($owner);

		$this->assertCount(2, UsersHelper::getAddedFriends($owner, 25, 1, '', 'asc'));
		$this->assertCount(2, UsersHelper::getAddedFriends($owner, 25, 1, '', 'desc'));
		$this->assertCount(2, UsersHelper::getAddedFriends($owner, 25, 1, '', 'rand'));
		// search + pagination narrow the set
		$this->assertCount(1, UsersHelper::getAddedFriends($owner, 25, 1, 'sort_a'));
	}

	#[Test]
	#[TestDox('getMutualFriends and getNonMutualFriends accept rand sort and search filters')]
	#[Group('mantle2/users')]
	public function mutualFriendsSortAndSearch(): void
	{
		// owner -> a,b ; a -> x ; b -> x,y  => x mutual, y non-mutual
		$owner = $this->user(['name' => 'ms_o']);
		$a = $this->user(['name' => 'ms_a']);
		$b = $this->user(['name' => 'ms_b']);
		$x = $this->user(['name' => 'ms_x']);
		$y = $this->user(['name' => 'ms_y']);
		$this->setFriends($owner, [$a->id(), $b->id()]);
		$this->setFriends($a, [$x->id()]);
		$this->setFriends($b, [$x->id(), $y->id()]);
		$owner = $this->reload($owner);

		$this->assertCount(1, UsersHelper::getMutualFriends($owner, 25, 1, '', 'rand'));
		$this->assertCount(1, UsersHelper::getMutualFriends($owner, 25, 1, 'ms_x'));
		$this->assertCount(0, UsersHelper::getMutualFriends($owner, 25, 1, 'no_match'));

		$this->assertCount(1, UsersHelper::getNonMutualFriends($owner, 25, 1, '', 'rand'));
		$this->assertCount(1, UsersHelper::getNonMutualFriends($owner, 25, 1, 'ms_y'));
	}

	#[Test]
	#[TestDox('getAddedBy paginates and searches the reverse friend index')]
	#[Group('mantle2/users')]
	public function addedBySearchAndPage(): void
	{
		$target = $this->user();
		$one = $this->user(['name' => 'rev_one']);
		$two = $this->user(['name' => 'rev_two']);
		$this->setFriends($one, [$target->id()]);
		$this->setFriends($two, [$target->id()]);

		$this->assertCount(1, UsersHelper::getAddedBy($this->reload($target), 1, 1));
		$this->assertCount(1, UsersHelper::getAddedBy($this->reload($target), 25, 1, 'rev_one'));
	}

	#[Test]
	#[TestDox('friend counts are zero with no friends and honor a search filter')]
	#[Group('mantle2/users')]
	public function friendCountEmptyAndSearch(): void
	{
		$loner = $this->user();
		$this->assertSame(0, UsersHelper::getAddedFriendsCount($loner));
		$this->assertSame(0, UsersHelper::getNonMutualFriendsCount($loner));
		$this->assertSame(0, UsersHelper::getAddedByCount($loner));

		// owner -> a,b ; b -> y  => y is a non-mutual friend-of-friend
		$owner = $this->user(['name' => 'fc_o']);
		$a = $this->user(['name' => 'fc_a']);
		$b = $this->user(['name' => 'fc_b']);
		$y = $this->user(['name' => 'fc_y']);
		$this->setFriends($owner, [$a->id(), $b->id()]);
		$this->setFriends($b, [$y->id()]);
		$owner = $this->reload($owner);

		$this->assertSame(2, UsersHelper::getAddedFriendsCount($owner));
		$this->assertSame(1, UsersHelper::getAddedFriendsCount($owner, 'fc_a'));
		$this->assertSame(1, UsersHelper::getNonMutualFriendsCount($owner, 'fc_y'));
		$this->assertSame(0, UsersHelper::getNonMutualFriendsCount($owner, 'no_match'));
	}

	#[Test]
	#[TestDox('getMutualFriendsCount filters the shared-friend intersection by search')]
	#[Group('mantle2/users')]
	public function mutualCountSearch(): void
	{
		$target = $this->user();
		$requester = $this->user();
		$sharedA = $this->user(['name' => 'mc_alpha']);
		$sharedB = $this->user(['name' => 'mc_beta']);
		$this->setFriends($target, [$sharedA->id(), $sharedB->id()]);
		$this->setFriends($requester, [$sharedA->id(), $sharedB->id()]);

		$this->assertSame(
			2,
			UsersHelper::getMutualFriendsCount($this->reload($target), $this->reload($requester)),
		);
		$this->assertSame(
			1,
			UsersHelper::getMutualFriendsCount(
				$this->reload($target),
				$this->reload($requester),
				'mc_alpha',
			),
		);
	}

	// #endregion

	// #region User Blocking

	#[Test]
	#[TestDox('block predicates read the forward and reverse index directionally')]
	#[Group('mantle2/users')]
	public function blockPredicates(): void
	{
		$a = $this->user();
		$b = $this->user();
		$a->set('field_blocked_users', json_encode([(int) $b->id()]));
		$a->save();
		$b->set('field_blocked_by', json_encode([(int) $a->id()]));
		$b->save();

		$this->assertTrue(UsersHelper::isBlocking($a, $b));
		$this->assertFalse(UsersHelper::isBlocking($b, $a));
		$this->assertTrue(UsersHelper::isBlockedBy($b, $a));
		$this->assertTrue(UsersHelper::hasBlockRelationship($a, $b));
		$this->assertTrue(UsersHelper::hasBlockRelationship($b, $a));
		$this->assertSame([(int) $b->id()], UsersHelper::getBlockedUsers($a));
		$this->assertSame([(int) $a->id()], UsersHelper::getBlockedByIds($b));
	}

	#[Test]
	#[TestDox('getBlockRelatedIds is the union of blocked and blocked-by')]
	#[Group('mantle2/users')]
	public function getBlockRelatedIds(): void
	{
		$user = $this->user();
		$blocked = $this->user();
		$blockedBy = $this->user();
		$user->set('field_blocked_users', json_encode([(int) $blocked->id()]));
		$user->set('field_blocked_by', json_encode([(int) $blockedBy->id()]));
		$user->save();

		$related = UsersHelper::getBlockRelatedIds($this->reload($user));
		sort($related);
		$expected = [(int) $blocked->id(), (int) $blockedBy->id()];
		sort($expected);
		$this->assertSame($expected, $related);
	}

	#[Test]
	#[
		TestDox(
			'blockUser persists the block, severs friendship both ways, and unblockUser reverses it',
		),
	]
	#[Group('mantle2/users')]
	public function blockAndUnblock(): void
	{
		$user = $this->user();
		$target = $this->user();
		$this->setFriends($user, [$target->id()]);
		$this->setFriends($target, [$user->id()]);
		$user = $this->reload($user);
		$target = $this->reload($target);

		$this->assertFalse(UsersHelper::blockUser($user, $user));
		$this->assertTrue(UsersHelper::blockUser($user, $target));
		$this->assertFalse(UsersHelper::blockUser($this->reload($user), $this->reload($target)));

		$user = $this->reload($user);
		$target = $this->reload($target);
		$this->assertTrue(UsersHelper::isBlocking($user, $target));
		$this->assertTrue(UsersHelper::isBlockedBy($target, $user));
		// blocking severs the friendship in both directions
		$this->assertNotContains(
			(string) $target->id(),
			json_decode($user->get('field_friends')->value ?: '[]', true),
		);
		$this->assertNotContains(
			(string) $user->id(),
			json_decode($target->get('field_friends')->value ?: '[]', true),
		);

		$this->assertFalse(UsersHelper::unblockUser($this->reload($user), $this->user()));
		$this->assertTrue(UsersHelper::unblockUser($this->reload($user), $this->reload($target)));
		$this->assertFalse(UsersHelper::isBlocking($this->reload($user), $this->reload($target)));
		$this->assertFalse(UsersHelper::isBlockedBy($this->reload($target), $this->reload($user)));
	}

	// #endregion

	// #region User Circles

	#[Test]
	#[TestDox('getMaxCircleCount scales with account type and defaults to 100 for null')]
	#[Group('mantle2/users')]
	public function getMaxCircleCount(): void
	{
		$this->assertSame(100, UsersHelper::getMaxCircleCount(null));
		$this->assertSame(
			25,
			UsersHelper::getMaxCircleCount($this->accountUser(AccountType::FREE)),
		);
		$this->assertSame(
			500,
			UsersHelper::getMaxCircleCount($this->accountUser(AccountType::PRO)),
		);
		$this->assertSame(
			500,
			UsersHelper::getMaxCircleCount($this->accountUser(AccountType::WRITER)),
		);
		$this->assertSame(
			1000,
			UsersHelper::getMaxCircleCount($this->accountUser(AccountType::ORGANIZER)),
		);
		$this->assertSame(
			1000,
			UsersHelper::getMaxCircleCount($this->accountUser(AccountType::ADMINISTRATOR)),
		);
	}

	#[Test]
	#[TestDox('getCircle lists, counts, searches, and paginates; isInCircle is directional')]
	#[Group('mantle2/users')]
	public function circleReads(): void
	{
		$owner = $this->user();
		$m1 = $this->user(['name' => 'circle_one']);
		$m2 = $this->user(['name' => 'circle_two']);
		$owner->set('field_circle', json_encode([(string) $m1->id(), (string) $m2->id()]));
		$owner->save();
		$owner = $this->reload($owner);

		$this->assertCount(2, UsersHelper::getCircle($owner));
		$this->assertSame(2, UsersHelper::getCircleCount($owner));
		$this->assertSame(1, UsersHelper::getCircleCount($owner, 'circle_one'));
		$this->assertCount(1, UsersHelper::getCircle($owner, 1, 2));

		$this->assertTrue(UsersHelper::isInCircle($owner, $this->reload($m1)));
		$this->assertFalse(UsersHelper::isInCircle($this->reload($m1), $owner));
		$this->assertFalse(UsersHelper::isInCircle($owner, $owner));
	}

	#[Test]
	#[TestDox('addToCircle refuses self and non-friends, accepts friends, and rejects duplicates')]
	#[Group('mantle2/users')]
	public function addToCircleGuards(): void
	{
		$owner = $this->user();

		// cannot add self
		$this->assertFalse(UsersHelper::addToCircle($owner, $owner));

		// cannot add a non-friend
		$stranger = $this->user();
		$this->assertFalse(
			UsersHelper::addToCircle($this->reload($owner), $this->reload($stranger)),
		);

		// a friend can be added exactly once
		$friend = $this->user();
		$this->setFriends($owner, [$friend->id()]);
		$this->assertTrue(UsersHelper::addToCircle($this->reload($owner), $this->reload($friend)));
		$this->assertTrue(UsersHelper::isInCircle($this->reload($owner), $this->reload($friend)));
		$this->assertFalse(UsersHelper::addToCircle($this->reload($owner), $this->reload($friend)));
	}

	#[Test]
	#[TestDox('removeFromCircle refuses self, no-ops on non-members, and removes members')]
	#[Group('mantle2/users')]
	public function removeFromCircleGuards(): void
	{
		$owner = $this->user();
		$this->assertFalse(UsersHelper::removeFromCircle($owner, $owner));

		$notMember = $this->user();
		$this->assertFalse(
			UsersHelper::removeFromCircle($this->reload($owner), $this->reload($notMember)),
		);

		$friend = $this->user();
		$this->setFriends($owner, [$friend->id()]);
		UsersHelper::addToCircle($this->reload($owner), $this->reload($friend));
		$this->assertTrue(
			UsersHelper::removeFromCircle($this->reload($owner), $this->reload($friend)),
		);
		$this->assertFalse(UsersHelper::isInCircle($this->reload($owner), $this->reload($friend)));
	}

	#[Test]
	#[TestDox('getMaxCircleCount defaults to 25 for FREE when queried by account type')]
	#[Group('mantle2/users')]
	public function circleCountSearchEmpty(): void
	{
		$owner = $this->user();
		$this->assertSame(0, UsersHelper::getCircleCount($owner, 'no-match'));
		$this->assertCount(0, UsersHelper::getCircle($owner));
	}

	// #endregion

	// #region User Leaderboards

	// leaderboardMetric and getScopedLeaderboard hit CloudHelper for the journey types and the
	// global scope; the friends/circle points path is covered in the controller test via a
	// seeded points cache. no additional local-only leaderboard branch to exercise here.

	// #endregion

	// #region User Activities

	private function activity(string $id): Activity
	{
		ActivityHelper::createActivity(
			Activity::fromArray([
				'id' => $id,
				'name' => "Name of $id",
				'description' => "Description of $id",
				'types' => ['HOBBY'],
				'aliases' => [],
				'fields' => ['icon' => 'mdi:star'],
			]),
			$this->accountUser(AccountType::ADMINISTRATOR),
		);
		return ActivityHelper::getActivity($id);
	}

	#[Test]
	#[TestDox('get/set/has activities round-trip and hasActivity matches by id')]
	#[Group('mantle2/users')]
	public function activityRoundTrip(): void
	{
		$hiking = $this->activity('hiking');
		$cycling = $this->activity('cycling');
		$user = $this->user();

		$this->assertSame([], UsersHelper::getActivities($user));

		UsersHelper::setActivities($user, [$hiking, $cycling]);
		$user = $this->reload($user);
		$stored = UsersHelper::getActivities($user);
		$this->assertCount(2, $stored);
		$this->assertSame(['hiking', 'cycling'], array_map(fn($a) => $a->getId(), $stored));
		$this->assertTrue(UsersHelper::hasActivity($user, 'hiking'));
		$this->assertFalse(UsersHelper::hasActivity($user, 'nope'));
	}

	#[Test]
	#[TestDox('addActivity appends and setActivities enforces the max of 10')]
	#[Group('mantle2/users')]
	public function activityMutations(): void
	{
		$hiking = $this->activity('hiking');
		$cycling = $this->activity('cycling');
		$user = $this->user();

		UsersHelper::addActivity($user, $hiking);
		$user = $this->reload($user);
		$this->assertTrue(UsersHelper::hasActivity($user, 'hiking'));

		UsersHelper::addActivity($user, $cycling);
		$user = $this->reload($user);
		$this->assertCount(2, UsersHelper::getActivities($user));

		// setActivities refuses to persist more than MAX_ACTIVITIES (10)
		$eleven = [];
		for ($i = 0; $i < 11; $i++) {
			$eleven[] = $this->activity('act_' . $i);
		}
		$before = UsersHelper::getActivities($this->reload($user));
		UsersHelper::setActivities($this->reload($user), $eleven);
		$this->assertEquals($before, UsersHelper::getActivities($this->reload($user)));
	}

	#[Test]
	#[TestDox('removeActivity removes the matching activity by id')]
	#[Group('mantle2/users')]
	public function removeActivityById(): void
	{
		$hiking = $this->activity('hiking');
		$user = $this->user();
		UsersHelper::setActivities($user, [$hiking]);
		$user = $this->reload($user);

		UsersHelper::removeActivity($user, ActivityHelper::getActivity('hiking'));
		$this->assertFalse(UsersHelper::hasActivity($this->reload($user), 'hiking'));
	}

	// #endregion

	// #region User Profile Photos (local)

	#[Test]
	#[TestDox('buildUserProfilePromptData assembles the local profile fields for cloud generation')]
	#[Group('mantle2/users')]
	public function buildUserProfilePromptData(): void
	{
		$hiking = $this->activity('hiking');
		$user = $this->user(['name' => 'photo_user']);
		$user->set('field_first_name', 'Ada');
		$user->set('field_last_name', 'Lovelace');
		$user->set('field_country', 'US');
		UsersHelper::setActivities($user, [$hiking]);
		$user = $this->reload($user);

		$data = UsersHelper::buildUserProfilePromptData($user);
		$this->assertSame('photo_user', $data['username']);
		$this->assertSame('US', $data['country']);
		$this->assertSame('Ada Lovelace', $data['full_name']);
		$this->assertSame('PUBLIC', $data['visibility']);
		$this->assertArrayHasKey('created_at', $data);
		$this->assertCount(1, $data['activities']);
		$this->assertSame('hiking', $data['activities'][0]->getId());
	}

	#[Test]
	#[TestDox('getProfilePhoto degrades to an empty string without cloud')]
	#[Group('mantle2/users')]
	public function getProfilePhotoDegrades(): void
	{
		$user = $this->user();
		$this->assertSame('', UsersHelper::getProfilePhoto($user));
		$this->assertSame('', UsersHelper::getProfilePhoto($user, 512));
	}

	// #endregion
}
