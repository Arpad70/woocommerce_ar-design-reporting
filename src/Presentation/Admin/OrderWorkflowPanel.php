<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Presentation\Admin;

use ArDesign\Reporting\Domain\Orders\OrderArchiveService;
use ArDesign\Reporting\Domain\Processing\ProcessingService;

final class OrderWorkflowPanel
{
	private ProcessingService $processing_service;

	private OrderArchiveService $order_archive_service;

	public function __construct( ProcessingService $processing_service, OrderArchiveService $order_archive_service )
	{
		$this->processing_service   = $processing_service;
		$this->order_archive_service = $order_archive_service;
	}

	public function register(): void
	{
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render' ) );
	}

	/**
	 * @param mixed $order
	 */
	public function render( $order ): void
	{
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id      = $order->get_id();
		$workflow      = $this->processing_service->getWorkflowSummary( $order_id );
		$archives      = $this->order_archive_service->getRecentArchivesForOrder( $order_id, 5 );
		$current_url   = $this->getCurrentUrl();
		$status_label  = isset( $workflow['status'] ) ? (string) $workflow['status'] : __( 'new', 'ar-design-reporting' );
		$owner_user_id = isset( $workflow['owner_user_id'] ) ? (int) $workflow['owner_user_id'] : 0;
		$owner_label   = $this->resolveUserLabel( $owner_user_id );

		echo '<div class="order_data_column" style="width:100%;margin-top:16px;">';
		echo '<h3>' . esc_html__( 'AR Workflow', 'ar-design-reporting' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Stav', 'ar-design-reporting' ) . ':</strong> ' . esc_html( $status_label ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Zodpovedný používateľ', 'ar-design-reporting' ) . ':</strong> ' . esc_html( $owner_label ) . '</p>';

		echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
		wp_nonce_field( 'ard_take_over_order' );
		echo '<input type="hidden" name="action" value="ard_take_over_order" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $current_url ) . '" />';
		submit_button( __( 'Prevziať objednávku', 'ar-design-reporting' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
		wp_nonce_field( 'ard_finish_processing' );
		echo '<input type="hidden" name="action" value="ard_finish_processing" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $current_url ) . '" />';
		submit_button( __( 'Označiť ako Na odoslanie', 'ar-design-reporting' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '</div>';

		echo '<h4 style="margin-top:16px;">' . esc_html__( 'Archivácie objednávky', 'ar-design-reporting' ) . '</h4>';

		if ( empty( $archives ) ) {
			echo '<p>' . esc_html__( 'Pre túto objednávku zatiaľ nie je žiadna archivácia.', 'ar-design-reporting' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:100%;">';
			echo '<thead><tr><th>' . esc_html__( 'Čas (GMT)', 'ar-design-reporting' ) . '</th><th>' . esc_html__( 'Dôvod', 'ar-design-reporting' ) . '</th><th>' . esc_html__( 'Snapshot', 'ar-design-reporting' ) . '</th></tr></thead>';
			echo '<tbody>';

			foreach ( $archives as $archive ) {
				$created_at_gmt = isset( $archive['created_at_gmt'] ) ? (string) $archive['created_at_gmt'] : '';
				$reason         = isset( $archive['archive_reason'] ) ? (string) $archive['archive_reason'] : '';
				$snapshot       = isset( $archive['snapshot'] ) && is_array( $archive['snapshot'] ) ? $archive['snapshot'] : array();
				$snapshot_json  = wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

				echo '<tr>';
				echo '<td>' . esc_html( $created_at_gmt ) . '</td>';
				echo '<td>' . esc_html( $reason ) . '</td>';
				echo '<td><details><summary>' . esc_html__( 'Zobraziť', 'ar-design-reporting' ) . '</summary><pre style="white-space:pre-wrap;max-width:640px;">' . esc_html( is_string( $snapshot_json ) ? $snapshot_json : '' ) . '</pre></details></td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		}

		echo '</div>';
	}

	private function getCurrentUrl(): string
	{
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return admin_url( 'admin.php' );
		}

		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		$request_uri = '/' . ltrim( $request_uri, '/' );

		if ( false !== strpos( $request_uri, '/wp-admin/' ) ) {
			return home_url( $request_uri );
		}

		return admin_url( 'admin.php' );
	}

	private function resolveUserLabel( int $user_id ): string
	{
		if ( $user_id <= 0 ) {
			return __( 'nezadaný', 'ar-design-reporting' );
		}

		if ( ! function_exists( 'get_user_by' ) ) {
			return sprintf( __( 'Používateľ #%d', 'ar-design-reporting' ), $user_id );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			return sprintf( __( 'Používateľ #%d', 'ar-design-reporting' ), $user_id );
		}

		$name = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;

		return $name . ' (#' . $user_id . ')';
	}
}
