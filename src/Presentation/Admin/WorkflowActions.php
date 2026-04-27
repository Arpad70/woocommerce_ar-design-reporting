<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Presentation\Admin;

use ArDesign\Reporting\Application\Emails\EmailReporter;
use ArDesign\Reporting\Application\Exports\ExportManager;
use ArDesign\Reporting\Application\Reports\DashboardQueryService;
use ArDesign\Reporting\Domain\Processing\ProcessingService;

final class WorkflowActions
{
	private ProcessingService $processing_service;

	private ExportManager $export_manager;

	private EmailReporter $email_reporter;

	private DashboardQueryService $dashboard_query_service;

	public function __construct(
		ProcessingService $processing_service,
		ExportManager $export_manager,
		EmailReporter $email_reporter,
		DashboardQueryService $dashboard_query_service
	)
	{
		$this->processing_service = $processing_service;
		$this->export_manager     = $export_manager;
		$this->email_reporter     = $email_reporter;
		$this->dashboard_query_service = $dashboard_query_service;
	}

	public function register(): void
	{
		add_action('admin_post_ard_take_over_order', array($this, 'handleTakeOver'));
		add_action('admin_post_ard_finish_processing', array($this, 'handleFinishProcessing'));
		add_action('admin_post_ard_complete_fulfillment', array($this, 'handleCompleteFulfillment'));
		add_action('admin_post_ard_mark_order_cancelled', array($this, 'handleMarkOrderCancelled'));
		add_action('admin_post_ard_export_csv', array($this, 'handleExportCsv'));
		add_action('admin_post_ard_export_xlsx', array($this, 'handleExportXlsx'));
		add_action('admin_post_ard_export_audit_xlsx', array($this, 'handleExportAuditXlsx'));
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

		if ($order_id > 0) {
			$this->processing_service->completeFulfillment($order_id, $actor_user_id);
		}

		$this->redirectBack('complete_fulfillment', $order_id, 0, $redirect_to);
	}

	public function handleMarkOrderCancelled(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_mark_order_cancelled');

		$order_id      = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$redirect_to   = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
		$actor_user_id = get_current_user_id();

		if ($order_id > 0) {
			$this->processing_service->markOrderCancelled($order_id, $actor_user_id);
		}

		$this->redirectBack('marked_cancelled', $order_id, 0, $redirect_to);
	}

	public function handleExportCsv(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_export_data');

		$raw_filters = wp_unslash($_POST);
		$filters = $this->export_manager->normalizeFilters(is_array($raw_filters) ? $raw_filters : array());
		$this->export_manager->streamProcessingCsv($filters);
		exit;
	}

	public function handleExportXlsx(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_export_data');

		$raw_filters = wp_unslash($_POST);
		$filters = $this->export_manager->normalizeFilters(is_array($raw_filters) ? $raw_filters : array());
		$this->export_manager->streamProcessingXlsx($filters);
		exit;
	}

	public function handleExportAuditXlsx(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_export_audit_xlsx');

		$raw_filters = wp_unslash($_POST);
		$filters = $this->export_manager->normalizeFilters(is_array($raw_filters) ? $raw_filters : array());
		$event_type = isset($raw_filters['audit_event_type']) ? sanitize_key((string) $raw_filters['audit_event_type']) : '';
		$events = $this->dashboard_query_service->getRecentAuditEvents($filters, $event_type, 1000);
		$this->export_manager->streamAuditEventsXlsx($events, $event_type);
		exit;
	}

	public function handleSaveEmailReport(): void
	{
		$this->ensurePermissions();
		$this->redirectBack('digest_disabled');
	}

	public function handleSendDigestNow(): void
	{
		$this->ensurePermissions();
		$this->redirectBack('digest_disabled');
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
