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
		$allowed_statuses = array( 'new', 'processing', 'packed', 'completed' );
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
				'owner_user_id',
				'processing_mode',
				'classification',
				'status',
				'is_kpi_included',
				'source_trigger',
				'started_at_gmt',
				'finished_at_gmt',
				'processing_seconds',
				'created_at_gmt',
				'updated_at_gmt',
			)
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				fputcsv(
					$output,
					array(
						$row['order_id'] ?? '',
						$row['owner_user_id'] ?? '',
						$row['processing_mode'] ?? '',
						$row['classification'] ?? '',
						$row['status'] ?? '',
						$row['is_kpi_included'] ?? '',
						$row['source_trigger'] ?? '',
						$row['started_at_gmt'] ?? '',
						$row['finished_at_gmt'] ?? '',
						$row['processing_seconds'] ?? '',
						$row['created_at_gmt'] ?? '',
						$row['updated_at_gmt'] ?? '',
					)
				);
			}
		}

		fclose( $output );
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
