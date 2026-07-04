<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ActivityTest extends TestCase
{
	#[Test]
	#[TestDox('Constructor stores every field and getters return them')]
	#[Group('mantle2/custom')]
	public function testConstructAndGetters(): void
	{
		$a = new Activity(
			'hiking',
			'Hiking',
			['SPORT', 'NATURE'],
			'Walking in nature',
			['trekking', 'walking'],
			['icon' => 'mdi:hiking'],
		);

		$this->assertSame('hiking', $a->getId());
		$this->assertSame('Hiking', $a->getName());
		$this->assertSame(['SPORT', 'NATURE'], $a->getTypes());
		$this->assertSame('Walking in nature', $a->getDescription());
		$this->assertSame(['trekking', 'walking'], $a->getAliases());
		$this->assertSame(['icon' => 'mdi:hiking'], $a->getAllFields());
		$this->assertSame('mdi:hiking', $a->getField('icon'));
	}

	#[Test]
	#[TestDox('MAX_TYPES is 5 and constructor rejects more than five types')]
	#[Group('mantle2/custom')]
	public function testTooManyTypesRejected(): void
	{
		$this->assertSame(5, Activity::MAX_TYPES);
		$this->expectException(InvalidArgumentException::class);
		new Activity('a', 'A', ['SPORT', 'NATURE', 'ART', 'WORK', 'STUDY', 'OTHER']);
	}

	#[Test]
	#[TestDox('Exactly five types is accepted')]
	#[Group('mantle2/custom')]
	public function testFiveTypesAccepted(): void
	{
		$a = new Activity('a', 'A', ['SPORT', 'NATURE', 'ART', 'WORK', 'STUDY']);
		$this->assertCount(5, $a->getTypes());
	}

	#[Test]
	#[TestDox('Constructor rejects a non-string type')]
	#[Group('mantle2/custom')]
	public function testNonStringTypeRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new Activity('a', 'A', [ActivityType::SPORT]);
	}

	#[Test]
	#[TestDox('jsonSerialize emits the canonical key shape and maps enum types to names')]
	#[Group('mantle2/custom')]
	public function testJsonSerializeShape(): void
	{
		$a = new Activity('hiking', 'Hiking', ['SPORT'], 'desc', ['t'], ['icon' => 'x']);
		$json = $a->jsonSerialize();

		$this->assertSame(
			['id', 'name', 'description', 'types', 'aliases', 'fields'],
			array_keys($json),
		);
		$this->assertSame('hiking', $json['id']);
		$this->assertSame('Hiking', $json['name']);
		$this->assertSame('desc', $json['description']);
		$this->assertSame(['SPORT'], $json['types']);
		$this->assertSame(['t'], $json['aliases']);
		$this->assertSame(['icon' => 'x'], $json['fields']);
	}

	#[Test]
	#[
		TestDox(
			'jsonSerialize converts ActivityType instances to their name; setTypes bypasses validation',
		),
	]
	#[Group('mantle2/custom')]
	public function testJsonSerializeEnumMapping(): void
	{
		$a = new Activity('a', 'A');
		$a->setTypes([ActivityType::SPORT, 'NATURE']);
		$json = $a->jsonSerialize();
		$this->assertSame(['SPORT', 'NATURE'], $json['types']);
	}

	#[Test]
	#[TestDox('jsonSerialize reindexes aliases with array_values')]
	#[Group('mantle2/custom')]
	public function testAliasesReindexed(): void
	{
		$a = new Activity('a', 'A', [], null, [5 => 'x', 9 => 'y']);
		$this->assertSame(['x', 'y'], $a->jsonSerialize()['aliases']);
	}

	#[Test]
	#[TestDox('fromArray builds from a full array and defaults fields to icon when absent')]
	#[Group('mantle2/custom')]
	public function testFromArray(): void
	{
		$a = Activity::fromArray([
			'id' => 'hiking',
			'name' => 'Hiking',
			'types' => ['SPORT'],
			'description' => 'd',
			'aliases' => ['x'],
			'fields' => ['icon' => 'z'],
		]);
		$this->assertSame('hiking', $a->getId());
		$this->assertSame(['SPORT'], $a->getTypes());
		$this->assertSame(['icon' => 'z'], $a->getAllFields());

		$minimal = Activity::fromArray(['id' => 'a', 'name' => 'A']);
		$this->assertSame([], $minimal->getTypes());
		$this->assertNull($minimal->getDescription());
		$this->assertSame(['icon' => ''], $minimal->getAllFields());
	}

	#[Test]
	#[TestDox('setField sets, overwrites, and null removes a field')]
	#[Group('mantle2/custom')]
	public function testSetField(): void
	{
		$a = new Activity('a', 'A');
		$a->setField('icon', 'x');
		$this->assertSame('x', $a->getField('icon'));
		$a->setField('icon', 'y');
		$this->assertSame('y', $a->getField('icon'));
		$a->setField('icon', null);
		$this->assertNull($a->getField('icon'));
		$this->assertArrayNotHasKey('icon', $a->getAllFields());
	}

	#[Test]
	#[TestDox('getField and setField warn and no-op on an empty key')]
	#[Group('mantle2/custom')]
	public function testEmptyKeyWarns(): void
	{
		$a = new Activity('a', 'A', [], null, [], ['icon' => 'x']);

		$got = @$a->getField('');
		$this->assertNull($got);

		@$a->setField('', 'value');
		$this->assertSame(['icon' => 'x'], $a->getAllFields());
	}

	#[Test]
	#[TestDox('Setters mutate name, description, types, aliases, and full field map')]
	#[Group('mantle2/custom')]
	public function testSetters(): void
	{
		$a = new Activity('a', 'A');
		$a->setName('B');
		$a->setDescription('d');
		$a->setAliases(['q']);
		$a->setFields(['k' => 'v']);
		$this->assertSame('B', $a->getName());
		$this->assertSame('d', $a->getDescription());
		$this->assertSame(['q'], $a->getAliases());
		$this->assertSame(['k' => 'v'], $a->getAllFields());
	}

	#[Test]
	#[TestDox('__toString renders name and id')]
	#[Group('mantle2/custom')]
	public function testToString(): void
	{
		$a = new Activity('hiking', 'Hiking');
		$this->assertSame('Hiking <hiking>', (string) $a);
	}
}
