<?php

namespace Drupal\mantle2\Custom;

use JsonSerializable;

class QuestStep implements JsonSerializable
{
	public string $type;
	public string $description;
	public array $parameters = [];
	public int $reward = 0;
	public int $delay = 0;

	public function __construct(
		string $type,
		string $description,
		array $parameters = [],
		int $reward = 0,
		int $delay = 0,
	) {
		$this->type = $type;
		$this->description = $description;
		$this->parameters = $parameters;
		$this->reward = $reward;
		$this->delay = $delay;
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'description' => $this->description,
			'parameters' => $this->parameters,
			'reward' => $this->reward,
			'delay' => $this->delay,
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['type'],
			$data['description'],
			$data['parameters'] ?? [],
			$data['reward'] ?? 0,
			$data['delay'] ?? 0,
		);
	}
}
