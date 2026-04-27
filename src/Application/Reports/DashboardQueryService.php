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
		$ready_seconds_values = array();
		foreach ($order_rows as $row) {
			$order_id = (int) ($row['order_id'] ?? 0);
			$last_change = $last_change_map[$order_id] ?? array();
			$ready_event = $first_ready_map[$order_id] ?? array();
			$created_at_gmt = (string) ($row['created_at_gmt'] ?? '');
			$ready_at_gmt = (string) ($ready_event['created_at_gmt'] ?? '');
			$ready_seconds = $this->calculateSecondsDiff($created_at_gmt, $ready_at_gmt);

			if ($ready_seconds > 0) {
				$ready_seconds_values[] = $ready_seconds;
			}

			$orders_overview[] = array(
				'order_id'                  => $order_id,
				'owner_user_id'             => (int) ($row['owner_user_id'] ?? 0),
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

		$overall_avg_ready_seconds = $this->calculateOverallAverageReadySeconds($ready_seconds_values);

		$current_kpis = $this->kpi_calculator->getOverview($filters, $overall_avg_ready_seconds);
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
			'audit_overview' => $this->audit_log_repository->getEventTypeSummary($filters, 200),
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

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecentAuditEvents(array $filters = array(), string $event_type = '', int $limit = 100): array
	{
		return $this->audit_log_repository->getRecentEvents($filters, $event_type, $limit);
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
	 * @param array<int, int> $ready_seconds_values
	 */
	private function calculateOverallAverageReadySeconds(array $ready_seconds_values): float
	{
		if (empty($ready_seconds_values)) {
			return 0.0;
		}

		return (float) (array_sum($ready_seconds_values) / count($ready_seconds_values));
	}
}
