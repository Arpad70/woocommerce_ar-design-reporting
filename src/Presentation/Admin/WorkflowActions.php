<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Presentation\Admin;

use ArDesign\Reporting\Application\Emails\EmailReporter;
use ArDesign\Reporting\Application\Exports\ExportManager;
use ArDesign\Reporting\Domain\Processing\ProcessingService;

final class WorkflowActions
{
	private ProcessingService $processing_service;

	private ExportManager $export_manager;

	private EmailReporter $email_reporter;

	public function __construct(
		ProcessingService $processing_service,
		ExportManager $export_manager,
		EmailReporter $email_reporter
	)
	{
		$this->processing_service = $processing_service;
		$this->export_manager     = $export_manager;
		$this->email_reporter     = $email_reporter;
	}

	public function register(): void
	{
		add_action('admin_post_ard_take_over_order', array($this, 'handleTakeOver'));
		add_action('admin_post_ard_finish_processing', array($this, 'handleFinishProcessing'));
		add_action('admin_post_ard_complete_fulfillment', array($this, 'handleCompleteFulfillment'));
		add_action('admin_post_ard_reassign_and_run', array($this, 'handleReassignAndRun'));
		add_action('admin_post_ard_reassign_and_apply_status', array($this, 'handleReassignAndApplyStatus'));
		add_action('admin_post_ard_save_default_manager', array($this, 'handleSaveDefaultManager'));
		add_action('admin_post_ard_export_csv', array($this, 'handleExportCsv'));
		add_action('admin_post_ard_save_email_report', array($this, 'handleSaveEmailReport'));
		add_action('admin_post_ard_send_digest_now', array($this, 'handleSendDigestNow'));
	}

	public function handleTakeOver(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_take_over_order');

		$order_id    = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
		$actor_user_id = get_current_user_id();

		if (! $this->ensureOrderOwnershipOrPrompt($order_id, $actor_user_id, 'take_over', $redirect_to)) {
			return;
		}

		if ($order_id > 0) {
			$this->processing_service->takeOverOrder($order_id, $actor_user_id);
		}

		$this->redirectBack('take_over', $order_id, 0, $redirect_to);
	}

	public function handleFinishProcessing(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_finish_processing');

		$order_id    = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
		$actor_user_id = get_current_user_id();

		if (! $this->ensureOrderOwnershipOrPrompt($order_id, $actor_user_id, 'finish_processing', $redirect_to)) {
			return;
		}

		if ($order_id > 0) {
			$this->processing_service->finishProcessing($order_id, $actor_user_id);
		}

		$this->redirectBack('finish_processing', $order_id, 0, $redirect_to);
	}

	public function handleCompleteFulfillment(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_complete_fulfillment');

		$order_id    = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
		$actor_user_id = get_current_user_id();

		if (! $this->ensureOrderOwnershipOrPrompt($order_id, $actor_user_id, 'complete_fulfillment', $redirect_to)) {
			return;
		}

		if ($order_id > 0) {
			$this->processing_service->completeFulfillment($order_id, $actor_user_id);
		}

		$this->redirectBack('complete_fulfillment', $order_id, 0, $redirect_to);
	}

	public function handleSaveDefaultManager(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_save_default_manager');

		$manager_user_id = isset($_POST['manager_user_id']) ? absint(wp_unslash($_POST['manager_user_id'])) : 0;
		update_option('ard_reporting_default_manager_user_id', $manager_user_id > 0 ? $manager_user_id : 0);

		$this->redirectBack('manager_saved');
	}

	public function handleReassignAndRun(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_reassign_and_run');

		$order_id    = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$requested_action = isset($_POST['requested_action']) ? sanitize_key(wp_unslash($_POST['requested_action'])) : '';
		$redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
		$actor_user_id = get_current_user_id();

		if ($order_id <= 0 || $actor_user_id <= 0) {
			$this->redirectBack('owner_mismatch_invalid', $order_id, 0, $redirect_to);
		}

		$this->processing_service->assignOrderOwner($order_id, $actor_user_id);

		if ('take_over' === $requested_action) {
			$this->processing_service->takeOverOrder($order_id, $actor_user_id);
		}

		if ('finish_processing' === $requested_action) {
			$this->processing_service->finishProcessing($order_id, $actor_user_id);
		}

		if ('complete_fulfillment' === $requested_action) {
			$this->processing_service->completeFulfillment($order_id, $actor_user_id);
		}

		$this->redirectBack(
			'reassigned_and_completed',
			$order_id,
			0,
			$redirect_to,
			array(
				'requested_action' => $requested_action,
			)
		);
	}

	public function handleReassignAndApplyStatus(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_reassign_and_apply_status');

		$order_id      = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$target_status = isset($_POST['target_status']) ? sanitize_key(wp_unslash($_POST['target_status'])) : '';
		$redirect_to   = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
		$actor_user_id = get_current_user_id();

		if ($order_id <= 0 || $actor_user_id <= 0 || '' === $target_status) {
			$this->redirectBack('owner_mismatch_invalid', $order_id, 0, $redirect_to);
		}

		$this->processing_service->assignOrderOwner($order_id, $actor_user_id);
		$applied = $this->processing_service->applyOrderStatusAfterReassign($order_id, $target_status, $actor_user_id);

		$this->redirectBack(
			$applied ? 'reassigned_and_status_applied' : 'owner_mismatch_invalid',
			$order_id,
			0,
			$redirect_to,
			array(
				'target_status' => $target_status,
			)
		);
	}

	public function handleExportCsv(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_export_csv');

		$raw_filters = wp_unslash($_POST);
		$filters = $this->export_manager->normalizeFilters(is_array($raw_filters) ? $raw_filters : array());
		$this->export_manager->streamProcessingCsv($filters);
		exit;
	}

	public function handleSaveEmailReport(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_save_email_report');

		$email    = isset($_POST['recipient_email']) ? sanitize_email(wp_unslash($_POST['recipient_email'])) : '';
		$schedule = isset($_POST['schedule_key']) ? sanitize_key(wp_unslash($_POST['schedule_key'])) : 'daily';
		$active   = isset($_POST['is_active']) ? (bool) absint(wp_unslash($_POST['is_active'])) : false;
		$saved    = $this->email_reporter->saveConfiguration($email, $schedule, $active);

		$this->redirectBack($saved ? 'email_saved' : 'email_save_failed');
	}

	public function handleSendDigestNow(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_send_digest_now');

		$schedule = isset($_POST['schedule_key']) ? sanitize_key(wp_unslash($_POST['schedule_key'])) : 'daily';
		$sent     = $this->email_reporter->sendScheduledDigest($schedule);

		$this->redirectBack('digest_sent', 0, $sent);
	}

	private function ensurePermissions(): void
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			wp_die(esc_html__('Na vykonanie tejto akcie nemáte oprávnenie.', 'ar-design-reporting'));
		}
	}

	private function redirectBack(string $action, int $order_id = 0, int $sent = 0, string $redirect_to = '', array $extra_args = array()): void
	{
		$args = array('ard_admin' => $action);

		if ($order_id > 0) {
			$args['order_id'] = $order_id;
		}

		if ($sent > 0) {
			$args['sent'] = $sent;
		}

		if (! empty($extra_args)) {
			foreach ($extra_args as $arg_key => $arg_value) {
				if (! is_string($arg_key)) {
					continue;
				}

				$args[$arg_key] = is_scalar($arg_value) ? (string) $arg_value : '';
			}
		}

		$base_url = $this->resolveAdminRedirect($redirect_to, $order_id);

		$url = add_query_arg($args, $base_url);

		wp_safe_redirect($url);
		exit;
	}

	private function ensureOrderOwnershipOrPrompt(int $order_id, int $actor_user_id, string $requested_action, string $redirect_to): bool
	{
		if ($order_id <= 0 || $actor_user_id <= 0) {
			return true;
		}

		$workflow = $this->processing_service->getWorkflowSummary($order_id);
		$owner_user_id = isset($workflow['owner_user_id']) ? (int) $workflow['owner_user_id'] : 0;

		if ($owner_user_id <= 0 || $owner_user_id === $actor_user_id) {
			return true;
		}

		$this->redirectBack(
			'owner_mismatch',
			$order_id,
			0,
			$redirect_to,
			array(
				'expected_owner'   => (string) $owner_user_id,
				'requested_action' => $requested_action,
			)
		);

		return false;
	}

	private function resolveAdminRedirect(string $redirect_to, int $order_id): string
	{
		$candidates = array();

		if ('' !== $redirect_to) {
			$candidates[] = wp_validate_redirect($redirect_to, '');
		}

		$referer = wp_get_referer();
		if (is_string($referer) && '' !== $referer) {
			$candidates[] = wp_validate_redirect($referer, '');
		}

		foreach ($candidates as $candidate) {
			if (! is_string($candidate) || '' === $candidate) {
				continue;
			}

			$path = (string) wp_parse_url($candidate, PHP_URL_PATH);

			if (
				false !== strpos($path, '/wp-admin/') &&
				false === strpos($path, '/wp-admin/wp-admin/')
			) {
				return $candidate;
			}
		}

		$order_url = $this->getOrderEditUrl($order_id);

		if ('' !== $order_url) {
			return $order_url;
		}

		return add_query_arg(array('page' => 'ar-design-reporting'), admin_url('admin.php'));
	}

	private function getOrderEditUrl(int $order_id): string
	{
		if ($order_id <= 0) {
			return '';
		}

		if (
			class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) &&
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return add_query_arg(
				array(
					'page'   => 'wc-orders',
					'action' => 'edit',
					'id'     => $order_id,
				),
				admin_url('admin.php')
			);
		}

		return add_query_arg(
			array(
				'post'   => $order_id,
				'action' => 'edit',
			),
			admin_url('post.php')
		);
	}
}
