<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Quest;
use Drupal\mantle2\Custom\QuestData;
use Drupal\mantle2\Custom\QuestProgressEntry;
use Drupal\mantle2\Custom\QuestStep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class QuestDataTest extends TestCase
{
	#[Test]
	#[TestDox('Constructor populates every public field')]
	#[Group('mantle2/custom')]
	public function testConstructor(): void
	{
		$step = new QuestStep('t', 'd');
		$entry = QuestProgressEntry::photo('take_photo', 'k', 1.0, 2.0);
		$d = new QuestData(null, 'q1', $step, 2, true, [$entry]);

		$this->assertNull($d->quest);
		$this->assertSame('q1', $d->questId);
		$this->assertSame($step, $d->currentStep);
		$this->assertSame(2, $d->currentStepIndex);
		$this->assertTrue($d->completed);
		$this->assertSame([$entry], $d->progress);
	}

	#[Test]
	#[TestDox('Optional constructor args default index 0, incomplete, empty progress')]
	#[Group('mantle2/custom')]
	public function testDefaults(): void
	{
		$d = new QuestData(null, null, null);
		$this->assertSame(0, $d->currentStepIndex);
		$this->assertFalse($d->completed);
		$this->assertSame([], $d->progress);
	}

	#[Test]
	#[TestDox('jsonSerialize emits camelCase keys mirroring the fields')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$d = new QuestData(null, 'q1', null, 3, true, []);
		$json = $d->jsonSerialize();
		$this->assertSame(
			['quest', 'questId', 'currentStep', 'currentStepIndex', 'completed', 'progress'],
			array_keys($json),
		);
		$this->assertNull($json['quest']);
		$this->assertSame('q1', $json['questId']);
		$this->assertSame(3, $json['currentStepIndex']);
		$this->assertTrue($json['completed']);
	}

	#[Test]
	#[TestDox('fromArray builds the nested Quest when a quest payload is present')]
	#[Group('mantle2/custom')]
	public function testFromArrayWithQuest(): void
	{
		$d = QuestData::fromArray([
			'quest' => [
				'id' => 'q1',
				'title' => 'T',
				'description' => 'D',
				'icon' => 'i',
			],
			'questId' => 'q1',
			'currentStepIndex' => 1,
			'completed' => false,
		]);

		$this->assertInstanceOf(Quest::class, $d->quest);
		$this->assertSame('q1', $d->quest->id);
		$this->assertSame('q1', $d->questId);
		$this->assertSame(1, $d->currentStepIndex);
		$this->assertFalse($d->completed);
	}

	#[Test]
	#[TestDox('fromArray leaves quest null and applies scalar defaults when omitted')]
	#[Group('mantle2/custom')]
	public function testFromArrayDefaults(): void
	{
		$d = QuestData::fromArray([]);
		$this->assertNull($d->quest);
		$this->assertNull($d->questId);
		$this->assertNull($d->currentStep);
		$this->assertSame(0, $d->currentStepIndex);
		$this->assertFalse($d->completed);
		$this->assertSame([], $d->progress);
	}

	#[Test]
	#[TestDox('fromArray parses a single-object currentStep into a QuestStep')]
	#[Group('mantle2/custom')]
	public function testFromArraySingleCurrentStep(): void
	{
		$d = QuestData::fromArray([
			'currentStep' => ['type' => 'take_photo', 'description' => 'snap it', 'reward' => 5],
		]);
		$this->assertInstanceOf(QuestStep::class, $d->currentStep);
		$this->assertSame('take_photo', $d->currentStep->type);
		$this->assertSame(5, $d->currentStep->reward);
	}

	#[Test]
	#[TestDox('fromArray parses a list currentStep into an array of QuestStep alternatives')]
	#[Group('mantle2/custom')]
	public function testFromArrayAlternativeCurrentStep(): void
	{
		$d = QuestData::fromArray([
			'currentStep' => [
				['type' => 'a', 'description' => 'alt a'],
				['type' => 'b', 'description' => 'alt b'],
			],
		]);
		$this->assertIsArray($d->currentStep);
		$this->assertCount(2, $d->currentStep);
		$this->assertInstanceOf(QuestStep::class, $d->currentStep[0]);
		$this->assertSame('a', $d->currentStep[0]->type);
		$this->assertSame('b', $d->currentStep[1]->type);
	}

	#[Test]
	#[TestDox('fromArray drops malformed alternatives and nulls a currentStep with none valid')]
	#[Group('mantle2/custom')]
	public function testFromArrayMalformedAlternativesDropped(): void
	{
		$d = QuestData::fromArray([
			'currentStep' => [
				['type' => 'good', 'description' => 'keep me'],
				['type' => 'missing_desc'],
				['description' => 'missing type'],
			],
		]);
		$this->assertIsArray($d->currentStep);
		$this->assertCount(1, $d->currentStep);
		$this->assertSame('good', $d->currentStep[0]->type);

		$empty = QuestData::fromArray([
			'currentStep' => [['type' => 'no_desc'], ['description' => 'no_type']],
		]);
		$this->assertNull($empty->currentStep);
	}

	#[Test]
	#[TestDox('fromArray returns null currentStep for non-array input')]
	#[Group('mantle2/custom')]
	public function testFromArrayScalarCurrentStepIsNull(): void
	{
		$this->assertNull(QuestData::fromArray(['currentStep' => 'not-an-array'])->currentStep);
		$this->assertNull(QuestData::fromArray(['currentStep' => 42])->currentStep);
	}

	#[Test]
	#[TestDox('fromArray decodes single progress entries into QuestProgressEntry objects')]
	#[Group('mantle2/custom')]
	public function testFromArraySingleProgressEntries(): void
	{
		$d = QuestData::fromArray([
			'progress' => [
				['type' => 'take_photo', 'r2Key' => 'k1', 'index' => 0],
				['type' => 'article_quiz', 'scoreKey' => 's1', 'score' => 90],
			],
		]);

		$this->assertCount(2, $d->progress);
		$this->assertInstanceOf(QuestProgressEntry::class, $d->progress[0]);
		$this->assertSame('take_photo', $d->progress[0]->type);
		$this->assertSame('k1', $d->progress[0]->r2Key);
		$this->assertInstanceOf(QuestProgressEntry::class, $d->progress[1]);
		$this->assertSame(90, $d->progress[1]->score);
	}

	#[Test]
	#[TestDox('fromArray decodes an alt-step progress group into an array of entries')]
	#[Group('mantle2/custom')]
	public function testFromArrayAlternativeProgressEntries(): void
	{
		$d = QuestData::fromArray([
			'progress' => [
				['type' => 'take_photo', 'r2Key' => 'single'],
				[['type' => 'alt_a', 'text' => 'ta'], ['type' => 'alt_b', 'text' => 'tb']],
			],
		]);

		$this->assertCount(2, $d->progress);

		$this->assertInstanceOf(QuestProgressEntry::class, $d->progress[0]);
		$this->assertSame('single', $d->progress[0]->r2Key);

		$this->assertIsArray($d->progress[1]);
		$this->assertCount(2, $d->progress[1]);
		$this->assertInstanceOf(QuestProgressEntry::class, $d->progress[1][0]);
		$this->assertSame('alt_a', $d->progress[1][0]->type);
		$this->assertSame('tb', $d->progress[1][1]->text);
	}

	#[Test]
	#[TestDox('fromArray skips progress entries that are not arrays or lack a type')]
	#[Group('mantle2/custom')]
	public function testFromArraySkipsInvalidProgress(): void
	{
		$d = QuestData::fromArray([
			'progress' => ['not-an-array', ['no_type' => true], ['type' => 'valid', 'index' => 1]],
		]);

		$this->assertCount(1, $d->progress);
		$this->assertInstanceOf(QuestProgressEntry::class, $d->progress[0]);
		$this->assertSame('valid', $d->progress[0]->type);
	}
}
