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

    public static function add_woocommerce_settings_tab($settings_tabs)
    {
        $settings_tabs['kigocloud'] = __('KigoCloud', 'kigocloud-for-woocommerce');
        return $settings_tabs;
    }

    public static function update_woocommerce_settings()
    {
        woocommerce_update_options(self::get_woocommerce_settings_options());
        update_option('kigocloud_show_migration_notice', 0);
    }

    public static function add_woocommerce_settings()
    {
        update_option('kigocloud_show_migration_notice', 0);
        woocommerce_admin_fields(self::get_woocommerce_settings_options());
    }

    public static function get_woocommerce_settings_options()
    {
        // Translatable token labels for document types.
        __('pos_type_0', 'kigocloud-for-woocommerce');
        __('pos_type_1', 'kigocloud-for-woocommerce');
        __('pos_type_2', 'kigocloud-for-woocommerce');

        $form_fields = self::connection_fields();
        $form_fields += self::payment_gateway_fields();
        $form_fields += self::misc_fields();
        $form_fields += self::email_fields();
        $form_fields += self::vat_invoice_fields();
        $form_fields += self::custom_mapping_fields();

        return apply_filters('kigocloud_settings_fields', $form_fields);
    }

    private static function connection_fields()
    {
        return array(
            'connection_title' => array(
                'name' => __('Connection', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('KigoCloud API credentials and endpoint.', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_api_url' => array(
                'title'       => __('API endpoint', 'kigocloud-for-woocommerce'),
                'name'        => 'api_url',
                'type'        => 'text',
                'id'          => 'kigocloud_api_url',
                'description' => __('Leave empty to use the default https://app.kigo.cloud/hr/api/v1/', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'placeholder' => 'https://app.kigo.cloud/hr/api/v1/',
                'default'     => '',
                'css'         => 'width:380px;',
            ),
            'kigocloud_username' => array(
                'title'       => __('API username', 'kigocloud-for-woocommerce'),
                'name'        => 'username',
                'type'        => 'text',
                'id'          => 'kigocloud_username',
                'description' => __('KigoCloud account username.', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => 'admin_demo',
                'css'         => 'width:240px;',
            ),
            'kigocloud_password' => array(
                'title'       => __('API password', 'kigocloud-for-woocommerce'),
                'type'        => 'password',
                'name'        => 'password',
                'id'          => 'kigocloud_password',
                'description' => __('KigoCloud account password.', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => 'admin_demo',
                'css'         => 'width:240px;',
            ),
            'kigocloud_pin' => array(
                'title'       => __('Employee PIN', 'kigocloud-for-woocommerce'),
                'type'        => 'text',
                'name'        => 'pin',
                'id'          => 'kigocloud_pin',
                'description' => __('KigoCloud employee PIN used when creating documents.', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => '1',
                'css'         => 'width:120px;',
            ),
            'connection_section_end' => array('type' => 'sectionend'),
        );
    }

    private static function payment_gateway_fields()
    {
        $paymentFields = array(
            'gateways_title' => array(
                'name' => __('Payment gateways', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('For each enabled gateway, choose the document type, payment method, and the order status that triggers the KigoCloud API call.', 'kigocloud-for-woocommerce'),
            ),
            'gateways_intro_end' => array('type' => 'sectionend'),
        );

        if (!function_exists('WC') || !WC()->payment_gateways) {
            return $paymentFields;
        }

        $all_gateways = WC()->payment_gateways->payment_gateways();
        foreach ($all_gateways as $gateway) {
            if ('yes' !== $gateway->enabled) {
                continue;
            }
            $gid = esc_attr($gateway->id);

            $paymentFields['gw_start_' . $gid] = array(
                'name' => esc_html($gateway->title),
                'type' => 'title',
            );

            $paymentFields['pos_type-' . $gid] = array(
                'title'   => __('Document type', 'kigocloud-for-woocommerce'),
                'type'    => 'select',
                'name'    => 'pos_type-' . $gid,
                'id'      => 'kigocloud_pos_type-' . $gid,
                'options' => array(
                    '0' => __('Disabled', 'kigocloud-for-woocommerce'),
                    '1' => __('Invoice', 'kigocloud-for-woocommerce'),
                    '2' => __('Offer', 'kigocloud-for-woocommerce'),
                ),
                'default' => '0',
                'css'     => 'width:200px;',
            );
            $paymentFields['payment_type-' . $gid] = array(
                'title'   => __('Payment method', 'kigocloud-for-woocommerce'),
                'name'    => 'payment_type-' . $gid,
                'id'      => 'kigocloud_payment_type-' . $gid,
                'type'    => 'select',
                'options' => array(
                    'T' => __('Transaction account', 'kigocloud-for-woocommerce'),
                    'K' => __('Card', 'kigocloud-for-woocommerce'),
                    'G' => __('Cash', 'kigocloud-for-woocommerce'),
                    'C' => __('Cheque', 'kigocloud-for-woocommerce'),
                    'O' => __('Other', 'kigocloud-for-woocommerce'),
                ),
                'default' => 'T',
                'css'     => 'width:240px;',
            );
            $paymentFields['on_status-' . $gid] = array(
                'title'   => __('Trigger on', 'kigocloud-for-woocommerce'),
                'name'    => 'on_status-' . $gid,
                'id'      => 'kigocloud_on_status-' . $gid,
                'type'    => 'select',
                'options' => array(
                    '0' => __('Order created', 'kigocloud-for-woocommerce'),
                    '1' => __('Order completed', 'kigocloud-for-woocommerce'),
                ),
                'default' => '1',
                'css'     => 'width:240px;',
            );
            $paymentFields['pdf_payment_type-' . $gid] = array(
                'title'   => __('Send invoice PDF to customer', 'kigocloud-for-woocommerce'),
                'name'    => 'pdf_payment_type-' . $gid,
                'id'      => 'kigocloud_pdf_payment_type-' . $gid,
                'type'    => 'select',
                'options' => array(
                    '0' => __('No', 'kigocloud-for-woocommerce'),
                    '1' => __('Yes', 'kigocloud-for-woocommerce'),
                ),
                'default' => '0',
                'css'     => 'width:160px;',
            );
            $paymentFields['gw_end_' . $gid] = array('type' => 'sectionend');
        }

        return $paymentFields;
    }

    private static function misc_fields()
    {
        return array(
            'misc_title' => array(
                'name' => __('Order options', 'kigocloud-for-woocommerce'),
                'type' => 'title',
            ),
            'kigocloud_shipping_reference' => array(
                'title'       => __('Shipping reference number', 'kigocloud-for-woocommerce'),
                'type'        => 'text',
                'name'        => 'shipping_reference',
                'id'          => 'kigocloud_shipping_reference',
                'description' => __('KigoCloud item reference used for the shipping line. Leave empty for "shipping".', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'placeholder' => 'shipping',
                'css'         => 'width:240px;',
            ),
            'kigocloud_fill_empty_sku' => array(
                'title'       => __('Fill empty SKU before sending', 'kigocloud-for-woocommerce'),
                'type'        => 'select',
                'options'     => array(
                    0 => __('No', 'kigocloud-for-woocommerce'),
                    1 => __('Yes (use product ID)', 'kigocloud-for-woocommerce'),
                ),
                'name'        => 'fill_empty_sku',
                'id'          => 'kigocloud_fill_empty_sku',
                'description' => __('When enabled, missing SKUs are replaced with sku-<item_id> so the KigoCloud item lookup never fails.', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => 0,
                'css'         => 'width:240px;',
            ),
            'misc_section_end' => array('type' => 'sectionend'),
        );
    }

    private static function email_fields()
    {
        return array(
            'email_title' => array(
                'name' => __('Email', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('Overrides the From / Reply-To headers on outgoing WordPress mail. Global, not just KigoCloud emails.', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_email_from_name' => array(
                'title'       => __('From name', 'kigocloud-for-woocommerce'),
                'name'        => 'from_name',
                'type'        => 'text',
                'id'          => 'kigocloud_email_from_name',
                'desc_tip'    => true,
                'description' => __('Displayed name in the From header.', 'kigocloud-for-woocommerce'),
                'default'     => '',
                'css'         => 'width:240px;',
            ),
            'kigocloud_email_from' => array(
                'title'       => __('From email', 'kigocloud-for-woocommerce'),
                'type'        => 'email',
                'name'        => 'from_email',
                'id'          => 'kigocloud_email_from',
                'desc_tip'    => true,
                'description' => __('Address used in the From header.', 'kigocloud-for-woocommerce'),
                'default'     => '',
                'css'         => 'width:280px;',
            ),
            'kigocloud_reply_to' => array(
                'title'       => __('Reply-To email', 'kigocloud-for-woocommerce'),
                'type'        => 'email',
                'name'        => 'reply_to',
                'id'          => 'kigocloud_reply_to',
                'desc_tip'    => true,
                'description' => __('Address used in the Reply-To header.', 'kigocloud-for-woocommerce'),
                'default'     => '',
                'css'         => 'width:280px;',
            ),
            'email_section_end' => array('type' => 'sectionend'),
        );
    }

    private static function vat_invoice_fields()
    {
        return array(
            'vat_invoices_title' => array(
                'name' => __('R1 customer fields', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('Adds company-related fields to checkout for B2B invoicing: company name, address, city, postcode, OIB (VAT number). Works on both classic and block checkout (WC 8.6+).', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_vat_invoices' => array(
                'title'    => __('Mode', 'kigocloud-for-woocommerce'),
                'name'     => 'vat_invoices',
                'type'     => 'select',
                'id'       => 'kigocloud_vat_invoices',
                'desc_tip' => true,
                'default'  => 0,
                'options'  => array(
                    '0' => __('Off', 'kigocloud-for-woocommerce'),
                    '1' => __('Show OIB / VAT field only', 'kigocloud-for-woocommerce'),
                    '2' => __('Show full R1 block (company, address, city, postcode, OIB)', 'kigocloud-for-woocommerce'),
                ),
                'css'      => 'width:420px;',
            ),
            'vat_invoices_section_end' => array('type' => 'sectionend'),
        );
    }

    private static function custom_mapping_fields()
    {
        return array(
            'custom_mapping_title' => array(
                'name' => __('Custom mapping', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('Override billing data with values from other order meta keys. Format: source_meta:target.field, comma-separated.', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_custom_mapping' => array(
                'title'    => __('Mapping rules', 'kigocloud-for-woocommerce'),
                'name'     => 'custom_mapping',
                'type'     => 'textarea',
                'id'       => 'kigocloud_custom_mapping',
                'desc_tip' => __('Example: r1_oib_tvrtke:_billing_vat_number, r1_ime_tvrtke:billing.company, r1_adresa_tvrtke:billing.address_1', 'kigocloud-for-woocommerce'),
                'default'  => '',
                'css'      => 'width:600px;min-height:120px;font-family:monospace;',
            ),
            'custom_mapping_end' => array('type' => 'sectionend'),
        );
    }

    /**
     * @param WC_Order $order
     */
    public function display_admin_order_meta($order)
    {
        $shipping_vat = get_post_meta($order->get_id(), '_shipping_vat_number', true);
        $billing_vat  = get_post_meta($order->get_id(), '_billing_vat_number', true);

        if (!empty($shipping_vat)) {
            echo '<p><strong>' . esc_html__('Shipping VAT number', 'kigocloud-for-woocommerce') . '</strong><br />' . esc_html($shipping_vat) . '</p>';
        }
        if (!empty($billing_vat)) {
            echo '<p><strong>' . esc_html__('Billing VAT number', 'kigocloud-for-woocommerce') . '</strong><br />' . esc_html($billing_vat) . '</p>';
        }

        $isVATInvoice = get_post_meta($order->get_id(), 'kigocloud_vat_invoices_checkbox', true);
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
            $val = get_post_meta($order->get_id(), $key, true);
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
        $pos_number    = get_post_meta($order->get_id(), '_kigocloud_pos_number', true);
        $document_type = get_post_meta($order->get_id(), '_kigocloud_doc_type', true);

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
