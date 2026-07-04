<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\AccountType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class AccountTypeTest extends TestCase
{
	#[Test]
	#[TestDox('AccountType has exactly the five expected cases')]
	#[Group('mantle2/custom')]
	public function testCaseSet(): void
	{
		$names = array_map(fn(AccountType $c) => $c->name, AccountType::cases());
		$this->assertSame(['FREE', 'PRO', 'WRITER', 'ORGANIZER', 'ADMINISTRATOR'], $names);
		$this->assertCount(5, AccountType::cases());
	}

	public static function valueProvider(): array
	{
		return [
			'free' => [AccountType::FREE, 'free'],
			'pro' => [AccountType::PRO, 'pro'],
			'writer' => [AccountType::WRITER, 'writer'],
			'organizer' => [AccountType::ORGANIZER, 'organizer'],
			'administrator' => [AccountType::ADMINISTRATOR, 'administrator'],
		];
	}

	#[Test]
	#[TestDox('Each case maps to its lowercase backing value')]
	#[Group('mantle2/custom')]
	#[DataProvider('valueProvider')]
	public function testBackingValues(AccountType $case, string $value): void
	{
		$this->assertSame($value, $case->value);
		$this->assertSame($case, AccountType::from($value));
		$this->assertSame($case, AccountType::tryFrom($value));
	}

	#[Test]
	#[TestDox('tryFrom returns null and from throws on unknown values')]
	#[Group('mantle2/custom')]
	public function testInvalidValues(): void
	{
		$this->assertNull(AccountType::tryFrom('FREE'));
		$this->assertNull(AccountType::tryFrom('nope'));
		$this->assertNull(AccountType::tryFrom(''));

		$this->expectException(\ValueError::class);
		AccountType::from('nope');
	}
}
