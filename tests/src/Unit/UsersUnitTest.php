<?php

use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Mocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class UsersUnitTest extends TestCase
{
	protected function setUp(): void
	{
		Mocks::instance()->mockDrupalContainer();
	}

	public static function warningWindowProvider(): array
	{
		return [
			// [secondsUntilDeletion, expectedKey]
			'past deletion' => [-100, null],
			'at deletion' => [0, null],
			'30 minutes left' => [1800, '1_hour'],
			'exactly 1 hour' => [3600, '1_hour'],
			'just over 1 hour' => [3601, '1_day'],
			'exactly 1 day' => [86400, '1_day'],
			'just over 1 day' => [86401, '3_days'],
			'exactly 3 days' => [259200, '3_days'],
			'just over 3 days' => [259201, '1_week'],
			'exactly 1 week' => [604800, '1_week'],
			'just over 1 week' => [604801, '2_weeks'],
			'exactly 2 weeks' => [1209600, '2_weeks'],
			'just over 2 weeks' => [1209601, null],
			'a month out' => [2592000, null],
		];
	}

	#[Test]
	#[TestDox('resolveDeletionWarningWindow picks the most urgent crossed window')]
	#[Group('mantle2/users')]
	#[DataProvider('warningWindowProvider')]
	public function testResolveDeletionWarningWindow(int $seconds, ?string $expectedKey): void
	{
		$window = UsersHelper::resolveDeletionWarningWindow($seconds);

		if ($expectedKey === null) {
			$this->assertNull($window);
			return;
		}

		$this->assertIsArray($window);
		$this->assertSame($expectedKey, $window['key']);
		$this->assertGreaterThanOrEqual($seconds, $window['seconds']);
	}

	#[Test]
	#[TestDox('resolveDeletionWarningWindow never fires beyond the one year window')]
	#[Group('mantle2/users')]
	public function testResolveDeletionWarningWindowBeyondYear(): void
	{
		$this->assertNull(
			UsersHelper::resolveDeletionWarningWindow(UsersHelper::INACTIVE_DELETION_SECONDS),
		);
		$this->assertNull(UsersHelper::resolveDeletionWarningWindow(30 * 86400));
	}
}
