<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Presentation\Admin;

use ArDesign\Reporting\Application\Emails\EmailReporter;
use ArDesign\Reporting\Application\Exports\ExportManager;
use ArDesign\Reporting\Application\Reports\DashboardQueryService;
use ArDesign\Reporting\Domain\Orders\OrderArchiveService;
use ArDesign\Reporting\Domain\Processing\ProcessingService;

final class DashboardPage
{
	private DashboardQueryService $dashboard_query_service;

	private ExportManager $export_manager;

	private EmailReporter $email_reporter;

	private ProcessingService $processing_service;

	private OrderArchiveService $order_archive_service;

	/**
	 * @var array<string, string>
	 */
	private array $plugin_meta;

	/**
	 * @param array<string, string> $plugin_meta
	 */
	public function __construct(
		DashboardQueryService $dashboard_query_service,
		ExportManager $export_manager,
		EmailReporter $email_reporter,
		ProcessingService $processing_service,
		OrderArchiveService $order_archive_service,
		array $plugin_meta
	) {
		$this->dashboard_query_service = $dashboard_query_service;
		$this->export_manager          = $export_manager;
		$this->email_reporter          = $email_reporter;
		$this->processing_service      = $processing_service;
		$this->order_archive_service   = $order_archive_service;
		$this->plugin_meta             = $plugin_meta;
	}

	public function render(): void
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			wp_die(esc_html__('Na zobrazenie tejto stránky nemáte oprávnenie.', 'ar-design-reporting'));
		}

		$data         = $this->dashboard_query_service->getDashboardData();
		$export_info  = $this->export_manager->describeCsvExport();
		$email_info   = $this->email_reporter->describeDigest();
		$email_configs = $this->email_reporter->getConfigurations();
		$kpis         = is_array($data['kpis']) ? $data['kpis'] : array();
		$tables       = is_array($data['tables']) ? $data['tables'] : array();
		$missing      = is_array($data['missing_tables']) ? $data['missing_tables'] : array();
		$sample_order = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
		$export_status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
		$export_classification = isset($_GET['classification']) ? sanitize_key(wp_unslash($_GET['classification'])) : '';
		$export_date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
		$export_date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
		$workflow     = $sample_order > 0 ? $this->processing_service->getWorkflowSummary($sample_order) : array();
		$order_archives = $sample_order > 0 ? $this->order_archive_service->getRecentArchivesForOrder($sample_order, 10) : array();
		$recent_deleted_archives = $this->order_archive_service->getRecentDeletedArchives(20);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('AR Design Reporting', 'ar-design-reporting') . '</h1>';
		echo '<p>' . esc_html__('Skeleton pluginu je připravený pro audit, workflow metadata, exporty a základní emailing.', 'ar-design-reporting') . '</p>';

		echo '<table class="widefat striped" style="max-width:960px;margin-top:16px;">';
		echo '<tbody>';
		echo '<tr><th style="width:260px;">' . esc_html__('Verzia pluginu', 'ar-design-reporting') . '</th><td>' . esc_html((string) $this->plugin_meta['version']) . '</td></tr>';
		echo '<tr><th>' . esc_html__('DB verzia', 'ar-design-reporting') . '</th><td>' . esc_html((string) get_option('ard_reporting_db_version', 'n/a')) . '</td></tr>';
		echo '<tr><th>' . esc_html__('HPOS režim', 'ar-design-reporting') . '</th><td>' . esc_html(! empty($data['hpos_enabled']) ? __('aktivní', 'ar-design-reporting') : __('neaktivní', 'ar-design-reporting')) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Migrace', 'ar-design-reporting') . '</th><td>' . esc_html(empty($missing) ? __('v pořádku', 'ar-design-reporting') : __('chybí některé tabulky', 'ar-design-reporting')) . '</td></tr>';
		echo '</tbody>';
		echo '</table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Připravené moduly', 'ar-design-reporting') . '</h2>';
		echo '<ul style="list-style:disc;padding-left:18px;">';
		echo '<li>' . esc_html__('Audit log a archivace smazaných objednávek', 'ar-design-reporting') . '</li>';
		echo '<li>' . esc_html__('Workflow metadata pro ownera, klasifikaci a čas balení', 'ar-design-reporting') . '</li>';
		echo '<li>' . esc_html__('Dashboard query vrstva pro admin, export a emailing', 'ar-design-reporting') . '</li>';
		echo '</ul>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('KPI snapshot', 'ar-design-reporting') . '</h2>';
		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr><th>' . esc_html__('Metrika', 'ar-design-reporting') . '</th><th>' . esc_html__('Hodnota', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		foreach ($kpis as $key => $value) {
			echo '<tr>';
			echo '<td>' . esc_html($this->getKpiLabel((string) $key)) . '</td>';
			echo '<td>' . esc_html($this->formatKpiValue((string) $key, $value)) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Reporting tabulky', 'ar-design-reporting') . '</h2>';
		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr><th>' . esc_html__('Klíč', 'ar-design-reporting') . '</th><th>' . esc_html__('Název', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		foreach ($tables as $key => $table_name) {
			echo '<tr>';
			echo '<td>' . esc_html($this->getTableLabel((string) $key)) . '</td>';
			echo '<td><code>' . esc_html((string) $table_name) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Export a emailing', 'ar-design-reporting') . '</h2>';
		echo '<p><strong>' . esc_html__('Export', 'ar-design-reporting') . ':</strong> ' . esc_html(sprintf('%s / %s', (string) $export_info['format'], (string) $export_info['scope'])) . '</p>';
		echo '<p><strong>' . esc_html__('Email digest', 'ar-design-reporting') . ':</strong> ' . esc_html(sprintf('%s / %s', (string) $email_info['type'], (string) $email_info['frequency'])) . '</p>';

		echo '<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #dcdcde;padding:16px;min-width:320px;">';
		wp_nonce_field('ard_export_csv');
		echo '<input type="hidden" name="action" value="ard_export_csv" />';
		echo '<h3 style="margin-top:0;">' . esc_html__('CSV export', 'ar-design-reporting') . '</h3>';
		echo '<p><label for="ard-export-status">' . esc_html__('Stav workflow', 'ar-design-reporting') . '</label></p>';
		echo '<p><select id="ard-export-status" name="status" class="regular-text">';
		echo '<option value="">' . esc_html__('Všechny', 'ar-design-reporting') . '</option>';
		echo '<option value="new"' . selected('new', $export_status, false) . '>new</option>';
		echo '<option value="processing"' . selected('processing', $export_status, false) . '>processing</option>';
		echo '<option value="packed"' . selected('packed', $export_status, false) . '>packed</option>';
		echo '<option value="completed"' . selected('completed', $export_status, false) . '>completed</option>';
		echo '</select></p>';
		echo '<p><label for="ard-export-classification">' . esc_html__('Klasifikace', 'ar-design-reporting') . '</label></p>';
		echo '<p><select id="ard-export-classification" name="classification" class="regular-text">';
		echo '<option value="">' . esc_html__('Všechny', 'ar-design-reporting') . '</option>';
		echo '<option value="standard"' . selected('standard', $export_classification, false) . '>standard</option>';
		echo '<option value="preorder"' . selected('preorder', $export_classification, false) . '>preorder</option>';
		echo '<option value="custom"' . selected('custom', $export_classification, false) . '>custom</option>';
		echo '</select></p>';
		echo '<p><label for="ard-export-date-from">' . esc_html__('Datum od', 'ar-design-reporting') . '</label></p>';
		echo '<p><input id="ard-export-date-from" type="date" name="date_from" value="' . esc_attr($export_date_from) . '" class="regular-text" /></p>';
		echo '<p><label for="ard-export-date-to">' . esc_html__('Datum do', 'ar-design-reporting') . '</label></p>';
		echo '<p><input id="ard-export-date-to" type="date" name="date_to" value="' . esc_attr($export_date_to) . '" class="regular-text" /></p>';
		submit_button(__('Stáhnout CSV', 'ar-design-reporting'), 'primary', 'submit', false);
		echo '</form>';

		echo '<div style="display:flex;flex-direction:column;gap:16px;min-width:360px;max-width:520px;">';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #dcdcde;padding:16px;">';
		wp_nonce_field('ard_save_email_report');
		echo '<input type="hidden" name="action" value="ard_save_email_report" />';
		echo '<h3 style="margin-top:0;">' . esc_html__('Nastavení email digestu', 'ar-design-reporting') . '</h3>';
		echo '<p><label for="ard-recipient-email">' . esc_html__('Příjemce', 'ar-design-reporting') . '</label></p>';
		echo '<p><input id="ard-recipient-email" type="email" name="recipient_email" value="" required class="regular-text" /></p>';
		echo '<p><label for="ard-schedule-key">' . esc_html__('Frekvence', 'ar-design-reporting') . '</label></p>';
		echo '<p><select id="ard-schedule-key" name="schedule_key" class="regular-text">';
		echo '<option value="daily">daily</option>';
		echo '<option value="weekly">weekly</option>';
		echo '</select></p>';
		echo '<p><label><input type="checkbox" name="is_active" value="1" checked /> ' . esc_html__('Aktivní', 'ar-design-reporting') . '</label></p>';
		submit_button(__('Uložit příjemce', 'ar-design-reporting'), 'secondary', 'submit', false);
		echo '</form>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #dcdcde;padding:16px;">';
		wp_nonce_field('ard_send_digest_now');
		echo '<input type="hidden" name="action" value="ard_send_digest_now" />';
		echo '<h3 style="margin-top:0;">' . esc_html__('Spustit digest ručně', 'ar-design-reporting') . '</h3>';
		echo '<p><label for="ard-send-schedule-key">' . esc_html__('Frekvence', 'ar-design-reporting') . '</label></p>';
		echo '<p><select id="ard-send-schedule-key" name="schedule_key" class="regular-text">';
		echo '<option value="daily">daily</option>';
		echo '<option value="weekly">weekly</option>';
		echo '</select></p>';
		submit_button(__('Odeslat teď', 'ar-design-reporting'), 'secondary', 'submit', false);
		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '<h3 style="margin-top:16px;">' . esc_html__('Aktivní konfigurace digestu', 'ar-design-reporting') . '</h3>';
		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr><th>' . esc_html__('Email', 'ar-design-reporting') . '</th><th>' . esc_html__('Frekvence', 'ar-design-reporting') . '</th><th>' . esc_html__('Aktivní', 'ar-design-reporting') . '</th><th>' . esc_html__('Naposledy odesláno (GMT)', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		if (empty($email_configs)) {
			echo '<tr><td colspan="4">' . esc_html__('Zatím nejsou uložené žádné konfigurace.', 'ar-design-reporting') . '</td></tr>';
		} else {
			foreach ($email_configs as $config) {
				echo '<tr>';
				echo '<td>' . esc_html((string) ($config['recipient_email'] ?? '')) . '</td>';
				echo '<td>' . esc_html((string) ($config['schedule_key'] ?? '')) . '</td>';
				echo '<td>' . esc_html(! empty($config['is_active']) ? __('Ano', 'ar-design-reporting') : __('Ne', 'ar-design-reporting')) . '</td>';
				echo '<td>' . esc_html((string) ($config['last_sent_at_gmt'] ?? '')) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Workflow akce', 'ar-design-reporting') . '</h2>';
		echo '<p>' . esc_html__('Workflow akce sú presunuté priamo do administrácie konkrétnej objednávky (detail objednávky vo WooCommerce).', 'ar-design-reporting') . '</p>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Workflow detail objednávky', 'ar-design-reporting') . '</h2>';
		echo '<p>' . esc_html__('Pro rychlou kontrolu lze otevřít dashboard s parametrem `order_id` a zobrazit uložená workflow metadata.', 'ar-design-reporting') . '</p>';
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-bottom:16px;">';
		echo '<input type="hidden" name="page" value="ar-design-reporting" />';
		echo '<input type="number" min="1" name="order_id" value="' . esc_attr((string) $sample_order) . '" class="regular-text" />';
		submit_button(__('Načíst workflow detail', 'ar-design-reporting'), 'secondary', 'submit', false);
		echo '</form>';

		if ($sample_order > 0) {
			echo '<table class="widefat striped" style="max-width:960px;">';
			echo '<thead><tr><th>' . esc_html__('Pole', 'ar-design-reporting') . '</th><th>' . esc_html__('Hodnota', 'ar-design-reporting') . '</th></tr></thead>';
			echo '<tbody>';

			if (empty($workflow)) {
				echo '<tr><td colspan="2">' . esc_html__('Pro zadanou objednávku zatím neexistují workflow metadata.', 'ar-design-reporting') . '</td></tr>';
			} else {
				foreach ($workflow as $key => $value) {
					echo '<tr>';
					echo '<td>' . esc_html($this->getWorkflowFieldLabel((string) $key)) . '</td>';
					echo '<td>' . esc_html($this->formatWorkflowValue((string) $key, $value)) . '</td>';
					echo '</tr>';
				}
			}

			echo '</tbody>';
			echo '</table>';

			echo '<h3 style="margin-top:16px;">' . esc_html__('Archivácie pre zadanú objednávku', 'ar-design-reporting') . '</h3>';

			if (empty($order_archives)) {
				echo '<p>' . esc_html__('Pre túto objednávku zatiaľ neexistuje archivácia.', 'ar-design-reporting') . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:960px;">';
				echo '<thead><tr><th>' . esc_html__('Čas (GMT)', 'ar-design-reporting') . '</th><th>' . esc_html__('Dôvod', 'ar-design-reporting') . '</th><th>' . esc_html__('Snapshot', 'ar-design-reporting') . '</th></tr></thead>';
				echo '<tbody>';

				foreach ($order_archives as $archive) {
					$snapshot = isset($archive['snapshot']) && is_array($archive['snapshot']) ? $archive['snapshot'] : array();
					$snapshot_json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

					echo '<tr>';
					echo '<td>' . esc_html((string) ($archive['created_at_gmt'] ?? '')) . '</td>';
					echo '<td>' . esc_html((string) ($archive['archive_reason'] ?? '')) . '</td>';
					echo '<td><details><summary>' . esc_html__('Zobraziť', 'ar-design-reporting') . '</summary><pre style="white-space:pre-wrap;max-width:640px;">' . esc_html(is_string($snapshot_json) ? $snapshot_json : '') . '</pre></details></td>';
					echo '</tr>';
				}

				echo '</tbody>';
				echo '</table>';
			}
		}

		echo '<h2 style="margin-top:24px;">' . esc_html__('Posledné archivácie zmazaných objednávok', 'ar-design-reporting') . '</h2>';

		if (empty($recent_deleted_archives)) {
			echo '<p>' . esc_html__('Zatiaľ nebola zaznamenaná žiadna archivácia zmazanej objednávky.', 'ar-design-reporting') . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:960px;">';
			echo '<thead><tr><th>' . esc_html__('Order ID', 'ar-design-reporting') . '</th><th>' . esc_html__('Čas (GMT)', 'ar-design-reporting') . '</th><th>' . esc_html__('Používateľ', 'ar-design-reporting') . '</th><th>' . esc_html__('Snapshot', 'ar-design-reporting') . '</th></tr></thead>';
			echo '<tbody>';

			foreach ($recent_deleted_archives as $archive) {
				$snapshot = isset($archive['snapshot']) && is_array($archive['snapshot']) ? $archive['snapshot'] : array();
				$snapshot_json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

				echo '<tr>';
				echo '<td>' . esc_html((string) ($archive['order_id'] ?? 0)) . '</td>';
				echo '<td>' . esc_html((string) ($archive['created_at_gmt'] ?? '')) . '</td>';
				echo '<td>' . esc_html((string) ($archive['actor_user_id'] ?? '')) . '</td>';
				echo '<td><details><summary>' . esc_html__('Zobraziť', 'ar-design-reporting') . '</summary><pre style="white-space:pre-wrap;max-width:640px;">' . esc_html(is_string($snapshot_json) ? $snapshot_json : '') . '</pre></details></td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		}

		echo '<h2 style="margin-top:24px;">' . esc_html__('Ďalší krok', 'ar-design-reporting') . '</h2>';
		echo '<p>' . esc_html__('Workflow akcie sú dostupné priamo v administrácii objednávky a archivácia zmazaných objednávok je dostupná v prehľade vyššie.', 'ar-design-reporting') . '</p>';
		echo '</div>';
	}

	private function getKpiLabel(string $key): string
	{
		$labels = array(
			'total_orders' => __('Všechny sledované objednávky', 'ar-design-reporting'),
			'kpi_orders'   => __('Objednávky započítané do KPI', 'ar-design-reporting'),
			'completed'    => __('Dokončené objednávky', 'ar-design-reporting'),
			'audit_events' => __('Zaznamenané auditní události', 'ar-design-reporting'),
		);

		return $labels[$key] ?? $key;
	}

	/**
	 * @param mixed $value
	 */
	private function formatKpiValue(string $key, $value): string
	{
		if (is_scalar($value) || null === $value) {
			return (string) $value;
		}

		return wp_json_encode($value) ?: '';
	}

	private function getTableLabel(string $key): string
	{
		$labels = array(
			'audit_log'        => __('Auditní log', 'ar-design-reporting'),
			'order_processing' => __('Workflow objednávek', 'ar-design-reporting'),
			'order_archive'    => __('Archiv smazaných objednávek', 'ar-design-reporting'),
			'order_flags'      => __('Příznaky a klasifikace objednávek', 'ar-design-reporting'),
			'email_reports'    => __('Nastavení e-mailových reportů', 'ar-design-reporting'),
		);

		return $labels[$key] ?? $key;
	}

	private function getWorkflowFieldLabel(string $key): string
	{
		$labels = array(
			'id'                 => __('ID záznamu', 'ar-design-reporting'),
			'order_id'           => __('Číslo objednávky', 'ar-design-reporting'),
			'owner_user_id'      => __('Odpovědný uživatel', 'ar-design-reporting'),
			'processing_mode'    => __('Režim zpracování', 'ar-design-reporting'),
			'classification'     => __('Typ objednávky', 'ar-design-reporting'),
			'started_at_gmt'     => __('Začátek balení', 'ar-design-reporting'),
			'finished_at_gmt'    => __('Konec balení', 'ar-design-reporting'),
			'processing_seconds' => __('Čas balení', 'ar-design-reporting'),
			'status'             => __('Stav workflow', 'ar-design-reporting'),
			'is_kpi_included'    => __('Započítat do KPI', 'ar-design-reporting'),
			'source_trigger'     => __('Zdroj vytvoření záznamu', 'ar-design-reporting'),
			'created_at_gmt'     => __('Vytvořeno', 'ar-design-reporting'),
			'updated_at_gmt'     => __('Naposledy upraveno', 'ar-design-reporting'),
		);

		return $labels[$key] ?? $key;
	}

	/**
	 * @param mixed $value
	 */
	private function formatWorkflowValue(string $key, $value): string
	{
		if (null === $value || '' === $value) {
			return __('Nevyplněno', 'ar-design-reporting');
		}

		if ('is_kpi_included' === $key) {
			return ! empty($value) ? __('Ano', 'ar-design-reporting') : __('Ne', 'ar-design-reporting');
		}

		if ('classification' === $key) {
			$labels = array(
				'standard' => __('Standardní objednávka', 'ar-design-reporting'),
				'preorder' => __('Předobjednávka', 'ar-design-reporting'),
				'custom'   => __('Zakázková objednávka', 'ar-design-reporting'),
			);

			return $labels[(string) $value] ?? (string) $value;
		}

		if ('processing_mode' === $key) {
			$labels = array(
				'standard' => __('Běžné zpracování', 'ar-design-reporting'),
			);

			return $labels[(string) $value] ?? (string) $value;
		}

		if ('status' === $key) {
			$labels = array(
				'new'        => __('Nová', 'ar-design-reporting'),
				'processing' => __('Ve zpracování', 'ar-design-reporting'),
				'packed'     => __('Zabalená', 'ar-design-reporting'),
				'completed'  => __('Dokončeno', 'ar-design-reporting'),
			);

			return $labels[(string) $value] ?? (string) $value;
		}

		if ('source_trigger' === $key) {
			$labels = array(
				'woocommerce_new_order'            => __('Automaticky při vytvoření objednávky', 'ar-design-reporting'),
				'woocommerce_order_status_changed' => __('Automaticky při změně stavu objednávky', 'ar-design-reporting'),
				'manual_take_over'                 => __('Ruční převzetí objednávky', 'ar-design-reporting'),
				'manual_finish'                    => __('Ruční dokončení balení', 'ar-design-reporting'),
			);

			return $labels[(string) $value] ?? (string) $value;
		}

		if ('processing_seconds' === $key) {
			return $this->formatDuration((int) $value);
		}

		if (str_ends_with($key, '_gmt')) {
			return $this->formatGmtDate((string) $value);
		}

		if (is_scalar($value)) {
			return (string) $value;
		}

		return wp_json_encode($value) ?: '';
	}

	private function formatDuration(int $seconds): string
	{
		if ($seconds <= 0) {
			return __('0 sekund', 'ar-design-reporting');
		}

		$hours   = (int) floor($seconds / 3600);
		$minutes = (int) floor(($seconds % 3600) / 60);
		$rest    = $seconds % 60;
		$parts   = array();

		if ($hours > 0) {
			$parts[] = sprintf(__('%d h', 'ar-design-reporting'), $hours);
		}

		if ($minutes > 0) {
			$parts[] = sprintf(__('%d min', 'ar-design-reporting'), $minutes);
		}

		if ($rest > 0 || empty($parts)) {
			$parts[] = sprintf(__('%d s', 'ar-design-reporting'), $rest);
		}

		return implode(' ', $parts);
	}

	private function formatGmtDate(string $raw_value): string
	{
		try {
			$date = new \DateTimeImmutable($raw_value, new \DateTimeZone('UTC'));

			return $date->format('d.m.Y H:i:s');
		} catch (\Exception $exception) {
			return $raw_value;
		}
	}
}
