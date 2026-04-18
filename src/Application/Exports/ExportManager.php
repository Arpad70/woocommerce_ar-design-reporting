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
		$from   = isset( $filters['date_from'] ) ? sanitize_text_field( (string) $filters['date_from'] ) : '';
		$to     = isset( $filters['date_to'] ) ? sanitize_text_field( (string) $filters['date_to'] ) : '';
		$allowed_statuses = array( 'new', 'processing', 'na-odoslanie', 'odoslana', 'packed', 'completed' );
		$allowed_classes  = array( 'standard', 'preorder', 'custom' );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = '';
		}

		if ( ! in_array( $class, $allowed_classes, true ) ) {
			$class = '';
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
			'date_from'      => $from,
			'date_to'        => $to,
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public function streamProcessingCsv( array $filters = array() ): void
	{
		$normalized = $this->normalizeFilters( $filters );
		$rows       = $this->order_processing_repository->findForExport( $normalized );
		$audit_actors = $this->loadLastStatusChangeActors( $rows );

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
			array(
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
			)
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$order_id = (int) ( $row['order_id'] ?? 0 );
				$wc_data = $this->loadWooOrderData( $order_id );
				$owner_id = (int) ( $row['owner_user_id'] ?? 0 );
				$last_actor_id = (int) ( $audit_actors[ $order_id ] ?? 0 );
				$processing_seconds = isset( $row['processing_seconds'] ) ? (int) $row['processing_seconds'] : 0;
				fputcsv(
					$output,
					array(
						$order_id,
						$wc_data['order_number'],
						$owner_id,
						$this->resolveUserDisplayName( $owner_id ),
						$last_actor_id > 0 ? $last_actor_id : '',
						$this->resolveUserDisplayName( $last_actor_id ),
						$row['processing_mode'] ?? '',
						$row['classification'] ?? '',
						$row['status'] ?? '',
						$wc_data['wc_status'],
						$wc_data['order_total'],
						$wc_data['order_currency'],
						$wc_data['customer_name'],
						$wc_data['customer_email'],
						$wc_data['billing_phone'],
						$wc_data['customer_note'],
						$wc_data['internal_notes'],
						$row['is_kpi_included'] ?? '',
						$row['source_trigger'] ?? '',
						$row['started_at_gmt'] ?? '',
						$row['finished_at_gmt'] ?? '',
						$processing_seconds > 0 ? $processing_seconds : '',
						$processing_seconds > 0 ? round( $processing_seconds / 3600, 2 ) : '',
						$row['created_at_gmt'] ?? '',
						$row['updated_at_gmt'] ?? '',
					)
				);
			}
		}

		fclose( $output );
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

	private function isValidIsoDate( string $value ): bool
	{
		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		return $parsed instanceof \DateTimeImmutable && $parsed->format( 'Y-m-d' ) === $value;
	}
}
