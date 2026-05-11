<?php

/**
 * Handles plugin updates and migrations.
 *
 * @since      1.7.0
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 */
class Woo_KigoCloud_Updater {

	/**
	 * Checks for updates and runs migrations if needed.
	 *
	 * @since    1.7.0
	 */
	public function check_for_updates() {
		// Get the current and new version numbers
		$current_version = get_option('kigocloud_version', '1.0.0'); // Default to 1.0.0 for old installs
		//$current_version = '1.0.0';
		$new_version = Woo_KigoCloud::PLUGIN_VERSION;

		// If the version has changed, trigger migrations
		if (version_compare($current_version, $new_version, '<')) {
			$this->run_migrations($current_version, $new_version);

			// Set the migration notice flag
			update_option('kigocloud_show_migration_notice', 1);

			// Update the stored version in the database
			update_option('kigocloud_version', $new_version);
		}
	}

	/**
	 * Runs migration logic for updating settings or performing other upgrade tasks.
	 *
	 * @since    1.0.0
	 * @param string $old_version The currently installed version.
	 * @param string $new_version The new version being installed.
	 */
	private function run_migrations($old_version, $new_version) {
		if (version_compare($old_version, '1.7.0', '<')) {
			// Migration: Convert global order status settings to per-gateway settings
			$skip_status_order_created = get_option('kigocloud_skip_status_order_created', '0');
			$skip_status_order_completed = get_option('kigocloud_skip_status_order_completed', '0');

			if (class_exists('WC_Payment_Gateways')) {
				$gateways = WC_Payment_Gateways::instance()->get_available_payment_gateways();

				// List of known gateways that should default to "Order Completed" (1)
				$known_credit_card_gateways = array(
					'stripe', 'braintree_credit_card', 'corvuspay', 'monri',
					'mypos_virtual', 'eh_paypal_express', 'revolut_cc', 'aircash-woocommerce'
				);

				foreach ($gateways as $gateway_id => $gateway) {
					$gateway_setting_key = 'kigocloud_on_status-' . esc_attr($gateway->id);

					// Default to "Order Created" (0) for all gateways
					$on_status = 0;

					// If "Skip Order Created" is enabled, set new setting to "Order Completed" (1)
					if ($skip_status_order_created === '1') {
						$on_status = 1;
					}

					// If the gateway is known as a credit card processor, override to "Order Completed" (1)
					if (in_array($gateway->id, $known_credit_card_gateways, true)) {
						$on_status = 1;
					}

					// Save the new per-gateway setting
					update_option($gateway_setting_key, $on_status);
				}
			}

			// Clean up old global settings after migration
			//delete_option('kigocloud_skip_status_order_created');
			//delete_option('kigocloud_skip_status_order_completed');
		}
	}
}

