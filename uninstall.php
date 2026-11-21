<?php
/**
 * Triggered when an admin clicks "Delete" on the plugin in WP -> Plugins.
 *
 * Removes only this plugin's settings: options named "kigocloud_*" and
 * transients named "kigocloud_*". Data kept on products and orders is
 * never removed: the deposit amount on products and variations, the
 * link of each order to its Kigo.Cloud invoice (number, id, cancellation)
 * and the R1 company data on orders. Without the invoice link a shop that
 * installs the plugin again would issue a second fiscal invoice for an
 * order and could no longer cancel the first one.
 *
 * Nothing at all is removed while another copy of the plugin is still
 * installed (for example the old version left in a folder of another
 * name after a zip was installed by hand): that copy uses the same
 * settings.
 *
 * @package Woo_KigoCloud
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!current_user_can('activate_plugins')) {
    return;
}

if (!function_exists('kigocloud_uninstall_other_copy')) {
    /**
     * Basename of another installed copy of this plugin, or '' when there is none.
     *
     * @param string $selfDir Folder of the copy being deleted, relative to the plugins folder.
     * @return string
     */
    function kigocloud_uninstall_other_copy($selfDir)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Bulk delete removes the folders one after another while get_plugins()
        // may still list a copy that is already gone, hence the file check.
        foreach (get_plugins() as $file => $data) {
            if (dirname($file) === $selfDir || !is_file(WP_PLUGIN_DIR . '/' . $file)) {
                continue;
            }
            $domain = isset($data['TextDomain']) ? $data['TextDomain'] : '';
            if ($domain === 'kigocloud-for-woocommerce' || basename($file) === 'kigocloud-for-woocommerce.php') {
                return $file;
            }
        }
        return '';
    }
}

// The folder of this file, not WP_UNINSTALL_PLUGIN: when several plugins are
// deleted in one request WordPress keeps that constant from the first one.
if (kigocloud_uninstall_other_copy(basename(__DIR__)) !== '') {
    return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off cleanup of this plugin's own settings on uninstall.

$kigocloud_like = array(
    $wpdb->esc_like('kigocloud_') . '%',
    $wpdb->esc_like('_transient_kigocloud_') . '%',
    $wpdb->esc_like('_transient_timeout_kigocloud_') . '%',
    $wpdb->esc_like('_site_transient_kigocloud_') . '%',
    $wpdb->esc_like('_site_transient_timeout_kigocloud_') . '%',
);

// Options and transients.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $kigocloud_like
));

// Site-wide options and site transients on multisite.
if (is_multisite()) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
        $kigocloud_like[0],
        $kigocloud_like[3],
        $kigocloud_like[4]
    ));
}

wp_cache_flush();
