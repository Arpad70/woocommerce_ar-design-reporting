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
	 * @return array<string, int>
	 */
	public function getOverviewCounters(): array
	{
		global $wpdb;

		$table = $this->tables->orderProcessing();

		return array(
			'total_orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
			'kpi_orders'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_kpi_included = 1"),
			'completed'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('packed', 'completed')"),
		);
	}
}
