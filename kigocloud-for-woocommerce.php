<?php
/**
 * Plugin main file.
 *
 * @link              https://github.com/symmetria-doo/kigocloud-for-woocommerce
 * @since             2.0.0
 * @package           Woo_KigoCloud
 *
 * @wordpress-plugin
 * Plugin Name:       KigoCloud for WooCommerce
 * Plugin URI:        https://github.com/symmetria-doo/kigocloud-for-woocommerce
 * Description:       Sends WooCommerce orders to KigoCloud (R1 invoices, fiscalization, inventory). Supports both classic and block checkout for R1 customer fields.
 * Version:           2.1.14
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * Requires Plugins:  woocommerce
 * WC requires at least: 5.0
 * WC tested up to:   9.4
 * Author:            Symmetria d.o.o. (Dejan Potočić)
 * Author URI:        https://www.symmetria.hr/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       kigocloud-for-woocommerce
 * Domain Path:       /languages
 * Update URI:        https://github.com/symmetria-doo/kigocloud-for-woocommerce
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'WOO_KIGOCLOUD_PLUGIN_NAME_VERSION', '2.1.14' );
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

// Declare compatibility with WooCommerce features so HPOS and the
// cart/checkout block do not flag the plugin as incompatible.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// Auto-update against GitHub Releases.
$kigocloud_puc_bootstrap = WOO_KIGOCLOUD_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $kigocloud_puc_bootstrap ) ) {
	require_once $kigocloud_puc_bootstrap;
	if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		// 4th constructor argument is the checkPeriod in hours.
		// plugin-update-checker's default is 12; we set it to 1 because
		// this plugin is self-hosted on GitHub with no wordpress.org
		// rate-limit concerns, so faster propagation of fixes is worth
		// the small extra API traffic.
		// (There is no setCheckPeriod() setter - it must be passed in
		// the factory call. See Puc/v5p5/Plugin/UpdateChecker.php.)
		$kigocloud_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/symmetria-doo/kigocloud-for-woocommerce/',
			__FILE__,
			'kigocloud-for-woocommerce',
			1
		);
		if ( method_exists( $kigocloud_update_checker, 'setBranch' ) ) {
			$kigocloud_update_checker->setBranch( 'main' );
		}
		// Release assets are the uploaded zip files attached to a GitHub
		// Release. enableReleaseAssets() makes the updater download the
		// zip instead of a tarball of the repo.
		if ( method_exists( $kigocloud_update_checker, 'getVcsApi' ) ) {
			$vcs_api = $kigocloud_update_checker->getVcsApi();
			if ( $vcs_api && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
				$vcs_api->enableReleaseAssets();
			}
		}
	}
}

require WOO_KIGOCLOUD_PLUGIN_DIR . 'includes/class-woo-kigocloud.php';

function kigocloud_run_plugin_name() {
	$plugin = new Woo_KigoCloud();
	$plugin->run();
}
kigocloud_run_plugin_name();
