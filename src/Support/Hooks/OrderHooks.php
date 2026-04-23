<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Support\Hooks;

use ArDesign\Reporting\Domain\Processing\ProcessingService;

final class OrderHooks
{
	private ProcessingService $processing_service;

	public function __construct(ProcessingService $processing_service)
	{
		$this->processing_service = $processing_service;
	}

	public function register(): void
	{
		add_action('woocommerce_new_order', array($this, 'handleNewOrder'));
		add_action('woocommerce_order_status_changed', array($this, 'handleStatusChanged'), 10, 3);
		add_filter('woocommerce_cancel_unpaid_order', array($this, 'preventAutoCancelForDeferredMethods'), 10, 2);
	}

	public function handleNewOrder(int $order_id): void
	{
		$this->processing_service->initializeOrder($order_id);
	}

	public function handleStatusChanged(int $order_id, string $from_status, string $to_status): void
	{
		$this->processing_service->handleStatusChange($order_id, $from_status, $to_status);
	}

	/**
	 * Keep deferred payment methods (COD, bank transfer) out of unpaid auto-cancel cron.
	 *
	 * @param mixed $should_cancel
	 * @param mixed $order
	 * @return mixed
	 */
	public function preventAutoCancelForDeferredMethods($should_cancel, $order)
	{
		if (! $order instanceof \WC_Order) {
			return $should_cancel;
		}

		$payment_method = sanitize_key((string) $order->get_payment_method());
		$deferred_methods = (array) apply_filters(
			'ard_reporting_unpaid_cancel_excluded_gateways',
			array('cod', 'bacs')
		);
		$normalized_methods = array_map('sanitize_key', $deferred_methods);

		if (in_array($payment_method, $normalized_methods, true)) {
			return false;
		}

		return $should_cancel;
	}
}
