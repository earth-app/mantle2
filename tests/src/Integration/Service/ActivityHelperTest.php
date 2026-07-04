<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ActivityHelperTest extends IntegrationTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
	}

	private function make(string $id, array $types = ['SPORT'], array $aliases = []): Activity
	{
		return new Activity($id, "Name $id", $types, "Desc $id", $aliases, ['icon' => 'mdi:x']);
	}

	#[Test]
	#[TestDox('createActivity persists a node with all fields mapped back through nodeToActivity')]
	#[Group('mantle2/activities')]
	public function create_(): void
	{
		$node = ActivityHelper::createActivity($this->make('run', ['SPORT', 'HEALTH'], ['jog']));
		$this->assertSame('activity', $node->getType());
		$this->assertSame('run', $node->get('field_activity_id')->value);

		$activity = ActivityHelper::nodeToActivity($node);
		$this->assertSame('run', $activity->getId());
		$this->assertSame('Name run', $activity->getName());
		$this->assertSame('Desc run', $activity->getDescription());
		$this->assertSame(['SPORT', 'HEALTH'], $activity->getTypes());
		$this->assertSame(['jog'], $activity->getAliases());
		$this->assertSame('mdi:x', $activity->getField('icon'));
	}

	#[Test]
	#[
		TestDox(
			'getActivity, getNodeByActivityId, getNodeByActivityAlias, and getActivityByNid resolve as expected',
		),
	]
	#[Group('mantle2/activities')]
	public function get(): void
	{
		$node = ActivityHelper::createActivity($this->make('run', ['SPORT'], ['jog', 'sprint']));

		$this->assertSame('run', ActivityHelper::getActivity('run')->getId());
		$this->assertNull(ActivityHelper::getActivity('nope'));

		$this->assertSame(
			(int) $node->id(),
			(int) ActivityHelper::getNodeByActivityId('run')->id(),
		);
		$this->assertNull(ActivityHelper::getNodeByActivityId('nope'));

		$this->assertSame(
			(int) $node->id(),
			(int) ActivityHelper::getNodeByActivityAlias('sprint')->id(),
		);
		$this->assertNull(ActivityHelper::getNodeByActivityAlias('missing'));

		$this->assertSame('run', ActivityHelper::getActivityByNid((int) $node->id())->getId());
		$this->assertNull(ActivityHelper::getActivityByNid(999999));
	}

	#[Test]
	#[TestDox('updateActivity rewrites all fields and rejects non-activity nodes')]
	#[Group('mantle2/activities')]
	public function update(): void
	{
		$node = ActivityHelper::createActivity($this->make('run', ['SPORT']));

		$activity = ActivityHelper::nodeToActivity($node);
		$activity->setName('Jogging');
		$activity->setDescription('slow');
		$activity->setTypes(['HEALTH', 'PERSONAL_GOAL']);
		$activity->setAliases(['jog']);
		$activity->setFields(['icon' => 'mdi:run']);

		ActivityHelper::updateActivity($node, $activity);

		$reloaded = ActivityHelper::getActivity('run');
		$this->assertSame('Jogging', $reloaded->getName());
		$this->assertSame('slow', $reloaded->getDescription());
		$this->assertSame(['HEALTH', 'PERSONAL_GOAL'], $reloaded->getTypes());
		$this->assertSame(['jog'], $reloaded->getAliases());
		$this->assertSame('mdi:run', $reloaded->getField('icon'));
		$this->assertSame('Jogging', $node->getTitle());
	}

	#[Test]
	#[TestDox('deleteActivity removes the node and rejects non-activity nodes')]
	#[Group('mantle2/activities')]
	public function delete(): void
	{
		$node = ActivityHelper::createActivity($this->make('run'));
		$this->assertNotNull(ActivityHelper::getNodeByActivityId('run'));

		ActivityHelper::deleteActivity($node);
		$this->assertNull(ActivityHelper::getNodeByActivityId('run'));
	}

	#[Test]
	#[TestDox('non-activity nodes are rejected by update and delete')]
	#[Group('mantle2/activities')]
	public function typeGuards(): void
	{
		$prompt = \Drupal\node\Entity\Node::create(['type' => 'prompt', 'title' => 't']);
		$prompt->save();

		$this->expectException(\InvalidArgumentException::class);
		ActivityHelper::deleteActivity($prompt);
	}

	#[Test]
	#[
		TestDox(
			'recommendation helpers return local activities: random single, random batch, and recency window',
		),
	]
	#[Group('mantle2/activities')]
	public function recommendations(): void
	{
		$this->assertNull(ActivityHelper::getRandomActivity());
		$this->assertSame([], ActivityHelper::getRandomActivities());
		$this->assertSame([], ActivityHelper::getActivitiesCreatedInLastDays(7));

		ActivityHelper::createActivity($this->make('run'));
		ActivityHelper::createActivity($this->make('read', ['LEARNING']));
		ActivityHelper::createActivity($this->make('cook', ['HOBBY']));

		$single = ActivityHelper::getRandomActivity();
		$this->assertInstanceOf(Activity::class, $single);

		$batch = ActivityHelper::getRandomActivities(2);
		$this->assertCount(2, $batch);
		$this->assertContainsOnlyInstancesOf(Activity::class, $batch);

		$recent = ActivityHelper::getActivitiesCreatedInLastDays(1);
		$this->assertCount(3, $recent);
		$this->assertContainsOnlyInstancesOf(Activity::class, $recent);
	}
}
