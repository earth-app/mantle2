<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\QuestStep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class QuestStepTest extends TestCase
{
	#[Test]
	#[TestDox('Constructor populates every public field')]
	#[Group('mantle2/custom')]
	public function testConstructor(): void
	{
		$s = new QuestStep('take_photo', 'Snap a tree', ['k' => 'v'], 50, 3600);
		$this->assertSame('take_photo', $s->type);
		$this->assertSame('Snap a tree', $s->description);
		$this->assertSame(['k' => 'v'], $s->parameters);
		$this->assertSame(50, $s->reward);
		$this->assertSame(3600, $s->delay);
	}

	#[Test]
	#[TestDox('Optional constructor args default to empty parameters and zero reward/delay')]
	#[Group('mantle2/custom')]
	public function testDefaults(): void
	{
		$s = new QuestStep('t', 'd');
		$this->assertSame([], $s->parameters);
		$this->assertSame(0, $s->reward);
		$this->assertSame(0, $s->delay);
	}

	#[Test]
	#[TestDox('jsonSerialize emits type, description, parameters, reward, and delay')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$json = new QuestStep('t', 'd', ['a' => 1], 10, 20)->jsonSerialize();
		$this->assertSame(
			['type', 'description', 'parameters', 'reward', 'delay'],
			array_keys($json),
		);
		$this->assertSame('t', $json['type']);
		$this->assertSame('d', $json['description']);
		$this->assertSame(['a' => 1], $json['parameters']);
		$this->assertSame(10, $json['reward']);
		$this->assertSame(20, $json['delay']);
	}

	#[Test]
	#[TestDox('fromArray round-trips a full payload')]
	#[Group('mantle2/custom')]
	public function testFromArrayFull(): void
	{
		$s = QuestStep::fromArray([
			'type' => 'attend_event',
			'description' => 'Go outside',
			'parameters' => ['eventId' => 'e1'],
			'reward' => 5,
			'delay' => 7,
		]);
		$this->assertSame('attend_event', $s->type);
		$this->assertSame('Go outside', $s->description);
		$this->assertSame(['eventId' => 'e1'], $s->parameters);
		$this->assertSame(5, $s->reward);
		$this->assertSame(7, $s->delay);
	}

	#[Test]
	#[TestDox('fromArray applies defaults for missing optional keys')]
	#[Group('mantle2/custom')]
	public function testFromArrayDefaults(): void
	{
		$s = QuestStep::fromArray(['type' => 't', 'description' => 'd']);
		$this->assertSame([], $s->parameters);
		$this->assertSame(0, $s->reward);
		$this->assertSame(0, $s->delay);
	}

	#[Test]
	#[TestDox('fromArray output serializes identically to a direct construction')]
	#[Group('mantle2/custom')]
	public function testFromArrayRoundTrip(): void
	{
		$data = [
			'type' => 't',
			'description' => 'd',
			'parameters' => ['x' => 'y'],
			'reward' => 3,
			'delay' => 9,
		];
		$this->assertSame($data, QuestStep::fromArray($data)->jsonSerialize());
	}
}
