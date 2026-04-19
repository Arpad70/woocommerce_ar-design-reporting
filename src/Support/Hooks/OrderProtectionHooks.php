<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Support\Hooks;

use ArDesign\Reporting\Domain\Audit\AuditLogger;

final class OrderProtectionHooks
{
	private const DELETE_BLOCKED_TRANSIENT_PREFIX = 'ard_delete_blocked_';

	private AuditLogger $audit_logger;

	/**
	 * @var array<string, bool>
	 */
	private array $blocked_events = array();

	public function __construct(AuditLogger $audit_logger)
	{
		$this->audit_logger = $audit_logger;
	}

	public function register(): void
	{
		add_filter('pre_delete_post', array($this, 'preventPermanentDelete'), 10, 2);
		add_filter('pre_trash_post', array($this, 'preventTrashOrder'), 10, 3);
		add_filter('woocommerce_pre_delete_order', array($this, 'preventWooOrderDelete'), 10, 3);
	}

	/**
	 * @param mixed $delete
	 * @return mixed
	 */
	public function preventPermanentDelete($delete, \WP_Post $post)
	{
		if ('shop_order' !== $post->post_type) {
			return $delete;
		}

		$this->blockDeleteAttempt((int) $post->ID, 'delete', 'pre_delete_post');

		return false;
	}

	/**
	 * @param mixed $trash
	 * @param mixed $previous_status
	 * @return mixed
	 */
	public function preventTrashOrder($trash, \WP_Post $post, $previous_status)
	{
		if ('shop_order' !== $post->post_type) {
			return $trash;
		}

		$this->blockDeleteAttempt((int) $post->ID, 'trash', 'pre_trash_post');

		return false;
	}

	/**
	 * @param mixed $check
	 * @param mixed $order
	 * @return mixed
	 */
	public function preventWooOrderDelete($check, $order, bool $force_delete)
	{
		if (! $order instanceof \WC_Order) {
			return $check;
		}

		$this->blockDeleteAttempt(
			(int) $order->get_id(),
			$force_delete ? 'delete' : 'trash',
			'woocommerce_pre_delete_order'
		);

		return false;
	}

	private function blockDeleteAttempt(int $order_id, string $attempt, string $source): void
	{
		if ($order_id <= 0) {
			return;
		}

		$attempt = sanitize_key($attempt);

		if (! in_array($attempt, array('delete', 'trash'), true)) {
			$attempt = 'delete';
		}

		$dedupe_key = $order_id . ':' . $attempt . ':' . $source;

		if (isset($this->blocked_events[$dedupe_key])) {
			return;
		}

		$this->blocked_events[$dedupe_key] = true;

		$actor_user_id = get_current_user_id() ?: null;

		$this->storeDeleteBlockedNotice($order_id, $attempt, $actor_user_id);

		$this->audit_logger->log(
			'order_delete_attempt_blocked',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array(),
			array(
				'attempt' => $attempt,
			),
			array(
				'source' => $source,
			)
		);

		if ('delete' === $attempt) {
			$this->audit_logger->log(
				'order_permanent_delete_blocked',
				'order',
				$order_id,
				$order_id,
				$actor_user_id,
				array(),
				array(),
				array(
					'source' => $source,
				)
			);
		}
	}

	private function storeDeleteBlockedNotice(int $order_id, string $attempt, ?int $actor_user_id): void
	{
		if (null === $actor_user_id || $actor_user_id <= 0 || ! function_exists('set_transient')) {
			return;
		}

		set_transient(
			self::DELETE_BLOCKED_TRANSIENT_PREFIX . $actor_user_id,
			array(
				'order_id' => $order_id,
				'attempt'  => $attempt,
			),
			300
		);
	}
}
