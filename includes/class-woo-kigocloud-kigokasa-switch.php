<?php
/**
 * Takes over from the older KigoKasa API for WooCommerce plugin.
 *
 * Both plugins send every order to the same account, so with both sending
 * each order would be fiscalized twice. The switch is the shop's decision:
 *
 * - On activation KigoCloud copies the KigoKasa settings (connection,
 *   per-gateway document types, R1 mode, mail, mapping) into its own
 *   options, but only while KigoCloud has no username set, so a configured
 *   install is never touched. The imported credentials keep the KigoKasa
 *   server as the endpoint.
 * - While the KigoKasa plugin is active, KigoCloud sends no orders
 *   (Woo_KigoCloud_Request checks is_kigokasa_active()) and wp-admin shows
 *   a notice with a button. The button deactivates the KigoKasa plugin (no
 *   delete, its data stays) and KigoCloud takes over from the next request.
 *
 * Orders already sent by KigoKasa keep their `_kigokasa_id_pos` meta, which
 * Woo_KigoCloud_Request treats as "already sent". Once the KigoKasa plugin is
 * deactivated, WP-Cron copies that meta (document id, number, type and the R1
 * fields) into the matching KigoCloud keys in batches, because KigoKasa
 * versions before 1.7.5 delete their order meta when the plugin is deleted.
 *
 * @package Woo_KigoCloud
 */

class Woo_KigoCloud_KigoKasa_Switch
{
    const KIGOKASA_PLUGIN = 'kigokasa-api-for-woocommerce/kigokasa-api-for-woocommerce.php';
    const KIGOKASA_API_URL = 'https://trgovina.kigoserver.com/hr/api/v1/';
    const IMPORTED_OPTION = 'kigocloud_kigokasa_imported';
    const SWITCHED_OPTION = 'kigocloud_kigokasa_switched';
    const ACTION = 'kigocloud_switch_from_kigokasa';
    const META_CRON = 'kigocloud_copy_kigokasa_order_meta';
    const META_STATE = 'kigocloud_kigokasa_meta_copy';
    const META_BATCH = 100;
    const META_NOTICE = 'kigocloud_kigokasa_meta_copied';

    /**
     * KigoKasa order meta => KigoCloud order meta.
     */
    private static $order_meta = array(
        '_kigokasa_id_pos'                          => '_kigocloud_id_pos',
        '_kigokasa_pos_number'                      => '_kigocloud_pos_number',
        '_kigokasa_doc_type'                        => '_kigocloud_doc_type',
        'woo_kigokasa_api_vat_invoices_checkbox'    => 'kigocloud_vat_invoices_checkbox',
        'woo_kigokasa_api_vat_invoices_company'     => 'kigocloud_vat_invoices_company',
        'woo_kigokasa_api_vat_invoices_address'     => 'kigocloud_vat_invoices_address',
        'woo_kigokasa_api_vat_invoices_city'        => 'kigocloud_vat_invoices_city',
        'woo_kigokasa_api_vat_invoices_zip'         => 'kigocloud_vat_invoices_zip',
        'woo_kigokasa_api_vat_invoices_vat_number'  => 'kigocloud_vat_invoices_vat_number',
    );

    /**
     * Orders are found by these keys, one pass each: orders with a KigoKasa
     * document, then orders with KigoKasa R1 details that were never sent.
     */
    private static $meta_passes = array('_kigokasa_id_pos', 'woo_kigokasa_api_vat_invoices_company');

    /**
     * KigoKasa option => KigoCloud option.
     */
    private static $global_options = array(
        'woo_kigokasa_api_username'                   => 'kigocloud_username',
        'woo_kigokasa_api_password'                   => 'kigocloud_password',
        'woo_kigokasa_api_pin'                        => 'kigocloud_pin',
        'woo_kigokasa_api_shipping_reference'         => 'kigocloud_shipping_reference',
        'woo_kigokasa_api_email_from_name'            => 'kigocloud_email_from_name',
        'woo_kigokasa_api_email_from'                 => 'kigocloud_email_from',
        'woo_kigokasa_api_reply_to'                   => 'kigocloud_reply_to',
        'woo_kigokasa_api_fill_empty_sku'             => 'kigocloud_fill_empty_sku',
        'woo_kigokasa_api_skip_status_order_created'  => 'kigocloud_skip_status_order_created',
        'woo_kigokasa_api_vat_invoices'               => 'kigocloud_vat_invoices',
        'woo_kigokasa_api_show_anchor_price'          => 'kigocloud_show_anchor_price',
        'woo_kigokasa_api_custom_mapping'             => 'kigocloud_custom_mapping',
    );

    /**
     * Per-gateway option prefixes, suffixed with "-<gateway id>".
     */
    private static $gateway_options = array(
        'woo_kigokasa_api_pos_type'         => 'kigocloud_pos_type',
        'woo_kigokasa_api_payment_type'     => 'kigocloud_payment_type',
        'woo_kigokasa_api_on_status'        => 'kigocloud_on_status',
        'woo_kigokasa_api_pdf_payment_type' => 'kigocloud_pdf_payment_type',
    );

    /**
     * One lookup in the autoloaded active_plugins option.
     *
     * @return bool
     */
    public static function is_kigokasa_active()
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active(self::KIGOKASA_PLUGIN);
    }

    /**
     * Called from the activator, and on admin_init for installs that were
     * already active when this version arrived through the auto-updater.
     *
     * @return bool true when settings were copied
     */
    public static function import_settings()
    {
        if ((string) get_option('kigocloud_username', '') !== '') {
            return false;
        }
        if ((string) get_option('woo_kigokasa_api_username', '') === '') {
            return false;
        }

        foreach (self::$global_options as $from => $to) {
            $value = get_option($from, null);
            if ($value !== null) {
                update_option($to, $value);
            }
        }

        if (class_exists('WC_Payment_Gateways')) {
            foreach (WC_Payment_Gateways::instance()->payment_gateways() as $gateway) {
                $id = esc_attr($gateway->id);
                foreach (self::$gateway_options as $from => $to) {
                    $value = get_option($from . '-' . $id, null);
                    if ($value !== null) {
                        update_option($to . '-' . $id, $value);
                    }
                }
            }
        }

        // The imported credentials belong to the KigoKasa server. A shop
        // that already had an endpoint set keeps it.
        if ((string) get_option('kigocloud_api_url', '') === '' && !defined('WOO_KIGOCLOUD_API_URL')) {
            update_option('kigocloud_api_url', self::KIGOKASA_API_URL);
        }

        update_option(self::IMPORTED_OPTION, 1, false);
        return true;
    }

    /**
     * Hooked on `admin_init`.
     */
    public static function maybe_import()
    {
        if (self::is_kigokasa_active()) {
            self::import_settings();
        }
    }

    /**
     * Hooked on `admin_init` (with maybe_import). Installs that never ran the
     * copy, and whose KigoKasa plugin is not active, run it once; a copy that
     * lost its cron event is rescheduled.
     */
    public static function maybe_copy_order_meta()
    {
        $state = get_option(self::META_STATE, null);
        if ($state === 'done') {
            return;
        }
        if (self::is_kigokasa_active()) {
            return;
        }
        if (!is_array($state)) {
            self::start_order_meta_copy();
        } elseif (!wp_next_scheduled(self::META_CRON)) {
            wp_schedule_single_event(time(), self::META_CRON);
        }
    }

    /**
     * Hooked on `deactivated_plugin`: covers the switch button and a manual
     * deactivation of the KigoKasa plugin alike.
     *
     * @param string $plugin
     */
    public static function on_plugin_deactivated($plugin)
    {
        if ($plugin === self::KIGOKASA_PLUGIN) {
            self::start_order_meta_copy();
        }
    }

    private static function start_order_meta_copy()
    {
        update_option(self::META_STATE, array('pass' => 0, 'page' => 1), false);
        if (!wp_next_scheduled(self::META_CRON)) {
            wp_schedule_single_event(time(), self::META_CRON);
        }
    }

    /**
     * WP-Cron handler. Copies batches for up to 20 seconds, then schedules
     * itself again if orders are left.
     */
    public static function run_order_meta_copy()
    {
        $state = get_option(self::META_STATE, null);
        if (!is_array($state) || !function_exists('wc_get_orders')) {
            return;
        }
        $started = time();
        while (time() - $started < 20) {
            if (!isset(self::$meta_passes[$state['pass']])) {
                update_option(self::META_STATE, 'done', false);
                // Tell the shop the old plugin may go, if it is still installed.
                if (file_exists(WP_PLUGIN_DIR . '/' . self::KIGOKASA_PLUGIN)) {
                    update_option(self::META_NOTICE, 1, false);
                }
                return;
            }
            $orders = wc_get_orders(array(
                'type'         => 'shop_order',
                'status'       => array_keys(wc_get_order_statuses()),
                'meta_key'     => self::$meta_passes[$state['pass']],
                'meta_compare' => 'EXISTS',
                'orderby'      => 'ID',
                'order'        => 'ASC',
                'limit'        => self::META_BATCH,
                'paged'        => $state['page'],
            ));
            foreach ($orders as $order) {
                self::copy_order_meta($order);
            }
            if (count($orders) < self::META_BATCH) {
                $state = array('pass' => $state['pass'] + 1, 'page' => 1);
            } else {
                $state['page']++;
            }
            update_option(self::META_STATE, $state, false);
        }
        wp_schedule_single_event(time() + 60, self::META_CRON);
    }

    /**
     * Fills only KigoCloud keys that are still empty.
     *
     * @param WC_Order $order
     */
    private static function copy_order_meta($order)
    {
        $changed = false;
        foreach (self::$order_meta as $from => $to) {
            $value = $order->get_meta($from, true);
            if ($value === '' || $value === null || $value === false) {
                continue;
            }
            $current = $order->get_meta($to, true);
            if ($current !== '' && $current !== null && $current !== false) {
                continue;
            }
            $order->update_meta_data($to, $value);
            $changed = true;
        }
        if ($changed) {
            $order->save_meta_data();
        }
    }

    /**
     * Hooked on `admin_post_kigocloud_switch_from_kigokasa`.
     */
    public static function handle_switch()
    {
        if (!current_user_can('activate_plugins')) {
            wp_die(esc_html__('You do not have permission to change plugins.', 'kigocloud-for-woocommerce'));
        }
        check_admin_referer(self::ACTION);

        if (!function_exists('deactivate_plugins')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $network = is_multisite() && is_plugin_active_for_network(self::KIGOKASA_PLUGIN);
        deactivate_plugins(self::KIGOKASA_PLUGIN, false, $network);
        update_option(self::SWITCHED_OPTION, 1, false);

        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url('admin.php?page=kigocloud'));
        exit;
    }

    /**
     * Hooked on `admin_notices`.
     */
    public static function admin_notice()
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if (get_option(self::SWITCHED_OPTION)) {
            delete_option(self::SWITCHED_OPTION);
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('The KigoKasa API for WooCommerce plugin is deactivated. Orders are now sent by KigoCloud for WooCommerce.', 'kigocloud-for-woocommerce')
                . '</p></div>';
            return;
        }

        if (get_option(self::META_NOTICE)) {
            delete_option(self::META_NOTICE);
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('KigoCloud for WooCommerce now keeps its own record of the documents the KigoKasa plugin issued for your orders, so they are never sent again. The KigoKasa API for WooCommerce plugin can be deleted.', 'kigocloud-for-woocommerce')
                . '</p></div>';
        }

        if (!self::is_kigokasa_active()) {
            return;
        }

        $url = wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION);

        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__('KigoCloud for WooCommerce is waiting to take over.', 'kigocloud-for-woocommerce')
            . '</strong></p><p>';
        if (get_option(self::IMPORTED_OPTION)) {
            echo esc_html__('The settings were copied from the KigoKasa API for WooCommerce plugin.', 'kigocloud-for-woocommerce') . ' ';
        }
        echo esc_html__('Until you switch, orders are still sent by the KigoKasa plugin, so no order is sent twice. When you click Switch to KigoCloud, the KigoKasa plugin is deactivated (its data stays) and KigoCloud sends the orders from then on.', 'kigocloud-for-woocommerce')
            . '</p><p><a href="' . esc_url($url) . '" class="button button-primary">'
            . esc_html__('Switch to KigoCloud', 'kigocloud-for-woocommerce')
            . '</a> <a href="' . esc_url(admin_url('admin.php?page=kigocloud')) . '" class="button">'
            . esc_html__('Review settings first', 'kigocloud-for-woocommerce')
            . '</a></p></div>';
    }
}
