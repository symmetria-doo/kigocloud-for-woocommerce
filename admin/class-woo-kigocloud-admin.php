<?php
/**
 * Admin-side glue: WooCommerce settings tab, plugin action link, order
 * meta display, mail-from override.
 *
 * @link    https://github.com/symmetria-doo/kigocloud-for-woocommerce
 * @since   1.0.0
 * @package Woo_KigoCloud
 */

class Woo_KigoCloud_Admin
{
    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    public function enqueue_styles()
    {
        // Reserved for future admin stylesheet.
    }

    public function enqueue_scripts()
    {
        // Reserved for future admin script.
    }

    /**
     * Settings link in the Plugins list row.
     *
     * @param array $links
     * @return array
     */
    public function plugin_action_links($links)
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=kigocloud')),
            esc_html__('Settings', 'kigocloud-for-woocommerce')
        );
        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Extra metadata link in the Plugins list row (GitHub).
     *
     * @param array  $plugin_meta
     * @param string $plugin_file
     * @return array
     */
    public function plugin_row_meta($plugin_meta, $plugin_file)
    {
        if (defined('WOO_KIGOCLOUD_PLUGIN_FILE') && $plugin_file === plugin_basename(WOO_KIGOCLOUD_PLUGIN_FILE)) {
            $plugin_meta[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener">%s</a>',
                'https://github.com/symmetria-doo/kigocloud-for-woocommerce',
                esc_html__('GitHub', 'kigocloud-for-woocommerce')
            );
        }
        return $plugin_meta;
    }

    public function change_wp_email_from($email)
    {
        $override = esc_attr(get_option('kigocloud_email_from'));
        return $override !== '' ? $override : $email;
    }

    public function change_wp_email_from_name($fromName)
    {
        $override = esc_attr(get_option('kigocloud_email_from_name'));
        return $override !== '' ? $override : $fromName;
    }


    /**
     * @param WC_Order $order
     */
    public function display_admin_order_meta($order)
    {
        // HPOS-safe meta reads. get_post_meta() reads only from wp_postmeta
        // and silently returns empty strings on HPOS-only installations.
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }

        $shipping_vat = $order->get_meta('_shipping_vat_number', true);
        $billing_vat  = $order->get_meta('_billing_vat_number', true);

        if (!empty($shipping_vat)) {
            echo '<p><strong>' . esc_html__('Shipping VAT number', 'kigocloud-for-woocommerce') . '</strong><br />' . esc_html($shipping_vat) . '</p>';
        }
        if (!empty($billing_vat)) {
            echo '<p><strong>' . esc_html__('Billing VAT number', 'kigocloud-for-woocommerce') . '</strong><br />' . esc_html($billing_vat) . '</p>';
        }

        $isVATInvoice = $order->get_meta('kigocloud_vat_invoices_checkbox', true);
        if (!empty($isVATInvoice)) {
            echo '<p><strong>' . esc_html__('R1 invoice', 'kigocloud-for-woocommerce') . ':</strong> ' . esc_html__('Yes', 'kigocloud-for-woocommerce') . '</p>';
        }

        $rows = array(
            'kigocloud_vat_invoices_company'    => __('Company', 'kigocloud-for-woocommerce'),
            'kigocloud_vat_invoices_address'    => __('Company address', 'kigocloud-for-woocommerce'),
            'kigocloud_vat_invoices_city'       => __('Company city', 'kigocloud-for-woocommerce'),
            'kigocloud_vat_invoices_zip'        => __('Company postcode', 'kigocloud-for-woocommerce'),
            'kigocloud_vat_invoices_vat_number' => __('Company VAT', 'kigocloud-for-woocommerce'),
        );
        foreach ($rows as $key => $label) {
            $val = $order->get_meta($key, true);
            if (!empty($val)) {
                echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html($val) . '</p>';
            }
        }
    }

    /**
     * @param WC_Order $order
     */
    public function display_admin_order_kigocloud($order)
    {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }
        $pos_number    = $order->get_meta('_kigocloud_pos_number', true);
        $document_type = $order->get_meta('_kigocloud_doc_type', true);

        if (!empty($pos_number)) {
            echo '<p class="form-field form-field-wide wc-order-pos-number"><strong>'
                . esc_html__('KigoCloud document', 'kigocloud-for-woocommerce')
                . ':</strong> '
                . (!empty($document_type) ? esc_html($document_type) . ' ' : '')
                . esc_html($pos_number)
                . '</p>';
        }
    }

    public function show_admin_notice()
    {
        $old_version = get_option('kigocloud_version', '1.0.0');
        $new_version = Woo_KigoCloud::PLUGIN_VERSION;
        $this->show_migration_admin_notice($old_version, $new_version);
    }

    public function show_migration_admin_notice($old_version, $new_version)
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings_saved = get_option('kigocloud_show_migration_notice', 0);
        if ($settings_saved != 1) {
            return;
        }

        echo '<div class="notice notice-warning">';
        echo '<p><strong>' . esc_html__('KigoCloud for WooCommerce has been updated to version', 'kigocloud-for-woocommerce') . ' ' . esc_html($new_version) . '.</strong></p>';
        echo '<p>' . esc_html__('Please review and save your settings to ensure everything works correctly.', 'kigocloud-for-woocommerce') . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=kigocloud')) . '" class="button button-primary">' . esc_html__('Review and save settings', 'kigocloud-for-woocommerce') . '</a></p>';
        echo '</div>';
    }

    /**
     * Notice shown only if the legacy classic-checkout R1 mode is selected
     * but the site uses the block checkout. The block branch handles R1 via
     * Woo_KigoCloud_Block_Checkout (WC 8.6+); for WC < 8.6 we warn the user.
     */
    public function checkout_block_admin_notice()
    {
        if (!is_admin()) {
            return;
        }
        $enableVatInvoice = (int) get_option('kigocloud_vat_invoices', 0);
        if ($enableVatInvoice === 0) {
            return;
        }
        if (!function_exists('has_block') || !function_exists('wc_get_page_id')) {
            return;
        }

        $checkout_page_id = wc_get_page_id('checkout');
        if (!$checkout_page_id) {
            return;
        }
        $content = get_post_field('post_content', $checkout_page_id);

        if (!has_block('woocommerce/checkout', $content)) {
            return;
        }

        // Block checkout is in use. Check if WC version supports Additional Checkout Fields API.
        $wc_supports_block_r1 = defined('WC_VERSION') && version_compare(WC_VERSION, '8.6.0', '>=');
        if ($wc_supports_block_r1) {
            return; // Block branch handles it.
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>'
            . esc_html__('KigoCloud R1 fields require WooCommerce 8.6 or newer for the block checkout.', 'kigocloud-for-woocommerce')
            . '</strong></p><p>'
            . esc_html__('You are using the block checkout on an older WooCommerce version that does not support custom checkout fields. Either upgrade WooCommerce or switch the checkout page back to the [woocommerce_checkout] shortcode.', 'kigocloud-for-woocommerce')
            . '</p></div>';
    }
}
