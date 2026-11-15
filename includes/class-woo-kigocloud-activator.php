<?php
/**
 * Fired during plugin activation.
 *
 * - Bails out (and deactivates the plugin) if the user lacks the
 *   activate_plugins capability.
 * - Activates without WooCommerce too: then only the price list works.
 * - Records the current plugin version into wp_options so the migrator
 *   can detect future version bumps.
 *
 * @package Woo_KigoCloud
 */

if (!defined('ABSPATH')) {
    exit;
}

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

        // No WooCommerce check: without it the plugin publishes the price list
        // only (Woo_KigoCloud::has_woocommerce()).

        // Record current version so Woo_KigoCloud_Migrator can detect
        // future upgrades on subsequent loads.
        update_option('kigocloud_version', Woo_KigoCloud::PLUGIN_VERSION);

        // Settings of the KigoKasa plugin, if this shop used it. It stays
        // active until the shop clicks the switch button.
        if (class_exists('Woo_KigoCloud_KigoKasa_Switch')) {
            Woo_KigoCloud_KigoKasa_Switch::import_settings();
        }
    }
}
