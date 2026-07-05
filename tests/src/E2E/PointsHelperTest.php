<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Custom\Quest;
use Drupal\mantle2\Custom\QuestData;
use Drupal\mantle2\Service\PointsHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class PointsHelperTest extends E2ETestBase
{
	#[Test]
	#[TestDox('addPoints/getPoints round-trip through cloud with history entries')]
	#[Group('mantle2/points')]
	public function addThenGetPoints(): void
	{
		$user = $this->createUser();

		[$start] = PointsHelper::getPoints($user);
		$this->assertIsInt($start);

		[$after, $history] = PointsHelper::addPoints($user, 50, 'e2e add');
		$this->assertSame($start + 50, $after);
		$this->assertNotEmpty($history);
		$this->assertSame(50, $history[0]['difference']);
		$this->assertSame('e2e add', $history[0]['reason']);

		[$read, $readHistory] = PointsHelper::getPoints($user);
		$this->assertSame($after, $read);
		$this->assertNotEmpty($readHistory);
	}

	#[Test]
	#[TestDox('removePoints subtracts from the cloud balance')]
	#[Group('mantle2/points')]
	public function removePoints(): void
	{
		$user = $this->createUser();
		[$seeded] = PointsHelper::addPoints($user, 80, 'seed');

		[$after] = PointsHelper::removePoints($user, 30, 'e2e remove');
		$this->assertSame($seeded - 30, $after);
	}

	#[Test]
	#[TestDox('setPoints overwrites the cloud balance to an absolute value')]
	#[Group('mantle2/points')]
	public function setPoints(): void
	{
		$user = $this->createUser();
		PointsHelper::addPoints($user, 15, 'seed');

		[$after] = PointsHelper::setPoints($user, 200, 'e2e set');
		$this->assertSame(200, $after);

		[$read] = PointsHelper::getPoints($user);
		$this->assertSame(200, $read);
	}

	#[Test]
	#[TestDox('getAllQuests returns the parsed catalog and getQuest resolves one by id')]
	#[Group('mantle2/points')]
	public function allQuestsAndSingleQuest(): void
	{
		$quests = PointsHelper::getAllQuests();
		$this->assertNotEmpty($quests, 'cloud returned no quest catalog');
		$this->assertContainsOnlyInstancesOf(Quest::class, $quests);

		$first = $quests[0];
		$fetched = PointsHelper::getQuest($first->id);
		$this->assertInstanceOf(Quest::class, $fetched);
		$this->assertSame($first->id, $fetched->id);

		$missing = PointsHelper::getQuest('____not_a_quest____');
		$this->assertNull($missing);
	}

	#[Test]
	#[TestDox('startQuest then getCurrentQuest reports an ongoing quest; resetQuest clears it')]
	#[Group('mantle2/points')]
	public function startReadResetQuest(): void
	{
		$user = $this->createUser();
		$questId = PointsHelper::getAllQuests()[0]->id;

		$this->assertFalse(PointsHelper::hasOngoingQuest($user));

		$this->assertTrue(PointsHelper::startQuest($user, $questId));

		$current = PointsHelper::getCurrentQuest($user);
		$this->assertInstanceOf(QuestData::class, $current);
		$this->assertSame($questId, $current->questId);
		$this->assertTrue(PointsHelper::hasOngoingQuest($user));

		$progress = PointsHelper::getCurrentQuestStepProgress($user, 0);
		$this->assertIsArray($progress);

		$this->assertTrue(PointsHelper::resetQuest($user));
		$this->assertFalse(PointsHelper::hasOngoingQuest($user));
	}

	#[Test]
	#[TestDox('getCurrentQuest is empty for a user with no active quest')]
	#[Group('mantle2/points')]
	public function currentQuestEmptyWhenNone(): void
	{
		$user = $this->createUser();
		$current = PointsHelper::getCurrentQuest($user);
		$this->assertInstanceOf(QuestData::class, $current);
		$this->assertNull($current->questId);
	}

	#[Test]
	#[TestDox('getCompletedQuests returns an array (empty for a fresh user)')]
	#[Group('mantle2/points')]
	public function completedQuests(): void
	{
		$user = $this->createUser();
		$completed = PointsHelper::getCompletedQuests($user);
		$this->assertIsArray($completed);
		$this->assertContainsOnlyInstancesOf(Quest::class, $completed);
	}
}
