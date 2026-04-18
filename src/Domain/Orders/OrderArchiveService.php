<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Domain\Orders;

use ArDesign\Reporting\Domain\Audit\AuditLogger;
use ArDesign\Reporting\Infrastructure\Repository\OrderArchiveRepository;

final class OrderArchiveService
{
	private OrderArchiveRepository $order_archive_repository;

	private AuditLogger $audit_logger;

	/**
	 * @var array<int, bool>
	 */
	private array $archived_in_request = array();

	public function __construct( OrderArchiveRepository $order_archive_repository, AuditLogger $audit_logger )
	{
		$this->order_archive_repository = $order_archive_repository;
		$this->audit_logger             = $audit_logger;
	}

	public function archiveBeforeDelete( int $order_id, ?int $actor_user_id = null, string $reason = 'deleted' ): void
	{
		if ( $order_id <= 0 || isset( $this->archived_in_request[ $order_id ] ) ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$reason = sanitize_key( $reason );

		if ( ! in_array( $reason, array( 'deleted', 'trash' ), true ) ) {
			$reason = 'deleted';
		}

		$snapshot = $this->buildSnapshot( $order );
		$snapshot_json = wp_json_encode( $snapshot );

		if ( ! is_string( $snapshot_json ) ) {
			return;
		}

		$inserted = $this->order_archive_repository->insertArchive(
			array(
				'order_id'       => $order_id,
				'archive_reason' => $reason,
				'snapshot_json'  => $snapshot_json,
				'actor_user_id'  => $actor_user_id,
				'created_at_gmt' => current_time( 'mysql', true ),
			)
		);

		if ( ! $inserted ) {
			return;
		}

		$this->archived_in_request[ $order_id ] = true;

		$this->audit_logger->log(
			'order_archived_before_delete',
			'order',
			$order_id,
			$order_id,
			$actor_user_id,
			array(),
			array(
				'archive_reason' => $reason,
			),
			array(
				'source' => 'order_delete_hook',
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecentArchivesForOrder( int $order_id, int $limit = 10 ): array
	{
		$rows = $this->order_archive_repository->getRecentByOrderId( $order_id, $limit );

		return $this->mapArchiveRows( $rows );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecentDeletedArchives( int $limit = 20 ): array
	{
		$rows = $this->order_archive_repository->getRecentDeleted( $limit );

		return $this->mapArchiveRows( $rows );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildSnapshot( \WC_Order $order ): array
	{
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			$items[] = array(
				'item_id'     => $item->get_id(),
				'product_id'  => $item->get_product_id(),
				'name'        => $item->get_name(),
				'quantity'    => $item->get_quantity(),
				'subtotal'    => $item->get_subtotal(),
				'total'       => $item->get_total(),
				'sku'         => $product ? $product->get_sku() : '',
			);
		}

		return array(
			'order_id'          => $order->get_id(),
			'number'            => $order->get_order_number(),
			'status'            => $order->get_status(),
			'currency'          => $order->get_currency(),
			'total'             => $order->get_total(),
			'customer_id'       => $order->get_customer_id(),
			'billing_email'     => $order->get_billing_email(),
			'payment_method'    => $order->get_payment_method(),
			'created_via'       => $order->get_created_via(),
			'date_created_gmt'  => $this->formatAsGmt( $order->get_date_created() ),
			'date_paid_gmt'     => $this->formatAsGmt( $order->get_date_paid() ),
			'items'             => $items,
		);
	}

	private function formatAsGmt( ?\WC_DateTime $date ): ?string
	{
		if ( ! $date instanceof \WC_DateTime ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function mapArchiveRows( array $rows ): array
	{
		$mapped = array();

		foreach ( $rows as $row ) {
			$snapshot_json = isset( $row['snapshot_json'] ) ? (string) $row['snapshot_json'] : '';
			$snapshot      = json_decode( $snapshot_json, true );

			$mapped[] = array(
				'id'            => (int) ( $row['id'] ?? 0 ),
				'order_id'      => (int) ( $row['order_id'] ?? 0 ),
				'archive_reason'=> (string) ( $row['archive_reason'] ?? '' ),
				'actor_user_id' => isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
				'created_at_gmt'=> (string) ( $row['created_at_gmt'] ?? '' ),
				'snapshot'      => is_array( $snapshot ) ? $snapshot : array(),
			);
		}

		return $mapped;
	}
}
