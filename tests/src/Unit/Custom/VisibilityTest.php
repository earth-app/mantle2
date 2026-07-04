<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class VisibilityTest extends TestCase
{
	#[Test]
	#[TestDox('Visibility has exactly PUBLIC, UNLISTED, PRIVATE')]
	#[Group('mantle2/custom')]
	public function testCaseSet(): void
	{
		$names = array_map(fn(Visibility $c) => $c->name, Visibility::cases());
		$this->assertSame(['PUBLIC', 'UNLISTED', 'PRIVATE'], $names);
		$this->assertCount(3, Visibility::cases());
	}

	public static function valueProvider(): array
	{
		return [
			[Visibility::PUBLIC, 'PUBLIC'],
			[Visibility::UNLISTED, 'UNLISTED'],
			[Visibility::PRIVATE, 'PRIVATE'],
		];
	}

	#[Test]
	#[TestDox('Each case round-trips through from/tryFrom')]
	#[Group('mantle2/custom')]
	#[DataProvider('valueProvider')]
	public function testBackingValues(Visibility $case, string $value): void
	{
		$this->assertSame($value, $case->value);
		$this->assertSame($case, Visibility::from($value));
		$this->assertSame($case, Visibility::tryFrom($value));
	}

	#[Test]
	#[TestDox('tryFrom rejects unknowns; from throws')]
	#[Group('mantle2/custom')]
	public function testInvalidValues(): void
	{
		$this->assertNull(Visibility::tryFrom('public'));
		$this->assertNull(Visibility::tryFrom('HIDDEN'));
		$this->assertNull(Visibility::tryFrom(''));

		$this->expectException(\ValueError::class);
		Visibility::from('HIDDEN');
	}
}
