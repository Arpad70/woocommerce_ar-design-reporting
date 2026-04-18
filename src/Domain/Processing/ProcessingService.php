<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Domain\Processing;

use ArDesign\Reporting\Domain\Audit\AuditLogger;
use ArDesign\Reporting\Domain\Orders\OrderClassifier;
use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;

final class ProcessingService
{
	private OrderProcessingRepository $order_processing_repository;

	private AuditLogger $audit_logger;

	private OrderClassifier $order_classifier;

	public function __construct(
		OrderProcessingRepository $order_processing_repository,
		AuditLogger $audit_logger,
		OrderClassifier $order_classifier
	)
	{
		$this->order_processing_repository = $order_processing_repository;
		$this->audit_logger     = $audit_logger;
		$this->order_classifier = $order_classifier;
	}

	public function initializeOrder(int $order_id): void
	{
		$existing_record = $this->getRecord($order_id);
		$classification = $this->order_classifier->classify($order_id);
		$created_at     = current_time('mysql', true);

		$this->order_processing_repository->replace(
			array(
				'order_id'          => $order_id,
				'owner_user_id'     => isset($existing_record['owner_user_id']) ? (int) $existing_record['owner_user_id'] : null,
				'processing_mode'   => 'standard',
				'classification'    => $classification['classification'],
				'is_kpi_included'   => $classification['is_kpi_included'] ? 1 : 0,
				'status'            => isset($existing_record['status']) ? (string) $existing_record['status'] : 'new',
				'source_trigger'    => 'woocommerce_new_order',
				'started_at_gmt'    => $existing_record['started_at_gmt'] ?? null,
				'finished_at_gmt'   => $existing_record['finished_at_gmt'] ?? null,
				'processing_seconds'=> isset($existing_record['processing_seconds']) ? (int) $existing_record['processing_seconds'] : null,
				'created_at_gmt'    => isset($existing_record['created_at_gmt']) ? (string) $existing_record['created_at_gmt'] : $created_at,
				'updated_at_gmt'    => $created_at,
			)
		);
	}

	public function handleStatusChange(int $order_id, string $from_status, string $to_status): void
	{
		if ($order_id <= 0) {
			return;
		}

		$record = $this->ensureRecord($order_id);

		if (empty($record)) {
			return;
		}

		$from_status = sanitize_key($from_status);
		$to_status   = sanitize_key($to_status);
		$actor_user_id = get_current_user_id() ?: null;

		if ($this->isFailedStatus($to_status) && ! $this->canTransitionToFailed($from_status)) {
			$this->audit_logger->log(
				'order_failed_transition_blocked',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array('status' => $from_status),
				array('status' => $to_status),
				array('source' => 'woocommerce_order_status_changed')
			);

			return;
		}

		$update_data = array(
			'status'         => $to_status,
			'source_trigger' => 'woocommerce_order_status_changed',
			'updated_at_gmt' => current_time('mysql', true),
		);

		if (null !== $actor_user_id && $actor_user_id > 0) {
			$update_data['owner_user_id'] = $actor_user_id;
		}

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			$update_data
		);

		$this->audit_logger->log(
			'order_status_changed',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array('status' => $from_status),
			array('status' => $to_status),
			array('source' => 'woocommerce_order_status_changed')
		);
	}

	public function takeOverOrder(int $order_id, int $actor_user_id): void
	{
		$record     = $this->ensureRecord($order_id);
		$started_at = ! empty($record['started_at_gmt']) ? (string) $record['started_at_gmt'] : current_time('mysql', true);
		$current_status = ! empty($record['status']) ? (string) $record['status'] : 'processing';

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			array(
				'owner_user_id'  => $actor_user_id,
				'started_at_gmt' => $started_at,
				'status'         => $current_status,
				'source_trigger' => 'manual_take_over',
				'updated_at_gmt' => current_time('mysql', true),
			)
		);

		$this->audit_logger->log(
			'order_taken_over',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array(
				'owner_user_id'  => isset($record['owner_user_id']) ? (int) $record['owner_user_id'] : null,
				'started_at_gmt' => $record['started_at_gmt'] ?? null,
			),
			array(
				'owner_user_id'  => $actor_user_id,
				'started_at_gmt' => $started_at,
				'status'         => $current_status,
			),
			array('source' => 'manual_take_over')
		);
	}

	public function finishProcessing(int $order_id, int $actor_user_id): void
	{
		$record = $this->ensureRecord($order_id);
		if (empty($record)) {
			return;
		}

		$started_at = ! empty($record['started_at_gmt']) ? (string) $record['started_at_gmt'] : current_time('mysql', true);
		$finished_at = current_time('mysql', true);
		$processing_seconds = $this->calculateProcessingSeconds($started_at, $finished_at);

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			array(
				'status'         => 'zabalena',
				'source_trigger' => 'manual_packed',
				'updated_at_gmt' => $finished_at,
			)
		);

		$this->audit_logger->log(
			'order_packed',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array(
				'status' => $record['status'] ?? null,
			),
			array(
				'status' => 'zabalena',
			),
			array('source' => 'manual_packed')
		);
	}

	public function completeFulfillment(int $order_id, int $actor_user_id): void
	{
		$record = $this->ensureRecord($order_id);
		if (empty($record)) {
			return;
		}

		$started_at = ! empty($record['started_at_gmt']) ? (string) $record['started_at_gmt'] : current_time('mysql', true);
		$finished_at = current_time('mysql', true);
		$processing_seconds = $this->calculateProcessingSeconds($started_at, $finished_at);

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			array(
				'owner_user_id'      => $actor_user_id,
				'finished_at_gmt'    => $finished_at,
				'processing_seconds' => $processing_seconds,
				'status'             => 'vybavena',
				'source_trigger'     => 'manual_fulfillment',
				'updated_at_gmt'     => $finished_at,
			)
		);

		$this->audit_logger->log(
			'order_fulfilled',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array(
				'finished_at_gmt'    => $record['finished_at_gmt'] ?? null,
				'processing_seconds' => isset($record['processing_seconds']) ? (int) $record['processing_seconds'] : null,
				'status'             => $record['status'] ?? null,
			),
			array(
				'finished_at_gmt'    => $finished_at,
				'processing_seconds' => $processing_seconds,
				'status'             => 'vybavena',
			),
			array('source' => 'manual_fulfillment')
		);

		$this->updateWooOrderToFulfilledStatus($order_id, $actor_user_id);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getWorkflowSummary(int $order_id): array
	{
		$record = $this->getRecord($order_id);

		if (empty($record)) {
			return array();
		}

		return $record;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function ensureRecord(int $order_id): array
	{
		$record = $this->getRecord($order_id);

		if (! empty($record)) {
			return $record;
		}

		$this->initializeOrder($order_id);

		return $this->getRecord($order_id);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getRecord(int $order_id): array
	{
		return $this->order_processing_repository->findByOrderId($order_id);
	}

	private function calculateProcessingSeconds(string $started_at_gmt, string $finished_at_gmt): int
	{
		try {
			$timezone  = new \DateTimeZone('UTC');
			$started_at = new \DateTimeImmutable($started_at_gmt, $timezone);
			$finished_at = new \DateTimeImmutable($finished_at_gmt, $timezone);
			$seconds = $finished_at->getTimestamp() - $started_at->getTimestamp();

			return max(0, $seconds);
		} catch (\Exception $exception) {
			return 0;
		}
	}

	private function updateWooOrderToFulfilledStatus(int $order_id, int $actor_user_id): void
	{
		if (! function_exists('wc_get_order') || ! function_exists('wc_get_order_statuses')) {
			return;
		}

		$order = wc_get_order($order_id);

		if (! $order instanceof \WC_Order) {
			return;
		}

		$target_status = $this->resolveFulfilledStatusSlug();
		$current_status = (string) $order->get_status();

		if ('' === $target_status || $target_status === $current_status) {
			return;
		}

		$order->update_status(
			$target_status,
			__('Stav bol automaticky nastavený na Vybavená po dokončení workflow.', 'ar-design-reporting'),
			true
		);

		$this->audit_logger->log(
			'order_status_set_to_fulfilled',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array('woocommerce_status' => $current_status),
			array('woocommerce_status' => $target_status),
			array('source' => 'manual_fulfillment')
		);
	}

	private function resolveFulfilledStatusSlug(): string
	{
		$statuses = wc_get_order_statuses();

		if (! is_array($statuses) || empty($statuses)) {
			return '';
		}

		foreach ($statuses as $status_key => $label) {
			$key   = (string) $status_key;
			$title = trim(wp_strip_all_tags((string) $label));
			$slug  = 0 === strpos($key, 'wc-') ? substr($key, 3) : $key;

			if ('vybavena' === $slug || 'Vybavená' === $title || 'Vybavena' === $title) {
				return $slug;
			}
		}

		if (isset($statuses['wc-completed'])) {
			return 'completed';
		}

		return '';
	}

	private function isFailedStatus(string $status): bool
	{
		return in_array($status, array('failed', 'neuspesna'), true);
	}

	private function canTransitionToFailed(string $from_status): bool
	{
		return in_array($from_status, array('vybavena', 'completed'), true);
	}
}
