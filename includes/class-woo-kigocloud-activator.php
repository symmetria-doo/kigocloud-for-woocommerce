<?php
/**
 * Fired during plugin activation.
 *
 * - Bails out (and deactivates the plugin) if the user lacks the
 *   activate_plugins capability.
 * - Bails out (and deactivates the plugin) if WooCommerce is not active.
 * - Records the current plugin version into wp_options so the migrator
 *   can detect future version bumps.
 *
 * @package Woo_KigoCloud
 */

class Woo_KigoCloud_Activator
{
    public static function activate()
    {
        if (!function_exists('is_plugin_active_for_network')) {
            include_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        // Use the main plugin file constant, NOT __FILE__ (which would
        // resolve to this activator class file and silently fail).
        $self = defined('WOO_KIGOCLOUD_PLUGIN_FILE')
            ? plugin_basename(WOO_KIGOCLOUD_PLUGIN_FILE)
            : plugin_basename(dirname(__FILE__, 2) . '/kigocloud-for-woocommerce.php');

        if (!current_user_can('activate_plugins')) {
            deactivate_plugins($self);
            wp_die(esc_html__('You do not have proper authorization to activate a plugin.', 'kigocloud-for-woocommerce'));
        }

        if (!class_exists('WooCommerce')) {
            deactivate_plugins($self);
            wp_die(
                wp_kses_post(
                    sprintf(
                        /* translators: %s: link to the WooCommerce plugin page */
                        __('This plugin requires %s to be active.', 'kigocloud-for-woocommerce'),
                        '<a href="' . esc_url('https://wordpress.org/plugins/woocommerce/') . '">WooCommerce</a>'
                    )
                )
            );
        }

        // Record current version so Woo_KigoCloud_Migrator can detect
        // future upgrades on subsequent loads.
        update_option('kigocloud_version', Woo_KigoCloud::PLUGIN_VERSION);
    }
}
