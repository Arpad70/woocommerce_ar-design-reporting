<?php
/**
 * Plugin Name: Ar Design Reporting
 * Description: HPOS-first admin reporting plugin pro WooCommerce pro ar-design.sk.
 * Version: 0.3.25
 * Author: Arpád Horák
 * Update URI: https://github.com/Arpad70/woocommerce_ar-design-reporting
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * Text Domain: ar-design-reporting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARD_REPORTING_VERSION', '0.3.25' );
define( 'ARD_REPORTING_DB_VERSION', '0.3.25' );
define( 'ARD_REPORTING_FILE', __FILE__ );
define( 'ARD_REPORTING_BASENAME', plugin_basename( __FILE__ ) );
define( 'ARD_REPORTING_PATH', plugin_dir_path( __FILE__ ) );
define( 'ARD_REPORTING_URL', plugin_dir_url( __FILE__ ) );
define( 'ARD_REPORTING_GITHUB_REPOSITORY', 'Arpad70/woocommerce_ar-design-reporting' );

require_once ARD_REPORTING_PATH . 'bootstrap/autoload.php';

ArDesign\Reporting\Support\Autoloader::register();

register_activation_hook( ARD_REPORTING_FILE, array( 'ArDesign\\Reporting\\Application\\Bootstrap', 'activate' ) );
register_deactivation_hook( ARD_REPORTING_FILE, array( 'ArDesign\\Reporting\\Application\\Bootstrap', 'deactivate' ) );

ArDesign\Reporting\Application\Bootstrap::boot()->run();
