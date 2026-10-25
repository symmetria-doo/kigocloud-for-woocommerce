<?php
/**
 * Triggered when an admin clicks "Delete" on the plugin in WP -> Plugins.
 *
 * Strategy: a single SQL DELETE that catches every option whose name
 * starts with "kigocloud_" (or "_kigocloud_" for post-meta). Listing
 * options manually leaks - older installs have legacy keys, per-gateway
 * keys are dynamic, and WC may not even be loaded any more by the time
 * we run.
 *
 * @package Woo_KigoCloud
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!current_user_can('activate_plugins')) {
    return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off cleanup of this plugin's own rows on uninstall.

// All options.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'kigocloud_%'"
);

// Site-wide options on multisite.
if (is_multisite()) {
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'kigocloud_%'"
    );
}

// Post-meta written on orders.
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_kigocloud_%' OR meta_key LIKE 'kigocloud_vat_invoices_%'"
);

// HPOS order meta (WC 8.0+ orders_meta table).
$kigocloud_orders_meta = $wpdb->prefix . 'wc_orders_meta';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $kigocloud_orders_meta)) === $kigocloud_orders_meta) {
    $wpdb->query(
        "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_kigocloud_%' OR meta_key LIKE 'kigocloud_vat_invoices_%'"
    );
}
