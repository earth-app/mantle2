<?php

namespace Drupal\mantle2\Custom;

use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use JsonSerializable;

class Prompt implements JsonSerializable
{
	private int $id;
	private string $prompt;
	private int $ownerId;
	private Visibility $visibility;

	public function __construct(int $id, string $prompt, int $ownerId, Visibility $visibility)
	{
		$this->id = $id;
		$this->prompt = $prompt;
		$this->ownerId = $ownerId;
		$this->visibility = $visibility;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => GeneralHelper::formatId($this->id),
			'prompt' => $this->prompt,
			'owner_id' => GeneralHelper::formatId($this->ownerId),
			'visibility' => $this->visibility->value,
		];
	}

	public function getId(): int
	{
		return $this->id;
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
