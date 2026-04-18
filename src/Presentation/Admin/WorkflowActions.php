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

		if ($order_id > 0) {
			$this->processing_service->takeOverOrder($order_id, get_current_user_id());
		}

		$this->redirectBack('take_over', $order_id, 0, $redirect_to);
	}

	public function handleFinishProcessing(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_finish_processing');

		$order_id    = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
		$redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';

		if ($order_id > 0) {
			$this->processing_service->finishProcessing($order_id, get_current_user_id());
		}

		$this->redirectBack('finish_processing', $order_id, 0, $redirect_to);
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

	private function redirectBack(string $action, int $order_id = 0, int $sent = 0, string $redirect_to = ''): void
	{
		$args = array('ard_admin' => $action);

		if ($order_id > 0) {
			$args['order_id'] = $order_id;
		}

		if ($sent > 0) {
			$args['sent'] = $sent;
		}

		$base_url = '' !== $redirect_to ? wp_validate_redirect($redirect_to, '') : '';

		if ('' === $base_url) {
			$base_url = add_query_arg(array('page' => 'ar-design-reporting'), admin_url('admin.php'));
		}

		$url = add_query_arg($args, $base_url);

		wp_safe_redirect($url);
		exit;
	}
}
