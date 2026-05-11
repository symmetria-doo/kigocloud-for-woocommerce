<?php
/**
 * Runs one-shot data migrations when the plugin version stored in the
 * database lags behind the code version. Not to be confused with the
 * GitHub Releases auto-updater (handled by plugin-update-checker in
 * the main plugin file).
 *
 * Renamed from Woo_KigoCloud_Updater in 2.0.0 to make the responsibility
 * clearer now that there is a separate auto-update mechanism.
 *
 * @package Woo_KigoCloud
 */

class Woo_KigoCloud_Migrator
{
    /**
     * Runs migrations on every WordPress init if the stored version is
     * older than the code version.
     */
    public function check_for_updates()
    {
        $current_version = get_option('kigocloud_version', '1.0.0');
        $new_version     = Woo_KigoCloud::PLUGIN_VERSION;

        if (version_compare($current_version, $new_version, '<')) {
            $this->run_migrations($current_version, $new_version);
            update_option('kigocloud_show_migration_notice', 1);
            update_option('kigocloud_version', $new_version);
        }
    }

    /**
     * @param string $old_version
     * @param string $new_version
     */
    private function run_migrations($old_version, $new_version)
    {
        if (version_compare($old_version, '1.7.0', '<')) {
            $this->migrate_to_per_gateway_status();
        }
    }

    /**
     * Pre-1.7.0 had global skip-status options; 1.7.0+ uses per-gateway
     * "trigger on" settings. Translate the old globals into per-gateway
     * defaults so existing installs keep their behaviour after upgrade.
     */
    private function migrate_to_per_gateway_status()
    {
        if (!class_exists('WC_Payment_Gateways')) {
            return;
        }
        $skip_status_order_created = get_option('kigocloud_skip_status_order_created', '0');

        $known_credit_card_gateways = array(
            'stripe', 'braintree_credit_card', 'corvuspay', 'monri',
            'mypos_virtual', 'eh_paypal_express', 'revolut_cc', 'aircash-woocommerce',
        );

        $gateways = WC_Payment_Gateways::instance()->get_available_payment_gateways();
        foreach ($gateways as $gateway) {
            $on_status = 0;
            if ($skip_status_order_created === '1') {
                $on_status = 1;
            }
            if (in_array($gateway->id, $known_credit_card_gateways, true)) {
                $on_status = 1;
            }
            update_option('kigocloud_on_status-' . esc_attr($gateway->id), $on_status);
        }
    }
}
