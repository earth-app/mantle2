<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\GeneralHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class PromptTest extends TestCase
{
	private function make(): Prompt
	{
		return new Prompt(101, 'What did you do today?', 42, Visibility::PUBLIC);
	}

	#[Test]
	#[TestDox('Every getter returns its constructor argument')]
	#[Group('mantle2/custom')]
	public function testGetters(): void
	{
		$p = $this->make();
		$this->assertSame(101, $p->getId());
		$this->assertSame('What did you do today?', $p->getPrompt());
		$this->assertSame(42, $p->getOwnerId());
		$this->assertSame(Visibility::PUBLIC, $p->getVisibility());
	}

	#[Test]
	#[TestDox('Setters mutate prompt text and visibility')]
	#[Group('mantle2/custom')]
	public function testSetters(): void
	{
		$p = $this->make();

		$p->setPrompt('New prompt');
		$this->assertSame('New prompt', $p->getPrompt());

		$p->setVisibility(Visibility::PRIVATE);
		$this->assertSame(Visibility::PRIVATE, $p->getVisibility());
	}

	public static function visibilityProvider(): array
	{
		return [
			'public' => [Visibility::PUBLIC, 'PUBLIC'],
			'unlisted' => [Visibility::UNLISTED, 'UNLISTED'],
			'private' => [Visibility::PRIVATE, 'PRIVATE'],
		];
	}

	#[Test]
	#[TestDox('jsonSerialize formats ids and emits the $_dataName visibility value')]
	#[Group('mantle2/custom')]
	#[DataProvider('visibilityProvider')]
	public function testJsonSerialize(Visibility $visibility, string $expected): void
	{
		$json = new Prompt(101, 'q', 42, $visibility)->jsonSerialize();

		$this->assertSame(['id', 'prompt', 'owner_id', 'visibility'], array_keys($json));
		$this->assertSame(GeneralHelper::formatId(101), $json['id']);
		$this->assertSame('q', $json['prompt']);
		$this->assertSame(GeneralHelper::formatId(42), $json['owner_id']);
		$this->assertSame($expected, $json['visibility']);
	}
}
