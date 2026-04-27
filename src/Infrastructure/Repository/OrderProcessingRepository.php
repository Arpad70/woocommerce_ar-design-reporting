<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Infrastructure\Repository;

use ArDesign\Reporting\Infrastructure\Database\Tables;

class OrderProcessingRepository
{
	private Tables $tables;

	public function __construct(Tables $tables)
	{
		$this->tables = $tables;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function findByOrderId(int $order_id): array
	{
		global $wpdb;

		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->tables->orderProcessing()} WHERE order_id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		return is_array($record) ? $record : array();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function replace(array $data): bool
	{
		global $wpdb;

		$formats = $this->inferFormats($data);

		$result = $wpdb->replace(
			$this->tables->orderProcessing(),
			$data,
			$formats
		);

		return false !== $result;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateByOrderId(int $order_id, array $data): bool
	{
		global $wpdb;

		if (empty($data)) {
			return false;
		}

		$formats = $this->inferFormats($data);

		$updated = $wpdb->update(
			$this->tables->orderProcessing(),
			$data,
			array('order_id' => $order_id),
			$formats,
			array('%d')
		);

		return false !== $updated;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, string>
	 */
	private function inferFormats(array $data): array
	{
		$formats = array();

		foreach ($data as $value) {
			if (is_int($value) || is_bool($value)) {
				$formats[] = '%d';
				continue;
			}

			$formats[] = '%s';
		}

		return $formats;
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function findForExport(array $filters): array
	{
		global $wpdb;

		$where  = array();
		$params = array();
		$table  = $this->tables->orderProcessing();

		if ('' !== ($filters['status'] ?? '')) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		if ('' !== ($filters['classification'] ?? '')) {
			$where[]  = 'classification = %s';
			$params[] = $filters['classification'];
		}

		$this->appendKpiIncludedFilter($where, $params, $filters);

		if ('' !== ($filters['date_from'] ?? '')) {
			$where[]  = 'DATE(created_at_gmt) >= %s';
			$params[] = $filters['date_from'];
		}

		if ('' !== ($filters['date_to'] ?? '')) {
			$where[]  = 'DATE(created_at_gmt) <= %s';
			$params[] = $filters['date_to'];
		}

		$sql = "SELECT order_id, owner_user_id, processing_mode, classification, status, is_kpi_included, source_trigger, started_at_gmt, finished_at_gmt, processing_seconds, created_at_gmt, updated_at_gmt FROM {$table}";

		if (! empty($where)) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' ORDER BY created_at_gmt DESC LIMIT 5000';

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<string, int>
	 */
	public function getOverviewCounters(array $filters = array()): array
	{
		global $wpdb;

		$table = $this->tables->orderProcessing();
		$where_parts = array();
		$params = array();
		$this->appendKpiIncludedFilter($where_parts, $params, $filters);
		$this->appendDateRangeFilter($where_parts, $params, $filters);
		$where_sql = ! empty($where_parts) ? ' WHERE ' . implode(' AND ', $where_parts) : '';

		$totals_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";
		$kpi_sql    = "SELECT COUNT(*) FROM {$table}{$where_sql}" . (! empty($where_parts) ? ' AND' : ' WHERE') . " is_kpi_included = 1";
		$done_sql   = "SELECT COUNT(*) FROM {$table}{$where_sql}" . (! empty($where_parts) ? ' AND' : ' WHERE') . " status IN ('vybavena', 'completed')";

		if (! empty($params)) {
			$totals_sql = $wpdb->prepare($totals_sql, $params);
			$kpi_sql    = $wpdb->prepare($kpi_sql, $params);
			$done_sql   = $wpdb->prepare($done_sql, $params);
		}

		return array(
			'total_orders' => (int) $wpdb->get_var($totals_sql),
			'kpi_orders'   => (int) $wpdb->get_var($kpi_sql),
			'completed'    => (int) $wpdb->get_var($done_sql),
		);
	}

	/**
	 * @param array<string, string> $filters
	 */
	public function getAverageProcessingSeconds(array $filters = array()): float
	{
		global $wpdb;

		$table = $this->tables->orderProcessing();
		$where_parts = array(
			'processing_seconds IS NOT NULL',
			"status IN ('vybavena', 'completed')",
		);
		$params = array();
		$this->appendKpiIncludedFilter($where_parts, $params, $filters);
		$this->appendDateRangeFilter($where_parts, $params, $filters);
		$sql = "SELECT AVG(processing_seconds) FROM {$table} WHERE " . implode(' AND ', $where_parts);

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		return (float) $wpdb->get_var($sql);
	}

	/**
	 * @param array<string, string> $filters
	 */
	public function getAverageOrdersPerEmployee(array $filters = array()): float
	{
		global $wpdb;

		$table = $this->tables->orderProcessing();
		$where_parts = array(
			'owner_user_id IS NOT NULL',
			'owner_user_id > 0',
		);
		$params = array();
		$this->appendKpiIncludedFilter($where_parts, $params, $filters);
		$this->appendDateRangeFilter($where_parts, $params, $filters);

		$subquery = "SELECT owner_user_id, COUNT(*) AS order_count FROM {$table} WHERE " . implode(' AND ', $where_parts) . ' GROUP BY owner_user_id';
		$sql = "SELECT AVG(order_count) FROM ({$subquery}) employee_counts";

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		return (float) $wpdb->get_var($sql);
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function getEmployeePerformanceRows(array $filters = array(), int $limit = 50): array
	{
		global $wpdb;

		$table = $this->tables->orderProcessing();
		$limit = max(1, min(200, $limit));
		$where_parts = array(
			'owner_user_id IS NOT NULL',
			'owner_user_id > 0',
		);
		$params = array();
		$this->appendKpiIncludedFilter($where_parts, $params, $filters);
		$this->appendDateRangeFilter($where_parts, $params, $filters);

		$sql = "SELECT owner_user_id, COUNT(*) AS orders_count, AVG(processing_seconds) AS avg_processing_seconds
			FROM {$table}
			WHERE " . implode(' AND ', $where_parts) . '
			GROUP BY owner_user_id
			ORDER BY orders_count DESC
			LIMIT ' . $limit;

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderRowsForDashboard(array $filters = array(), int $limit = 200): array
	{
		global $wpdb;

		$table = $this->tables->orderProcessing();
		$limit = max(1, min(5000, $limit));
		$where_parts = array();
		$params = array();

		if ('' !== ($filters['status'] ?? '')) {
			$where_parts[] = 'status = %s';
			$params[] = $filters['status'];
		}

		if ('' !== ($filters['classification'] ?? '')) {
			$where_parts[] = 'classification = %s';
			$params[] = $filters['classification'];
		}

		$this->appendKpiIncludedFilter($where_parts, $params, $filters);

		$this->appendDateRangeFilter($where_parts, $params, $filters);

		$sql = "SELECT order_id, owner_user_id, classification, status, is_kpi_included, started_at_gmt, finished_at_gmt, processing_seconds, source_trigger, created_at_gmt, updated_at_gmt
			FROM {$table}";

		if (! empty($where_parts)) {
			$sql .= ' WHERE ' . implode(' AND ', $where_parts);
		}

		$sql .= ' ORDER BY updated_at_gmt DESC LIMIT ' . $limit;

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * @param array<int, int> $order_ids
	 * @return array<int, int>
	 */
	public function getKpiEligibleOrderIds(array $order_ids): array
	{
		global $wpdb;

		$order_ids = array_values(array_filter(array_map('absint', $order_ids)));

		if (empty($order_ids)) {
			return array();
		}

		$table = $this->tables->orderProcessing();
		$ids_sql = implode(',', $order_ids);
		$rows = $wpdb->get_col(
			"SELECT order_id
			FROM {$table}
			WHERE order_id IN ({$ids_sql})
				AND is_kpi_included = 1"
		);

		if (! is_array($rows)) {
			return array();
		}

		return array_values(array_filter(array_map('absint', $rows)));
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

	/**
	 * @param array<int, string> $where_parts
	 * @param array<int, string> $params
	 * @param array<string, string> $filters
	 */
	private function appendKpiIncludedFilter(array &$where_parts, array &$params, array $filters): void
	{
		$kpi_included = isset($filters['kpi_included']) ? (string) $filters['kpi_included'] : '';
		if ('1' === $kpi_included || '0' === $kpi_included) {
			$where_parts[] = 'is_kpi_included = %d';
			$params[] = $kpi_included;
		}
	}
}
