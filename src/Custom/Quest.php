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

class QuestData implements JsonSerializable
{
	/** @var (QuestProgressEntry[]|QuestProgressEntry)[] */
	public array $progress = []; // array of QuestProgressEntry
	public ?Quest $quest;
	public ?string $questId;
	/** @var QuestStep[]|QuestStep|null */
	public mixed $currentStep;
	public int $currentStepIndex = 0;
	public bool $completed = false;

	public function __construct(
		Quest $quest,
		string $questId,
		mixed $currentStep,
		int $currentStepIndex = 0,
		bool $completed = false,
		array $progress = [],
	) {
		$this->quest = $quest;
		$this->questId = $questId;
		$this->currentStep = $currentStep;
		$this->currentStepIndex = $currentStepIndex;
		$this->completed = $completed;
		$this->progress = $progress;
	}

	public function jsonSerialize(): array
	{
		return [
			'quest' => $this->quest,
			'questId' => $this->questId,
			'currentStep' => $this->currentStep,
			'currentStepIndex' => $this->currentStepIndex,
			'completed' => $this->completed,
			'progress' => $this->progress,
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			Quest::fromArray($data['quest']),
			$data['questId'],
			$data['currentStep'],
			$data['currentStepIndex'] ?? 0,
			$data['completed'] ?? false,
			array_map(fn($entry) => QuestProgressEntry::fromArray($entry), $data['progress'] ?? []),
		);
	}
}

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
