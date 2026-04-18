<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application;

use InvalidArgumentException;

final class Container
{
	/**
	 * @var array<string, mixed>
	 */
	private array $entries = array();

	/**
	 * @var array<string, callable>
	 */
	private array $factories = array();

	public function set(string $id, callable $factory): void
	{
		$this->factories[$id] = $factory;
	}

	public function has(string $id): bool
	{
		return array_key_exists($id, $this->entries) || array_key_exists($id, $this->factories);
	}

	public function get(string $id)
	{
		if (array_key_exists($id, $this->entries)) {
			return $this->entries[$id];
		}

		if (! array_key_exists($id, $this->factories)) {
			throw new InvalidArgumentException(sprintf('Service "%s" is not registered.', $id));
		}

		$this->entries[$id] = $this->factories[$id]($this);

		return $this->entries[$id];
	}
}
