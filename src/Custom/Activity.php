<?php /** @noinspection PhpUnused */

namespace Drupal\mantle2\Custom;

use InvalidArgumentException;
use JsonSerializable;

class Activity implements JsonSerializable
{
	protected string $id;
	protected string $name;
	/** @var ActivityType[] */
	protected array $types = [];
	protected ?string $description = null;
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
		$this->types = $types;
		$this->description = $description;
		$this->aliases = $aliases;
		$this->fields = $fields;
	}

	public static function fromArray(array $data): self
	{
		$clazz = new self($data['id'], $data['name']);
		$clazz->types = array_map(
			fn($t) => $t instanceof ActivityType ? $t : ActivityType::from((string) $t),
			$data['types'] ?? [],
		);
		$clazz->description = $data['description'] ?? null;
		$clazz->aliases = $data['aliases'] ?? [];
		$clazz->fields = $data['fields'] ?? [];
		return $clazz;
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
			'types' => array_map(fn(ActivityType $t) => $t->value, $this->types),
			'aliases' => array_values($this->aliases),
			'fields' => $this->fields,
		];
	}

	/**
	 * Patch properties (null means keep current value).
	 */
	public function patch(
		?string $name = null,
		?string $description = null,
		?array $types = null,
		?array $aliases = null,
		?array $fields = null,
	): self {
		if ($name !== null) {
			$this->name = $name;
		}
		if ($description !== null) {
			$this->description = $description;
		}
		if ($types !== null) {
			$this->types = array_map(
				fn($t) => $t instanceof ActivityType ? $t : ActivityType::from((string) $t),
				array_values($types),
			);
		}
		if ($aliases !== null) {
			$this->aliases = array_values($aliases);
		}
		if ($fields !== null) {
			$this->fields = $fields;
		}

		$this->validate();
		return $this;
	}

	/**
	 * Validate invariants similar to the Kotlin model.
	 * @throws InvalidArgumentException
	 */
	public function validate(): void
	{
		if ($this->id === '') {
			throw new InvalidArgumentException('ID must not be empty.');
		}
		if ($this->name === '') {
			throw new InvalidArgumentException('Name must not be empty.');
		}
		if (empty($this->types)) {
			throw new InvalidArgumentException('Activity types must not be empty.');
		}
		if (count($this->types) > self::MAX_TYPES) {
			throw new InvalidArgumentException(
				'Activity can have a maximum of ' . self::MAX_TYPES . ' types.',
			);
		}

		if ($this->description === null || trim($this->description) === '') {
			throw new InvalidArgumentException('Description must not be empty.');
		}
		$len = strlen($this->description);
		if ($len < 1 || $len > 2500) {
			throw new InvalidArgumentException(
				'Description must be between 1 and 2500 characters.',
			);
		}
	}

	// Types API
	public function addType(ActivityType $type): void
	{
		if (count($this->types) >= self::MAX_TYPES) {
			trigger_error(
				"Cannot add type $type->value to activity '$this->id': maximum of " .
					self::MAX_TYPES .
					' types reached.',
				E_USER_WARNING,
			);
			return;
		}
		$this->types[] = $type;
		$this->validate();
	}

	public function addTypes(ActivityType ...$types): void
	{
		if (count($this->types) + count($types) > self::MAX_TYPES) {
			trigger_error(
				'Cannot add types ' .
					implode(', ', array_map(fn($t) => $t->value, $types)) .
					" to activity '$this->id': maximum of " .
					self::MAX_TYPES .
					' types reached.',
				E_USER_WARNING,
			);
			return;
		}
		array_push($this->types, ...$types);
		$this->validate();
	}

	public function removeType(ActivityType $type): void
	{
		$idx = array_search($type, $this->types, true);
		if ($idx === false) {
			trigger_error(
				"Type $type->value is not present in the activity types for activity '$this->id'.",
				E_USER_WARNING,
			);
			return;
		}
		array_splice($this->types, (int) $idx, 1);
		$this->validate();
	}

	public function removeTypes(ActivityType ...$types): void
	{
		foreach ($types as $type) {
			$idx = array_search($type, $this->types, true);
			if ($idx === false) {
				trigger_error(
					"Type $type->value is not present in the activity types for activity '$this->id'.",
					E_USER_WARNING,
				);
				continue;
			}
			array_splice($this->types, (int) $idx, 1);
		}
		$this->validate();
	}

	// Matching API
	public function doesMatch(string $name): bool
	{
		if (strcasecmp($this->name, $name) === 0) {
			return true;
		}
		foreach ($this->aliases as $alias) {
			if (strcasecmp($alias, $name) === 0) {
				return true;
			}
		}
		return false;
	}

	// Aliases API
	public function addAlias(string $alias): void
	{
		if (in_array($alias, $this->aliases, true)) {
			trigger_error(
				"Alias '$alias' is already present in the activity aliases for activity '$this->id'.",
				E_USER_WARNING,
			);
			return;
		}
		$this->aliases[] = $alias;
	}

	public function addAliases(string ...$aliases): void
	{
		foreach ($aliases as $alias) {
			if (in_array($alias, $this->aliases, true)) {
				trigger_error(
					"Alias '$alias' is already present in the activity aliases for activity '$this->id'.",
					E_USER_WARNING,
				);
				continue;
			}
			$this->aliases[] = $alias;
		}
	}

	public function removeAlias(string $alias): void
	{
		$idx = array_search($alias, $this->aliases, true);
		if ($idx === false) {
			trigger_error(
				"Alias '$alias' is not present in the activity aliases for activity '$this->id'.",
				E_USER_WARNING,
			);
			return;
		}
		array_splice($this->aliases, (int) $idx, 1);
	}

	public function removeAliases(string ...$aliases): void
	{
		foreach ($aliases as $alias) {
			$idx = array_search($alias, $this->aliases, true);
			if ($idx === false) {
				trigger_error(
					"Alias '$alias' is not present in the activity aliases for activity '$this->id'.",
					E_USER_WARNING,
				);
				continue;
			}
			array_splice($this->aliases, (int) $idx, 1);
		}
	}

	// Fields API
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

	public function setField(string $key, string $value): void
	{
		if ($key === '') {
			trigger_error('Key for field cannot be empty.', E_USER_WARNING);
			return;
		}
		if ($value === '') {
			trigger_error("Value for field '$key' cannot be empty.", E_USER_WARNING);
			return;
		}
		$this->fields[$key] = $value;
	}

	public function getId(): string
	{
		return $this->id;
	}
	public function getName(): string
	{
		return $this->name;
	}
	public function getDescription(): ?string
	{
		return $this->description;
	}
	/** @return ActivityType[] */
	public function getTypes(): array
	{
		return $this->types;
	}
	public function getAliases(): array
	{
		return $this->aliases;
	}

	public function __toString(): string
	{
		return sprintf('%s <%s>', $this->name, $this->id);
	}
}
