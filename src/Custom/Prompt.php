<?php

namespace Drupal\mantle2\Custom;

use Drupal\mantle2\Custom\Visibility;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use JsonSerializable;

class Prompt implements JsonSerializable
{
	private string $prompt;
	private int $ownerId;
	private Visibility $visibility;

	public function __construct(string $prompt, int $ownerId, Visibility $visibility)
	{
		$this->prompt = $prompt;
		$this->ownerId = $ownerId;
		$this->visibility = $visibility;
	}

	public function jsonSerialize(): array
	{
		return [
			'prompt' => $this->prompt,
			'ownerId' => $this->ownerId,
			'visibility' => $this->visibility->value,
		];
	}

	public function getPrompt(): string
	{
		return $this->prompt;
	}
	public function setPrompt(string $prompt): void
	{
		$this->prompt = $prompt;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	public function getOwner(): UserInterface
	{
		return User::load($this->ownerId);
	}

	public function getVisibility(): Visibility
	{
		return $this->visibility;
	}
	public function setVisibility(Visibility $visibility): void
	{
		$this->visibility = $visibility;
	}
}
