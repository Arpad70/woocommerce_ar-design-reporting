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
	public function getDashboardData(array $filters = array()): array
	{
		$order_rows = $this->order_processing_repository->getOrderRowsForDashboard($filters, 200);
		$order_ids  = array();

		foreach ($order_rows as $row) {
			$order_ids[] = (int) ($row['order_id'] ?? 0);
		}

		$last_status_changes = $this->audit_log_repository->getLatestStatusChangeByOrderIds($order_ids);
		$last_change_map = array();

		foreach ($last_status_changes as $change_row) {
			$last_change_map[(int) ($change_row['order_id'] ?? 0)] = $change_row;
		}

		$orders_overview = array();
		foreach ($order_rows as $row) {
			$order_id = (int) ($row['order_id'] ?? 0);
			$last_change = $last_change_map[$order_id] ?? array();

			$orders_overview[] = array(
				'order_id'                  => $order_id,
				'owner_user_id'             => (int) ($row['owner_user_id'] ?? 0),
				'classification'            => (string) ($row['classification'] ?? ''),
				'status'                    => (string) ($row['status'] ?? ''),
				'processing_seconds'        => isset($row['processing_seconds']) ? (int) $row['processing_seconds'] : null,
				'updated_at_gmt'            => (string) ($row['updated_at_gmt'] ?? ''),
				'last_status_change_actor'  => isset($last_change['actor_user_id']) ? (int) $last_change['actor_user_id'] : 0,
				'last_status_change_at_gmt' => (string) ($last_change['created_at_gmt'] ?? ''),
			);
		}

		return array(
			'kpis'           => $this->kpi_calculator->getOverview($filters),
			'tables'         => $this->tables->all(),
			'missing_tables' => $this->migrator->getMissingTables(),
			'hpos_enabled'   => $this->compatibility->isHposEnabled(),
			'orders_overview' => $orders_overview,
			'employee_overview' => $this->order_processing_repository->getEmployeePerformanceRows($filters, 50),
			'audit_overview' => $this->audit_log_repository->getEventTypeSummary($filters, 20),
		);
	}
}
