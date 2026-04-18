<?php

declare(strict_types=1);

final class FakeWpdb
{
	public string $prefix = 'wp_';

	/**
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $tables = array();

	/**
	 * @var array<string, int>
	 */
	private array $increments = array();

	public function prepare(string $query, ...$args): string
	{
		$normalized_args = $args;

		if (1 === count($args) && is_array($args[0])) {
			$normalized_args = $args[0];
		}

		foreach ($normalized_args as $arg) {
			$value = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
			$query = preg_replace('/%[ds]/', $value, $query, 1) ?? $query;
		}

		return $query;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function replace(string $table, array $data): int|false
	{
		$this->ensureTable($table);
		$order_id = isset($data['order_id']) ? (int) $data['order_id'] : 0;

		foreach ($this->tables[$table] as $index => $row) {
			if ((int) ($row['order_id'] ?? 0) === $order_id) {
				$data['id']                    = $row['id'];
				$this->tables[$table][$index] = $data;

				return 1;
			}
		}

		$data['id']          = $this->nextId($table);
		$this->tables[$table][] = $data;

		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update(string $table, array $data, array $where): int|false
	{
		$this->ensureTable($table);
		$updated = 0;

		foreach ($this->tables[$table] as $index => $row) {
			if (! $this->matchesWhere($row, $where)) {
				continue;
			}

			$this->tables[$table][$index] = array_merge($row, $data);
			$updated++;
		}

		return $updated;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert(string $table, array $data): int|false
	{
		$this->ensureTable($table);
		$data['id']          = $this->nextId($table);
		$this->tables[$table][] = $data;

		return 1;
	}

	public function get_row(string $query, string $format)
	{
		if (ARRAY_A !== $format) {
			return null;
		}

		if (! preg_match('/FROM\s+([a-zA-Z0-9_]+)\s+WHERE\s+order_id\s+=\s+(\d+)/', $query, $matches)) {
			return null;
		}

		$table    = $matches[1];
		$order_id = (int) $matches[2];
		$this->ensureTable($table);

		foreach ($this->tables[$table] as $row) {
			if ((int) ($row['order_id'] ?? 0) === $order_id) {
				return $row;
			}
		}

		return null;
	}

	public function get_var(string $query)
	{
		if (! preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+([a-zA-Z0-9_]+)/', $query, $matches)) {
			return null;
		}

		$table = $matches[1];
		$this->ensureTable($table);

		return count($this->tables[$table]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getTableRows(string $table): array
	{
		$this->ensureTable($table);

		return $this->tables[$table];
	}

	private function ensureTable(string $table): void
	{
		if (! isset($this->tables[$table])) {
			$this->tables[$table] = array();
		}

		if (! isset($this->increments[$table])) {
			$this->increments[$table] = 1;
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $where
	 */
	private function matchesWhere(array $row, array $where): bool
	{
		foreach ($where as $key => $value) {
			if (! array_key_exists($key, $row)) {
				return false;
			}

			if ((string) $row[$key] !== (string) $value) {
				return false;
			}
		}

		return true;
	}

	private function nextId(string $table): int
	{
		$id = $this->increments[$table];
		$this->increments[$table]++;

		return $id;
	}
}

