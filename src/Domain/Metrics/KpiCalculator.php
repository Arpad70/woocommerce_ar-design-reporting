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
	 * @param array<string, string> $filters
	 * @return array<string, int|float>
	 */
	public function getOverview(array $filters = array(), float $overall_ready_avg_seconds = 0.0): array
	{
		$processing_counters = $this->order_processing_repository->getOverviewCounters($filters);
		$order_ids = $this->getOrderIdsForKpiScope($filters);
		$scope_counters = $this->getKpiScopeCounters($order_ids);
		$avg_processing_seconds = $this->order_processing_repository->getAverageProcessingSeconds($filters);
		$orders_per_employee = $this->order_processing_repository->getAverageOrdersPerEmployee($filters);
		$audit_events        = $this->audit_log_repository->countAll();
		$financials          = $this->getFinancialKpis($order_ids);

		return array(
			'total_orders'           => (int) ( $scope_counters['total_orders'] ?? 0 ),
			'kpi_orders'             => (int) ( $scope_counters['kpi_orders'] ?? 0 ),
			'completed'              => (int) ( $processing_counters['completed'] ?? 0 ),
			'completed_orders'       => (int) ( $financials['completed_orders'] ?? 0 ),
			'pending_orders'         => (int) ( $financials['pending_orders'] ?? 0 ),
			'audit_events'           => $audit_events,
			'gross_revenue'          => (float) ( $financials['gross_revenue'] ?? 0.0 ),
			'revenue_completed'      => (float) ( $financials['revenue_completed'] ?? 0.0 ),
			'revenue_pending'        => (float) ( $financials['revenue_pending'] ?? 0.0 ),
			'cancelled_orders'       => (int) ( $financials['cancelled_orders'] ?? 0 ),
			'net_revenue'            => (float) ( $financials['net_revenue'] ?? 0.0 ),
			'average_order_value'    => (float) ( $financials['average_order_value'] ?? 0.0 ),
			'average_order_value_completed' => (float) ( $financials['average_order_value_completed'] ?? 0.0 ),
			'average_order_value_pending' => (float) ( $financials['average_order_value_pending'] ?? 0.0 ),
			'avg_processing_hours'   => $avg_processing_seconds > 0 ? round( $avg_processing_seconds / 3600, 2 ) : 0.0,
			'orders_per_employee'    => $orders_per_employee > 0 ? round( $orders_per_employee, 2 ) : 0.0,
			'avg_ready_for_packing_hours' => $overall_ready_avg_seconds > 0 ? round($overall_ready_avg_seconds / 3600, 2) : 0.0,
		);
	}

	/**
	 * @param array<int, int> $order_ids
	 * @return array<string, int|float>
	 */
	private function getFinancialKpis(array $order_ids = array()): array
	{
		if (! function_exists('wc_get_order')) {
			return array(
				'gross_revenue'       => 0.0,
				'revenue_completed'   => 0.0,
				'revenue_pending'     => 0.0,
				'cancelled_orders'    => 0,
				'completed_orders'    => 0,
				'pending_orders'      => 0,
				'net_revenue'         => 0.0,
				'average_order_value' => 0.0,
				'average_order_value_completed' => 0.0,
				'average_order_value_pending' => 0.0,
			);
		}

		if (! is_array($order_ids) || empty($order_ids)) {
			return array(
				'gross_revenue'       => 0.0,
				'revenue_completed'   => 0.0,
				'revenue_pending'     => 0.0,
				'cancelled_orders'    => 0,
				'completed_orders'    => 0,
				'pending_orders'      => 0,
				'net_revenue'         => 0.0,
				'average_order_value' => 0.0,
				'average_order_value_completed' => 0.0,
				'average_order_value_pending' => 0.0,
			);
		}

		$gross = 0.0;
		$net = 0.0;
		$cancelled = 0;
		$count_for_average = 0;
		$completed_orders = 0;
		$pending_orders = 0;
		$revenue_completed = 0.0;
		$revenue_pending = 0.0;
		$completed_for_average = 0;
		$pending_for_average = 0;

		foreach ($order_ids as $order_id) {
			$order = wc_get_order((int) $order_id);
			if (! $order instanceof \WC_Order) {
				continue;
			}

			$total = (float) $order->get_total();
			$status = sanitize_key((string) $order->get_status());
			$refunded = (float) $order->get_total_refunded();

			$gross += $total;

			if ($this->isCancelledLikeStatus($status)) {
				$cancelled++;
			}

			$net_amount = max(0.0, $total - $refunded);

			if (! $this->isExcludedFromRevenueStatus($status)) {
				$net += $net_amount;
				$count_for_average++;
			}

			if ($this->isCompletedLikeStatus($status)) {
				$completed_orders++;
				$revenue_completed += $net_amount;
				$completed_for_average++;
				continue;
			}

			if (! $this->isTerminalNonPendingStatus($status)) {
				$pending_orders++;
				$revenue_pending += $net_amount;
				$pending_for_average++;
			}
		}

		return array(
			'gross_revenue'       => round($gross, 2),
			'revenue_completed'   => round($revenue_completed, 2),
			'revenue_pending'     => round($revenue_pending, 2),
			'cancelled_orders'    => $cancelled,
			'completed_orders'    => $completed_orders,
			'pending_orders'      => $pending_orders,
			'net_revenue'         => round($net, 2),
			'average_order_value' => $count_for_average > 0 ? round($net / $count_for_average, 2) : 0.0,
			'average_order_value_completed' => $completed_for_average > 0 ? round($revenue_completed / $completed_for_average, 2) : 0.0,
			'average_order_value_pending' => $pending_for_average > 0 ? round($revenue_pending / $pending_for_average, 2) : 0.0,
		);
	}

	private function isCompletedLikeStatus(string $status): bool
	{
		return in_array($status, array('vybavena', 'completed'), true);
	}

	private function isCancelledLikeStatus(string $status): bool
	{
		return in_array($status, array('cancelled', 'zrusena'), true);
	}

	private function isExcludedFromRevenueStatus(string $status): bool
	{
		return in_array($status, array('cancelled', 'zrusena', 'failed', 'neuspesna'), true);
	}

	private function isTerminalNonPendingStatus(string $status): bool
	{
		return in_array(
			$status,
			array('cancelled', 'zrusena', 'failed', 'neuspesna', 'refunded', 'refundovana', 'completed', 'vybavena'),
			true
		);
	}

	/**
	 * @param array<string, string> $filters
	 */
	private function getOrderIdsForKpiScope(array $filters): array
	{
		if (! function_exists('wc_get_orders') || ! function_exists('wc_get_order_statuses')) {
			return array();
		}

		$args = array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => array_keys(wc_get_order_statuses()),
		);

		$from = (string) ($filters['date_from'] ?? '');
		$to   = (string) ($filters['date_to'] ?? '');

		if ('' !== $from && '' !== $to) {
			$args['date_created'] = $from . '...' . $to . ' 23:59:59';
		} elseif ('' !== $from) {
			$args['date_created'] = '>=' . $from;
		} elseif ('' !== $to) {
			$args['date_created'] = '<=' . $to . ' 23:59:59';
		}

		$order_ids = wc_get_orders($args);

		return is_array($order_ids) ? array_values(array_map('absint', $order_ids)) : array();
	}

	/**
	 * @param array<int, int> $order_ids
	 * @return array<string, int>
	 */
	private function getKpiScopeCounters(array $order_ids): array
	{
		if (! function_exists('wc_get_order')) {
			return array(
				'total_orders' => 0,
				'kpi_orders'   => 0,
			);
		}

		$total_orders = count($order_ids);
		$kpi_eligible_order_ids = $this->order_processing_repository->getKpiEligibleOrderIds($order_ids);
		$workflow_audited_order_ids = $this->audit_log_repository->getOrderIdsWithWorkflowAuditEvents($order_ids);
		$kpi_eligible_map = array_fill_keys($kpi_eligible_order_ids, true);
		$workflow_audited_map = array_fill_keys($workflow_audited_order_ids, true);
		$kpi_orders = 0;

		foreach ($order_ids as $order_id) {
			if (isset($kpi_eligible_map[$order_id]) && isset($workflow_audited_map[$order_id])) {
				$kpi_orders++;
			}
		}

		return array(
			'total_orders' => $total_orders,
			'kpi_orders'   => $kpi_orders,
		);
	}
}
