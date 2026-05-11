<?php

/**
 * Fired during plugin activation
 *
 * @link       https://github.com/dpotocic
 * @since      1.0.0
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 * @author     Dejan Potocic <dpotocic@gmail.com>
 */
class Woo_KigoCloud_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            include_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        if ( ! current_user_can( 'activate_plugins' ) ) {
            // Deactivate the plugin.
            deactivate_plugins( plugin_basename( __FILE__ ) );

            $error_message = esc_html__( 'You do not have proper authorization to activate a plugin!', 'kigocloud-for-woocommerce' );
            die( esc_html( $error_message ) );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            // Deactivate the plugin.
            deactivate_plugins( plugin_basename( __FILE__ ) );
            // Throw an error in the WordPress admin console.
            $error_message = esc_html__( 'This plugin requires ', 'kigocloud-for-woocommerce' ) . '<a href="' . esc_url( 'https://wordpress.org/plugins/woocommerce/' ) . '">WooCommerce</a>' . esc_html__( ' plugin to be active!', 'kigocloud-for-woocommerce' );
            die( wp_kses_post( $error_message ) );
        }

		// Store or update the current version of the plugin in the database
		if (!get_option('kigocloud_version')) {
			add_option('kigocloud_version', Woo_KigoCloud::PLUGIN_VERSION);
		} else {
			update_option('kigocloud_version', Woo_KigoCloud::PLUGIN_VERSION);
		}
	}

	public function kigocloud_check_for_update() {
		$current_version = get_option('kigocloud_plugin_version', '1.0.0'); // Default to old version
		$new_version = '1.7'; // Set this to the latest version

		// If the stored version is older, trigger the update migration
		if (version_compare($current_version, $new_version, '<')) {
			$this->kigocloud_run_update_migrations($current_version, $new_version);

			// Update stored version so the migration only runs once
			update_option('kigocloud_plugin_version', $new_version);
		}
	}

	function kigocloud_run_update_migrations($old_version, $new_version) {
		// Example: Rename or remap settings from old versions
		$settings = get_option('kigocloud_plugin_settings', []);

		if (version_compare($old_version, '1.7', '<')) {
			// Get old global settings
			$skip_order_created = isset($settings['skip_status_order_created']) ? $settings['skip_status_order_created'] : '0';
			$skip_order_completed = isset($settings['skip_status_order_completed']) ? $settings['skip_status_order_completed'] : '0';

			// Fetch active payment gateways
			if (class_exists('WC_Payment_Gateways')) {
				$gateways = WC_Payment_Gateways::instance()->get_available_payment_gateways();

				foreach ($gateways as $gateway_id => $gateway) {
					// Create new settings for each gateway based on old global values
					$settings['on_status-' . esc_attr($gateway_id)] = $skip_order_created === '1' ? '0' : '1'; // Map "Order Created"
					$settings['on_status-' . esc_attr($gateway_id)] = $skip_order_completed === '1' ? '1' : '0'; // Map "Order Completed"
				}
			}

			// Remove old global settings
			unset($settings['skip_status_order_created']);
			unset($settings['skip_status_order_completed']);

			// Save updated settings
			update_option('kigocloud_settings', $settings);
		}

		// You can add more migrations for future updates
	}
}
