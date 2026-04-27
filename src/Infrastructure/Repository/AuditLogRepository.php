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
	 * @param array<int, int> $order_ids
	 * @return array<int, array<string, mixed>>
	 */
	public function getStatusChangeEventsByOrderIds(array $order_ids): array
	{
		global $wpdb;

		$order_ids = array_values(array_filter(array_map('absint', $order_ids)));

		if (empty($order_ids)) {
			return array();
		}

		$table = $this->tables->auditLog();
		$ids_sql = implode(',', $order_ids);
		$rows = $wpdb->get_results(
			"SELECT order_id, actor_user_id, old_value_json, new_value_json, created_at_gmt
			FROM {$table}
			WHERE event_type = 'order_status_changed' AND order_id IN ({$ids_sql})
			ORDER BY order_id ASC, id ASC",
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @param array<int, int> $order_ids
	 * @return array<int, int>
	 */
	public function getOrderIdsWithWorkflowAuditEvents(array $order_ids): array
	{
		global $wpdb;

		$order_ids = array_values(array_filter(array_map('absint', $order_ids)));

		if (empty($order_ids)) {
			return array();
		}

		$table = $this->tables->auditLog();
		$ids_sql = implode(',', $order_ids);
		$event_types = array(
			'order_status_changed',
			'order_taken_over',
			'order_owner_reassigned',
			'order_packed',
			'order_fulfilled',
			'order_status_set_to_packed',
			'order_status_set_to_fulfilled',
		);
		$event_types_sql = "'" . implode("','", array_map('esc_sql', $event_types)) . "'";
		$rows = $wpdb->get_col(
			"SELECT DISTINCT order_id
			FROM {$table}
			WHERE order_id IN ({$ids_sql})
				AND event_type IN ({$event_types_sql})"
		);

		if (! is_array($rows)) {
			return array();
		}

		return array_values(array_filter(array_map('absint', $rows)));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getStatusTimelineByOrderId(int $order_id): array
	{
		global $wpdb;

		if ($order_id <= 0) {
			return array();
		}

		$table = $this->tables->auditLog();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT actor_user_id, old_value_json, new_value_json, created_at_gmt
				FROM {$table}
				WHERE event_type = 'order_status_changed' AND order_id = %d
				ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecentEvents(array $filters = array(), string $event_type = '', int $limit = 100): array
	{
		global $wpdb;

		$limit = max(1, min(500, $limit));
		$table = $this->tables->auditLog();
		$where_parts = array();
		$params = array();
		$this->appendDateRangeFilter($where_parts, $params, $filters);

		$event_type = sanitize_key($event_type);
		if ('' !== $event_type) {
			$where_parts[] = 'event_type = %s';
			$params[] = $event_type;
		}

		$sql = "SELECT id, event_type, order_id, actor_user_id, old_value_json, new_value_json, context_json, created_at_gmt
			FROM {$table}";

		if (! empty($where_parts)) {
			$sql .= ' WHERE ' . implode(' AND ', $where_parts);
		}

		$sql .= ' ORDER BY id DESC LIMIT ' . $limit;

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

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
