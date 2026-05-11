<?php
/**
 * Plugin main file.
 *
 * @link              https://github.com/dpotocic/kigocloud-for-woocommerce
 * @since             2.0.0
 * @package           Woo_KigoCloud
 *
 * @wordpress-plugin
 * Plugin Name:       KigoCloud for WooCommerce
 * Plugin URI:        https://github.com/dpotocic/kigocloud-for-woocommerce
 * Description:       Sends WooCommerce orders to KigoCloud (R1 invoices, fiscalization, inventory). Supports both classic and block checkout for R1 customer fields.
 * Version:           2.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * Requires Plugins:  woocommerce
 * WC requires at least: 5.0
 * WC tested up to:   9.4
 * Author:            Dejan Potočić
 * Author URI:        https://github.com/dpotocic
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       kigocloud-for-woocommerce
 * Domain Path:       /languages
 * Update URI:        https://github.com/dpotocic/kigocloud-for-woocommerce
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WOO_KIGOCLOUD_PLUGIN_NAME_VERSION', '2.0.0' );
define( 'WOO_KIGOCLOUD_PLUGIN_FILE', __FILE__ );
define( 'WOO_KIGOCLOUD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_KIGOCLOUD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function kigocloud_activate_plugin_name() {
	require_once WOO_KIGOCLOUD_PLUGIN_DIR . 'includes/class-woo-kigocloud-activator.php';
	Woo_KigoCloud_Activator::activate();
}

function kigocloud_deactivate_plugin_name() {
	require_once WOO_KIGOCLOUD_PLUGIN_DIR . 'includes/class-woo-kigocloud-deactivator.php';
	Woo_KigoCloud_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'kigocloud_activate_plugin_name' );
register_deactivation_hook( __FILE__, 'kigocloud_deactivate_plugin_name' );

require WOO_KIGOCLOUD_PLUGIN_DIR . 'includes/class-woo-kigocloud.php';

function kigocloud_run_plugin_name() {
	$plugin = new Woo_KigoCloud();
	$plugin->run();
}
kigocloud_run_plugin_name();
