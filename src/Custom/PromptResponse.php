<?php

namespace Drupal\mantle2\Custom;

use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use JsonSerializable;

class PromptResponse implements JsonSerializable
{
	private int $promptId;
	private string $response;
	private int $ownerId;
	private int $createdAt;
	private int $updatedAt;

	public function __construct(
		int $promptId,
		string $response,
		?int $ownerId = -1,
		?int $createdAt = null,
		?int $updatedAt = null,
	) {
		$this->promptId = $promptId;
		$this->response = $response;

		if ($ownerId !== null) {
			$this->ownerId = $ownerId;
		} else {
			$this->ownerId = -1;
		}

		if ($createdAt !== null) {
			$this->createdAt = $createdAt;
		} else {
			$this->createdAt = time();
		}

		if ($updatedAt !== null) {
			$this->updatedAt = $updatedAt;
		} else {
			$this->updatedAt = time();
		}
	}

	public function jsonSerialize(): array
	{
		if ($this->ownerId === -1) {
			return [
				'prompt_id' => $this->promptId,
				'response' => $this->response,
			];
		}

		$owner = UsersHelper::serializeUser($this->getOwner());
		return [
			'prompt_id' => $this->promptId,
			'response' => $this->response,
			'owner' => $owner,
		];
	}

	public function getPromptId(): int
	{
		return $this->promptId;
	}

	public function getResponse(): string
	{
		return $this->response;
	}

	public function setResponse(string $response): void
	{
		$this->response = $response;
	}

	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	public function hideOwnerId(): void
	{
		$this->ownerId = -1;
	}

	public function getOwner(): UserInterface
	{
		return User::load($this->ownerId);
	}

	public function getCreatedAt(): int
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): int
	{
		return $this->updatedAt;
	}
}
