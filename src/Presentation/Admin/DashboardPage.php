<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Presentation\Admin;

use ArDesign\Reporting\Application\Requirements;
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
	 * @var array<int, string>
	 */
	private array $order_number_cache = array();

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

		$export_status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
		$export_classification = isset($_GET['classification']) ? sanitize_key(wp_unslash($_GET['classification'])) : '';
		$default_date_from = $this->getCurrentMonthStartDate();
		$default_date_to = $this->getCurrentDateIso();
		$export_date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : $default_date_from;
		$export_date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : $default_date_to;

		if ('' === trim($export_date_from)) {
			$export_date_from = $default_date_from;
		}

		if ('' === trim($export_date_to)) {
			$export_date_to = $default_date_to;
		}
		$default_compare_from = $this->getIsoDateOffsetYears($export_date_from, -1);
		$default_compare_to = $this->getIsoDateOffsetYears($export_date_to, -1);
		$compare_date_from = isset($_GET['compare_date_from']) ? sanitize_text_field(wp_unslash($_GET['compare_date_from'])) : $default_compare_from;
		$compare_date_to = isset($_GET['compare_date_to']) ? sanitize_text_field(wp_unslash($_GET['compare_date_to'])) : $default_compare_to;

		if ('' === trim($compare_date_from)) {
			$compare_date_from = $default_compare_from;
		}

		if ('' === trim($compare_date_to)) {
			$compare_date_to = $default_compare_to;
		}

		$dashboard_filters = $this->export_manager->normalizeFilters(
			array(
				'status'         => $export_status,
				'classification' => $export_classification,
				'date_from'      => $export_date_from,
				'date_to'        => $export_date_to,
			)
		);
		$compare_filters = $this->export_manager->normalizeFilters(
			array(
				'status'         => $export_status,
				'classification' => $export_classification,
				'date_from'      => $compare_date_from,
				'date_to'        => $compare_date_to,
			)
		);
		$data         = $this->dashboard_query_service->getDashboardData($dashboard_filters, $compare_filters);
		$export_info  = $this->export_manager->describeCsvExport($dashboard_filters);
		$email_info   = $this->email_reporter->describeDigest();
		$email_configs = $this->email_reporter->getConfigurations();
		$kpis         = is_array($data['kpis']) ? $data['kpis'] : array();
		$compare_kpis = is_array($data['compare_kpis'] ?? null) ? $data['compare_kpis'] : array();
		$kpi_compare  = is_array($data['kpi_compare'] ?? null) ? $data['kpi_compare'] : array();
		$tables       = is_array($data['tables']) ? $data['tables'] : array();
		$missing      = is_array($data['missing_tables']) ? $data['missing_tables'] : array();
		$orders_overview = is_array($data['orders_overview'] ?? null) ? $data['orders_overview'] : array();
		$employee_overview = is_array($data['employee_overview'] ?? null) ? $data['employee_overview'] : array();
		$audit_overview = is_array($data['audit_overview'] ?? null) ? $data['audit_overview'] : array();
		$selected_audit_event_type = isset($_GET['audit_event_type']) ? sanitize_key(wp_unslash($_GET['audit_event_type'])) : '';
		$audit_order_id = isset($_GET['audit_order_id']) ? absint(wp_unslash($_GET['audit_order_id'])) : 0;
		$audit_user_id = isset($_GET['audit_user_id']) ? absint(wp_unslash($_GET['audit_user_id'])) : 0;
		$audit_date_from = isset($_GET['audit_date_from']) ? sanitize_text_field(wp_unslash($_GET['audit_date_from'])) : $export_date_from;
		$audit_date_to = isset($_GET['audit_date_to']) ? sanitize_text_field(wp_unslash($_GET['audit_date_to'])) : $export_date_to;
		$audit_filters = array(
			'date_from'     => $audit_date_from,
			'date_to'       => $audit_date_to,
			'order_id'      => (string) $audit_order_id,
			'actor_user_id' => (string) $audit_user_id,
		);
		$recent_audit_events = $this->dashboard_query_service->getRecentAuditEvents($audit_filters, $selected_audit_event_type, 1000);
		$sample_order = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
		$workflow     = $sample_order > 0 ? $this->processing_service->getWorkflowSummary($sample_order) : array();
		$timeline     = $sample_order > 0 ? $this->dashboard_query_service->getOrderTimeline($sample_order) : array();
		$order_archives = $sample_order > 0 ? $this->order_archive_service->getRecentArchivesForOrder($sample_order, 10) : array();
		$recent_deleted_archives = $this->order_archive_service->getRecentDeletedArchives(20);
		$audit_filter_base_args = array(
			'page'              => 'ar-design-reporting',
			'status'            => $export_status,
			'classification'    => $export_classification,
			'date_from'         => $export_date_from,
			'date_to'           => $export_date_to,
			'compare_date_from' => $compare_date_from,
			'compare_date_to'   => $compare_date_to,
			'audit_order_id'    => $audit_order_id > 0 ? (string) $audit_order_id : '',
			'audit_user_id'     => $audit_user_id > 0 ? (string) $audit_user_id : '',
			'audit_date_from'   => $audit_date_from,
			'audit_date_to'     => $audit_date_to,
		);

		echo '<div class="wrap">';
		$this->renderDashboardStyles();
		$this->renderDashboardLayoutScript();
		echo '<div class="ard-reporting-dashboard">';
		echo '<h1>' . esc_html__('AR Design Reporting', 'ar-design-reporting') . '</h1>';
		echo '<p>' . esc_html__('Dashboard zobrazuje objednávky, KPI, výkon zamestnancov a auditné udalosti podľa zvolených filtrov.', 'ar-design-reporting') . '</p>';
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:1200px;margin-top:12px;">';
		echo '<input type="hidden" name="page" value="ar-design-reporting" />';
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">';
		echo '<p><label for="ard-dashboard-status">' . esc_html__('Stav', 'ar-design-reporting') . '</label><br />';
		echo '<select id="ard-dashboard-status" name="status">';
		echo '<option value="">' . esc_html__('Všechny', 'ar-design-reporting') . '</option>';
		echo '<option value="new"' . selected('new', $export_status, false) . '>new</option>';
		echo '<option value="pending"' . selected('pending', $export_status, false) . '>pending</option>';
		echo '<option value="processing"' . selected('processing', $export_status, false) . '>processing</option>';
		echo '<option value="on-hold"' . selected('on-hold', $export_status, false) . '>on-hold</option>';
		echo '<option value="na-odoslanie"' . selected('na-odoslanie', $export_status, false) . '>na-odoslanie</option>';
		echo '<option value="zabalena"' . selected('zabalena', $export_status, false) . '>zabalena</option>';
		echo '<option value="vybavena"' . selected('vybavena', $export_status, false) . '>vybavena</option>';
		echo '<option value="failed"' . selected('failed', $export_status, false) . '>failed</option>';
		echo '<option value="cancelled"' . selected('cancelled', $export_status, false) . '>cancelled</option>';
		echo '<option value="refunded"' . selected('refunded', $export_status, false) . '>refunded</option>';
		echo '</select></p>';
		echo '<p><label for="ard-dashboard-classification">' . esc_html__('Klasifikácia', 'ar-design-reporting') . '</label><br />';
		echo '<select id="ard-dashboard-classification" name="classification">';
		echo '<option value="">' . esc_html__('Všechny', 'ar-design-reporting') . '</option>';
		echo '<option value="standard"' . selected('standard', $export_classification, false) . '>standard</option>';
		echo '<option value="preorder"' . selected('preorder', $export_classification, false) . '>preorder</option>';
		echo '<option value="custom"' . selected('custom', $export_classification, false) . '>custom</option>';
		echo '</select></p>';
		echo '<p><label for="ard-dashboard-date-from">' . esc_html__('Datum od', 'ar-design-reporting') . '</label><br />';
		echo '<input id="ard-dashboard-date-from" type="date" name="date_from" value="' . esc_attr($export_date_from) . '" /></p>';
		echo '<p><label for="ard-dashboard-date-to">' . esc_html__('Datum do', 'ar-design-reporting') . '</label><br />';
		echo '<input id="ard-dashboard-date-to" type="date" name="date_to" value="' . esc_attr($export_date_to) . '" /></p>';
		echo '<p><label for="ard-dashboard-compare-date-from">' . esc_html__('Porovnanie od', 'ar-design-reporting') . '</label><br />';
		echo '<input id="ard-dashboard-compare-date-from" type="date" name="compare_date_from" value="' . esc_attr($compare_date_from) . '" /></p>';
		echo '<p><label for="ard-dashboard-compare-date-to">' . esc_html__('Porovnanie do', 'ar-design-reporting') . '</label><br />';
		echo '<input id="ard-dashboard-compare-date-to" type="date" name="compare_date_to" value="' . esc_attr($compare_date_to) . '" /></p>';
		echo '<p>';
		submit_button(__('Použiť filtre', 'ar-design-reporting'), 'secondary', 'submit', false);
		echo '</p>';
		echo '</div>';
		echo '</form>';

		echo '<table class="widefat striped" style="max-width:960px;margin-top:16px;">';
		echo '<tbody>';
		$wp_694_compatible = version_compare('6.9.4', Requirements::MIN_WORDPRESS_VERSION, '>=');
		echo '<tr><th style="width:260px;">' . esc_html__('Verzia pluginu', 'ar-design-reporting') . '</th><td>' . esc_html((string) $this->plugin_meta['version']) . '</td></tr>';
		echo '<tr><th>' . esc_html__('DB verzia', 'ar-design-reporting') . '</th><td>' . esc_html((string) get_option('ard_reporting_db_version', 'n/a')) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Kompatibilita s WordPress 6.9.4', 'ar-design-reporting') . '</th><td>' . esc_html(
			$wp_694_compatible
				? sprintf(
					/* translators: %s: minimum supported WordPress version */
					__('Áno (plugin vyžaduje WordPress %s alebo novší).', 'ar-design-reporting'),
					Requirements::MIN_WORDPRESS_VERSION
				)
				: sprintf(
					/* translators: %s: minimum supported WordPress version */
					__('Nie (plugin vyžaduje WordPress %s alebo novší).', 'ar-design-reporting'),
					Requirements::MIN_WORDPRESS_VERSION
				)
		) . '</td></tr>';
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
		$this->renderKpiCards($kpis, $kpi_compare, $compare_date_from, $compare_date_to);
		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr><th>' . esc_html__('Metrika', 'ar-design-reporting') . '</th><th>' . esc_html__('Hodnota', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		foreach ($kpis as $key => $value) {
			echo '<tr>';
			echo '<td>' . esc_html($this->getKpiLabel((string) $key)) . '</td>';
			$compare_value = $compare_kpis[(string) $key] ?? null;
			$compare_label = null !== $compare_value ? $this->formatKpiValue((string) $key, $compare_value) : __('Nevyplněno', 'ar-design-reporting');
			echo '<td>' . esc_html($this->formatKpiValue((string) $key, $value)) . ' <small style="color:#64748b;">(' . esc_html__('porovnanie', 'ar-design-reporting') . ': ' . esc_html($compare_label) . ')</small></td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Prehľad objednávok', 'ar-design-reporting') . '</h2>';
		echo '<p>' . esc_html__('Zoznam zobrazuje všetky objednávky v zvolenom filtri vrátane stavu, zodpovednej osoby a poslednej zmeny statusu.', 'ar-design-reporting') . '</p>';
		echo '<div class="ard-orders-overview-wrap">';
		echo '<table id="ard-orders-overview-table" class="widefat striped ard-orders-overview-table" style="max-width:1200px;">';
		echo '<thead><tr><th>' . esc_html__('Objednávka', 'ar-design-reporting') . '</th><th>' . esc_html__('Stav', 'ar-design-reporting') . '</th><th>' . esc_html__('Klasifikácia', 'ar-design-reporting') . '</th><th>' . esc_html__('Zodpovedný', 'ar-design-reporting') . '</th><th>' . esc_html__('Posledná zmena', 'ar-design-reporting') . '</th><th>' . esc_html__('Do Na odoslanie', 'ar-design-reporting') . '</th><th>' . esc_html__('Celkový čas procesu', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		if (empty($orders_overview)) {
			echo '<tr><td colspan="7">' . esc_html__('Pre zvolený filter nie sú dostupné žiadne objednávky.', 'ar-design-reporting') . '</td></tr>';
		} else {
			foreach ($orders_overview as $order_row) {
				$order_id = (int) ($order_row['order_id'] ?? 0);
				$owner_id = (int) ($order_row['owner_user_id'] ?? 0);
				$last_actor_id = (int) ($order_row['last_status_change_actor'] ?? 0);
				$processing_seconds = isset($order_row['processing_seconds']) ? (int) $order_row['processing_seconds'] : 0;
				$ready_seconds = isset($order_row['ready_for_packing_seconds']) ? (int) $order_row['ready_for_packing_seconds'] : 0;

				echo '<tr class="ard-orders-overview-row">';
				echo '<td><a href="' . esc_url($this->getOrderAdminUrl($order_id)) . '">' . esc_html($this->resolveOrderNumberLabel($order_id)) . '</a></td>';
				echo '<td>' . esc_html($this->formatWorkflowValue('status', (string) ($order_row['status'] ?? ''))) . '</td>';
				echo '<td>' . esc_html($this->formatWorkflowValue('classification', (string) ($order_row['classification'] ?? ''))) . '</td>';
				echo '<td>' . esc_html($this->formatUserLabel($owner_id)) . '</td>';
				echo '<td>' . esc_html($this->formatUserLabel($last_actor_id)) . ' / ' . esc_html($this->formatGmtDate((string) ($order_row['last_status_change_at_gmt'] ?? ''))) . '</td>';
				echo '<td>' . esc_html($ready_seconds > 0 ? $this->formatDuration($ready_seconds) : __('Nevyplněno', 'ar-design-reporting')) . '</td>';
				echo '<td>' . esc_html($processing_seconds > 0 ? $this->formatDuration($processing_seconds) : __('Nevyplněno', 'ar-design-reporting')) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
		echo '<div class="ard-orders-overview-pagination" aria-label="' . esc_attr__('Stránkovanie prehľadu objednávok', 'ar-design-reporting') . '">';
		echo '<button type="button" class="button" data-page-action="prev">' . esc_html__('Predchádzajúca', 'ar-design-reporting') . '</button>';
		echo '<span class="ard-orders-overview-page-info" data-page-info></span>';
		echo '<button type="button" class="button" data-page-action="next">' . esc_html__('Ďalšia', 'ar-design-reporting') . '</button>';
		echo '<label class="ard-orders-overview-page-size">';
		echo '<span>' . esc_html__('Na stránku', 'ar-design-reporting') . ':</span>';
		echo '<select data-page-size>';
		echo '<option value="10" selected>10</option>';
		echo '<option value="20">20</option>';
		echo '<option value="50">50</option>';
		echo '</select>';
		echo '</label>';
		echo '</div>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Výkon zamestnancov', 'ar-design-reporting') . '</h2>';
		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr><th>' . esc_html__('Používateľ', 'ar-design-reporting') . '</th><th>' . esc_html__('Počet objednávok', 'ar-design-reporting') . '</th><th>' . esc_html__('Priemerný čas spracovania', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		if (empty($employee_overview)) {
			echo '<tr><td colspan="3">' . esc_html__('Žiadne dáta o výkone zamestnancov v zvolenom období.', 'ar-design-reporting') . '</td></tr>';
		} else {
			foreach ($employee_overview as $employee_row) {
				$user_id = (int) ($employee_row['owner_user_id'] ?? 0);
				$orders_count = (int) ($employee_row['orders_count'] ?? 0);
				$avg_seconds = isset($employee_row['avg_processing_seconds']) ? (float) $employee_row['avg_processing_seconds'] : 0.0;
				echo '<tr>';
				echo '<td>' . esc_html($this->formatUserLabel($user_id)) . '</td>';
				echo '<td>' . esc_html((string) $orders_count) . '</td>';
				echo '<td>' . esc_html($avg_seconds > 0 ? $this->formatDuration((int) round($avg_seconds)) : __('Nevyplněno', 'ar-design-reporting')) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Auditný prehľad', 'ar-design-reporting') . '</h2>';
		echo '<table class="widefat striped" style="max-width:960px;">';
		echo '<thead><tr><th>' . esc_html__('Udalosť', 'ar-design-reporting') . '</th><th>' . esc_html__('Počet', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		if (empty($audit_overview)) {
			echo '<tr><td colspan="2">' . esc_html__('Za zvolené obdobie nie sú auditné udalosti.', 'ar-design-reporting') . '</td></tr>';
		} else {
			foreach ($audit_overview as $audit_row) {
				$event_type = sanitize_key((string) ($audit_row['event_type'] ?? ''));
				$count = (int) ($audit_row['events_count'] ?? 0);
				$event_link = add_query_arg(
					array_merge(
						$audit_filter_base_args,
						array(
							'audit_event_type' => $event_type,
						)
					),
					admin_url('admin.php')
				) . '#ard-audit-events-table';
				echo '<tr>';
				echo '<td>' . esc_html($this->formatAuditEventLabel($event_type)) . '</td>';
				echo '<td><a href="' . esc_url($event_link) . '">' . esc_html((string) $count) . '</a></td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';
		$this->renderAuditPieChart($audit_overview);

		echo '<h3 id="ard-audit-events-table" style="margin-top:16px;">' . esc_html__('Aktuálne auditné udalosti', 'ar-design-reporting') . '</h3>';
		if ('' !== $selected_audit_event_type) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: event label */
					__('Filter udalostí: %s', 'ar-design-reporting'),
					$this->formatAuditEventLabel($selected_audit_event_type)
				)
			) . ' <a href="' . esc_url(add_query_arg($audit_filter_base_args, admin_url('admin.php')) . '#ard-audit-events-table') . '">' . esc_html__('(zobraziť všetky)', 'ar-design-reporting') . '</a></p>';
		}
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="background:#fff;border:1px solid #dcdcde;padding:12px;max-width:1200px;margin-bottom:10px;">';
		echo '<input type="hidden" name="page" value="ar-design-reporting" />';
		echo '<input type="hidden" name="status" value="' . esc_attr($export_status) . '" />';
		echo '<input type="hidden" name="classification" value="' . esc_attr($export_classification) . '" />';
		echo '<input type="hidden" name="date_from" value="' . esc_attr($export_date_from) . '" />';
		echo '<input type="hidden" name="date_to" value="' . esc_attr($export_date_to) . '" />';
		echo '<input type="hidden" name="compare_date_from" value="' . esc_attr($compare_date_from) . '" />';
		echo '<input type="hidden" name="compare_date_to" value="' . esc_attr($compare_date_to) . '" />';
		echo '<input type="hidden" name="audit_event_type" value="' . esc_attr($selected_audit_event_type) . '" />';
		echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
		echo '<p style="margin:0;"><label for="ard-audit-order-id">' . esc_html__('Objednávka (ID)', 'ar-design-reporting') . '</label><br /><input id="ard-audit-order-id" type="number" min="1" name="audit_order_id" value="' . esc_attr($audit_order_id > 0 ? (string) $audit_order_id : '') . '" class="regular-text" /></p>';
		echo '<p style="margin:0;"><label for="ard-audit-user-id">' . esc_html__('Používateľ (ID)', 'ar-design-reporting') . '</label><br /><input id="ard-audit-user-id" type="number" min="1" name="audit_user_id" value="' . esc_attr($audit_user_id > 0 ? (string) $audit_user_id : '') . '" class="regular-text" /></p>';
		echo '<p style="margin:0;"><label for="ard-audit-date-from">' . esc_html__('Audit od', 'ar-design-reporting') . '</label><br /><input id="ard-audit-date-from" type="date" name="audit_date_from" value="' . esc_attr($audit_date_from) . '" class="regular-text" /></p>';
		echo '<p style="margin:0;"><label for="ard-audit-date-to">' . esc_html__('Audit do', 'ar-design-reporting') . '</label><br /><input id="ard-audit-date-to" type="date" name="audit_date_to" value="' . esc_attr($audit_date_to) . '" class="regular-text" /></p>';
		echo '<p style="margin:0;">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__('Filtrovať audit', 'ar-design-reporting') . '</button> ';
		echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($audit_filter_base_args, array('audit_order_id' => '', 'audit_user_id' => '', 'audit_date_from' => $export_date_from, 'audit_date_to' => $export_date_to)), admin_url('admin.php')) . '#ard-audit-events-table') . '">' . esc_html__('Reset', 'ar-design-reporting') . '</a>';
		echo '</p>';
		echo '</div>';
		echo '</form>';

		echo '<div class="ard-audit-events-wrap">';
		echo '<table id="ard-audit-events-table-grid" class="widefat striped ard-audit-events-table" style="max-width:1200px;">';
		echo '<thead><tr><th>' . esc_html__('Čas (GMT)', 'ar-design-reporting') . '</th><th>' . esc_html__('Udalosť', 'ar-design-reporting') . '</th><th>' . esc_html__('Objednávka', 'ar-design-reporting') . '</th><th>' . esc_html__('Používateľ', 'ar-design-reporting') . '</th><th>' . esc_html__('Zmena', 'ar-design-reporting') . '</th><th>' . esc_html__('Zdroj', 'ar-design-reporting') . '</th></tr></thead>';
		echo '<tbody>';

		if (empty($recent_audit_events)) {
			echo '<tr><td colspan="6">' . esc_html__('Pre zvolený filter nie sú dostupné žiadne auditné udalosti.', 'ar-design-reporting') . '</td></tr>';
		} else {
			foreach ($recent_audit_events as $audit_event_row) {
				$order_id = isset($audit_event_row['order_id']) ? (int) $audit_event_row['order_id'] : 0;
				$order_label = $order_id > 0 ? $this->resolveOrderNumberLabel($order_id) : __('Nevyplněno', 'ar-design-reporting');
				$order_cell = $order_id > 0
					? '<a href="' . esc_url($this->getOrderAdminUrl($order_id)) . '">' . esc_html($order_label) . '</a>'
					: esc_html($order_label);

				echo '<tr class="ard-audit-events-row">';
				echo '<td>' . esc_html($this->formatGmtDate((string) ($audit_event_row['created_at_gmt'] ?? ''))) . '</td>';
				echo '<td>' . esc_html($this->formatAuditEventLabel((string) ($audit_event_row['event_type'] ?? ''))) . '</td>';
				echo '<td>' . $order_cell . '</td>';
				echo '<td>' . esc_html($this->formatUserLabel((int) ($audit_event_row['actor_user_id'] ?? 0))) . '</td>';
				echo '<td>' . esc_html($this->formatAuditChangeSummary($audit_event_row)) . '</td>';
				echo '<td>' . esc_html($this->formatAuditSourceLabel($audit_event_row)) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
		echo '<div class="ard-audit-events-pagination" aria-label="' . esc_attr__('Stránkovanie auditných udalostí', 'ar-design-reporting') . '">';
		echo '<button type="button" class="button" data-audit-page-action="prev">' . esc_html__('Predchádzajúca', 'ar-design-reporting') . '</button>';
		echo '<span class="ard-audit-events-page-info" data-audit-page-info></span>';
		echo '<button type="button" class="button" data-audit-page-action="next">' . esc_html__('Ďalšia', 'ar-design-reporting') . '</button>';
		echo '<label class="ard-audit-events-page-size">';
		echo '<span>' . esc_html__('Na stránku', 'ar-design-reporting') . ':</span>';
		echo '<select data-audit-page-size>';
		echo '<option value="5" selected>5</option>';
		echo '<option value="10">10</option>';
		echo '<option value="20">20</option>';
		echo '<option value="50">50</option>';
		echo '</select>';
		echo '</label>';
		echo '</div>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;background:#fff;border:1px solid #dcdcde;padding:12px;max-width:1200px;">';
		wp_nonce_field('ard_export_audit_xlsx');
		echo '<input type="hidden" name="action" value="ard_export_audit_xlsx" />';
		echo '<input type="hidden" name="status" value="' . esc_attr($export_status) . '" />';
		echo '<input type="hidden" name="classification" value="' . esc_attr($export_classification) . '" />';
		echo '<input type="hidden" name="date_from" value="' . esc_attr($audit_date_from) . '" />';
		echo '<input type="hidden" name="date_to" value="' . esc_attr($audit_date_to) . '" />';
		echo '<input type="hidden" name="audit_order_id" value="' . esc_attr($audit_order_id > 0 ? (string) $audit_order_id : '') . '" />';
		echo '<input type="hidden" name="audit_user_id" value="' . esc_attr($audit_user_id > 0 ? (string) $audit_user_id : '') . '" />';
		echo '<input type="hidden" name="audit_event_type" value="' . esc_attr($selected_audit_event_type) . '" />';
		echo '<button type="submit" class="button button-secondary">' . esc_html__('Exportovať auditné udalosti (XLSX)', 'ar-design-reporting') . '</button>';
		echo '</form>';

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
		wp_nonce_field('ard_export_data');
		echo '<h3 style="margin-top:0;">' . esc_html__('Export dát', 'ar-design-reporting') . '</h3>';
		echo '<p><label for="ard-export-status">' . esc_html__('Stav workflow', 'ar-design-reporting') . '</label></p>';
		echo '<p><select id="ard-export-status" name="status" class="regular-text">';
		echo '<option value="">' . esc_html__('Všechny', 'ar-design-reporting') . '</option>';
		echo '<option value="new"' . selected('new', $export_status, false) . '>new</option>';
		echo '<option value="pending"' . selected('pending', $export_status, false) . '>pending</option>';
		echo '<option value="processing"' . selected('processing', $export_status, false) . '>processing</option>';
		echo '<option value="on-hold"' . selected('on-hold', $export_status, false) . '>on-hold</option>';
		echo '<option value="na-odoslanie"' . selected('na-odoslanie', $export_status, false) . '>na-odoslanie</option>';
		echo '<option value="zabalena"' . selected('zabalena', $export_status, false) . '>zabalena</option>';
		echo '<option value="vybavena"' . selected('vybavena', $export_status, false) . '>vybavena</option>';
		echo '<option value="failed"' . selected('failed', $export_status, false) . '>failed</option>';
		echo '<option value="cancelled"' . selected('cancelled', $export_status, false) . '>cancelled</option>';
		echo '<option value="refunded"' . selected('refunded', $export_status, false) . '>refunded</option>';
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
		echo '<p style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">';
		echo '<button type="submit" name="action" value="ard_export_csv" class="button button-primary">' . esc_html__('Stáhnout CSV', 'ar-design-reporting') . '</button>';
		echo '<button type="submit" name="action" value="ard_export_xlsx" class="button button-secondary">' . esc_html__('Stáhnout XLSX', 'ar-design-reporting') . '</button>';
		echo '</p>';
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

			echo '<h3 style="margin-top:16px;">' . esc_html__('Timeline práce s objednávkou', 'ar-design-reporting') . '</h3>';
			echo '<table class="widefat striped" style="max-width:960px;">';
			echo '<thead><tr><th>' . esc_html__('Čas (GMT)', 'ar-design-reporting') . '</th><th>' . esc_html__('Používateľ', 'ar-design-reporting') . '</th><th>' . esc_html__('Prechod', 'ar-design-reporting') . '</th><th>' . esc_html__('Dĺžka kroku', 'ar-design-reporting') . '</th></tr></thead>';
			echo '<tbody>';

			if (empty($timeline)) {
				echo '<tr><td colspan="4">' . esc_html__('Pre túto objednávku zatiaľ nie sú dostupné statusové udalosti.', 'ar-design-reporting') . '</td></tr>';
			} else {
				foreach ($timeline as $timeline_row) {
					$from_status = (string) ($timeline_row['from_status'] ?? '');
					$to_status = (string) ($timeline_row['to_status'] ?? '');
					$duration = isset($timeline_row['duration_since_prev_seconds']) ? (int) $timeline_row['duration_since_prev_seconds'] : 0;

					echo '<tr>';
					echo '<td>' . esc_html($this->formatGmtDate((string) ($timeline_row['at_gmt'] ?? ''))) . '</td>';
					echo '<td>' . esc_html($this->formatUserLabel((int) ($timeline_row['actor_user_id'] ?? 0))) . '</td>';
					echo '<td>' . esc_html($this->formatWorkflowValue('status', $from_status) . ' -> ' . $this->formatWorkflowValue('status', $to_status)) . '</td>';
					echo '<td>' . esc_html($duration > 0 ? $this->formatDuration($duration) : __('Nevyplněno', 'ar-design-reporting')) . '</td>';
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
			echo '<thead><tr><th>' . esc_html__('Objednávka', 'ar-design-reporting') . '</th><th>' . esc_html__('Čas (GMT)', 'ar-design-reporting') . '</th><th>' . esc_html__('Používateľ', 'ar-design-reporting') . '</th><th>' . esc_html__('Snapshot', 'ar-design-reporting') . '</th></tr></thead>';
			echo '<tbody>';

			foreach ($recent_deleted_archives as $archive) {
				$snapshot = isset($archive['snapshot']) && is_array($archive['snapshot']) ? $archive['snapshot'] : array();
				$snapshot_json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

				echo '<tr>';
				$archived_order_id = (int) ($archive['order_id'] ?? 0);
				echo '<td>' . esc_html($this->resolveOrderNumberLabel($archived_order_id)) . '</td>';
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
		echo '</div>';
	}

	/**
	 * @param array<string, int|float> $kpis
	 * @param array<string, array<string, float>> $kpi_compare
	 */
	private function renderKpiCards(array $kpis, array $kpi_compare, string $compare_date_from, string $compare_date_to): void
	{
		if (empty($kpis)) {
			return;
		}

		$card_keys = array(
			'total_orders',
			'completed_orders',
			'pending_orders',
			'gross_revenue',
			'revenue_completed',
			'revenue_pending',
			'average_order_value',
			'average_order_value_completed',
			'average_order_value_pending',
			'cancelled_orders',
		);

		$compare_from_label = $this->formatIsoDateForCzechDisplay($compare_date_from);
		$compare_to_label = $this->formatIsoDateForCzechDisplay($compare_date_to);

		echo '<div class="ard-kpi-compare-info">' . esc_html(
			sprintf(
				/* translators: 1: compare from date, 2: compare to date */
				__('Porovnanie voči obdobiu %1$s až %2$s', 'ar-design-reporting'),
				$compare_from_label,
				$compare_to_label
			)
		) . '</div>';
		echo '<div class="ard-kpi-grid">';

		foreach ($card_keys as $key) {
			if (! array_key_exists($key, $kpis)) {
				continue;
			}

			$value = $kpis[$key];
			$kpi_label = $this->getKpiLabel((string) $key);
			$comparison = $kpi_compare[$key] ?? array();
			$delta_percent = isset($comparison['delta_percent']) ? (float) $comparison['delta_percent'] : 0.0;
			$delta_class = $delta_percent > 0 ? 'is-up' : ($delta_percent < 0 ? 'is-down' : 'is-neutral');
			$delta_prefix = $delta_percent > 0 ? '+' : '';
			$delta_arrow = $delta_percent > 0 ? '↑' : ($delta_percent < 0 ? '↓' : '→');

			echo '<div class="ard-kpi-card ' . esc_attr($delta_class) . '">';
			echo '<span class="ard-kpi-split" aria-hidden="true"></span>';
			echo '<div class="ard-kpi-card-top">';
			echo '<div class="ard-kpi-value">' . esc_html($this->formatKpiValue((string) $key, $value)) . '</div>';
			echo '<div class="ard-kpi-delta">' . esc_html($delta_arrow . ' ' . $delta_prefix . number_format($delta_percent, 2, ',', ' ') . ' %') . '</div>';
			echo '</div>';
			echo '<div class="ard-kpi-label" title="' . esc_attr($kpi_label) . '" aria-label="' . esc_attr($kpi_label) . '">' . esc_html($kpi_label) . '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $audit_overview
	 */
	private function renderAuditPieChart(array $audit_overview): void
	{
		if (empty($audit_overview)) {
			return;
		}

		$total = 0;
		foreach ($audit_overview as $row) {
			$total += (int) ($row['events_count'] ?? 0);
		}

		if ($total <= 0) {
			return;
		}

		$segments = array();
		$legend_rows = array();
		$offset = 0.0;
		$cx = 130.0;
		$cy = 98.0;
		$radius = 86.0;
		$depth = 20.0;

		foreach ($audit_overview as $index => $row) {
			$event_type = sanitize_key((string) ($row['event_type'] ?? ''));
			$count = max(0, (int) ($row['events_count'] ?? 0));
			if ('' === $event_type || $count <= 0) {
				continue;
			}

			$ratio = $count / $total;
			$start = $offset * 360.0;
			$offset += $ratio;
			$end = $offset * 360.0;
			$color = $this->auditColorByIndex((int) $index);
			$segments[] = array(
				'start' => $start,
				'end'   => $end,
				'color' => $color,
				'side'  => $this->darkenHexColor($color, 0.7),
			);
			$legend_rows[] = array(
				'color' => $color,
				'label' => $this->formatAuditEventLabel($event_type),
				'count' => $count,
			);
		}

		if (empty($segments) || empty($legend_rows)) {
			return;
		}

		$chart_id = 'ard-audit-pie-' . substr(md5((string) wp_rand()), 0, 8);

		echo '<div class="ard-audit-pie-wrap">';
		echo '<div class="ard-audit-pie" aria-hidden="true">';
		echo '<svg class="ard-audit-pie-svg" viewBox="0 0 280 230" role="img" aria-label="' . esc_attr__('3D koláčový graf auditných udalostí', 'ar-design-reporting') . '">';
		echo '<defs>';
		echo '<filter id="' . esc_attr($chart_id . '-shadow') . '" x="-40%" y="-40%" width="180%" height="220%">';
		echo '<feDropShadow dx="0" dy="6" stdDeviation="4" flood-color="#0f172a" flood-opacity="0.22" />';
		echo '</filter>';
		echo '<clipPath id="' . esc_attr($chart_id . '-front') . '">';
		echo '<rect x="0" y="' . esc_attr((string) ($cy - 1.0)) . '" width="280" height="230" />';
		echo '</clipPath>';
		echo '<radialGradient id="' . esc_attr($chart_id . '-highlight') . '" cx="30%" cy="18%" r="64%">';
		echo '<stop offset="0%" stop-color="rgba(255,255,255,0.55)" />';
		echo '<stop offset="44%" stop-color="rgba(255,255,255,0.12)" />';
		echo '<stop offset="100%" stop-color="rgba(255,255,255,0)" />';
		echo '</radialGradient>';
		echo '</defs>';
		echo '<g class="ard-audit-pie-depth" clip-path="url(#' . esc_attr($chart_id . '-front') . ')">';
		foreach ($segments as $segment) {
			echo '<path d="' . esc_attr($this->buildPieSidePath($cx, $cy, $radius, $depth, (float) $segment['start'], (float) $segment['end'])) . '" fill="' . esc_attr((string) $segment['side']) . '" />';
		}
		echo '</g>';
		echo '<g class="ard-audit-pie-top" filter="url(#' . esc_attr($chart_id . '-shadow') . ')">';
		foreach ($segments as $segment) {
			echo '<path d="' . esc_attr($this->buildPieSlicePath($cx, $cy, $radius, (float) $segment['start'], (float) $segment['end'])) . '" fill="' . esc_attr((string) $segment['color']) . '" stroke="rgba(255,255,255,0.72)" stroke-width="1" />';
		}
		echo '<ellipse cx="' . esc_attr((string) $cx) . '" cy="' . esc_attr((string) $cy) . '" rx="' . esc_attr((string) ($radius - 1.5)) . '" ry="' . esc_attr((string) ($radius - 1.5)) . '" fill="url(#' . esc_attr($chart_id . '-highlight') . ')" />';
		echo '</g>';
		echo '</svg>';
		echo '</div>';
		echo '<ul class="ard-audit-legend">';
		foreach ($legend_rows as $legend_row) {
			echo '<li>';
			echo '<span class="ard-audit-legend-color" style="background:' . esc_attr((string) $legend_row['color']) . ';"></span>';
			echo '<span class="ard-audit-legend-label">' . esc_html((string) $legend_row['label']) . '</span>';
			echo '<span class="ard-audit-legend-count">' . esc_html((string) $legend_row['count']) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	private function buildPieSlicePath(float $cx, float $cy, float $radius, float $start_angle, float $end_angle): string
	{
		$delta = $end_angle - $start_angle;
		if ($delta >= 359.99) {
			$top = $cy - $radius;
			$bottom = $cy + $radius;
			return sprintf(
				'M %.2F %.2F A %.2F %.2F 0 1 1 %.2F %.2F A %.2F %.2F 0 1 1 %.2F %.2F Z',
				$cx,
				$top,
				$radius,
				$radius,
				$cx,
				$bottom,
				$radius,
				$radius,
				$cx,
				$top
			);
		}

		$start = $this->pointOnPie($cx, $cy, $radius, $start_angle);
		$end = $this->pointOnPie($cx, $cy, $radius, $end_angle);
		$large_arc = $delta > 180.0 ? 1 : 0;

		return sprintf(
			'M %.2F %.2F L %.2F %.2F A %.2F %.2F 0 %d 1 %.2F %.2F Z',
			$cx,
			$cy,
			$start['x'],
			$start['y'],
			$radius,
			$radius,
			$large_arc,
			$end['x'],
			$end['y']
		);
	}

	private function buildPieSidePath(float $cx, float $cy, float $radius, float $depth, float $start_angle, float $end_angle): string
	{
		$delta = $end_angle - $start_angle;
		if ($delta >= 359.99) {
			$top = $cy + $radius;
			$bottom = $cy + $radius + $depth;
			return sprintf(
				'M %.2F %.2F A %.2F %.2F 0 1 1 %.2F %.2F L %.2F %.2F A %.2F %.2F 0 1 0 %.2F %.2F Z',
				$cx - $radius,
				$top,
				$radius,
				$radius,
				$cx + $radius,
				$top,
				$cx + $radius,
				$bottom,
				$radius,
				$radius,
				$cx - $radius,
				$bottom
			);
		}

		$start = $this->pointOnPie($cx, $cy, $radius, $start_angle);
		$end = $this->pointOnPie($cx, $cy, $radius, $end_angle);
		$start_bottom = array('x' => $start['x'], 'y' => $start['y'] + $depth);
		$end_bottom = array('x' => $end['x'], 'y' => $end['y'] + $depth);
		$large_arc = $delta > 180.0 ? 1 : 0;

		return sprintf(
			'M %.2F %.2F A %.2F %.2F 0 %d 1 %.2F %.2F L %.2F %.2F A %.2F %.2F 0 %d 0 %.2F %.2F Z',
			$start['x'],
			$start['y'],
			$radius,
			$radius,
			$large_arc,
			$end['x'],
			$end['y'],
			$end_bottom['x'],
			$end_bottom['y'],
			$radius,
			$radius,
			$large_arc,
			$start_bottom['x'],
			$start_bottom['y']
		);
	}

	/**
	 * @return array{x: float, y: float}
	 */
	private function pointOnPie(float $cx, float $cy, float $radius, float $angle): array
	{
		$radians = deg2rad($angle - 90.0);
		return array(
			'x' => $cx + cos($radians) * $radius,
			'y' => $cy + sin($radians) * $radius,
		);
	}

	private function darkenHexColor(string $hex_color, float $factor): string
	{
		$normalized = ltrim($hex_color, '#');
		if (strlen($normalized) === 3) {
			$normalized = $normalized[0] . $normalized[0] . $normalized[1] . $normalized[1] . $normalized[2] . $normalized[2];
		}

		if (! preg_match('/^[0-9a-fA-F]{6}$/', $normalized)) {
			return '#334155';
		}

		$factor = max(0.0, min(1.0, $factor));
		$red = (int) round(hexdec(substr($normalized, 0, 2)) * $factor);
		$green = (int) round(hexdec(substr($normalized, 2, 2)) * $factor);
		$blue = (int) round(hexdec(substr($normalized, 4, 2)) * $factor);

		return sprintf('#%02x%02x%02x', $red, $green, $blue);
	}

	private function auditColorByIndex(int $index): string
	{
		$palette = array(
			'#2563eb',
			'#059669',
			'#d97706',
			'#dc2626',
			'#7c3aed',
			'#0f766e',
			'#4f46e5',
			'#ea580c',
			'#0284c7',
			'#16a34a',
			'#db2777',
			'#475569',
		);

		return $palette[$index % count($palette)];
	}

	private function renderDashboardStyles(): void
	{
		echo '<style>
		.ard-reporting-dashboard { max-width: 1400px; color: #1f2933; }
		.ard-reporting-dashboard h1 { margin-bottom: 6px; font-size: 30px; font-weight: 700; letter-spacing: -0.01em; }
		.ard-reporting-dashboard h2 { margin-top: 0 !important; margin-bottom: 14px; font-size: 20px; border-bottom: none; padding-bottom: 0; }
		.ard-reporting-dashboard h3 { margin: 14px 0 8px 0; font-size: 15px; }
		.ard-reporting-dashboard p { line-height: 1.5; color: #445468; }
		.ard-reporting-dashboard form { border-radius: 12px; box-shadow: 0 1px 2px rgba(16,24,40,.06); }
		.ard-reporting-dashboard .widefat { border-radius: 12px; overflow: hidden; border: 1px solid #d9e0e7; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
		.ard-reporting-dashboard .widefat thead th { background: #f7f7f7; font-weight: 600; }
		.ard-reporting-dashboard .widefat td, .ard-reporting-dashboard .widefat th { padding: 10px 12px; vertical-align: top; }
		.ard-reporting-dashboard input[type="date"],
		.ard-reporting-dashboard input[type="number"],
		.ard-reporting-dashboard input[type="email"],
		.ard-reporting-dashboard select { min-height: 36px; border-radius: 8px; border-color: #cbd5e1; }
		.ard-kpi-compare-info { margin: 0 0 10px 2px; color: #64748b; font-size: 12px; }
		.ard-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin: 0 0 14px 0; }
		.ard-kpi-card {
			position: relative;
			overflow: hidden;
			background: #f8fafc;
			border: 1px solid #dbe6f3;
			border-radius: 12px;
			padding: 16px 18px 18px 18px;
			box-shadow: 0 1px 2px rgba(16,24,40,.05);
			min-height: 108px;
		}
		.ard-kpi-split {
			position: absolute;
			inset: 0;
			z-index: 0;
			background: linear-gradient(
				140deg,
				transparent 0%,
				transparent 52%,
				var(--ard-kpi-accent-bg, rgba(148, 163, 184, 0.12)) 52%,
				var(--ard-kpi-accent-bg, rgba(148, 163, 184, 0.12)) 100%
			);
		}
		.ard-kpi-card::after {
			content: "";
			position: absolute;
			inset: 0;
			z-index: 0;
			background: linear-gradient(
				140deg,
				transparent calc(52% - 1px),
				rgba(255,255,255,0.95) 52%,
				transparent calc(52% + 1px)
			);
			pointer-events: none;
		}
		.ard-kpi-card-top { position: static; z-index: 1; display: block; margin-bottom: 10px; }
		.ard-kpi-label {
			position: relative;
			z-index: 1;
			display: block;
			max-width: 52%;
			font-size: 11px;
			font-weight: 700;
			color: #5b6778;
			margin: 0;
			letter-spacing: .01em;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		.ard-kpi-value { font-size: 30px; line-height: 1; font-weight: 700; color: #0f172a; letter-spacing: -0.01em; }
		.ard-kpi-delta {
			position: absolute;
			right: 14px;
			bottom: 12px;
			z-index: 2;
			font-size: 26px;
			line-height: 1;
			font-weight: 700;
			white-space: nowrap;
			text-align: right;
		}
		.ard-kpi-card .ard-kpi-value { max-width: 52%; }
		.ard-kpi-card.is-up { --ard-kpi-accent-bg: rgba(16, 185, 129, 0.16); }
		.ard-kpi-card.is-down { --ard-kpi-accent-bg: rgba(236, 72, 153, 0.13); }
		.ard-kpi-card.is-neutral { --ard-kpi-accent-bg: rgba(148, 163, 184, 0.12); }
		.ard-kpi-card.is-up .ard-kpi-delta { color: #16a34a; }
		.ard-kpi-card.is-down .ard-kpi-delta { color: #db2777; }
		.ard-kpi-card.is-neutral .ard-kpi-delta { color: #22c55e; }
		.ard-pro-grid { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, .9fr); gap: 16px; margin-top: 18px; align-items: start; }
		.ard-panel { background: #fff; border: 1px solid #d9e0e7; border-radius: 14px; padding: 16px; box-shadow: 0 2px 8px rgba(16,24,40,.05); }
		.ard-panel + .ard-panel { margin-top: 0; }
		.ard-panel-title { margin: 0 0 12px 0; font-size: 17px; font-weight: 700; color: #0f172a; }
		.ard-subsection { margin-top: 14px; padding-top: 12px; border-top: 1px solid #e4ebf3; }
		.ard-subsection:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
		.ard-subsection h3 { margin-top: 0; margin-bottom: 8px; font-size: 14px; color: #1f2937; text-transform: uppercase; letter-spacing: .04em; }
		.ard-reporting-dashboard > h2 { display: none; }
		.ard-orders-overview-wrap { max-width: 100%; overflow-x: auto; border-radius: 12px; }
		.ard-orders-overview-table { width: 100%; min-width: 980px; table-layout: fixed; font-size: 13px; }
		.ard-orders-overview-table td,
		.ard-orders-overview-table th {
			white-space: normal;
			overflow-wrap: anywhere;
			word-break: break-word;
			line-height: 1.35;
		}
		.ard-orders-overview-pagination {
			display: flex;
			gap: 10px;
			align-items: center;
			justify-content: flex-end;
			margin-top: 10px;
			flex-wrap: wrap;
		}
		.ard-orders-overview-page-info { color: #475569; font-size: 12px; min-width: 160px; text-align: center; }
		.ard-orders-overview-page-size { display: inline-flex; align-items: center; gap: 6px; color: #334155; font-size: 12px; }
		.ard-orders-overview-page-size select { min-height: 30px; }
		.ard-audit-pie-wrap { margin-top: 12px; display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
		.ard-audit-pie {
			width: 280px;
			height: 230px;
			flex: 0 0 auto;
		}
		.ard-audit-pie-svg {
			display: block;
			width: 100%;
			height: auto;
			overflow: visible;
		}
		.ard-audit-pie-top path {
			transition: transform .22s ease;
			transform-origin: 130px 98px;
		}
		.ard-audit-pie-top path:hover {
			transform: translateY(-2px);
		}
		.ard-audit-legend { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; min-width: 320px; }
		.ard-audit-legend li { display: grid; grid-template-columns: 14px 1fr auto; gap: 8px; align-items: center; }
		.ard-audit-legend-color { width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(15,23,42,.08); }
		.ard-audit-legend-label { color: #334155; }
		.ard-audit-legend-count { color: #0f172a; font-weight: 600; }
		.ard-audit-events-wrap { max-width: 100%; overflow-x: auto; border-radius: 12px; }
		.ard-audit-events-table { width: 100%; min-width: 980px; table-layout: fixed; font-size: 13px; }
		.ard-audit-events-table td,
		.ard-audit-events-table th {
			white-space: normal;
			overflow-wrap: anywhere;
			word-break: break-word;
			line-height: 1.35;
		}
		.ard-audit-events-pagination {
			display: flex;
			gap: 10px;
			align-items: center;
			justify-content: flex-end;
			margin-top: 10px;
			flex-wrap: wrap;
		}
		.ard-audit-events-page-info { color: #475569; font-size: 12px; min-width: 170px; text-align: center; }
		.ard-audit-events-page-size { display: inline-flex; align-items: center; gap: 6px; color: #334155; font-size: 12px; }
		.ard-audit-events-page-size select { min-height: 30px; }
		@media (max-width: 900px) {
			.ard-pro-grid { grid-template-columns: 1fr; }
			.ard-reporting-dashboard .widefat { font-size: 12px; }
			.ard-kpi-grid { grid-template-columns: 1fr; }
			.ard-kpi-value { font-size: 24px; }
			.ard-kpi-delta { right: 10px; bottom: 10px; font-size: 19px; }
			.ard-orders-overview-table { min-width: 840px; font-size: 12px; }
			.ard-orders-overview-pagination { justify-content: flex-start; }
			.ard-audit-events-table { min-width: 840px; font-size: 12px; }
			.ard-audit-events-pagination { justify-content: flex-start; }
			.ard-audit-pie { width: 220px; height: 180px; }
			.ard-audit-legend { min-width: 0; width: 100%; }
		}
		</style>';
	}

	private function renderDashboardLayoutScript(): void
	{
		echo '<script>
		document.addEventListener("DOMContentLoaded", function () {
			var root = document.querySelector(".ard-reporting-dashboard");
			if (!root || root.dataset.layoutApplied === "1") {
				return;
			}
			root.dataset.layoutApplied = "1";

			function initOrdersOverviewPagination() {
				var table = root.querySelector("#ard-orders-overview-table");
				var pagination = root.querySelector(".ard-orders-overview-pagination");
				if (!table || !pagination) {
					return;
				}

				var tbody = table.querySelector("tbody");
				if (!tbody) {
					return;
				}

				var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr.ard-orders-overview-row"));
				var prevBtn = pagination.querySelector("[data-page-action=\"prev\"]");
				var nextBtn = pagination.querySelector("[data-page-action=\"next\"]");
				var pageInfo = pagination.querySelector("[data-page-info]");
				var pageSizeSelect = pagination.querySelector("[data-page-size]");
				var currentPage = 1;

				if (!rows.length) {
					pagination.style.display = "none";
					return;
				}

				function renderPage() {
					var pageSize = parseInt(pageSizeSelect.value || "10", 10);
					if (!pageSize || pageSize < 1) {
						pageSize = 10;
					}

					var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
					if (currentPage > totalPages) {
						currentPage = totalPages;
					}

					var start = (currentPage - 1) * pageSize;
					var end = start + pageSize;

					rows.forEach(function (row, index) {
						row.style.display = index >= start && index < end ? "" : "none";
					});

					if (pageInfo) {
						pageInfo.textContent = "Strana " + currentPage + " / " + totalPages + " (" + rows.length + " objednávok)";
					}
					if (prevBtn) {
						prevBtn.disabled = currentPage <= 1;
					}
					if (nextBtn) {
						nextBtn.disabled = currentPage >= totalPages;
					}
				}

				if (prevBtn) {
					prevBtn.addEventListener("click", function () {
						if (currentPage > 1) {
							currentPage -= 1;
							renderPage();
						}
					});
				}

				if (nextBtn) {
					nextBtn.addEventListener("click", function () {
						var pageSize = parseInt(pageSizeSelect.value || "10", 10) || 10;
						var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
						if (currentPage < totalPages) {
							currentPage += 1;
							renderPage();
						}
					});
				}

				if (pageSizeSelect) {
					pageSizeSelect.addEventListener("change", function () {
						currentPage = 1;
						renderPage();
					});
				}

				renderPage();
			}

			function initAuditEventsPagination() {
				var table = root.querySelector("#ard-audit-events-table-grid");
				var pagination = root.querySelector(".ard-audit-events-pagination");
				if (!table || !pagination) {
					return;
				}

				var tbody = table.querySelector("tbody");
				if (!tbody) {
					return;
				}

				var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr.ard-audit-events-row"));
				var prevBtn = pagination.querySelector("[data-audit-page-action=\"prev\"]");
				var nextBtn = pagination.querySelector("[data-audit-page-action=\"next\"]");
				var pageInfo = pagination.querySelector("[data-audit-page-info]");
				var pageSizeSelect = pagination.querySelector("[data-audit-page-size]");
				var currentPage = 1;

				if (!rows.length) {
					pagination.style.display = "none";
					return;
				}

				function renderPage() {
					var pageSize = parseInt(pageSizeSelect.value || "5", 10);
					if (!pageSize || pageSize < 1) {
						pageSize = 5;
					}

					var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
					if (currentPage > totalPages) {
						currentPage = totalPages;
					}

					var start = (currentPage - 1) * pageSize;
					var end = start + pageSize;

					rows.forEach(function (row, index) {
						row.style.display = index >= start && index < end ? "" : "none";
					});

					if (pageInfo) {
						pageInfo.textContent = "Strana " + currentPage + " / " + totalPages + " (" + rows.length + " udalostí)";
					}
					if (prevBtn) {
						prevBtn.disabled = currentPage <= 1;
					}
					if (nextBtn) {
						nextBtn.disabled = currentPage >= totalPages;
					}
				}

				if (prevBtn) {
					prevBtn.addEventListener("click", function () {
						if (currentPage > 1) {
							currentPage -= 1;
							renderPage();
						}
					});
				}

				if (nextBtn) {
					nextBtn.addEventListener("click", function () {
						var pageSize = parseInt(pageSizeSelect.value || "5", 10) || 5;
						var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
						if (currentPage < totalPages) {
							currentPage += 1;
							renderPage();
						}
					});
				}

				if (pageSizeSelect) {
					pageSizeSelect.addEventListener("change", function () {
						currentPage = 1;
						renderPage();
					});
				}

				renderPage();
			}

			var headings = Array.prototype.slice.call(root.querySelectorAll(":scope > h2"));
			if (!headings.length) {
				initOrdersOverviewPagination();
				initAuditEventsPagination();
				return;
			}

			var blocks = {};
			headings.forEach(function (h2) {
				var title = (h2.textContent || "").trim();
				var nodes = [h2];
				var cursor = h2.nextSibling;
				while (cursor && !(cursor.nodeType === 1 && cursor.tagName === "H2")) {
					var next = cursor.nextSibling;
					nodes.push(cursor);
					cursor = next;
				}
				blocks[title] = nodes;
			});

			var groups = [
				{ key: "main", title: "Prehľad výkonu", sectionTitles: ["KPI snapshot", "Prehľad objednávok", "Výkon zamestnancov"] },
				{ key: "workflow", title: "Workflow a audit", sectionTitles: ["Workflow detail objednávky", "Auditný prehľad", "Posledné archivácie zmazaných objednávok"] },
				{ key: "ops", title: "Nastavenia a export", sectionTitles: ["Export a emailing", "Reporting tabulky", "Připravené moduly", "Workflow akce", "Ďalší krok"] }
			];

			var grid = document.createElement("div");
			grid.className = "ard-pro-grid";

			groups.forEach(function (group) {
				var panel = document.createElement("section");
				panel.className = "ard-panel ard-panel-" + group.key;
				var panelTitle = document.createElement("h2");
				panelTitle.className = "ard-panel-title";
				panelTitle.textContent = group.title;
				panel.appendChild(panelTitle);

				var found = false;
				group.sectionTitles.forEach(function (sectionTitle) {
					var sectionNodes = blocks[sectionTitle];
					if (!sectionNodes || !sectionNodes.length) {
						return;
					}
					found = true;
					var subsection = document.createElement("div");
					subsection.className = "ard-subsection";
					var label = document.createElement("h3");
					label.textContent = sectionTitle;
					subsection.appendChild(label);

					sectionNodes.forEach(function (node, index) {
						if (index === 0) {
							return;
						}
						subsection.appendChild(node);
					});

					panel.appendChild(subsection);
				});

				if (found) {
					grid.appendChild(panel);
				}
			});

			var firstHeading = headings[0];
			if (!firstHeading) {
				return;
			}

			headings.forEach(function (h) {
				if (h.parentNode === root) {
					root.removeChild(h);
				}
			});

			root.appendChild(grid);
			initOrdersOverviewPagination();
			initAuditEventsPagination();
		});
		</script>';
	}

	private function getKpiLabel(string $key): string
	{
		$labels = array(
			'gross_revenue'        => __('Obrat', 'ar-design-reporting'),
			'revenue_completed'    => __('Obrat vybavených objednávok', 'ar-design-reporting'),
			'revenue_pending'      => __('Obrat čakajúcich objednávok', 'ar-design-reporting'),
			'total_orders'         => __('Počet objednávok', 'ar-design-reporting'),
			'completed_orders'     => __('Počet vybavených objednávok', 'ar-design-reporting'),
			'pending_orders'       => __('Počet čakajúcich objednávok', 'ar-design-reporting'),
			'cancelled_orders'     => __('Storná', 'ar-design-reporting'),
			'net_revenue'          => __('Čistý obrat', 'ar-design-reporting'),
			'average_order_value'  => __('Priemerná hodnota objednávky', 'ar-design-reporting'),
			'average_order_value_completed' => __('Priemerná hodnota vybavených objednávok', 'ar-design-reporting'),
			'average_order_value_pending' => __('Priemerná hodnota čakajúcich objednávok', 'ar-design-reporting'),
			'avg_processing_hours' => __('Priemerný celkový čas procesu (h)', 'ar-design-reporting'),
			'avg_ready_for_packing_hours' => __('Priemer na zahájenie workflow (h)', 'ar-design-reporting'),
			'orders_per_employee'  => __('Objednávky na zamestnanca', 'ar-design-reporting'),
			'kpi_orders'           => __('Objednávky započítané do KPI', 'ar-design-reporting'),
			'completed'            => __('Dokončené objednávky', 'ar-design-reporting'),
			'audit_events'         => __('Zaznamenané auditní události', 'ar-design-reporting'),
		);

		return $labels[$key] ?? $key;
	}

	/**
	 * @param mixed $value
	 */
	private function formatKpiValue(string $key, $value): string
	{
		if (in_array($key, array('gross_revenue', 'net_revenue', 'average_order_value', 'revenue_completed', 'revenue_pending', 'average_order_value_completed', 'average_order_value_pending'), true)) {
			$currency_symbol = $this->getStoreCurrencySymbol();
			return number_format((float) $value, 2, ',', ' ') . ' ' . $currency_symbol;
		}

		if (in_array($key, array('avg_processing_hours', 'orders_per_employee', 'avg_ready_for_packing_hours'), true)) {
			return number_format((float) $value, 2, ',', ' ');
		}

		if (is_scalar($value) || null === $value) {
			return (string) $value;
		}

		return wp_json_encode($value) ?: '';
	}

	private function getStoreCurrencySymbol(): string
	{
		if (function_exists('get_woocommerce_currency') && function_exists('get_woocommerce_currency_symbol')) {
			return get_woocommerce_currency_symbol(get_woocommerce_currency());
		}

		return '€';
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

	private function formatAuditEventLabel(string $event_type): string
	{
		$labels = array(
			'order_status_changed'          => __('Zmena stavu objednávky', 'ar-design-reporting'),
			'order_taken_over'              => __('Prevzatie objednávky', 'ar-design-reporting'),
			'order_owner_reassigned'        => __('Zmena priradenia objednávky', 'ar-design-reporting'),
			'order_packed'                  => __('Označenie objednávky ako zabalená', 'ar-design-reporting'),
			'order_fulfilled'               => __('Označenie objednávky ako vybavená', 'ar-design-reporting'),
			'order_status_set_to_packed'    => __('Nastavenie Woo stavu na Zabalená', 'ar-design-reporting'),
			'order_status_set_to_fulfilled' => __('Nastavenie Woo stavu na Vybavená', 'ar-design-reporting'),
			'order_status_applied_after_reassign' => __('Použitie zmeny stavu po zmene priradenia', 'ar-design-reporting'),
			'order_status_transition_not_allowed' => __('Zablokovaný nepovolený prechod stavov', 'ar-design-reporting'),
			'order_cancelled_restore_not_allowed' => __('Zamietnutá obnova zo Zrušená (bez oprávnenia)', 'ar-design-reporting'),
			'order_action_blocked_owner_mismatch' => __('Zablokovaná akcia: objednávka priradená inému používateľovi', 'ar-design-reporting'),
			'order_marked_cancelled'        => __('Označenie objednávky ako Zrušená', 'ar-design-reporting'),
			'order_delete_attempt_blocked'  => __('Zablokovaný pokus o zmazanie/koš', 'ar-design-reporting'),
			'order_failed_transition_blocked' => __('Zablokovaný prechod na Neúspešná', 'ar-design-reporting'),
			'order_permanent_delete_blocked'  => __('Zablokované trvalé zmazanie objednávky', 'ar-design-reporting'),
			'order_archived_before_delete'  => __('Archivácia objednávky pred zmazaním', 'ar-design-reporting'),
		);

		return $labels[$event_type] ?? $event_type;
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
				'new'          => __('Nová', 'ar-design-reporting'),
				'pending'      => __('Čaká sa na platbu', 'ar-design-reporting'),
				'processing'   => __('Ve zpracování', 'ar-design-reporting'),
				'on-hold'      => __('Pozastavená', 'ar-design-reporting'),
				'na-odoslanie' => __('Na odoslanie', 'ar-design-reporting'),
				'zabalena'     => __('Zabalená', 'ar-design-reporting'),
				'vybavena'     => __('Vybavená', 'ar-design-reporting'),
				'failed'       => __('Neúspešná', 'ar-design-reporting'),
				'cancelled'    => __('Zrušená', 'ar-design-reporting'),
				'refunded'     => __('Refundovaná', 'ar-design-reporting'),
				'caka-sa-na-platbu' => __('Čaká sa na platbu', 'ar-design-reporting'),
				'spracovava-sa'     => __('Spracováva sa', 'ar-design-reporting'),
				'pozastavena'       => __('Pozastavená', 'ar-design-reporting'),
				'zrusena'           => __('Zrušená', 'ar-design-reporting'),
				'refundovana'       => __('Refundovaná', 'ar-design-reporting'),
				'neuspesna'         => __('Neúspešná', 'ar-design-reporting'),
				'completed'    => __('Vybavená', 'ar-design-reporting'),
			);

			return $labels[(string) $value] ?? (string) $value;
		}

		if ('source_trigger' === $key) {
			$labels = array(
				'woocommerce_new_order'            => __('Automaticky při vytvoření objednávky', 'ar-design-reporting'),
				'woocommerce_order_status_changed' => __('Automaticky při změně stavu objednávky', 'ar-design-reporting'),
				'manual_take_over'                 => __('Ruční převzetí objednávky', 'ar-design-reporting'),
				'manual_reassign'                  => __('Ruční změna priradenia objednávky', 'ar-design-reporting'),
				'manual_packed'                    => __('Ruční označení objednávky jako zabalené', 'ar-design-reporting'),
				'manual_fulfillment'               => __('Ruční označení objednávky jako vybavené', 'ar-design-reporting'),
				'manual_cancel_instead_delete'     => __('Ruční označení objednávky jako zrušené (místo smazání)', 'ar-design-reporting'),
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

		$days    = (int) floor($seconds / 86400);
		$hours   = (int) floor(($seconds % 86400) / 3600);
		$minutes = (int) floor(($seconds % 3600) / 60);
		$rest    = $seconds % 60;
		$parts   = array();

		if ($days > 0) {
			$parts[] = sprintf(__('%d d', 'ar-design-reporting'), $days);
		}

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
		if ('' === $raw_value) {
			return __('Nevyplněno', 'ar-design-reporting');
		}

		try {
			$date = new \DateTimeImmutable($raw_value, new \DateTimeZone('UTC'));

			return $date->format('d.m.Y H:i:s');
		} catch (\Exception $exception) {
			return $raw_value;
		}
	}

	private function formatUserLabel(int $user_id): string
	{
		if ($user_id <= 0) {
			return __('Nevyplněno', 'ar-design-reporting');
		}

		if (! function_exists('get_user_by')) {
			return sprintf(__('Používateľ #%d', 'ar-design-reporting'), $user_id);
		}

		$user = get_user_by('id', $user_id);

		if (! $user instanceof \WP_User) {
			return sprintf(__('Používateľ #%d', 'ar-design-reporting'), $user_id);
		}

		$label = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;

		return $label . ' (#' . $user_id . ')';
	}

	/**
	 * @param array<string, mixed> $audit_row
	 */
	private function formatAuditChangeSummary(array $audit_row): string
	{
		$old_data = json_decode((string) ($audit_row['old_value_json'] ?? ''), true);
		$new_data = json_decode((string) ($audit_row['new_value_json'] ?? ''), true);
		$old = is_array($old_data) ? $old_data : array();
		$new = is_array($new_data) ? $new_data : array();

		$old_status = sanitize_key((string) ($old['status'] ?? ''));
		$new_status = sanitize_key((string) ($new['status'] ?? ''));
		if ('' !== $old_status || '' !== $new_status) {
			return $this->formatWorkflowValue('status', $old_status) . ' -> ' . $this->formatWorkflowValue('status', $new_status);
		}

		$old_owner = isset($old['owner_user_id']) ? (int) $old['owner_user_id'] : 0;
		$new_owner = isset($new['owner_user_id']) ? (int) $new['owner_user_id'] : 0;
		if ($old_owner > 0 || $new_owner > 0) {
			return $this->formatUserLabel($old_owner) . ' -> ' . $this->formatUserLabel($new_owner);
		}

		if (! empty($old) || ! empty($new)) {
			$old_text = ! empty($old) ? wp_json_encode($old) : '-';
			$new_text = ! empty($new) ? wp_json_encode($new) : '-';

			return (string) $old_text . ' -> ' . (string) $new_text;
		}

		return __('Bez detailu', 'ar-design-reporting');
	}

	/**
	 * @param array<string, mixed> $audit_row
	 */
	private function formatAuditSourceLabel(array $audit_row): string
	{
		$context_data = json_decode((string) ($audit_row['context_json'] ?? ''), true);
		$context = is_array($context_data) ? $context_data : array();
		$source = sanitize_key((string) ($context['source'] ?? ''));

		if ('' === $source) {
			return __('Nevyplněno', 'ar-design-reporting');
		}

		return $this->formatWorkflowValue('source_trigger', $source);
	}

	private function resolveOrderNumberLabel(int $order_id): string
	{
		if ($order_id <= 0) {
			return __('Nevyplněno', 'ar-design-reporting');
		}

		if (isset($this->order_number_cache[$order_id])) {
			return $this->order_number_cache[$order_id];
		}

		$label = '#' . $order_id;

		if (function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);

			if ($order instanceof \WC_Order) {
				$order_number = (string) $order->get_order_number();

				if ('' !== $order_number && (string) $order_id !== $order_number) {
					$label = '#' . $order_number . ' (ID ' . $order_id . ')';
				}
			}
		}

		$this->order_number_cache[$order_id] = $label;

		return $label;
	}

	private function getOrderAdminUrl(int $order_id): string
	{
		if ($order_id <= 0) {
			return admin_url('admin.php?page=ar-design-reporting');
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

	private function getCurrentMonthStartDate(): string
	{
		$timestamp = current_time('timestamp');

		return gmdate('Y-m-01', (int) $timestamp);
	}

	private function getCurrentDateIso(): string
	{
		$timestamp = current_time('timestamp');

		return gmdate('Y-m-d', (int) $timestamp);
	}

	private function getIsoDateOffsetYears(string $date, int $years): string
	{
		$date = trim($date);

		if ('' === $date) {
			$date = $this->getCurrentDateIso();
		}

		$base = \DateTimeImmutable::createFromFormat('Y-m-d', $date, new \DateTimeZone('UTC'));
		if (! $base instanceof \DateTimeImmutable) {
			return $date;
		}

		$modifier = $years >= 0 ? '+' . $years . ' years' : (string) $years . ' years';
		$shifted = $base->modify($modifier);

		if (! $shifted instanceof \DateTimeImmutable) {
			return $date;
		}

		return $shifted->format('Y-m-d');
	}

	private function formatIsoDateForCzechDisplay(string $date): string
	{
		$date = trim($date);

		if ('' === $date) {
			return $date;
		}

		$parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date, new \DateTimeZone('UTC'));
		if (! $parsed instanceof \DateTimeImmutable) {
			return $date;
		}

		return $parsed->format('d.m.Y');
	}
}
