<?php

namespace Drupal\mantle2\Custom;

use JsonSerializable;

class CriterionBreakdown implements JsonSerializable
{
	public string $id;
	public float $similarity; // -1.0 - 1.0 (cosine similarity)
	public float $normalized; // 0.0 - 1.0
	public float $weight; // 0.0 - 1.0

	public function __construct(string $id, float $similarity, float $normalized, float $weight)
	{
		$this->id = $id;
		$this->similarity = $similarity;
		$this->normalized = $normalized;
		$this->weight = $weight;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'similarity' => $this->similarity,
			'normalized' => $this->normalized,
			'weight' => $this->weight,
		];
	}
}

class EventImageSubmission implements JsonSerializable
{
	public string $submission_id;
	public string $imageUrl; // data url of image
	public string $caption;
	public string $scored_at; // ISO 8601 format
	public int $timestamp; // unix timestamp of when the image was scored
	public string $user_id;
	public string $event_id; // event id

	public float $score = 0.0; // 0.0 - 1.0
	/** @var CriterionBreakdown[] */
	public array $breakdown = [];

	public function __construct(
		string $submission_id,
		string $event_id,
		string $user_id,
		string $imageUrl,
		int $timestamp,
		string $caption,
		string $scored_at,
		float $score = 0.0,
		array $breakdown = [],
	) {
		$this->submission_id = $submission_id;
		$this->event_id = $event_id;
		$this->user_id = $user_id;
		$this->imageUrl = $imageUrl;
		$this->timestamp = $timestamp;
		$this->caption = $caption;
		$this->scored_at = $scored_at;
		$this->score = $score;
		$this->breakdown = $breakdown;
	}

	public function jsonSerialize(): array
	{
		return [
			'submission_id' => $this->submission_id,
			'event_id' => $this->event_id,
			'user_id' => $this->user_id,
			'image' => $this->imageUrl,
			'timestamp' => $this->timestamp,
			'caption' => $this->caption,
			'scored_at' => $this->scored_at,
			'score' => [
				'score' => $this->score,
				'breakdown' => $this->breakdown,
			],
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['submission_id'],
			$data['event_id'],
			$data['user_id'],
			$data['image'] ?? '', // fallback to empty string if image is missing
			$data['timestamp'] ?? 0,
			$data['caption'] ?? '',
			$data['scored_at'] ?? '',
			$data['score']['score'] ?? 0.0,
			array_map(
				fn($item) => new CriterionBreakdown(
					$item['id'] ?? '',
					$item['similarity'] ?? 0.0,
					$item['normalized'] ?? 0.0,
					$item['weighted'] ?? 0.0,
				),
				$data['score']['breakdown'] ?? [],
			),
		);
	}
}
