<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Domain\Processing;

use ArDesign\Reporting\Domain\Audit\AuditLogger;
use ArDesign\Reporting\Domain\Orders\OrderClassifier;
use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;

final class ProcessingService
{
	private const MANAGER_META_KEY = '_ard_manager_user_id';
	private const OWNER_MISMATCH_TRANSIENT_PREFIX = 'ard_owner_mismatch_';
	private const TRANSITION_BLOCKED_TRANSIENT_PREFIX = 'ard_transition_blocked_';

	private OrderProcessingRepository $order_processing_repository;

	private AuditLogger $audit_logger;

	private OrderClassifier $order_classifier;

	private bool $is_reverting_blocked_status = false;

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
		$created_at     = $this->resolveOrderCreatedAtGmt($order_id);
		$assigned_manager_id = $this->resolveAssignedManagerUserId($order_id, $existing_record);

		$this->order_processing_repository->replace(
			array(
				'order_id'          => $order_id,
				'owner_user_id'     => isset($existing_record['owner_user_id']) ? (int) $existing_record['owner_user_id'] : ($assigned_manager_id > 0 ? $assigned_manager_id : null),
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

		if ($this->is_reverting_blocked_status) {
			return;
		}

		$record = $this->ensureRecord($order_id);

		if (empty($record)) {
			return;
		}

		$from_status = sanitize_key($from_status);
		$to_status   = sanitize_key($to_status);
		$canonical_from_status = $this->normalizeStatus($from_status);
		$canonical_to_status   = $this->normalizeStatus($to_status);
		$actor_user_id = get_current_user_id() ?: null;
		$owner_user_id = isset($record['owner_user_id']) ? (int) $record['owner_user_id'] : 0;

		if ($this->shouldBlockOwnerMismatch($actor_user_id, $owner_user_id)) {
			$this->revertWooOrderStatus($order_id, $from_status);
			$this->storeOwnerMismatchNotice($order_id, (int) $actor_user_id, $owner_user_id, $from_status, $to_status);
			$this->audit_logger->log(
				'order_action_blocked_owner_mismatch',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array(
					'status' => $from_status,
					'owner_user_id' => $owner_user_id,
				),
				array(
					'status' => $to_status,
					'owner_user_id' => $owner_user_id,
				),
				array('source' => 'woocommerce_order_status_changed')
			);

			return;
		}

		if (! $this->isTransitionAllowed($canonical_from_status, $canonical_to_status)) {
			$this->revertWooOrderStatus($order_id, $from_status);
			$this->storeTransitionBlockedNotice($order_id, $canonical_from_status, $canonical_to_status, $actor_user_id ?? 0);
			$this->audit_logger->log(
				'order_status_transition_not_allowed',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array('status' => $canonical_from_status),
				array('status' => $canonical_to_status),
				array('source' => 'woocommerce_order_status_changed')
			);

			return;
		}

		if ($this->isFailedStatus($canonical_to_status) && ! $this->canTransitionToFailed($canonical_from_status)) {
			$this->audit_logger->log(
				'order_failed_transition_blocked',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
					array('status' => $canonical_from_status),
					array('status' => $canonical_to_status),
					array('source' => 'woocommerce_order_status_changed')
				);

			return;
		}

		$update_data = array(
			'status'         => $canonical_to_status,
			'source_trigger' => 'woocommerce_order_status_changed',
			'updated_at_gmt' => current_time('mysql', true),
		);

		if (null !== $actor_user_id && $actor_user_id > 0 && $owner_user_id <= 0) {
			$update_data['owner_user_id'] = $actor_user_id;
		}

		if ('na-odoslanie' === $canonical_to_status) {
			$update_data['started_at_gmt'] = current_time('mysql', true);
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
			array('status' => $canonical_from_status),
			array('status' => $canonical_to_status),
			array('source' => 'woocommerce_order_status_changed')
		);
	}

	public function applyOrderStatusAfterReassign(int $order_id, string $target_status, int $actor_user_id): bool
	{
		if (
			$order_id <= 0
			|| $actor_user_id <= 0
			|| '' === $target_status
			|| ! function_exists('wc_get_order')
		) {
			return false;
		}

		$target_status = $this->normalizeStatus(sanitize_key($target_status));
		$order = wc_get_order($order_id);

		if (! $order instanceof \WC_Order) {
			return false;
		}

		$current_status = (string) $order->get_status();

		$current_status = $this->normalizeStatus($current_status);

		if ($current_status === $target_status) {
			return true;
		}

		if (! $this->isTransitionAllowed($current_status, $target_status)) {
			$this->audit_logger->log(
				'order_status_transition_not_allowed',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array('status' => $current_status),
				array('status' => $target_status),
				array('source' => 'manual_reassign')
			);

			return false;
		}

		$order->update_status(
			$target_status,
			__('Stav bol použitý po potvrdení zmeny priradenia objednávky.', 'ar-design-reporting'),
			true
		);

		$this->audit_logger->log(
			'order_status_applied_after_reassign',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array('woocommerce_status' => $current_status),
			array('woocommerce_status' => $target_status),
			array('source' => 'manual_reassign')
		);

		return true;
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

	public function assignOrderOwner(int $order_id, int $actor_user_id): void
	{
		if ($order_id <= 0 || $actor_user_id <= 0) {
			return;
		}

		$record = $this->ensureRecord($order_id);
		$old_owner = isset($record['owner_user_id']) ? (int) $record['owner_user_id'] : 0;

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			array(
				'owner_user_id'  => $actor_user_id,
				'updated_at_gmt' => current_time('mysql', true),
				'source_trigger' => 'manual_reassign',
			)
		);

		$this->setOrderMetaManagerUserId($order_id, $actor_user_id);

		$this->audit_logger->log(
			'order_owner_reassigned',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array('owner_user_id' => $old_owner > 0 ? $old_owner : null),
			array('owner_user_id' => $actor_user_id),
			array('source' => 'manual_reassign')
		);
	}

	public function finishProcessing(int $order_id, int $actor_user_id): void
	{
		$record = $this->ensureRecord($order_id);
		if (empty($record)) {
			return;
		}

		$current_status = $this->normalizeStatus((string) ($record['status'] ?? ''));

		if (! $this->isTransitionAllowed($current_status, 'zabalena')) {
			$this->audit_logger->log(
				'order_status_transition_not_allowed',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array('status' => $current_status),
				array('status' => 'zabalena'),
				array('source' => 'manual_packed')
			);
			return;
		}

		$finished_at = current_time('mysql', true);
		$status = $this->updateWooOrderToPackedStatus($order_id, $actor_user_id);

		if ('' === $status) {
			$status = 'zabalena';
		}

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			array(
				'status'         => $status,
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
				'status' => $status,
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

		$current_status = $this->normalizeStatus((string) ($record['status'] ?? ''));

		if (! $this->isTransitionAllowed($current_status, 'vybavena')) {
			$this->audit_logger->log(
				'order_status_transition_not_allowed',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array('status' => $current_status),
				array('status' => 'vybavena'),
				array('source' => 'manual_fulfillment')
			);
			return;
		}

		$finished_at = current_time('mysql', true);
		$process_started_at = ! empty($record['created_at_gmt']) ? (string) $record['created_at_gmt'] : current_time('mysql', true);
		$processing_seconds = $this->calculateProcessingSeconds($process_started_at, $finished_at);
		$status = $this->updateWooOrderToFulfilledStatus($order_id, $actor_user_id);

		if ('' === $status) {
			$status = 'vybavena';
		}

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			array(
				'owner_user_id'      => $actor_user_id,
				'finished_at_gmt'    => $finished_at,
				'processing_seconds' => $processing_seconds,
				'status'             => $status,
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
				'status'             => $status,
			),
			array('source' => 'manual_fulfillment')
		);
	}

	public function markOrderCancelled(int $order_id, int $actor_user_id): void
	{
		if ($order_id <= 0 || $actor_user_id <= 0 || ! function_exists('wc_get_order')) {
			return;
		}

		$record = $this->ensureRecord($order_id);
		$order  = wc_get_order($order_id);

		if (! $order instanceof \WC_Order) {
			return;
		}

		$target_status  = $this->resolveCancelledStatusSlug();
		$current_status = $this->normalizeStatus((string) $order->get_status());

		if ('' === $target_status) {
			return;
		}

		$target_status = $this->normalizeStatus($target_status);

		if (! $this->isTransitionAllowed($current_status, $target_status)) {
			$this->audit_logger->log(
				'order_status_transition_not_allowed',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array('status' => $current_status),
				array('status' => $target_status),
				array('source' => 'manual_cancel_instead_delete')
			);
			return;
		}

		if ($current_status !== $target_status) {
			$order->update_status(
				$target_status,
				__('Objednávka bola označená ako Zrušená namiesto odstránenia.', 'ar-design-reporting'),
				true
			);
		}

		$this->order_processing_repository->updateByOrderId(
			$order_id,
			array(
				'owner_user_id'  => $actor_user_id,
				'status'         => $target_status,
				'source_trigger' => 'manual_cancel_instead_delete',
				'updated_at_gmt' => current_time('mysql', true),
			)
		);

		$this->audit_logger->log(
			'order_marked_cancelled',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array(
				'status' => $record['status'] ?? $current_status,
			),
			array(
				'status' => $target_status,
			),
			array('source' => 'manual_cancel_instead_delete')
		);
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

	private function updateWooOrderToFulfilledStatus(int $order_id, int $actor_user_id): string
	{
		if (! function_exists('wc_get_order') || ! function_exists('wc_get_order_statuses')) {
			return '';
		}

		$order = wc_get_order($order_id);

		if (! $order instanceof \WC_Order) {
			return '';
		}

		$target_status = $this->resolveFulfilledStatusSlug();
		$current_status = (string) $order->get_status();

		if ('' === $target_status || $target_status === $current_status) {
			return $current_status;
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

		return $target_status;
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

	private function updateWooOrderToPackedStatus(int $order_id, int $actor_user_id): string
	{
		if (! function_exists('wc_get_order') || ! function_exists('wc_get_order_statuses')) {
			return '';
		}

		$order = wc_get_order($order_id);

		if (! $order instanceof \WC_Order) {
			return '';
		}

		$target_status = $this->resolvePackedStatusSlug();
		$current_status = (string) $order->get_status();

		if ('' === $target_status || $target_status === $current_status) {
			return $current_status;
		}

		$order->update_status(
			$target_status,
			__('Stav bol automaticky nastavený na Zabalená po dokončení balenia.', 'ar-design-reporting'),
			true
		);

		$this->audit_logger->log(
			'order_status_set_to_packed',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array('woocommerce_status' => $current_status),
			array('woocommerce_status' => $target_status),
			array('source' => 'manual_packed')
		);

		return $target_status;
	}

	private function resolvePackedStatusSlug(): string
	{
		$statuses = wc_get_order_statuses();

		if (! is_array($statuses) || empty($statuses)) {
			return '';
		}

		foreach ($statuses as $status_key => $label) {
			$key   = (string) $status_key;
			$title = trim(wp_strip_all_tags((string) $label));
			$slug  = 0 === strpos($key, 'wc-') ? substr($key, 3) : $key;

			if ('zabalena' === $slug || 'Zabalená' === $title || 'Zabalena' === $title) {
				return $slug;
			}
		}

		return '';
	}

	private function resolveCancelledStatusSlug(): string
	{
		if (! function_exists('wc_get_order_statuses')) {
			return '';
		}

		$statuses = wc_get_order_statuses();

		if (! is_array($statuses) || empty($statuses)) {
			return '';
		}

		foreach ($statuses as $status_key => $label) {
			$key   = (string) $status_key;
			$title = trim(wp_strip_all_tags((string) $label));
			$slug  = 0 === strpos($key, 'wc-') ? substr($key, 3) : $key;

			if (
				'cancelled' === $slug
				|| 'zrusena' === $slug
				|| 'Zrušená' === $title
				|| 'Zrusena' === $title
				|| 'Zrušena' === $title
			) {
				return $slug;
			}
		}

		if (isset($statuses['wc-cancelled'])) {
			return 'cancelled';
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

	private function normalizeStatus(string $status): string
	{
		$status = sanitize_key($status);

		$aliases = array(
			'caka-sa-na-platbu' => 'pending',
			'spracovava-sa'     => 'processing',
			'pozastavena'       => 'on-hold',
			'zrusena'           => 'cancelled',
			'refundovana'       => 'refunded',
			'neuspesna'         => 'failed',
			'completed'         => 'vybavena',
		);

		return $aliases[$status] ?? $status;
	}

	private function isTransitionAllowed(string $from_status, string $to_status): bool
	{
		$from_status = $this->normalizeStatus($from_status);
		$to_status   = $this->normalizeStatus($to_status);

		if ('' === $from_status || '' === $to_status || $from_status === $to_status) {
			return true;
		}

		$allowed = array(
			'new'          => array('pending', 'processing', 'on-hold', 'na-odoslanie', 'cancelled'),
			'pending'      => array('na-odoslanie', 'cancelled', 'on-hold'),
			'processing'   => array('na-odoslanie', 'cancelled', 'on-hold'),
			'on-hold'      => array('na-odoslanie', 'cancelled'),
			'na-odoslanie' => array('zabalena', 'on-hold', 'cancelled'),
			'zabalena'     => array('vybavena', 'on-hold', 'cancelled'),
			'vybavena'     => array('failed', 'refunded'),
			'failed'       => array('on-hold', 'cancelled'),
			'cancelled'    => array('refunded'),
		);

		if (! isset($allowed[$from_status])) {
			return false;
		}

		return in_array($to_status, $allowed[$from_status], true);
	}

	private function shouldBlockOwnerMismatch(?int $actor_user_id, int $owner_user_id): bool
	{
		return null !== $actor_user_id && $actor_user_id > 0 && $owner_user_id > 0 && $actor_user_id !== $owner_user_id;
	}

	private function revertWooOrderStatus(int $order_id, string $from_status): void
	{
		if ($order_id <= 0 || '' === $from_status || ! function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);

		if (! $order instanceof \WC_Order) {
			return;
		}

		$current_status = (string) $order->get_status();

		if ($current_status === $from_status) {
			return;
		}

		$this->is_reverting_blocked_status = true;

		try {
			$order->update_status(
				$from_status,
				__('Zmena stavu bola zablokovaná, objednávka je priradená inému používateľovi.', 'ar-design-reporting'),
				true
			);
		} finally {
			$this->is_reverting_blocked_status = false;
		}
	}

	private function storeOwnerMismatchNotice(int $order_id, int $actor_user_id, int $owner_user_id, string $from_status, string $to_status): void
	{
		if ($actor_user_id <= 0 || ! function_exists('set_transient')) {
			return;
		}

		set_transient(
			self::OWNER_MISMATCH_TRANSIENT_PREFIX . $actor_user_id,
			array(
				'order_id'       => $order_id,
				'expected_owner' => $owner_user_id,
				'from_status'    => $from_status,
				'to_status'      => $to_status,
				'action'         => 'status_change',
			),
				300
		);
	}

	private function storeTransitionBlockedNotice(int $order_id, string $from_status, string $to_status, int $actor_user_id): void
	{
		if ($actor_user_id <= 0 || ! function_exists('set_transient')) {
			return;
		}

		set_transient(
			self::TRANSITION_BLOCKED_TRANSIENT_PREFIX . $actor_user_id,
			array(
				'order_id'    => $order_id,
				'from_status' => $from_status,
				'to_status'   => $to_status,
			),
			300
		);
	}

	/**
	 * @param array<string, mixed> $existing_record
	 */
	private function resolveAssignedManagerUserId(int $order_id, array $existing_record): int
	{
		if (! empty($existing_record['owner_user_id'])) {
			return (int) $existing_record['owner_user_id'];
		}

		$current_meta_manager_id = $this->getOrderMetaManagerUserId($order_id);

		if ($current_meta_manager_id > 0) {
			return $current_meta_manager_id;
		}

		$default_manager_id = (int) get_option('ard_reporting_default_manager_user_id', 0);

		if ($default_manager_id > 0) {
			$this->setOrderMetaManagerUserId($order_id, $default_manager_id);
		}

		return $default_manager_id;
	}

	private function getOrderMetaManagerUserId(int $order_id): int
	{
		if ($order_id <= 0) {
			return 0;
		}

		if (! function_exists('wc_get_order')) {
			return 0;
		}

		$order = wc_get_order($order_id);
		if (! $order instanceof \WC_Order) {
			return 0;
		}

		return (int) $order->get_meta(self::MANAGER_META_KEY, true);
	}

	private function setOrderMetaManagerUserId(int $order_id, int $manager_user_id): void
	{
		if ($order_id <= 0 || $manager_user_id <= 0 || ! function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);

		if (! $order instanceof \WC_Order) {
			return;
		}

		$order->update_meta_data(self::MANAGER_META_KEY, $manager_user_id);
		$order->save();
	}

	private function resolveOrderCreatedAtGmt(int $order_id): string
	{
		if ($order_id > 0 && function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);

			if ($order instanceof \WC_Order) {
				$date_created = $order->get_date_created();

				if ($date_created instanceof \WC_DateTime) {
					return gmdate('Y-m-d H:i:s', $date_created->getTimestamp());
				}
			}
		}

		return current_time('mysql', true);
	}
}
