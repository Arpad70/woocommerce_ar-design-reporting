<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Domain\Metrics;

use ArDesign\Reporting\Infrastructure\Repository\AuditLogRepository;
use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;

final class KpiCalculator
{
	private OrderProcessingRepository $order_processing_repository;

	private AuditLogRepository $audit_log_repository;

	public function __construct(
		OrderProcessingRepository $order_processing_repository,
		AuditLogRepository $audit_log_repository
	)
	{
		$this->order_processing_repository = $order_processing_repository;
		$this->audit_log_repository        = $audit_log_repository;
	}

	/**
	 * @return array<string, int|float>
	 */
	public function getOverview(): array
	{
		$processing_counters = $this->order_processing_repository->getOverviewCounters();
		$audit_events        = $this->audit_log_repository->countAll();

		return array(
			'total_orders'   => (int) ( $processing_counters['total_orders'] ?? 0 ),
			'kpi_orders'     => (int) ( $processing_counters['kpi_orders'] ?? 0 ),
			'completed'      => (int) ( $processing_counters['completed'] ?? 0 ),
			'audit_events'   => $audit_events,
		);
	}
}
