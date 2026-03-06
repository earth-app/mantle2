<?php

namespace Drupal\mantle2\Custom;

use JsonSerializable;

class QuestProgressEntry implements JsonSerializable
{
	// global properties
	public string $type;
	public int $index = 0; // for tracking progress of multi-step quests
	public int $altIndex = 0; // for tracking progress of OR conditions (e.g. complete step 1 OR step 2)
	public int $submittedAt; // unix ms timestamp of when the step was completed

	// photo submission types (take_photo_classification, take_photo_location, draw_picture, etc)
	public string $r2Key = '';
	public int $lat = 0;
	public int $lng = 0;

	// attend_event
	public string $eventId = '';
	public int $timestamp = 0; // unix timestamp of when event was attended

	// article_quiz
	public string $scoreKey = '';
	public int $score = 0;

	private function __construct(
		string $type,
		int $index = 0,
		int $altIndex = 0,
		int $submittedAt = 0,
		string $r2Key = '',
		int $lat = 0,
		int $lng = 0,
		string $eventId = '',
		int $timestamp = 0,
		string $scoreKey = '',
		int $score = 0,
	) {
		$this->type = $type;
		$this->index = $index;
		$this->altIndex = $altIndex;
		$this->submittedAt = $submittedAt;
		$this->r2Key = $r2Key;
		$this->lat = $lat;
		$this->lng = $lng;
		$this->eventId = $eventId;
		$this->timestamp = $timestamp;
		$this->scoreKey = $scoreKey;
		$this->score = $score;
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'index' => $this->index,
			'altIndex' => $this->altIndex,
			'submittedAt' => $this->submittedAt,
			'r2Key' => $this->r2Key,
			'lat' => $this->lat,
			'lng' => $this->lng,
			'eventId' => $this->eventId,
			'timestamp' => $this->timestamp,
			'scoreKey' => $this->scoreKey,
			'score' => $this->score,
		];
	}

	public static function photo(
		string $type,
		string $r2Key,
		int $lat,
		int $lng,
		int $index = 0,
		int $altIndex = 0,
		int $submittedAt = 0,
	): self {
		return new self($type, $index, $altIndex, $submittedAt, $r2Key, $lat, $lng);
	}

	public static function articleQuiz(
		string $scoreKey,
		int $score,
		int $index = 0,
		int $altIndex = 0,
		int $submittedAt = 0,
	): self {
		return new self(
			'article_quiz',
			$index,
			$altIndex,
			$submittedAt,
			'',
			0,
			0,
			'',
			0,
			$scoreKey,
			$score,
		);
	}

	public static function attendEvent(
		string $eventId,
		int $timestamp,
		int $index = 0,
		int $altIndex = 0,
		int $submittedAt = 0,
	): self {
		return new self(
			'attend_event',
			$index,
			$altIndex,
			$submittedAt,
			'',
			0,
			0,
			$eventId,
			$timestamp,
		);
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['type'],
			$data['index'] ?? 0,
			$data['altIndex'] ?? 0,
			$data['submittedAt'] ?? 0,
			$data['r2Key'] ?? '',
			$data['lat'] ?? 0,
			$data['lng'] ?? 0,
			$data['eventId'] ?? '',
			$data['timestamp'] ?? 0,
			$data['scoreKey'] ?? '',
			$data['score'] ?? 0,
		);
	}
}
