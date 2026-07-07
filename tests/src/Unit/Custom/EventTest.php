<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\GeneralHelper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
	private function make(array $activities = [], array $attendees = []): Event
	{
		return new Event(
			10,
			'Beach Cleanup',
			'Clean the beach',
			EventType::IN_PERSON,
			$activities,
			37.7749,
			-122.4194,
			1696517280000,
			1696520880000,
			Visibility::PUBLIC,
			$attendees,
			['dress' => 'casual'],
			'evt_1',
		);
	}

	#[Test]
	#[TestDox('Constructor and getters cover every field')]
	#[Group('mantle2/custom')]
	public function testGetters(): void
	{
		$e = $this->make([ActivityType::NATURE], [1, 2, 3]);
		$this->assertSame('evt_1', $e->getId());
		$this->assertSame(10, $e->getHostId());
		$this->assertSame('Beach Cleanup', $e->getName());
		$this->assertSame('Clean the beach', $e->getDescription());
		$this->assertSame(EventType::IN_PERSON, $e->getType());
		$this->assertSame([ActivityType::NATURE], $e->getActivities());
		$this->assertSame(37.7749, $e->getLatitude());
		$this->assertSame(-122.4194, $e->getLongitude());
		$this->assertSame(1696517280000, $e->getRawDate());
		$this->assertSame(1696520880000, $e->getRawEndDate());
		$this->assertSame(Visibility::PUBLIC, $e->getVisibility());
		$this->assertSame([1, 2, 3], $e->getAttendeeIds());
		$this->assertSame(['dress' => 'casual'], $e->getFields());
	}

	#[Test]
	#[TestDox('MAX_ACTIVITIES is 20 and both constructor and setActivities reject overflow')]
	#[Group('mantle2/custom')]
	public function testTooManyActivities(): void
	{
		$this->assertSame(20, Event::MAX_ACTIVITIES);
		$activities = array_fill(0, 21, ActivityType::OTHER);

		$thrown = false;
		try {
			$this->make($activities);
		} catch (InvalidArgumentException) {
			$thrown = true;
		}
		$this->assertTrue($thrown, 'constructor should reject 21 activities');

		$e = $this->make();
		$this->expectException(InvalidArgumentException::class);
		$e->setActivities($activities);
	}

	#[Test]
	#[TestDox('Constructor rejects an activity that is neither Activity nor ActivityType')]
	#[Group('mantle2/custom')]
	public function testInvalidActivityType(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->make(['not-an-activity']);
	}

	#[Test]
	#[TestDox('getDate/getEndDate convert ms epoch to ISO 8601 UTC without timezone suffix')]
	#[Group('mantle2/custom')]
	public function testDateFormatting(): void
	{
		$e = $this->make();
		$expected = new \DateTime('@' . 1696517280)
			->setTimezone(new \DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:s');
		$this->assertSame($expected, $e->getDate());
		$this->assertSame('2023-10-05T14:48:00', $e->getDate());

		$expectedEnd = new \DateTime('@' . 1696520880)
			->setTimezone(new \DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:s');
		$this->assertSame($expectedEnd, $e->getEndDate());
	}

	#[Test]
	#[TestDox('getEndDate returns null when no end date is set')]
	#[Group('mantle2/custom')]
	public function testNullEndDate(): void
	{
		$e = new Event(1, 'n', 'd', EventType::ONLINE, [], 0.0, 0.0, 0, null);
		$this->assertNull($e->getRawEndDate());
		$this->assertNull($e->getEndDate());
		$this->assertSame('1970-01-01T00:00:00', $e->getDate());
	}

	#[Test]
	#[TestDox('hasPassed uses end date when set, else start date')]
	#[Group('mantle2/custom')]
	public function testHasPassed(): void
	{
		$now = time() * 1000;

		// end date in the past => passed
		$pastEnd = new Event(
			1,
			'n',
			'd',
			EventType::ONLINE,
			[],
			0.0,
			0.0,
			$now - 7200000,
			$now - 3600000,
		);
		$this->assertTrue($pastEnd->hasPassed());

		// end date in the future => not passed (even if start already began)
		$ongoing = new Event(
			1,
			'n',
			'd',
			EventType::ONLINE,
			[],
			0.0,
			0.0,
			$now - 3600000,
			$now + 3600000,
		);
		$this->assertFalse($ongoing->hasPassed());

		// no end date, start in the past => passed
		$startedNoEnd = new Event(
			1,
			'n',
			'd',
			EventType::ONLINE,
			[],
			0.0,
			0.0,
			$now - 3600000,
			null,
		);
		$this->assertTrue($startedNoEnd->hasPassed());

		// no end date, start in the future => not passed
		$upcomingNoEnd = new Event(
			1,
			'n',
			'd',
			EventType::ONLINE,
			[],
			0.0,
			0.0,
			$now + 3600000,
			null,
		);
		$this->assertFalse($upcomingNoEnd->hasPassed());
	}

	#[Test]
	#[TestDox('Attendee helpers count host, dedupe adds, and filter removes')]
	#[Group('mantle2/custom')]
	public function testAttendees(): void
	{
		$e = $this->make([], [5, 6]);
		$this->assertSame(3, $e->getAttendeesCount()); // +1 host
		$this->assertTrue($e->isAttendee(5));
		$this->assertTrue($e->isAttendee(10)); // host
		$this->assertFalse($e->isAttendee(99));

		$e->addAttendee(5); // duplicate ignored
		$this->assertSame(3, $e->getAttendeesCount());
		$e->addAttendee(7);
		$this->assertSame(4, $e->getAttendeesCount());
		$this->assertTrue($e->isAttendee(7));

		$e->removeAttendee(5);
		$this->assertFalse($e->isAttendee(5));
	}

	#[Test]
	#[TestDox('Setters mutate name, description, type, dates, visibility, and fields')]
	#[Group('mantle2/custom')]
	public function testSetters(): void
	{
		$e = $this->make();
		$e->setName('New');
		$e->setDescription('nd');
		$e->setType(EventType::HYBRID);
		$e->setLatitude(1.0);
		$e->setLongitude(2.0);
		$e->setDate(0);
		$e->setEndDate(null);
		$e->setVisibility(Visibility::PRIVATE);
		$e->setFields(['x' => 'y']);

		$this->assertSame('New', $e->getName());
		$this->assertSame('nd', $e->getDescription());
		$this->assertSame(EventType::HYBRID, $e->getType());
		$this->assertSame(1.0, $e->getLatitude());
		$this->assertSame(2.0, $e->getLongitude());
		$this->assertSame(0, $e->getRawDate());
		$this->assertNull($e->getRawEndDate());
		$this->assertSame(Visibility::PRIVATE, $e->getVisibility());
		$this->assertSame(['x' => 'y'], $e->getFields());
	}

	#[Test]
	#[TestDox('jsonSerialize emits canonical keys with formatted host id and enum values')]
	#[Group('mantle2/custom')]
	public function testJsonSerializeShape(): void
	{
		$json = $this->make([], [5, 6])->jsonSerialize();

		$this->assertSame(
			[
				'id',
				'hostId',
				'name',
				'description',
				'type',
				'activities',
				'location',
				'date',
				'date_f',
				'end_date',
				'end_date_f',
				'attendee_count',
				'visibility',
				'fields',
			],
			array_keys($json),
		);
		$this->assertSame('evt_1', $json['id']);
		$this->assertSame(GeneralHelper::formatId(10), $json['hostId']);
		$this->assertSame('IN_PERSON', $json['type']);
		$this->assertSame('PUBLIC', $json['visibility']);
		$this->assertSame(['latitude' => 37.7749, 'longitude' => -122.4194], $json['location']);
		$this->assertSame(1696517280000, $json['date']);
		$this->assertSame('2023-10-05T14:48:00', $json['date_f']);
		$this->assertSame(3, $json['attendee_count']);
	}

	#[Test]
	#[TestDox('jsonSerialize renders Activity as activity map and ActivityType as tagged value')]
	#[Group('mantle2/custom')]
	public function testJsonSerializeActivities(): void
	{
		$activity = new Activity('hiking', 'Hiking', ['SPORT']);
		$json = $this->make([$activity, ActivityType::NATURE])->jsonSerialize();

		$this->assertSame('activity', $json['activities'][0]['type']);
		$this->assertSame('hiking', $json['activities'][0]['id']);
		$this->assertSame(['type' => 'activity_type', 'value' => 'NATURE'], $json['activities'][1]);
	}
}
