<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Quest;
use Drupal\mantle2\Custom\QuestStep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class QuestTest extends TestCase
{
	#[Test]
	#[TestDox('Constructor populates every public field')]
	#[Group('mantle2/custom')]
	public function testConstructor(): void
	{
		$step = new QuestStep('t', 'd');
		$q = new Quest(
			'q1',
			'Title',
			'Desc',
			'icon.png',
			'rare',
			[$step],
			100,
			['quests:read'],
			true,
			true,
		);

		$this->assertSame('q1', $q->id);
		$this->assertSame('Title', $q->title);
		$this->assertSame('Desc', $q->description);
		$this->assertSame('icon.png', $q->icon);
		$this->assertSame('rare', $q->rarity);
		$this->assertSame([$step], $q->steps);
		$this->assertSame(100, $q->reward);
		$this->assertSame(['quests:read'], $q->permissions);
		$this->assertTrue($q->mobileOnly);
		$this->assertTrue($q->premium);
	}

	#[Test]
	#[TestDox('Optional constructor args default reward 0, empty permissions, and false flags')]
	#[Group('mantle2/custom')]
	public function testDefaults(): void
	{
		$q = new Quest('q1', 't', 'd', 'i', 'normal', []);
		$this->assertSame(0, $q->reward);
		$this->assertSame([], $q->permissions);
		$this->assertFalse($q->mobileOnly);
		$this->assertFalse($q->premium);
	}

	#[Test]
	#[TestDox('jsonSerialize emits canonical keys with snake_case flags')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$step = new QuestStep('t', 'd');
		$q = new Quest('q1', 'Title', 'Desc', 'i', 'green', [$step], 50, ['p'], true, true);
		$json = $q->jsonSerialize();

		$this->assertSame(
			[
				'id',
				'title',
				'description',
				'premium',
				'icon',
				'rarity',
				'mobile_only',
				'steps',
				'reward',
				'permissions',
			],
			array_keys($json),
		);
		$this->assertSame('q1', $json['id']);
		$this->assertTrue($json['premium']);
		$this->assertSame('green', $json['rarity']);
		$this->assertTrue($json['mobile_only']);
		$this->assertSame([$step], $json['steps']);
		$this->assertSame(50, $json['reward']);
		$this->assertSame(['p'], $json['permissions']);
	}

	#[Test]
	#[TestDox('fromArray builds a flat list of QuestStep for simple steps')]
	#[Group('mantle2/custom')]
	public function testFromArraySimpleSteps(): void
	{
		$q = Quest::fromArray([
			'id' => 'q1',
			'title' => 'T',
			'description' => 'D',
			'icon' => 'i',
			'rarity' => 'amazing',
			'steps' => [
				['type' => 'a', 'description' => 'step a'],
				['type' => 'b', 'description' => 'step b'],
			],
			'reward' => 25,
			'permissions' => ['quests:read'],
			'mobile_only' => true,
			'premium' => true,
		]);

		$this->assertSame('amazing', $q->rarity);
		$this->assertSame(25, $q->reward);
		$this->assertSame(['quests:read'], $q->permissions);
		$this->assertTrue($q->mobileOnly);
		$this->assertTrue($q->premium);

		$this->assertCount(2, $q->steps);
		$this->assertInstanceOf(QuestStep::class, $q->steps[0]);
		$this->assertSame('a', $q->steps[0]->type);
		$this->assertInstanceOf(QuestStep::class, $q->steps[1]);
		$this->assertSame('b', $q->steps[1]->type);
	}

	#[Test]
	#[TestDox('fromArray parses a 2D OR-condition group into an array of QuestStep')]
	#[Group('mantle2/custom')]
	public function testFromArrayOrConditions(): void
	{
		$q = Quest::fromArray([
			'id' => 'q1',
			'title' => 'T',
			'description' => 'D',
			'icon' => 'i',
			'steps' => [
				['type' => 'first', 'description' => 'do first'],
				[
					['type' => 'alt_a', 'description' => 'either a'],
					['type' => 'alt_b', 'description' => 'or b'],
				],
			],
		]);

		$this->assertCount(2, $q->steps);

		// first is a single QuestStep
		$this->assertInstanceOf(QuestStep::class, $q->steps[0]);
		$this->assertSame('first', $q->steps[0]->type);

		// second is a 2D OR-group: an array of QuestStep
		$this->assertIsArray($q->steps[1]);
		$this->assertCount(2, $q->steps[1]);
		$this->assertInstanceOf(QuestStep::class, $q->steps[1][0]);
		$this->assertSame('alt_a', $q->steps[1][0]->type);
		$this->assertSame('alt_b', $q->steps[1][1]->type);
	}

	#[Test]
	#[TestDox('fromArray applies defaults for rarity, reward, permissions, flags, and empty steps')]
	#[Group('mantle2/custom')]
	public function testFromArrayDefaults(): void
	{
		$q = Quest::fromArray([
			'id' => 'q1',
			'title' => 'T',
			'description' => 'D',
			'icon' => 'i',
		]);

		$this->assertSame('normal', $q->rarity);
		$this->assertSame([], $q->steps);
		$this->assertSame(0, $q->reward);
		$this->assertSame([], $q->permissions);
		$this->assertFalse($q->mobileOnly);
		$this->assertFalse($q->premium);
	}
}
