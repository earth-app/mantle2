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
