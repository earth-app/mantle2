<?php

namespace Drupal\mantle2\Custom;

use JsonSerializable;

class Quest implements JsonSerializable
{
	public string $id;
	public string $title;
	public string $description;
	public string $icon;
	public string $rarity; // normal, rare, amazing, green
	public bool $mobileOnly = false;
	/** @var (QuestStep[]|QuestStep)[] $steps */
	public array $steps = []; // either array of steps or 2D array for OR conditions (e.g. complete step 1 OR step 2)
	public int $reward = 0;
	/** @var string[] $permissions */
	public array $permissions = [];

	public function __construct(
		string $id,
		string $title,
		string $description,
		string $icon,
		string $rarity,
		array $steps,
		int $reward = 0,
		array $permissions = [],
		bool $mobileOnly = false,
	) {
		$this->id = $id;
		$this->title = $title;
		$this->description = $description;
		$this->icon = $icon;
		$this->rarity = $rarity;
		$this->steps = $steps;
		$this->reward = $reward;
		$this->permissions = $permissions;
		$this->mobileOnly = $mobileOnly;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description,
			'icon' => $this->icon,
			'rarity' => $this->rarity,
			'mobile_only' => $this->mobileOnly,
			'steps' => $this->steps,
			'reward' => $this->reward,
			'permissions' => $this->permissions,
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['id'],
			$data['title'],
			$data['description'],
			$data['icon'],
			$data['rarity'] ?? 'normal',
			array_map(
				fn($step) => is_array($step) && isset($step[0])
					? array_map(fn($s) => QuestStep::fromArray($s), $step)
					: QuestStep::fromArray($step),
				$data['steps'] ?? [],
			),
			$data['reward'] ?? 0,
			$data['permissions'] ?? [],
			$data['mobile_only'] ?? false,
		);
	}
}

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
