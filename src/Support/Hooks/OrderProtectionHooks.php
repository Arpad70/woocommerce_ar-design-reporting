<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Support\Hooks;

use ArDesign\Reporting\Domain\Audit\AuditLogger;

final class OrderProtectionHooks
{
	private AuditLogger $audit_logger;

	public function __construct(AuditLogger $audit_logger)
	{
		$this->audit_logger = $audit_logger;
	}

	public function register(): void
	{
		add_filter('pre_delete_post', array($this, 'preventPermanentDelete'), 10, 2);
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

		$this->audit_logger->log(
			'order_permanent_delete_blocked',
			'order',
			(int) $post->ID,
			(int) $post->ID,
			get_current_user_id() ?: null,
			array(),
			array(),
			array(
				'source' => 'pre_delete_post',
			)
		);

		return false;
	}
}
