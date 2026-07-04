<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\ActivityType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ActivityTypeTest extends TestCase
{
	private const EXPECTED = [
		'HOBBY',
		'SPORT',
		'WORK',
		'STUDY',
		'TRAVEL',
		'SOCIAL',
		'RELAXATION',
		'HEALTH',
		'PROJECT',
		'PERSONAL_GOAL',
		'COMMUNITY_SERVICE',
		'CREATIVE',
		'FAMILY',
		'HOLIDAY',
		'ENTERTAINMENT',
		'LEARNING',
		'NATURE',
		'TECHNOLOGY',
		'ART',
		'SPIRITUALITY',
		'FINANCE',
		'HOME_IMPROVEMENT',
		'PETS',
		'FASHION',
		'OTHER',
	];

	#[Test]
	#[TestDox('ActivityType has exactly 25 cases in declared order')]
	#[Group('mantle2/custom')]
	public function testCaseSet(): void
	{
		$names = array_map(fn(ActivityType $c) => $c->name, ActivityType::cases());
		$this->assertSame(self::EXPECTED, $names);
		$this->assertCount(25, ActivityType::cases());
	}

	#[Test]
	#[TestDox('Every case is value-identical to its name (uppercase backing)')]
	#[Group('mantle2/custom')]
	public function testValuesEqualNames(): void
	{
		foreach (ActivityType::cases() as $case) {
			$this->assertSame($case->name, $case->value);
		}
	}

	public static function valueProvider(): array
	{
		return array_map(fn(string $v) => [$v], self::EXPECTED);
	}

	#[Test]
	#[TestDox('from/tryFrom round-trip every known value')]
	#[Group('mantle2/custom')]
	#[DataProvider('valueProvider')]
	public function testFromRoundTrip(string $value): void
	{
		$this->assertSame($value, ActivityType::from($value)->value);
		$this->assertSame($value, ActivityType::tryFrom($value)->value);
	}

	#[Test]
	#[TestDox('tryFrom rejects lowercase and unknown values; from throws')]
	#[Group('mantle2/custom')]
	public function testInvalidValues(): void
	{
		$this->assertNull(ActivityType::tryFrom('hobby'));
		$this->assertNull(ActivityType::tryFrom('UNKNOWN'));
		$this->assertNull(ActivityType::tryFrom(''));

		$this->expectException(\ValueError::class);
		ActivityType::from('hobby');
	}
}
