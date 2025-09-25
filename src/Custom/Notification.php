<?php

namespace Drupal\mantle2\Custom;

use Drupal\mantle2\Service\GeneralHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use JsonSerializable;

class Notification implements JsonSerializable
{
	public string $id;
	public string $userId;
	public string $title;
	public string $message; // after first line break is full message; first line is summary
	public ?string $link;
	public string $type;
	public string $source = 'system'; // 'system', 'email', '<username>', etc
	public bool $isRead = false;
	public int $timestamp;

	public function __construct(
		string $id,
		string $userId,
		string $title,
		string $message,
		int $timestamp,
		?string $link = null,
		string $type = 'info',
		string $source = 'system',
		bool $isRead = false,
	) {
		$this->id = $id;
		$this->userId = $userId;
		$this->title = $title;
		$this->message = $message;
		$this->timestamp = $timestamp;
		$this->link = $link;
		$this->type = $type;
		$this->source = $source;
		$this->isRead = $isRead;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'user_id' => GeneralHelper::formatId($this->userId),
			'message' => $this->message,
			'link' => $this->link,
			'type' => $this->type,
			'source' => $this->source,
			'read' => $this->isRead,
			'created_at' => $this->timestamp,
		];
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getUserId(): string
	{
		return $this->userId;
	}

	public function getUser(): UserInterface
	{
		return User::load($this->userId);
	}

	public function getTimestamp(): int
	{
		return $this->timestamp;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): void
	{
		$this->title = $title;
	}

	public function getMessage(): string
	{
		return $this->message;
	}

	public function setMessage(string $message): void
	{
		$this->message = $message;
	}

	public function getLink(): ?string
	{
		return $this->link;
	}

	public function setLink(?string $link): void
	{
		$this->link = $link;
	}

	/**
	 * @return string 'info', 'warning', or 'error'
	 */
	public function getType(): string
	{
		return $this->type;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function isRead(): bool
	{
		return $this->isRead;
	}

	public function setRead(bool $isRead = true): void
	{
		$this->isRead = $isRead;
	}
}
