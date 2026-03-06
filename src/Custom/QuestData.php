<?php

namespace Drupal\mantle2\Custom;

use JsonSerializable;

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
		?Quest $quest,
		?string $questId,
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
			isset($data['quest']) ? Quest::fromArray($data['quest']) : null,
			$data['questId'] ?? null,
			$data['currentStep'] ?? null,
			$data['currentStepIndex'] ?? 0,
			$data['completed'] ?? false,
			array_map(fn($entry) => QuestProgressEntry::fromArray($entry), $data['progress'] ?? []),
		);
	}
}
