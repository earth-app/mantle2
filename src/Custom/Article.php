<?php

namespace Drupal\mantle2\Custom;

use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use JsonSerializable;

class Article implements JsonSerializable
{
	private int $id;
	private string $title;
	private string $description;
	private array $tags;
	private string $content;
	private int $authorId;
	private int $color;
	private int $createdAt;
	private int $updatedAt;
	private array $ocean;

	public function __construct(
		int $id,
		string $title,
		string $description,
		array $tags,
		string $content,
		int $authorId,
		int $color,
		int $createdAt,
		int $updatedAt,
		array $ocean,
	) {
		$this->id = $id;
		$this->title = $title;
		$this->description = $description;
		$this->tags = $tags;
		$this->content = $content;
		$this->authorId = $authorId;
		$this->color = $color;
		$this->createdAt = $createdAt;
		$this->updatedAt = $updatedAt;
		$this->ocean = $ocean;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => GeneralHelper::formatId($this->id),
			'title' => $this->title,
			'description' => $this->description,
			'tags' => $this->tags,
			'content' => $this->content,
			'color' => $this->color,
			'color_hex' => GeneralHelper::intToHex($this->color),
			'author_id' => GeneralHelper::formatId($this->authorId),
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
			'ocean' => $this->ocean,
		];
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getTags(): array
	{
		return $this->tags;
	}

	public function getContent(): string
	{
		return $this->content;
	}

	public function getAuthorId(): int
	{
		return $this->authorId;
	}

	public function getAuthor(): ?UserInterface
	{
		return User::load($this->authorId);
	}

	public function getColor(): int
	{
		return $this->color;
	}

	public function getCreatedAt(): int
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): int
	{
		return $this->updatedAt;
	}

	public function getOcean(): array
	{
		return $this->ocean;
	}
}
