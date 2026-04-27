<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application\Exports;

use ArDesign\Reporting\Infrastructure\Repository\OrderProcessingRepository;

final class ExportManager
{
	private OrderProcessingRepository $order_processing_repository;

	public function __construct( OrderProcessingRepository $order_processing_repository )
	{
		$this->order_processing_repository = $order_processing_repository;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, mixed>
	 */
	public function describeCsvExport(array $filters = array()): array
	{
		return array(
			'format'      => 'csv',
			'scope'       => 'filtered_dashboard_dataset',
			'filters'     => $filters,
			'delivery'    => 'manual_download',
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, string>
	 */
	public function normalizeFilters( array $filters ): array
	{
		$status = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$class  = isset( $filters['classification'] ) ? sanitize_key( (string) $filters['classification'] ) : '';
		$kpi_included = isset( $filters['kpi_included'] ) ? sanitize_key( (string) $filters['kpi_included'] ) : '';
		$from   = isset( $filters['date_from'] ) ? sanitize_text_field( (string) $filters['date_from'] ) : '';
		$to     = isset( $filters['date_to'] ) ? sanitize_text_field( (string) $filters['date_to'] ) : '';
		$allowed_statuses = array(
			'new',
			'pending',
			'processing',
			'on-hold',
			'cancelled',
			'refunded',
			'failed',
			'na-odoslanie',
			'zabalena',
			'vybavena',
			'caka-sa-na-platbu',
			'spracovava-sa',
			'pozastavena',
			'zrusena',
			'refundovana',
			'neuspesna',
			'completed',
		);
		$allowed_classes  = array( 'standard', 'preorder', 'custom' );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = '';
		}

		if ( ! in_array( $class, $allowed_classes, true ) ) {
			$class = '';
		}

		if ( ! in_array( $kpi_included, array( '1', '0' ), true ) ) {
			$kpi_included = '';
		}

		if ( ! $this->isValidIsoDate( $from ) ) {
			$from = '';
		}

		if ( ! $this->isValidIsoDate( $to ) ) {
			$to = '';
		}

		if ( '' !== $from && '' !== $to && $from > $to ) {
			$temp = $from;
			$from = $to;
			$to   = $temp;
		}

		return array(
			'status'         => $status,
			'classification' => $class,
			'kpi_included'   => $kpi_included,
			'date_from'      => $from,
			'date_to'        => $to,
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public function streamProcessingCsv( array $filters = array() ): void
	{
		$dataset = $this->buildExportDataset($filters);

		nocache_headers();

		$filename = 'ar-design-reporting-' . gmdate( 'Ymd-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Nepodarilo sa pripraviť CSV export.', 'ar-design-reporting' ) );
		}

		fputcsv(
			$output,
			$this->getExportColumns()
		);

		foreach ($dataset as $record) {
			fputcsv($output, $record);
		}

		fclose( $output );
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public function streamProcessingXlsx(array $filters = array()): void
	{
		if (! class_exists(\ZipArchive::class)) {
			wp_die(esc_html__('XLSX export vyžaduje rozšírenie ZipArchive na serveri.', 'ar-design-reporting'));
		}

		$columns = $this->getExportColumnsHumanLabels();
		$rows = $this->buildExportDataset($filters);
		$temp_file = wp_tempnam('ard-export-xlsx');

		if (! is_string($temp_file) || '' === $temp_file) {
			wp_die(esc_html__('Nepodarilo sa pripraviť dočasný súbor pre XLSX export.', 'ar-design-reporting'));
		}

		$zip = new \ZipArchive();
		$open_result = $zip->open($temp_file, \ZipArchive::OVERWRITE);

		if (true !== $open_result) {
			wp_die(esc_html__('Nepodarilo sa vytvoriť XLSX export.', 'ar-design-reporting'));
		}

		$sheet_rows = array();
		$sheet_rows[] = $columns;
		foreach ($rows as $record) {
			$sheet_rows[] = $record;
		}

		$zip->addFromString('[Content_Types].xml', $this->buildXlsxContentTypesXml());
		$zip->addFromString('_rels/.rels', $this->buildXlsxRootRelsXml());
		$zip->addFromString('xl/workbook.xml', $this->buildXlsxWorkbookXml());
		$zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildXlsxWorkbookRelsXml());
		$zip->addFromString('xl/styles.xml', $this->buildXlsxStylesXml());
		$zip->addFromString('xl/worksheets/sheet1.xml', $this->buildXlsxSheetXml($sheet_rows));
		$zip->close();

		nocache_headers();
		$filename = 'ar-design-reporting-' . gmdate('Ymd-His') . '.xlsx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($temp_file));

		readfile($temp_file);
		@unlink($temp_file);
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 */
	public function streamAuditEventsXlsx(array $events, string $event_type = ''): void
	{
		if (! class_exists(\ZipArchive::class)) {
			wp_die(esc_html__('XLSX export vyžaduje rozšírenie ZipArchive na serveri.', 'ar-design-reporting'));
		}

		$rows = array();
		foreach ($events as $event_row) {
			$order_id = isset($event_row['order_id']) ? (int) $event_row['order_id'] : 0;
			$order_data = $order_id > 0 ? $this->loadWooOrderData($order_id) : array('order_number' => '');
			$actor_user_id = isset($event_row['actor_user_id']) ? (int) $event_row['actor_user_id'] : 0;

			$rows[] = array(
				$this->formatGmtDate((string) ($event_row['created_at_gmt'] ?? '')),
				$this->formatAuditEventLabel((string) ($event_row['event_type'] ?? '')),
				$order_id > 0 ? (string) $order_id : '',
				(string) ($order_data['order_number'] ?? ''),
				$actor_user_id > 0 ? (string) $actor_user_id : '',
				$this->resolveUserDisplayName($actor_user_id),
				$this->formatAuditChangeSummary($event_row),
				$this->formatAuditSourceLabel($event_row),
			);
		}

		$sheet_rows = array();
		$sheet_rows[] = array(
			'Čas (GMT)',
			'Udalost',
			'ID objednávky',
			'Číslo objednávky',
			'ID používateľa',
			'Používateľ',
			'Zmena',
			'Zdroj',
		);
		foreach ($rows as $row) {
			$sheet_rows[] = $row;
		}

		$temp_file = wp_tempnam('ard-audit-export-xlsx');

		if (! is_string($temp_file) || '' === $temp_file) {
			wp_die(esc_html__('Nepodarilo sa pripraviť dočasný súbor pre XLSX export.', 'ar-design-reporting'));
		}

		$zip = new \ZipArchive();
		$open_result = $zip->open($temp_file, \ZipArchive::OVERWRITE);

		if (true !== $open_result) {
			wp_die(esc_html__('Nepodarilo sa vytvoriť XLSX export.', 'ar-design-reporting'));
		}

		$zip->addFromString('[Content_Types].xml', $this->buildXlsxContentTypesXml());
		$zip->addFromString('_rels/.rels', $this->buildXlsxRootRelsXml());
		$zip->addFromString('xl/workbook.xml', $this->buildXlsxWorkbookXml());
		$zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildXlsxWorkbookRelsXml());
		$zip->addFromString('xl/styles.xml', $this->buildXlsxStylesXml());
		$zip->addFromString('xl/worksheets/sheet1.xml', $this->buildXlsxSheetXml($sheet_rows));
		$zip->close();

		nocache_headers();
		$suffix = '' !== $event_type ? '-' . $event_type : '';
		$filename = 'ar-design-reporting-audit' . $suffix . '-' . gmdate('Ymd-His') . '.xlsx';
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($temp_file));

		readfile($temp_file);
		@unlink($temp_file);
	}

	/**
	 * @return array<int, string>
	 */
	private function getExportColumns(): array
	{
		return array(
			'order_id',
			'order_number',
			'owner_user_id',
			'owner_name',
			'last_status_change_actor',
			'last_status_change_actor_name',
			'processing_mode',
			'classification',
			'status',
			'wc_status',
			'order_total',
			'order_currency',
			'customer_name',
			'customer_email',
			'billing_phone',
			'customer_note',
			'internal_notes',
			'is_kpi_included',
			'source_trigger',
			'started_at_gmt',
			'finished_at_gmt',
			'processing_seconds',
			'processing_hours',
			'created_at_gmt',
			'updated_at_gmt',
		);
	}

	/**
	 * Human-friendly headers for XLSX export.
	 *
	 * @return array<int, string>
	 */
	private function getExportColumnsHumanLabels(): array
	{
		return array(
			'ID objednávky',
			'Číslo objednávky',
			'ID zodpovědného uživatele',
			'Zodpovědný uživatel',
			'ID uživatele poslední změny stavu',
			'Uživatel poslední změny stavu',
			'Režim zpracování',
			'Klasifikace',
			'Workflow stav',
			'WooCommerce stav',
			'Celková částka objednávky',
			'Měna',
			'Jméno zákazníka',
			'E-mail zákazníka',
			'Telefon zákazníka',
			'Poznámka zákazníka',
			'Interní poznámky',
			'Zahrnout do KPI',
			'Zdroj změny',
			'Začátek zpracování (GMT)',
			'Konec zpracování (GMT)',
			'Čas zpracování (s)',
			'Čas zpracování (h)',
			'Vytvořeno (GMT)',
			'Aktualizováno (GMT)',
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<int, array<int, string>>
	 */
	private function buildExportDataset(array $filters): array
	{
		$normalized = $this->normalizeFilters($filters);
		$rows = $this->order_processing_repository->findForExport($normalized);
		$audit_actors = $this->loadLastStatusChangeActors($rows);
		$dataset = array();

		if (! is_array($rows)) {
			return $dataset;
		}

		foreach ($rows as $row) {
			$order_id = (int) ($row['order_id'] ?? 0);
			$wc_data = $this->loadWooOrderData($order_id);
			$owner_id = (int) ($row['owner_user_id'] ?? 0);
			$last_actor_id = (int) ($audit_actors[$order_id] ?? 0);
			$processing_seconds = isset($row['processing_seconds']) ? (int) $row['processing_seconds'] : 0;

			$dataset[] = array(
				(string) $order_id,
				(string) $wc_data['order_number'],
				$owner_id > 0 ? (string) $owner_id : '',
				(string) $this->resolveUserDisplayName($owner_id),
				$last_actor_id > 0 ? (string) $last_actor_id : '',
				(string) $this->resolveUserDisplayName($last_actor_id),
				(string) ($row['processing_mode'] ?? ''),
				(string) ($row['classification'] ?? ''),
				(string) ($row['status'] ?? ''),
				(string) $wc_data['wc_status'],
				(string) $wc_data['order_total'],
				(string) $wc_data['order_currency'],
				(string) $wc_data['customer_name'],
				(string) $wc_data['customer_email'],
				(string) $wc_data['billing_phone'],
				(string) $wc_data['customer_note'],
				(string) $wc_data['internal_notes'],
				(string) ($row['is_kpi_included'] ?? ''),
				(string) ($row['source_trigger'] ?? ''),
				(string) ($row['started_at_gmt'] ?? ''),
				(string) ($row['finished_at_gmt'] ?? ''),
				$processing_seconds > 0 ? (string) $processing_seconds : '',
				$processing_seconds > 0 ? (string) round($processing_seconds / 3600, 2) : '',
				(string) ($row['created_at_gmt'] ?? ''),
				(string) ($row['updated_at_gmt'] ?? ''),
			);
		}

		return $dataset;
	}

	private function buildXlsxContentTypesXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	private function buildXlsxRootRelsXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private function buildXlsxWorkbookXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>'
			. '<sheet name="Export" sheetId="1" r:id="rId1"/>'
			. '</sheets>'
			. '</workbook>';
	}

	private function buildXlsxWorkbookRelsXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	private function buildXlsxStylesXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
			. '</styleSheet>';
	}

	/**
	 * @param array<int, array<int, string>> $rows
	 */
	private function buildXlsxSheetXml(array $rows): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetData>';

		foreach ($rows as $row_index => $row_cells) {
			$row_number = $row_index + 1;
			$xml .= '<row r="' . $row_number . '">';

			foreach ($row_cells as $col_index => $cell_value) {
				$cell_ref = $this->xlsxColumnName($col_index + 1) . $row_number;
				$escaped = $this->escapeXmlValue((string) $cell_value);
				$xml .= '<c r="' . $cell_ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
			}

			$xml .= '</row>';
		}

		$xml .= '</sheetData></worksheet>';

		return $xml;
	}

	private function escapeXmlValue(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	private function xlsxColumnName(int $column_index): string
	{
		$name = '';
		while ($column_index > 0) {
			$column_index--;
			$name = chr(($column_index % 26) + 65) . $name;
			$column_index = (int) floor($column_index / 26);
		}

		return $name;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, int>
	 */
	private function loadLastStatusChangeActors( array $rows ): array
	{
		global $wpdb;

		$order_ids = array();
		foreach ( $rows as $row ) {
			$order_id = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;
			if ( $order_id > 0 ) {
				$order_ids[] = $order_id;
			}
		}

		$order_ids = array_values( array_unique( $order_ids ) );

		if ( empty( $order_ids ) ) {
			return array();
		}

		$order_ids_sql = implode( ',', array_map( 'absint', $order_ids ) );
		$audit_table = $wpdb->prefix . 'ard_audit_log';
		$sql = "SELECT a.order_id, a.actor_user_id
			FROM {$audit_table} a
			INNER JOIN (
				SELECT order_id, MAX(id) AS max_id
				FROM {$audit_table}
				WHERE event_type = 'order_status_changed' AND order_id IN ({$order_ids_sql})
				GROUP BY order_id
			) latest ON latest.max_id = a.id";
		$rows_data = $wpdb->get_results( $sql, ARRAY_A );
		$actor_map = array();

		if ( ! is_array( $rows_data ) ) {
			return $actor_map;
		}

		foreach ( $rows_data as $audit_row ) {
			$actor_map[ (int) ( $audit_row['order_id'] ?? 0 ) ] = (int) ( $audit_row['actor_user_id'] ?? 0 );
		}

		return $actor_map;
	}

	/**
	 * @return array<string, string>
	 */
	private function loadWooOrderData( int $order_id ): array
	{
		$fallback = array(
			'order_number'  => (string) $order_id,
			'wc_status'     => '',
			'order_total'   => '',
			'order_currency'=> '',
			'customer_name' => '',
			'customer_email'=> '',
			'billing_phone' => '',
			'customer_note' => '',
			'internal_notes'=> '',
		);

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return $fallback;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $fallback;
		}

		$fallback['order_number']   = (string) $order->get_order_number();
		$fallback['wc_status']      = (string) $order->get_status();
		$fallback['order_total']    = (string) $order->get_total();
		$fallback['order_currency'] = (string) $order->get_currency();
		$fallback['customer_name']  = trim( (string) $order->get_formatted_billing_full_name() );
		$fallback['customer_email'] = (string) $order->get_billing_email();
		$fallback['billing_phone']  = (string) $order->get_billing_phone();
		$fallback['customer_note']  = (string) $order->get_customer_note();
		$fallback['internal_notes'] = $this->loadInternalOrderNotes( $order_id );

		return $fallback;
	}

	private function loadInternalOrderNotes( int $order_id ): string
	{
		if ( ! function_exists( 'wc_get_order_notes' ) || $order_id <= 0 ) {
			return '';
		}

		$notes = wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'type'     => 'internal',
				'limit'    => 5,
			)
		);

		if ( ! is_array( $notes ) || empty( $notes ) ) {
			return '';
		}

		$chunks = array();
		foreach ( $notes as $note ) {
			if ( is_object( $note ) && isset( $note->content ) ) {
				$chunks[] = trim( (string) $note->content );
			}
		}

		return implode( ' | ', array_filter( $chunks ) );
	}

	private function resolveUserDisplayName( int $user_id ): string
	{
		if ( $user_id <= 0 || ! function_exists( 'get_user_by' ) ) {
			return '';
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		return '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;
	}

	private function formatGmtDate(string $raw_value): string
	{
		if ('' === $raw_value) {
			return '';
		}

		try {
			$date = new \DateTimeImmutable($raw_value, new \DateTimeZone('UTC'));

			return $date->format('d.m.Y H:i:s');
		} catch (\Exception $exception) {
			return $raw_value;
		}
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
			return $this->formatAuditStatusLabel($old_status) . ' -> ' . $this->formatAuditStatusLabel($new_status);
		}

		$old_owner = isset($old['owner_user_id']) ? (int) $old['owner_user_id'] : 0;
		$new_owner = isset($new['owner_user_id']) ? (int) $new['owner_user_id'] : 0;
		if ($old_owner > 0 || $new_owner > 0) {
			return $this->resolveUserDisplayName($old_owner) . ' -> ' . $this->resolveUserDisplayName($new_owner);
		}

		if (! empty($old) || ! empty($new)) {
			$old_text = ! empty($old) ? wp_json_encode($old) : '-';
			$new_text = ! empty($new) ? wp_json_encode($new) : '-';

			return (string) $old_text . ' -> ' . (string) $new_text;
		}

		return 'Bez detailu';
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
			return '';
		}

		$labels = array(
			'woocommerce_new_order'            => 'Automaticky pri vytvorení objednávky',
			'woocommerce_order_status_changed' => 'Automaticky pri zmene stavu objednávky',
			'manual_take_over'                 => 'Ručné prevzatie objednávky',
			'manual_reassign'                  => 'Ručná zmena priradenia objednávky',
			'manual_packed'                    => 'Ručné označenie objednávky ako zabalená',
			'manual_fulfillment'               => 'Ručné označenie objednávky ako vybavená',
			'manual_cancel_instead_delete'     => 'Ručné označenie objednávky ako zrušená',
		);

		return $labels[$source] ?? $source;
	}

	private function formatAuditEventLabel(string $event_type): string
	{
		$event_type = sanitize_key($event_type);
		$labels = array(
			'order_status_changed'          => 'Zmena stavu objednávky',
			'order_taken_over'              => 'Prevzatie objednávky',
			'order_owner_reassigned'        => 'Zmena priradenia objednávky',
			'order_packed'                  => 'Označenie objednávky ako zabalená',
			'order_fulfilled'               => 'Označenie objednávky ako vybavená',
			'order_status_set_to_packed'    => 'Nastavenie Woo stavu na Zabalená',
			'order_status_set_to_fulfilled' => 'Nastavenie Woo stavu na Vybavená',
			'order_status_applied_after_reassign' => 'Použitie zmeny stavu po zmene priradenia',
			'order_status_transition_not_allowed' => 'Zablokovaný nepovolený prechod stavov',
			'order_cancelled_restore_not_allowed' => 'Zamietnutá obnova zo Zrušená',
			'order_action_blocked_owner_mismatch' => 'Zablokovaná akcia: objednávka priradená inému používateľovi',
			'order_marked_cancelled'        => 'Označenie objednávky ako Zrušená',
			'order_delete_attempt_blocked'  => 'Zablokovaný pokus o zmazanie alebo kôš',
			'order_failed_transition_blocked' => 'Zablokovaný prechod na Neúspešná',
			'order_permanent_delete_blocked'  => 'Zablokované trvalé zmazanie objednávky',
			'order_archived_before_delete'  => 'Archivácia objednávky pred zmazaním',
		);

		return $labels[$event_type] ?? $event_type;
	}

	private function formatAuditStatusLabel(string $status): string
	{
		$status = sanitize_key($status);
		$labels = array(
			'new'          => 'Nová',
			'pending'      => 'Čaká sa na platbu',
			'processing'   => 'Spracováva sa',
			'on-hold'      => 'Pozastavená',
			'na-odoslanie' => 'Na odoslanie',
			'zabalena'     => 'Zabalená',
			'vybavena'     => 'Vybavená',
			'failed'       => 'Neúspešná',
			'cancelled'    => 'Zrušená',
			'refunded'     => 'Refundovaná',
			'completed'    => 'Vybavená',
		);

		return $labels[$status] ?? $status;
	}

	private function isValidIsoDate( string $value ): bool
	{
		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		return $parsed instanceof \DateTimeImmutable && $parsed->format( 'Y-m-d' ) === $value;
	}
}
