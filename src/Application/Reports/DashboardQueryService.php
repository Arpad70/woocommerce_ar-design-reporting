<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application\Reports;

use ArDesign\Reporting\Domain\Metrics\KpiCalculator;
use ArDesign\Reporting\Infrastructure\Database\Migrator;
use ArDesign\Reporting\Infrastructure\Database\Tables;
use ArDesign\Reporting\Infrastructure\Repository\AuditLogRepository;
use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;
use ArDesign\Reporting\Integration\WooCommerce\Compatibility;

final class DashboardQueryService
{
	private KpiCalculator $kpi_calculator;

	private Tables $tables;

	private Migrator $migrator;

	private Compatibility $compatibility;

	private OrderProcessingRepository $order_processing_repository;

	private AuditLogRepository $audit_log_repository;

	public function __construct(
		KpiCalculator $kpi_calculator,
		Tables $tables,
		Migrator $migrator,
		Compatibility $compatibility,
		OrderProcessingRepository $order_processing_repository,
		AuditLogRepository $audit_log_repository
	) {
		$this->kpi_calculator = $kpi_calculator;
		$this->tables         = $tables;
		$this->migrator       = $migrator;
		$this->compatibility  = $compatibility;
		$this->order_processing_repository = $order_processing_repository;
		$this->audit_log_repository        = $audit_log_repository;
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<string, mixed>
	 */
	public function getDashboardData(array $filters = array(), array $compare_filters = array()): array
	{
		$order_rows = $this->order_processing_repository->getOrderRowsForDashboard($filters, 200);
		$order_ids  = array();

		foreach ($order_rows as $row) {
			$order_ids[] = (int) ($row['order_id'] ?? 0);
		}

		$last_status_changes = $this->audit_log_repository->getLatestStatusChangeByOrderIds($order_ids);
		$status_change_events = $this->audit_log_repository->getStatusChangeEventsByOrderIds($order_ids);
		$last_change_map = array();
		$first_ready_map = array();

		foreach ($last_status_changes as $change_row) {
			$last_change_map[(int) ($change_row['order_id'] ?? 0)] = $change_row;
		}

		foreach ($status_change_events as $event_row) {
			$order_id = (int) ($event_row['order_id'] ?? 0);
			$new_json = isset($event_row['new_value_json']) ? (string) $event_row['new_value_json'] : '';
			$new_data = json_decode($new_json, true);
			$status = is_array($new_data) ? sanitize_key((string) ($new_data['status'] ?? '')) : '';

			if ('na-odoslanie' === $status && ! isset($first_ready_map[$order_id])) {
				$first_ready_map[$order_id] = $event_row;
			}
		}

		$orders_overview = array();
		$manager_ready_seconds = array();
		$manager_map = array();
		foreach ($order_rows as $row) {
			$order_id = (int) ($row['order_id'] ?? 0);
			$last_change = $last_change_map[$order_id] ?? array();
			$ready_event = $first_ready_map[$order_id] ?? array();
			$created_at_gmt = (string) ($row['created_at_gmt'] ?? '');
			$ready_at_gmt = (string) ($ready_event['created_at_gmt'] ?? '');
			$ready_seconds = $this->calculateSecondsDiff($created_at_gmt, $ready_at_gmt);
			$manager_user_id = $this->resolveOrderManagerUserId($order_id);

			if ($manager_user_id > 0 && $ready_seconds > 0) {
				if (! isset($manager_ready_seconds[$manager_user_id])) {
					$manager_ready_seconds[$manager_user_id] = array();
				}

				$manager_ready_seconds[$manager_user_id][] = $ready_seconds;
				$manager_map[$manager_user_id] = true;
			}

			$orders_overview[] = array(
				'order_id'                  => $order_id,
				'owner_user_id'             => (int) ($row['owner_user_id'] ?? 0),
				'manager_user_id'           => $manager_user_id,
				'classification'            => (string) ($row['classification'] ?? ''),
				'status'                    => (string) ($row['status'] ?? ''),
				'processing_seconds'        => isset($row['processing_seconds']) ? (int) $row['processing_seconds'] : null,
				'created_at_gmt'            => $created_at_gmt,
				'ready_for_packing_at_gmt'  => $ready_at_gmt,
				'ready_for_packing_seconds' => $ready_seconds > 0 ? $ready_seconds : null,
				'updated_at_gmt'            => (string) ($row['updated_at_gmt'] ?? ''),
				'last_status_change_actor'  => isset($last_change['actor_user_id']) ? (int) $last_change['actor_user_id'] : 0,
				'last_status_change_at_gmt' => (string) ($last_change['created_at_gmt'] ?? ''),
			);
		}

		$default_manager_user_id = (int) get_option('ard_reporting_default_manager_user_id', 0);
		$default_manager_avg_ready_seconds = $this->calculateManagerAverageReadySeconds($manager_ready_seconds, $default_manager_user_id);
		$overall_avg_ready_seconds = $this->calculateOverallAverageReadySeconds($manager_ready_seconds);
		$manager_performance = $this->buildManagerPerformanceRows($manager_ready_seconds, array_keys($manager_map));

		$current_kpis = $this->kpi_calculator->getOverview($filters, $default_manager_avg_ready_seconds, $overall_avg_ready_seconds);
		$compare_kpis = $this->kpi_calculator->getOverview($compare_filters);

		return array(
			'kpis'           => $current_kpis,
			'compare_kpis'   => $compare_kpis,
			'kpi_compare'    => $this->buildKpiComparison($current_kpis, $compare_kpis),
			'tables'         => $this->tables->all(),
			'missing_tables' => $this->migrator->getMissingTables(),
			'hpos_enabled'   => $this->compatibility->isHposEnabled(),
			'orders_overview' => $orders_overview,
			'employee_overview' => $this->order_processing_repository->getEmployeePerformanceRows($filters, 50),
			'audit_overview' => $this->audit_log_repository->getEventTypeSummary($filters, 20),
			'manager_performance' => $manager_performance,
			'default_manager_user_id' => $default_manager_user_id,
		);
	}

	/**
	 * @param array<string, int|float> $current_kpis
	 * @param array<string, int|float> $compare_kpis
	 * @return array<string, array<string, float>>
	 */
	private function buildKpiComparison(array $current_kpis, array $compare_kpis): array
	{
		$comparison = array();

		foreach ($current_kpis as $key => $current_value) {
			if (! is_numeric($current_value)) {
				continue;
			}

			$current = (float) $current_value;
			$previous = isset($compare_kpis[$key]) && is_numeric($compare_kpis[$key]) ? (float) $compare_kpis[$key] : 0.0;
			$delta = $current - $previous;
			$delta_percent = 0.0;

			if (abs($previous) > 0.00001) {
				$delta_percent = ($delta / $previous) * 100;
			} elseif (abs($current) > 0.00001) {
				$delta_percent = 100.0;
			}

			$comparison[$key] = array(
				'current' => $current,
				'previous' => $previous,
				'delta' => $delta,
				'delta_percent' => $delta_percent,
			);
		}

		return $comparison;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderTimeline(int $order_id): array
	{
		$events = $this->audit_log_repository->getStatusTimelineByOrderId($order_id);
		$timeline = array();
		$previous_time = '';

		foreach ($events as $event) {
			$old_data = json_decode((string) ($event['old_value_json'] ?? ''), true);
			$new_data = json_decode((string) ($event['new_value_json'] ?? ''), true);
			$from_status = is_array($old_data) ? sanitize_key((string) ($old_data['status'] ?? '')) : '';
			$to_status = is_array($new_data) ? sanitize_key((string) ($new_data['status'] ?? '')) : '';
			$event_time = (string) ($event['created_at_gmt'] ?? '');

			$timeline[] = array(
				'actor_user_id' => isset($event['actor_user_id']) ? (int) $event['actor_user_id'] : 0,
				'from_status'   => $from_status,
				'to_status'     => $to_status,
				'at_gmt'        => $event_time,
				'duration_since_prev_seconds' => '' !== $previous_time ? $this->calculateSecondsDiff($previous_time, $event_time) : null,
			);

			$previous_time = $event_time;
		}

		return $timeline;
	}

	private function resolveOrderManagerUserId(int $order_id): int
	{
		if ($order_id <= 0 || ! function_exists('wc_get_order')) {
			return 0;
		}

		$order = wc_get_order($order_id);
		if (! $order instanceof \WC_Order) {
			return 0;
		}

		return (int) $order->get_meta('_ard_manager_user_id', true);
	}

	private function calculateSecondsDiff(string $from_gmt, string $to_gmt): int
	{
		if ('' === $from_gmt || '' === $to_gmt) {
			return 0;
		}

		try {
			$timezone = new \DateTimeZone('UTC');
			$from = new \DateTimeImmutable($from_gmt, $timezone);
			$to = new \DateTimeImmutable($to_gmt, $timezone);

			return max(0, $to->getTimestamp() - $from->getTimestamp());
		} catch (\Exception $exception) {
			return 0;
		}
	}

	/**
	 * @param array<int, array<int, int>> $manager_ready_seconds
	 */
	private function calculateManagerAverageReadySeconds(array $manager_ready_seconds, int $manager_user_id): float
	{
		if ($manager_user_id <= 0 || ! isset($manager_ready_seconds[$manager_user_id])) {
			return 0.0;
		}

		$values = $manager_ready_seconds[$manager_user_id];

		if (empty($values)) {
			return 0.0;
		}

		return (float) (array_sum($values) / count($values));
	}

	/**
	 * @param array<int, array<int, int>> $manager_ready_seconds
	 */
	private function calculateOverallAverageReadySeconds(array $manager_ready_seconds): float
	{
		$all = array();

		foreach ($manager_ready_seconds as $values) {
			foreach ($values as $seconds) {
				$all[] = (int) $seconds;
			}
		}

		if (empty($all)) {
			return 0.0;
		}

		return (float) (array_sum($all) / count($all));
	}

	/**
	 * @param array<int, array<int, int>> $manager_ready_seconds
	 * @param array<int, int|string> $manager_ids
	 * @return array<int, array<string, mixed>>
	 */
	private function buildManagerPerformanceRows(array $manager_ready_seconds, array $manager_ids): array
	{
		$rows = array();

		foreach ($manager_ids as $manager_id) {
			$user_id = (int) $manager_id;
			$values = $manager_ready_seconds[$user_id] ?? array();
			$avg_seconds = ! empty($values) ? (float) (array_sum($values) / count($values)) : 0.0;

			$rows[] = array(
				'manager_user_id' => $user_id,
				'orders_count'    => count($values),
				'avg_ready_seconds' => $avg_seconds,
			);
		}

		return $rows;
	}
}
