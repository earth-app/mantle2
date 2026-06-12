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

	// photo submission types (take_photo_classification, take_photo_location, draw_picture, etc.) + audio (transcribe_audio)
	public string $r2Key = '';
	public float $lat = 0.0;
	public float $lng = 0.0;

	// attend_event
	public string $eventId = '';
	public int $timestamp = 0; // unix timestamp of when event was attended

	// article_quiz
	public string $scoreKey = '';
	public int $score = 0;

	// describe_text, respond_to_prompt
	public string $text = '';

	// article_read_time, activity_read_time
	public int $duration = 0; // in seconds

	private function __construct(
		string $type,
		int $index = 0,
		int $altIndex = 0,
		int $submittedAt = 0,
		string $r2Key = '',
		float $lat = 0.0,
		float $lng = 0.0,
		string $eventId = '',
		int $timestamp = 0,
		string $scoreKey = '',
		int $score = 0,
		string $text = '',
		int $duration = 0,
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
		$this->text = $text;
		$this->duration = $duration;
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
			'text' => $this->text,
			'duration' => $this->duration,
		];
	}

	public static function photo(
		string $type,
		string $r2Key,
		float $lat,
		float $lng,
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
			$data['text'] ?? '',
			$data['duration'] ?? 0,
		);
	}
}
