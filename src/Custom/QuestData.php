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
		$currentStep = self::parseCurrentStep($data['currentStep'] ?? null);

		// Process progress entries - can be either single entries or arrays of entries (for alt steps)
		$progressEntries = [];
		foreach ($data['progress'] ?? [] as $entry) {
			if (is_array($entry)) {
				// Check if this is an array of entries (alternative steps) or a single entry
				if (!empty($entry) && is_array($entry[0] ?? null) && isset($entry[0]['type'])) {
					// This is an array of entries (alternative steps)
					$progressEntries[] = array_map(
						fn($altEntry) => QuestProgressEntry::fromArray($altEntry),
						$entry,
					);
				} elseif (isset($entry['type'])) {
					// This is a single entry object
					$progressEntries[] = QuestProgressEntry::fromArray($entry);
				}
			}
		}

		return new self(
			isset($data['quest']) ? Quest::fromArray($data['quest']) : null,
			$data['questId'] ?? null,
			$currentStep,
			$data['currentStepIndex'] ?? 0,
			$data['completed'] ?? false,
			$progressEntries,
		);
	}

	private static function parseCurrentStep(mixed $step): mixed
	{
		if (!is_array($step)) {
			return null;
		}

		if (
			isset($step['type']) &&
			is_string($step['type']) &&
			isset($step['description']) &&
			is_string($step['description'])
		) {
			return QuestStep::fromArray($step);
		}

		if (!array_is_list($step)) {
			return null;
		}

		$alternatives = [];
		foreach ($step as $altStep) {
			if (
				!is_array($altStep) ||
				!isset($altStep['type']) ||
				!is_string($altStep['type']) ||
				!isset($altStep['description']) ||
				!is_string($altStep['description'])
			) {
				continue;
			}

			$alternatives[] = QuestStep::fromArray($altStep);
		}

		return empty($alternatives) ? null : $alternatives;
	}
}
