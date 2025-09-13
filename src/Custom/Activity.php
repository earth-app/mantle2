<?php /** @noinspection PhpUnused */

namespace Drupal\mantle2\Custom;

use InvalidArgumentException;
use JsonSerializable;

class Activity implements JsonSerializable
{
	protected string $id;
	protected string $name;
	/** @var array<ActivityType|string> */
	protected array $types = [];
	protected ?string $description = null;
	/** @var array<string> */
	protected array $aliases = [];
	private array $fields = [];

	public const int MAX_TYPES = 5;

	public function __construct(
		string $id,
		string $name,
		array $types = [],
		?string $description = null,
		array $aliases = [],
		array $fields = [],
	) {
		$this->id = $id;
		$this->name = $name;

		if (count($types) > self::MAX_TYPES) {
			throw new InvalidArgumentException(
				'Too many activity types, max is ' . self::MAX_TYPES,
			);
		}

		foreach ($types as $t) {
			if (!is_string($t)) {
				throw new InvalidArgumentException(
					'Activity type must be a string, found ' . get_debug_type($t),
				);
			}
		}

		$this->types = $types;
		$this->description = $description;
		$this->aliases = $aliases;
		$this->fields = $fields;
	}

	/**
	 * Serialize to array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'types' => array_map(
				fn($t) => $t instanceof ActivityType ? $t->name : $t,
				$this->types,
			),
			'aliases' => array_values($this->aliases),
			'fields' => $this->fields,
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['id'],
			$data['name'],
			$data['types'] ?? [],
			$data['description'] ?? null,
			$data['aliases'] ?? [],
			$data['fields'] ?? [],
		);
	}

	public function getAllFields(): array
	{
		return $this->fields; // return copy not necessary for scalars
	}

	public function getField(string $key): ?string
	{
		if ($key === '') {
			trigger_error('Key for field cannot be empty.', E_USER_WARNING);
			return null;
		}
		return $this->fields[$key] ?? null;
	}
	public function setFields(array $fields): void
	{
		$this->fields = $fields;
	}
	public function setField(string $key, ?string $value): void
	{
		if ($key === '') {
			trigger_error('Key for field cannot be empty.', E_USER_WARNING);
			return;
		}

		if ($value === null) {
			unset($this->fields[$key]);
		} else {
			$this->fields[$key] = $value;
		}
	}

	public function getId(): string
	{
		return $this->id;
	}
	public function getName(): string
	{
		return $this->name;
	}
	public function setName(string $name): void
	{
		$this->name = $name;
	}
	public function getDescription(): ?string
	{
		return $this->description;
	}
	public function setDescription(?string $description): void
	{
		$this->description = $description;
	}

	/** @return array<ActivityType|string> */
	public function getTypes(): array
	{
		return $this->types;
	}
	public function setTypes(array $types): void
	{
		$this->types = $types;
	}

	/** @return array<string> */
	public function getAliases(): array
	{
		return $this->aliases;
	}
	public function setAliases(array $aliases): void
	{
		$this->aliases = $aliases;
	}

	public function __toString(): string
	{
		return sprintf('%s <%s>', $this->name, $this->id);
	}
}
