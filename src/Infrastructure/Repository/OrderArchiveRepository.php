<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Repository;

use ArDesign\Reporting\Infrastructure\Database\Tables;

class OrderArchiveRepository
{
	private Tables $tables;

	public function __construct(Tables $tables)
	{
		$this->tables = $tables;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insertArchive(array $data): bool
	{
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->tables->orderArchive(),
			$data,
			array('%d', '%s', '%s', '%d', '%s')
		);

		return false !== $inserted;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecentByOrderId(int $order_id, int $limit = 10): array
	{
		global $wpdb;

		$limit = max(1, min(50, $limit));
		$table = $this->tables->orderArchive();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_id, archive_reason, snapshot_json, actor_user_id, created_at_gmt
				FROM {$table}
				WHERE order_id = %d
				ORDER BY id DESC
				LIMIT {$limit}",
				$order_id
			),
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecentDeleted(int $limit = 20): array
	{
		global $wpdb;

		$limit = max(1, min(100, $limit));
		$table = $this->tables->orderArchive();
		$rows  = $wpdb->get_results(
			"SELECT id, order_id, archive_reason, snapshot_json, actor_user_id, created_at_gmt
			FROM {$table}
			WHERE archive_reason = 'deleted'
			ORDER BY id DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}
}

