<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Privacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase
{
	#[Test]
	#[TestDox('Privacy has exactly PRIVATE, CIRCLE, MUTUAL, PUBLIC')]
	#[Group('mantle2/custom')]
	public function testCaseSet(): void
	{
		$names = array_map(fn(Privacy $c) => $c->name, Privacy::cases());
		$this->assertSame(['PRIVATE', 'CIRCLE', 'MUTUAL', 'PUBLIC'], $names);
		$this->assertCount(4, Privacy::cases());
	}

	public static function valueProvider(): array
	{
		return [
			[Privacy::PRIVATE, 'PRIVATE'],
			[Privacy::CIRCLE, 'CIRCLE'],
			[Privacy::MUTUAL, 'MUTUAL'],
			[Privacy::PUBLIC, 'PUBLIC'],
		];
	}

	#[Test]
	#[TestDox('Each case round-trips through from/tryFrom')]
	#[Group('mantle2/custom')]
	#[DataProvider('valueProvider')]
	public function testBackingValues(Privacy $case, string $value): void
	{
		$this->assertSame($value, $case->value);
		$this->assertSame($case, Privacy::from($value));
		$this->assertSame($case, Privacy::tryFrom($value));
	}

	#[Test]
	#[TestDox('tryFrom rejects unknowns; from throws')]
	#[Group('mantle2/custom')]
	public function testInvalidValues(): void
	{
		$this->assertNull(Privacy::tryFrom('public'));
		$this->assertNull(Privacy::tryFrom('FRIENDS'));
		$this->assertNull(Privacy::tryFrom(''));

		$this->expectException(\ValueError::class);
		Privacy::from('FRIENDS');
	}
}
