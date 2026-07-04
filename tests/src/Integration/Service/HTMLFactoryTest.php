<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\HTMLFactory;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class HTMLFactoryTest extends IntegrationTestBase
{
	private function factory(): HTMLFactory
	{
		return \Drupal::service('mantle2.parser');
	}

	#[Test]
	#[TestDox('the html_factory service is wired and produces a full email document')]
	#[Group('mantle2/html')]
	public function serviceResolvesFromContainer(): void
	{
		$factory = $this->factory();
		$this->assertInstanceOf(HTMLFactory::class, $factory);

		$html = $factory->toHtml('Hello world.');
		$this->assertStringStartsWith('<!DOCTYPE html>', $html);
		$this->assertStringContainsString('Thank you for using The Earth App!', $html);
	}

	// headers (#..######) are not exercised by the unit test

	#[Test]
	#[TestDox('markdown headers render at the level-appropriate size and reset any open list')]
	#[Group('mantle2/html')]
	#[DataProvider('headerProvider')]
	public function headers(string $prefix, int $level, string $fontSize): void
	{
		$html = $this->factory()->toHtml("{$prefix} Section Title");
		$this->assertStringContainsString("<h{$level} style=\"margin: ", $html);
		$this->assertStringContainsString("font-size: {$fontSize};", $html);
		$this->assertStringContainsString(">Section Title</h{$level}>", $html);
	}

	public static function headerProvider(): array
	{
		return [
			'h1' => ['#', 1, '28px'],
			'h2' => ['##', 2, '24px'],
			'h3' => ['###', 3, '20px'],
			'h4' => ['####', 4, '18px'],
			'h5' => ['#####', 5, '16px'],
			'h6' => ['######', 6, '14px'],
		];
	}

	#[Test]
	#[TestDox('a header closes an open list before rendering')]
	#[Group('mantle2/html')]
	public function headerClosesOpenList(): void
	{
		$html = $this->factory()->toHtml("- item one\n- item two\n## Next Section");

		$ulClose = strpos($html, '</ul>');
		$headerPos = strpos($html, '<h2');
		$this->assertNotFalse($ulClose);
		$this->assertNotFalse($headerPos);
		$this->assertLessThan($headerPos, $ulClose);
	}

	#[Test]
	#[TestDox('header text still runs through inline formatting for bold and links')]
	#[Group('mantle2/html')]
	public function headerFormatsInlineElements(): void
	{
		$html = $this->factory()->toHtml('# Welcome **home**');
		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">home</strong>',
			$html,
		);
	}

	// unsubscribe branding branch is not exercised by the unit test

	#[Test]
	#[TestDox('the unsubscribe link is included and escaped only when both flag and url are given')]
	#[Group('mantle2/html')]
	public function unsubscribeLink(): void
	{
		$url = 'https://app.earth-app.com/unsub?u=1&t=abc';
		$with = $this->factory()->toHtml('Body.', true, $url);
		$this->assertStringContainsString('Unsubscribe from these emails', $with);
		$this->assertStringContainsString('https://app.earth-app.com/unsub?u=1&amp;t=abc', $with);

		$flagOnly = $this->factory()->toHtml('Body.', true, null);
		$this->assertStringNotContainsString('Unsubscribe from these emails', $flagOnly);

		$urlOnly = $this->factory()->toHtml('Body.', false, $url);
		$this->assertStringNotContainsString('Unsubscribe from these emails', $urlOnly);

		$default = $this->factory()->toHtml('Body.');
		$this->assertStringNotContainsString('Unsubscribe from these emails', $default);
	}
}
