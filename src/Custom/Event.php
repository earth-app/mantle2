<?php

namespace Drupal\mantle2\Custom;

use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use JsonSerializable;

class Event implements JsonSerializable
{
	private int $hostId;
	private string $name;
	private string $description;
	private EventType $type;
	/** @var ActivityType[] $activities */
	private array $activities;
	private float $latitude;
	private float $longitude;
	private int $date;
	private ?int $endDate = null;
	private Visibility $visibility;
	/** @var int[] $attendees */
	private array $attendees = [];
	private array $fields = [];

	public function __construct(
		int $hostId,
		string $name,
		string $description,
		EventType $type,
		array $activities,
		float $latitude,
		float $longitude,
		int $date,
		?int $endDate = null,
		Visibility $visibility = Visibility::PUBLIC,
		array $attendees = [],
		array $fields = [],
	) {
		$this->hostId = $hostId;
		$this->name = $name;
		$this->description = $description;
		$this->type = $type;
		$this->activities = $activities;
		$this->latitude = $latitude;
		$this->longitude = $longitude;
		$this->date = $date;
		$this->endDate = $endDate;
		$this->visibility = $visibility;
		$this->attendees = $attendees;
		$this->fields = $fields;
	}

	public function jsonSerialize(): array
	{
		return [
			'hostId' => GeneralHelper::formatId($this->hostId),
			'name' => $this->name,
			'description' => $this->description,
			'type' => $this->type->value,
			'activities' => array_map(
				fn(ActivityType $activity) => $activity->value,
				$this->activities,
			),
			'location' => [
				'latitude' => $this->latitude,
				'longitude' => $this->longitude,
			],
			'date' => $this->date,
			'end_date' => $this->endDate,
			'attendee_count' => $this->getAttendeesCount(),
			'visibility' => $this->visibility->value,
			'fields' => $this->fields,
		];
	}

	public function getHost(): UserInterface
	{
		return User::load($this->hostId);
	}

	public function getHostId(): int
	{
		return $this->hostId;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function setDescription(string $description): void
	{
		$this->description = $description;
	}

	public function getType(): EventType
	{
		return $this->type;
	}

	public function setType(EventType $type): void
	{
		$this->type = $type;
	}

	/**
	 * @return array<ActivityType>
	 */
	public function getActivityTypes(): array
	{
		return $this->activities;
	}

	public function setActivityTypes(array $activities): void
	{
		$this->activities = $activities;
	}

	public function getLatitude(): float
	{
		return $this->latitude;
	}

	public function setLatitude(float $latitude): void
	{
		$this->latitude = $latitude;
	}

	public function getLongitude(): float
	{
		return $this->longitude;
	}

	public function setLongitude(float $longitude): void
	{
		$this->longitude = $longitude;
	}

	public function getDate(): string
	{
		$timestamp = (int) floor($this->date / 1000);

		$datetime = new \DateTime('@' . $timestamp);
		$datetime->setTimezone(new \DateTimeZone('UTC'));

		// Format as ISO 8601 without timezone (e.g. "2023-10-05T14:48:00")
		return $datetime->format('Y-m-d\TH:i:s');
	}

	public function getRawDate(): int
	{
		return $this->date;
	}

	public function setDate(int $date): void
	{
		$this->date = $date;
	}

	public function getEndDate(): ?string
	{
		if ($this->endDate === null) {
			return null;
		}
		$timestamp = (int) floor($this->endDate / 1000);

		$datetime = new \DateTime('@' . $timestamp);
		$datetime->setTimezone(new \DateTimeZone('UTC'));

		// Format as ISO 8601 without timezone (e.g. "2023-10-05T14:48:00")
		return $datetime->format('Y-m-d\TH:i:s');
	}

	public function getRawEndDate(): ?int
	{
		return $this->endDate;
	}

	public function setEndDate(?int $endDate): void
	{
		$this->endDate = $endDate;
	}

	public function getVisibility(): Visibility
	{
		return $this->visibility;
	}

	public function setVisibility(Visibility $visibility): void
	{
		$this->visibility = $visibility;
	}

	/**
	 * @return array<int>
	 */
	public function getAttendeeIds(): array
	{
		return $this->attendees;
	}

	/**
	 * @return array<UserInterface>
	 */
	public function getAttendees(): array
	{
		return User::loadMultiple($this->attendees) + [$this->getHost()];
	}

	public function getAttendeesCount(): int
	{
		return count($this->attendees) + 1; // +1 for host
	}

	public function isAttendee(int $userId): bool
	{
		return in_array($userId, $this->attendees) || $userId === $this->hostId;
	}

	public function addAttendee(int $userId): void
	{
		if (!in_array($userId, $this->attendees)) {
			$this->attendees[] = $userId;
		}
	}

	public function removeAttendee(int $userId): void
	{
		$this->attendees = array_filter($this->attendees, fn(int $id) => $id !== $userId);
	}

	public function getFields(): array
	{
		return $this->fields;
	}

	public function setFields(array $fields): void
	{
		$this->fields = $fields;
	}
}
