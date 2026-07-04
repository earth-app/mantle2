<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\EventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class EventTypeTest extends TestCase
{
	#[Test]
	#[TestDox('EventType has exactly IN_PERSON, ONLINE, HYBRID')]
	#[Group('mantle2/custom')]
	public function testCaseSet(): void
	{
		$names = array_map(fn(EventType $c) => $c->name, EventType::cases());
		$this->assertSame(['IN_PERSON', 'ONLINE', 'HYBRID'], $names);
		$this->assertCount(3, EventType::cases());
	}

	public static function valueProvider(): array
	{
		return [
			[EventType::IN_PERSON, 'IN_PERSON'],
			[EventType::ONLINE, 'ONLINE'],
			[EventType::HYBRID, 'HYBRID'],
		];
	}

	#[Test]
	#[TestDox('Each case round-trips through from/tryFrom')]
	#[Group('mantle2/custom')]
	#[DataProvider('valueProvider')]
	public function testBackingValues(EventType $case, string $value): void
	{
		$this->assertSame($value, $case->value);
		$this->assertSame($case, EventType::from($value));
		$this->assertSame($case, EventType::tryFrom($value));
	}

	#[Test]
	#[TestDox('tryFrom rejects unknowns; from throws')]
	#[Group('mantle2/custom')]
	public function testInvalidValues(): void
	{
		$this->assertNull(EventType::tryFrom('in_person'));
		$this->assertNull(EventType::tryFrom('VIRTUAL'));
		$this->assertNull(EventType::tryFrom(''));

		$this->expectException(\ValueError::class);
		EventType::from('VIRTUAL');
	}
}
