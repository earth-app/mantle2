<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\QuestProgressEntry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class QuestProgressEntryTest extends TestCase
{
	#[Test]
	#[TestDox('The constructor is private; instances come from the static factories')]
	#[Group('mantle2/custom')]
	public function testPrivateConstructor(): void
	{
		$ctor = new ReflectionClass(QuestProgressEntry::class)->getConstructor();
		$this->assertNotNull($ctor);
		$this->assertTrue($ctor->isPrivate());
	}

	#[Test]
	#[TestDox('photo() sets type, r2Key, lat, lng and leaves other fields default')]
	#[Group('mantle2/custom')]
	public function testPhotoFactory(): void
	{
		$e = QuestProgressEntry::photo('take_photo_location', 'r2/abc', 37.5, -122.1, 2, 1, 999);
		$this->assertSame('take_photo_location', $e->type);
		$this->assertSame('r2/abc', $e->r2Key);
		$this->assertSame(37.5, $e->lat);
		$this->assertSame(-122.1, $e->lng);
		$this->assertSame(2, $e->index);
		$this->assertSame(1, $e->altIndex);
		$this->assertSame(999, $e->submittedAt);

		// unrelated fields stay at their defaults
		$this->assertSame('', $e->eventId);
		$this->assertSame(0, $e->timestamp);
		$this->assertSame('', $e->scoreKey);
		$this->assertSame(0, $e->score);
		$this->assertSame('', $e->text);
		$this->assertSame(0, $e->duration);
	}

	#[Test]
	#[TestDox('photo() defaults index, altIndex, and submittedAt to zero')]
	#[Group('mantle2/custom')]
	public function testPhotoFactoryDefaults(): void
	{
		$e = QuestProgressEntry::photo('draw_picture', 'k', 1.0, 2.0);
		$this->assertSame(0, $e->index);
		$this->assertSame(0, $e->altIndex);
		$this->assertSame(0, $e->submittedAt);
	}

	#[Test]
	#[TestDox('articleQuiz() hardcodes the type and sets scoreKey and score')]
	#[Group('mantle2/custom')]
	public function testArticleQuizFactory(): void
	{
		$e = QuestProgressEntry::articleQuiz('scores/q1', 88, 3, 1, 555);
		$this->assertSame('article_quiz', $e->type);
		$this->assertSame('scores/q1', $e->scoreKey);
		$this->assertSame(88, $e->score);
		$this->assertSame(3, $e->index);
		$this->assertSame(1, $e->altIndex);
		$this->assertSame(555, $e->submittedAt);

		$this->assertSame('', $e->r2Key);
		$this->assertSame(0.0, $e->lat);
		$this->assertSame(0.0, $e->lng);
		$this->assertSame('', $e->eventId);
	}

	#[Test]
	#[TestDox('attendEvent() hardcodes the type and sets eventId and timestamp')]
	#[Group('mantle2/custom')]
	public function testAttendEventFactory(): void
	{
		$e = QuestProgressEntry::attendEvent('evt_9', 1717000000, 4, 2, 777);
		$this->assertSame('attend_event', $e->type);
		$this->assertSame('evt_9', $e->eventId);
		$this->assertSame(1717000000, $e->timestamp);
		$this->assertSame(4, $e->index);
		$this->assertSame(2, $e->altIndex);
		$this->assertSame(777, $e->submittedAt);

		$this->assertSame('', $e->r2Key);
		$this->assertSame('', $e->scoreKey);
		$this->assertSame(0, $e->score);
	}

	#[Test]
	#[TestDox('fromArray populates every field from a full payload')]
	#[Group('mantle2/custom')]
	public function testFromArrayFull(): void
	{
		$e = QuestProgressEntry::fromArray([
			'type' => 'describe_text',
			'index' => 1,
			'altIndex' => 2,
			'submittedAt' => 123,
			'r2Key' => 'r2',
			'lat' => 10.5,
			'lng' => 20.5,
			'eventId' => 'e',
			'timestamp' => 456,
			'scoreKey' => 'sk',
			'score' => 7,
			'text' => 'hello',
			'duration' => 60,
		]);

		$this->assertSame('describe_text', $e->type);
		$this->assertSame(1, $e->index);
		$this->assertSame(2, $e->altIndex);
		$this->assertSame(123, $e->submittedAt);
		$this->assertSame('r2', $e->r2Key);
		$this->assertSame(10.5, $e->lat);
		$this->assertSame(20.5, $e->lng);
		$this->assertSame('e', $e->eventId);
		$this->assertSame(456, $e->timestamp);
		$this->assertSame('sk', $e->scoreKey);
		$this->assertSame(7, $e->score);
		$this->assertSame('hello', $e->text);
		$this->assertSame(60, $e->duration);
	}

	#[Test]
	#[TestDox('fromArray applies defaults for every optional field')]
	#[Group('mantle2/custom')]
	public function testFromArrayDefaults(): void
	{
		$e = QuestProgressEntry::fromArray(['type' => 'respond_to_prompt']);
		$this->assertSame('respond_to_prompt', $e->type);
		$this->assertSame(0, $e->index);
		$this->assertSame(0, $e->altIndex);
		$this->assertSame(0, $e->submittedAt);
		$this->assertSame('', $e->r2Key);
		$this->assertSame(0.0, $e->lat);
		$this->assertSame(0.0, $e->lng);
		$this->assertSame('', $e->eventId);
		$this->assertSame(0, $e->timestamp);
		$this->assertSame('', $e->scoreKey);
		$this->assertSame(0, $e->score);
		$this->assertSame('', $e->text);
		$this->assertSame(0, $e->duration);
	}

	#[Test]
	#[TestDox('jsonSerialize emits all thirteen keys in declared order')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$json = QuestProgressEntry::photo('take_photo', 'r2', 1.0, 2.0, 5, 6, 7)->jsonSerialize();
		$this->assertSame(
			[
				'type',
				'index',
				'altIndex',
				'submittedAt',
				'r2Key',
				'lat',
				'lng',
				'eventId',
				'timestamp',
				'scoreKey',
				'score',
				'text',
				'duration',
			],
			array_keys($json),
		);
		$this->assertSame('take_photo', $json['type']);
		$this->assertSame(5, $json['index']);
		$this->assertSame('r2', $json['r2Key']);
	}

	#[Test]
	#[TestDox('fromArray then jsonSerialize round-trips a full entry')]
	#[Group('mantle2/custom')]
	public function testFromArrayRoundTrip(): void
	{
		$data = [
			'type' => 'article_quiz',
			'index' => 1,
			'altIndex' => 0,
			'submittedAt' => 10,
			'r2Key' => '',
			'lat' => 0.0,
			'lng' => 0.0,
			'eventId' => '',
			'timestamp' => 0,
			'scoreKey' => 'sk',
			'score' => 90,
			'text' => '',
			'duration' => 0,
		];
		$this->assertSame($data, QuestProgressEntry::fromArray($data)->jsonSerialize());
	}
}
