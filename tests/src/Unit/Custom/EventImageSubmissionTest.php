<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\CriterionBreakdown;
use Drupal\mantle2\Custom\EventImageSubmission;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class EventImageSubmissionTest extends TestCase
{
	#[Test]
	#[TestDox('Constructor maps positional args onto public fields')]
	#[Group('mantle2/custom')]
	public function testConstruct(): void
	{
		$breakdown = [new CriterionBreakdown('c', 0.5, 0.6, 0.1)];
		$s = new EventImageSubmission(
			'sub_1',
			'evt_1',
			'user_1',
			'data:image/png;base64,AAAA',
			1736400000,
			'a caption',
			'2025-05-11T10:00:00Z',
			0.75,
			$breakdown,
		);

		$this->assertSame('sub_1', $s->submission_id);
		$this->assertSame('evt_1', $s->event_id);
		$this->assertSame('user_1', $s->user_id);
		$this->assertSame('data:image/png;base64,AAAA', $s->imageUrl);
		$this->assertSame(1736400000, $s->timestamp);
		$this->assertSame('a caption', $s->caption);
		$this->assertSame('2025-05-11T10:00:00Z', $s->scored_at);
		$this->assertSame(0.75, $s->score);
		$this->assertSame($breakdown, $s->breakdown);
	}

	#[Test]
	#[TestDox('score and breakdown default to 0.0 and empty')]
	#[Group('mantle2/custom')]
	public function testDefaults(): void
	{
		$s = new EventImageSubmission('s', 'e', 'u', 'img', 0, 'c', 'when');
		$this->assertSame(0.0, $s->score);
		$this->assertSame([], $s->breakdown);
	}

	#[Test]
	#[TestDox('jsonSerialize nests score and breakdown and renames imageUrl to image')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$breakdown = [new CriterionBreakdown('c', 0.5, 0.6, 0.1)];
		$s = new EventImageSubmission(
			'sub_1',
			'evt_1',
			'user_1',
			'img',
			1736400000,
			'cap',
			'when',
			0.75,
			$breakdown,
		);
		$json = $s->jsonSerialize();

		$this->assertSame(
			[
				'submission_id',
				'event_id',
				'user_id',
				'image',
				'timestamp',
				'caption',
				'scored_at',
				'score',
			],
			array_keys($json),
		);
		$this->assertSame('img', $json['image']);
		$this->assertSame(0.75, $json['score']['score']);
		$this->assertSame($breakdown, $json['score']['breakdown']);
	}

	#[Test]
	#[TestDox('fromArray reads nested score, maps weighted onto weight, and round-trips')]
	#[Group('mantle2/custom')]
	public function testFromArray(): void
	{
		$s = EventImageSubmission::fromArray([
			'submission_id' => 'sub_1',
			'event_id' => 'evt_1',
			'user_id' => 'user_1',
			'image' => 'img',
			'timestamp' => 1736400000,
			'caption' => 'cap',
			'scored_at' => 'when',
			'score' => [
				'score' => 0.75,
				'breakdown' => [
					[
						'id' => 'creativity',
						'similarity' => 0.8,
						'normalized' => 0.9,
						'weighted' => 0.15,
					],
				],
			],
		]);

		$this->assertSame('sub_1', $s->submission_id);
		$this->assertSame('img', $s->imageUrl);
		$this->assertSame(0.75, $s->score);
		$this->assertCount(1, $s->breakdown);
		$this->assertInstanceOf(CriterionBreakdown::class, $s->breakdown[0]);
		$this->assertSame('creativity', $s->breakdown[0]->id);
		$this->assertSame(0.8, $s->breakdown[0]->similarity);
		$this->assertSame(0.9, $s->breakdown[0]->normalized);
		// fromArray reads the 'weighted' key into the weight field
		$this->assertSame(0.15, $s->breakdown[0]->weight);
	}

	#[Test]
	#[TestDox('fromArray falls back to safe defaults for missing optional keys')]
	#[Group('mantle2/custom')]
	public function testFromArrayDefaults(): void
	{
		$s = EventImageSubmission::fromArray([
			'submission_id' => 'sub_1',
			'event_id' => 'evt_1',
			'user_id' => 'user_1',
		]);

		$this->assertSame('', $s->imageUrl);
		$this->assertSame(0, $s->timestamp);
		$this->assertSame('', $s->caption);
		$this->assertSame('', $s->scored_at);
		$this->assertSame(0.0, $s->score);
		$this->assertSame([], $s->breakdown);
	}
}
