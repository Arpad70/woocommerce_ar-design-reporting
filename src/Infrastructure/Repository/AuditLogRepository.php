<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Repository;

use ArDesign\Reporting\Infrastructure\Database\Tables;

class AuditLogRepository
{
	private Tables $tables;

	public function __construct(Tables $tables)
	{
		$this->tables = $tables;
	}

	public function countAll(): int
	{
		global $wpdb;

		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables->auditLog()}");
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function getEventTypeSummary(array $filters = array(), int $limit = 15): array
	{
		global $wpdb;

		$limit = max(1, min(100, $limit));
		$table = $this->tables->auditLog();
		$where_parts = array();
		$params = array();
		$this->appendDateRangeFilter($where_parts, $params, $filters);

		$sql = "SELECT event_type, COUNT(*) AS events_count
			FROM {$table}";

		if (! empty($where_parts)) {
			$sql .= ' WHERE ' . implode(' AND ', $where_parts);
		}

		$sql .= ' GROUP BY event_type ORDER BY events_count DESC LIMIT ' . $limit;

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @param array<int, int> $order_ids
	 * @return array<int, array<string, mixed>>
	 */
	public function getLatestStatusChangeByOrderIds(array $order_ids): array
	{
		global $wpdb;

		$order_ids = array_values(array_filter(array_map('absint', $order_ids)));

		if (empty($order_ids)) {
			return array();
		}

		$table = $this->tables->auditLog();
		$ids_sql = implode(',', $order_ids);
		$sql = "SELECT t.order_id, t.actor_user_id, t.created_at_gmt
			FROM {$table} t
			INNER JOIN (
				SELECT order_id, MAX(id) AS max_id
				FROM {$table}
				WHERE event_type = 'order_status_changed' AND order_id IN ({$ids_sql})
				GROUP BY order_id
			) latest ON latest.max_id = t.id";

		$rows = $wpdb->get_results($sql, ARRAY_A);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @param array<int, string> $where_parts
	 * @param array<int, string> $params
	 * @param array<string, string> $filters
	 */
	private function appendDateRangeFilter(array &$where_parts, array &$params, array $filters): void
	{
		if ('' !== ($filters['date_from'] ?? '')) {
			$where_parts[] = 'DATE(created_at_gmt) >= %s';
			$params[] = (string) $filters['date_from'];
		}

		if ('' !== ($filters['date_to'] ?? '')) {
			$where_parts[] = 'DATE(created_at_gmt) <= %s';
			$params[] = (string) $filters['date_to'];
		}
	}
}
