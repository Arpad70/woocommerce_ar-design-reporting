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
	}

	public function handleNewOrder(int $order_id): void
	{
		$this->processing_service->initializeOrder($order_id);
	}

	public function handleStatusChanged(int $order_id, string $from_status, string $to_status): void
	{
		$this->processing_service->handleStatusChange($order_id, $from_status, $to_status);
	}
}
