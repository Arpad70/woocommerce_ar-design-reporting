<?php

declare(strict_types=1);

namespace ArDesign\Reporting\Application;

use ArDesign\Reporting\Infrastructure\Database\Migrator;
use ArDesign\Reporting\Infrastructure\Scheduler\DigestScheduler;
use ArDesign\Reporting\Integration\WooCommerce\Compatibility;
use ArDesign\Reporting\Presentation\Admin\Menu;
use ArDesign\Reporting\Presentation\Admin\OrderWorkflowPanel;
use ArDesign\Reporting\Presentation\Admin\WorkflowActions;
use ArDesign\Reporting\Support\Hooks\OrderArchiveHooks;
use ArDesign\Reporting\Support\Hooks\OrderHooks;
use ArDesign\Reporting\Support\Hooks\OrderProtectionHooks;
use ArDesign\Reporting\Support\Updates\GitHubUpdater;
use ArDesign\Reporting\Support\Updates\RollbackManager;

final class Bootstrap
{
	private Container $container;

	private function __construct()
	{
		$this->container = new Container();
		ServiceProvider::register($this->container);
	}

	public static function boot(): self
	{
		static $instance = null;

		if (null === $instance) {
			$instance = new self();
		}

		return $instance;
	}

	public function run(): void
	{
		add_action( 'plugins_loaded', array( $this, 'bootstrapRuntime' ), 20 );
	}

	public function bootstrapRuntime(): void
	{
		add_action( 'admin_notices', array( $this, 'renderBootstrapNotice' ) );
		$this->ensureSchemaIsCurrent();

		if ( ! $this->container->get( Requirements::class )->canBoot() ) {
			return;
		}

		add_action( 'before_woocommerce_init', array( $this, 'declareWooCommerceCompatibility' ) );
		add_action( 'admin_menu', array( $this, 'registerAdminMenu' ) );
		add_action( 'init', array( $this, 'registerHooks' ) );
		add_action( 'admin_init', array( $this, 'registerAdminActions' ) );
		add_action( 'admin_init', array( $this, 'registerOrderPanels' ) );
		add_action( 'init', array( $this, 'registerUpdaters' ) );
		add_action( 'init', array( $this, 'registerRollbackManager' ) );
		$this->registerSchedulers();
	}

	public function declareWooCommerceCompatibility(): void
	{
		$this->container->get( Compatibility::class )->declareCompatibility();
	}

	public function registerAdminMenu(): void
	{
		$this->container->get( Menu::class )->register();
	}

	public function registerHooks(): void
	{
		$this->container->get( OrderHooks::class )->register();
		$this->container->get( OrderArchiveHooks::class )->register();
		$this->container->get( OrderProtectionHooks::class )->register();
	}

	public function registerAdminActions(): void
	{
		$this->container->get( WorkflowActions::class )->register();
	}

	public function registerOrderPanels(): void
	{
		$this->container->get( OrderWorkflowPanel::class )->register();
	}

	public function registerSchedulers(): void
	{
		// Email digest is intentionally disabled; keep cron queue clean.
		$this->container->get( DigestScheduler::class )->unscheduleAll();
	}

	public function registerUpdaters(): void
	{
		$this->container->get( GitHubUpdater::class )->register();
	}

	public function registerRollbackManager(): void
	{
		$this->container->get( RollbackManager::class )->register();
	}

	public static function activate(): void
	{
		$bootstrap = self::boot();
		$bootstrap->container->get( Migrator::class )->migrate();
		update_option( 'ard_reporting_version', ARD_REPORTING_VERSION );
	}

	public static function deactivate(): void
	{
		$bootstrap = self::boot();
		$bootstrap->container->get( DigestScheduler::class )->unscheduleAll();
	}

	private function ensureSchemaIsCurrent(): void
	{
		$current_db_version     = (string) get_option( 'ard_reporting_db_version', '0.0.0' );
		$current_plugin_version = (string) get_option( 'ard_reporting_version', '0.0.0' );
		$migrator               = $this->container->get( Migrator::class );
		$needs_db_migration     = version_compare( $current_db_version, ARD_REPORTING_DB_VERSION, '<' );

		if ( ! $needs_db_migration ) {
			$needs_db_migration = ! empty( $migrator->getMissingTables() );
		}

		if ( $needs_db_migration ) {
			$migrator->migrate();
		}

		if ( $current_plugin_version !== ARD_REPORTING_VERSION ) {
			update_option( 'ard_reporting_version', ARD_REPORTING_VERSION );
		}
	}

	public function renderBootstrapNotice(): void
	{
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			// Pokud WooCommerce není aktivní, capability manage_woocommerce nemusí existovat.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
		}

		$requirements = $this->container->get( Requirements::class );

		if ( ! $requirements->canBoot() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $requirements->getFailureMessage() ) . '</p></div>';

			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return;
		}

		$screen_id          = (string) $screen->id;
		$is_reporting_screen = false !== strpos( $screen_id, 'ar-design-reporting' );
		$is_order_screen     = false !== strpos( $screen_id, 'shop-order' ) || false !== strpos( $screen_id, 'wc-orders' );

		if ( ! $is_reporting_screen && ! $is_order_screen ) {
			return;
		}

		if ( $is_reporting_screen ) {
			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'Plugin AR Design Reporting má pripravený rozšírený skeleton pre HPOS-first reporting, audit a workflow metadata.', 'ar-design-reporting' );
			echo '</p></div>';
		}

		$this->renderDeleteBlockedTransientNotice();

		if ( isset( $_GET['ard_admin'] ) && is_string( $_GET['ard_admin'] ) ) {
			$action   = sanitize_key( wp_unslash( $_GET['ard_admin'] ) );
			$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
			$message  = '';

			if ( 'take_over' === $action ) {
				$message = sprintf(
					/* translators: %d: order ID */
					__( 'Objednávka #%d byla převzata do zpracování.', 'ar-design-reporting' ),
					$order_id
				);
			}

			if ( 'finish_processing' === $action ) {
				$message = sprintf(
					/* translators: %d: order ID */
					__( 'Objednávka #%d byla označena jako zabalená.', 'ar-design-reporting' ),
					$order_id
				);
			}

				if ( 'complete_fulfillment' === $action ) {
				$message = sprintf(
					/* translators: %d: order ID */
					__( 'Objednávka #%d byla označena jako vybavená.', 'ar-design-reporting' ),
					$order_id
				);
			}

			if ( 'email_saved' === $action ) {
				$message = __( 'Nastavenie e-mailového reportu bolo uložené.', 'ar-design-reporting' );
			}

			if ( 'marked_cancelled' === $action ) {
				$message = sprintf(
					/* translators: %d: order ID */
					__( 'Objednávka #%d bola označená ako Zrušená.', 'ar-design-reporting' ),
					$order_id
				);
			}

			if ( 'email_save_failed' === $action ) {
				$message = __( 'Nastavenie e-mailového reportu sa nepodarilo uložiť. Skontrolujte e-mailovú adresu.', 'ar-design-reporting' );
			}

			if ( 'digest_sent' === $action ) {
				$sent_count = isset( $_GET['sent'] ) ? absint( wp_unslash( $_GET['sent'] ) ) : 0;
				$message    = sprintf(
					/* translators: %d: sent emails count */
					__( 'Manuálny digest bol odoslaný na %d e-mailov.', 'ar-design-reporting' ),
					$sent_count
				);
			}

			if ( 'digest_disabled' === $action ) {
				$message = __( 'Email digest je vypnutý.', 'ar-design-reporting' );
			}

			if ( '' !== $message ) {
				echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
	}

	private function renderDeleteBlockedTransientNotice(): void
	{
		if ( ! function_exists( 'get_transient' ) ) {
			return;
		}

		$current_user_id = get_current_user_id();

		if ( $current_user_id <= 0 ) {
			return;
		}

		$transient_key = 'ard_delete_blocked_' . $current_user_id;
		$notice_data   = get_transient( $transient_key );

		if ( ! is_array( $notice_data ) ) {
			return;
		}

		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $transient_key );
		}

		$order_id    = isset( $notice_data['order_id'] ) ? (int) $notice_data['order_id'] : 0;
		$attempt     = isset( $notice_data['attempt'] ) ? sanitize_key( (string) $notice_data['attempt'] ) : 'delete';
		$redirect_to = $this->getCurrentAdminUrl();

		if ( $order_id <= 0 ) {
			return;
		}

		$attempt_label = 'trash' === $attempt ? __( 'presunúť do koša', 'ar-design-reporting' ) : __( 'trvalo zmazať', 'ar-design-reporting' );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: order ID, 2: blocked attempt label */
				__( 'Akcia %2$s pre objednávku #%1$d je zablokovaná. Objednávky sa nesmú mazať ani presúvať do koša.', 'ar-design-reporting' ),
				$order_id,
				$attempt_label
			)
		);
		echo '</p><p>';
		echo esc_html( __( 'Povolená je iba zmena stavu na Zrušená.', 'ar-design-reporting' ) );
		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
		wp_nonce_field( 'ard_mark_order_cancelled' );
		echo '<input type="hidden" name="action" value="ard_mark_order_cancelled" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
		echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect_to ) . '" />';
		submit_button( __( 'Označiť ako Zrušená', 'ar-design-reporting' ), 'primary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	private function getCurrentAdminUrl(): string
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

	public function renderDashboardPage(): void
	{
		$this->container->get( \ArDesign\Reporting\Presentation\Admin\DashboardPage::class )->render();
	}
}
