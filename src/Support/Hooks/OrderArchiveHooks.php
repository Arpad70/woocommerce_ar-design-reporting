<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Support\Hooks;

use ArDesign\Reporting\Domain\Orders\OrderArchiveService;

final class OrderArchiveHooks
{
	private OrderArchiveService $order_archive_service;

	public function __construct( OrderArchiveService $order_archive_service )
	{
		$this->order_archive_service = $order_archive_service;
	}

	public function register(): void
	{
		add_action( 'before_delete_post', array( $this, 'handleBeforeDeletePost' ) );
		add_action( 'woocommerce_before_delete_order', array( $this, 'handleBeforeDeleteOrder' ) );
	}

	public function handleBeforeDeletePost( int $post_id ): void
	{
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'shop_order' !== $post->post_type ) {
			return;
		}

		$this->order_archive_service->archiveBeforeDelete( $post_id, get_current_user_id() ?: null );
	}

	public function handleBeforeDeleteOrder( int $order_id ): void
	{
		$this->order_archive_service->archiveBeforeDelete( $order_id, get_current_user_id() ?: null );
	}
}
