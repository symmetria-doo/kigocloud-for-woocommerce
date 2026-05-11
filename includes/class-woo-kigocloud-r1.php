<?php
/**
 * R1 customer fields module.
 *
 * Handles both checkout flavours:
 * - Classic checkout (shortcode):  uses legacy WooCommerce field hooks.
 * - Block checkout (Gutenberg):    uses the Additional Checkout Fields API
 *                                  introduced in WooCommerce 8.6.
 *
 * Both paths persist data to the same legacy post-meta keys
 * (kigocloud_vat_invoices_*) so Woo_KigoCloud_Request can build the
 * KigoCloud API payload without caring which checkout type was used.
 *
 * @package Woo_KigoCloud
 * @since   2.0.0
 */

class Woo_KigoCloud_R1
{
    const FIELD_R1_TOGGLE     = 'kigocloud/r1_invoice';
    const FIELD_R1_VAT_NUMBER = 'kigocloud/r1_vat_number';
    const FIELD_R1_COMPANY    = 'kigocloud/r1_company';
    const FIELD_R1_ADDRESS    = 'kigocloud/r1_address';
    const FIELD_R1_CITY       = 'kigocloud/r1_city';
    const FIELD_R1_ZIP        = 'kigocloud/r1_zip';

    /**
     * Returns the active R1 mode:
     *   0 = off
     *   1 = OIB / VAT field only
     *   2 = full R1 block (company, address, city, postcode, OIB)
     *
     * @return int
     */
    public static function mode()
    {
        return (int) get_option('kigocloud_vat_invoices', 0);
    }

    /**
     * Whether the block checkout is supported by the current WC version.
     */
    public static function block_supported()
    {
        return function_exists('woocommerce_register_additional_checkout_field')
            && defined('WC_VERSION')
            && version_compare(WC_VERSION, '8.6.0', '>=');
    }

    /**
     * Whether the current checkout page is actually rendered with the
     * Gutenberg checkout block. Returns false if WC or the page is missing.
     */
    public static function checkout_uses_block()
    {
        if (!function_exists('has_block') || !function_exists('wc_get_page_id')) {
            return false;
        }
        $page_id = wc_get_page_id('checkout');
        if (!$page_id) {
            return false;
        }
        return has_block('woocommerce/checkout', get_post_field('post_content', $page_id));
    }

    // -----------------------------------------------------------------
    // Block checkout (WC 8.6+)
    // -----------------------------------------------------------------

    /**
     * Registers extra checkout fields with the Additional Checkout Fields
     * API. Must run on `woocommerce_init` so that the API is loaded.
     */
    public function register_block_fields()
    {
        if (!self::block_supported()) {
            return;
        }
        $mode = self::mode();
        if ($mode === 0) {
            return;
        }

        // OIB / VAT number is needed for both R1 modes. For mode 2
        // (full R1 block) it is required; for mode 1 (OIB only) it is
        // optional so B2C customers can still check out without an OIB.
        woocommerce_register_additional_checkout_field(array(
            'id'                => self::FIELD_R1_VAT_NUMBER,
            'label'             => __('OIB / VAT number', 'kigocloud-for-woocommerce'),
            'location'          => 'address',
            'type'              => 'text',
            'required'          => ($mode === 2),
            'sanitize_callback' => static function ($value) {
                return preg_replace('/[^0-9]/', '', (string) $value);
            },
            'validate_callback' => static function ($value) {
                $value = trim((string) $value);
                if ($value === '') {
                    return;
                }
                if (!Woo_KigoCloud_R1::is_valid_oib($value)) {
                    return new \WP_Error(
                        'kigocloud_invalid_oib',
                        __('OIB must be 11 digits and pass the checksum.', 'kigocloud-for-woocommerce')
                    );
                }
            },
        ));

        if ($mode !== 2) {
            return;
        }

        woocommerce_register_additional_checkout_field(array(
            'id'       => self::FIELD_R1_COMPANY,
            'label'    => __('Company name (for invoice)', 'kigocloud-for-woocommerce'),
            'location' => 'address',
            'type'     => 'text',
            'required' => true,
        ));
    }

    /**
     * Marks the standard WooCommerce billing.company field as required.
     * Hook: woocommerce_default_address_fields (covers block checkout
     * and Customer Account address forms).
     */
    public function force_company_required($fields)
    {
        if (isset($fields['company'])) {
            $fields['company']['required'] = true;
        }
        return $fields;
    }

    /**
     * Same as above but on the classic checkout filter (which uses
     * billing_* prefixed keys instead of the default address fields).
     */
    public function force_classic_billing_company_required($fields)
    {
        if (isset($fields['billing_company'])) {
            $fields['billing_company']['required'] = true;
        }
        return $fields;
    }


    /**
     * When the Store API creates the order, copy the additional checkout
     * field values into the legacy kigocloud_vat_invoices_* post-meta
     * keys so the existing API request payload still works unchanged.
     *
     * @param WC_Order            $order
     * @param WP_REST_Request|null $request (unused on this filter signature)
     */
    public function sync_block_meta_to_legacy($order, $request = null)
    {
        if (!self::block_supported() || !$order instanceof WC_Order) {
            return;
        }

        $vat = self::get_additional_field_value($order, self::FIELD_R1_VAT_NUMBER);
        if ($vat !== '') {
            $order->update_meta_data('kigocloud_vat_invoices_checkbox', 1);
            $order->update_meta_data('kigocloud_vat_invoices_vat_number', sanitize_text_field($vat));
            // also mirror onto _billing_vat_number for compatibility with
            // any external code that reads the canonical billing VAT meta.
            $order->update_meta_data('_billing_vat_number', sanitize_text_field($vat));
        }

        if (self::mode() === 2) {
            $company = self::get_additional_field_value($order, self::FIELD_R1_COMPANY);
            if ($company !== '') {
                $order->update_meta_data('kigocloud_vat_invoices_company', sanitize_text_field($company));
            }
            // Address/city/postcode are read directly from billing.* in the
            // request builder, so no mirroring is needed there.
        }

        $order->save_meta_data();
    }

    /**
     * Returns the value of an Additional Checkout Field on the order.
     * Tries the official getter, falls back to direct meta lookup.
     */
    private static function get_additional_field_value($order, $field_id)
    {
        if (method_exists($order, 'get_meta')) {
            // WC stores these under the namespaced meta key _wc_other/{id}.
            $value = $order->get_meta('_wc_other/' . $field_id, true);
            if ($value !== '' && $value !== null) {
                return (string) $value;
            }
        }
        return '';
    }

    // -----------------------------------------------------------------
    // Classic checkout
    // -----------------------------------------------------------------

    /**
     * Mode 1: adds a single VAT / OIB field on both billing and shipping
     * sections of the classic checkout. Hook: woocommerce_checkout_fields.
     */
    public function classic_add_vat_field($fields)
    {
        $vat = array(
            'label'       => __('OIB / VAT number', 'kigocloud-for-woocommerce'),
            'placeholder' => _x('12345678901', 'placeholder', 'kigocloud-for-woocommerce'),
            'required'    => false,
            'class'       => array('form-row-wide'),
            'clear'       => true,
        );
        $fields['billing']['billing_vat_number']   = $vat;
        $fields['shipping']['shipping_vat_number'] = $vat;
        return $fields;
    }

    /**
     * Mode 2 (a): renders the full R1 widget below billing form.
     * Hook: woocommerce_after_checkout_billing_form
     */
    public function classic_render_full($checkout)
    {
        echo '<div id="kigocloud_vat_invoices_form" class="kigocloud-r1-form"><strong>'
            . esc_html__('R1 invoice', 'kigocloud-for-woocommerce')
            . '</strong><p>'
            . esc_html__('Do you need an R1 invoice (B2B)?', 'kigocloud-for-woocommerce')
            . '</p>';

        woocommerce_form_field('kigocloud_vat_invoices_checkbox', array(
            'type'  => 'checkbox',
            'class' => array('form-row-wide'),
            'label' => __('Yes', 'kigocloud-for-woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_checkbox'));

        echo '<div id="kigocloud_vat_invoices_fields">';
        woocommerce_form_field('kigocloud_vat_invoices_company', array(
            'type'        => 'text',
            'class'       => array('form-row-wide'),
            'label'       => __('Company name', 'kigocloud-for-woocommerce'),
            'required'    => true,
            'placeholder' => _x('Enter company name', 'placeholder', 'kigocloud-for-woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_company'));

        woocommerce_form_field('kigocloud_vat_invoices_address', array(
            'type'        => 'text',
            'class'       => array('form-row-wide'),
            'label'       => __('Company address', 'kigocloud-for-woocommerce'),
            'required'    => true,
            'placeholder' => _x('Street and number', 'placeholder', 'kigocloud-for-woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_address'));

        woocommerce_form_field('kigocloud_vat_invoices_city', array(
            'type'        => 'text',
            'class'       => array('form-row-wide'),
            'label'       => __('City', 'kigocloud-for-woocommerce'),
            'required'    => true,
        ), $checkout->get_value('kigocloud_vat_invoices_city'));

        woocommerce_form_field('kigocloud_vat_invoices_zip', array(
            'type'        => 'text',
            'class'       => array('form-row-wide'),
            'label'       => __('Postcode', 'kigocloud-for-woocommerce'),
            'required'    => true,
        ), $checkout->get_value('kigocloud_vat_invoices_zip'));

        woocommerce_form_field('kigocloud_vat_invoices_vat_number', array(
            'type'        => 'text',
            'class'       => array('form-row-wide'),
            'label'       => __('OIB / VAT number', 'kigocloud-for-woocommerce'),
            'required'    => true,
            'placeholder' => _x('12345678901', 'placeholder', 'kigocloud-for-woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_vat_number'));
        echo '</div></div>';
    }

    /**
     * Mode 2 (b): validates the classic checkout R1 fields on POST.
     */
    public function classic_validate_full()
    {
        if (empty($_POST['kigocloud_vat_invoices_checkbox'])) {
            return;
        }
        if (empty($_POST['kigocloud_vat_invoices_company'])) {
            wc_add_notice(__('Company name is mandatory for R1 invoices.', 'kigocloud-for-woocommerce'), 'error');
        }
        if (empty($_POST['kigocloud_vat_invoices_address'])) {
            wc_add_notice(__('Company address is mandatory for R1 invoices.', 'kigocloud-for-woocommerce'), 'error');
        }
        if (empty($_POST['kigocloud_vat_invoices_city'])) {
            wc_add_notice(__('Company city is mandatory for R1 invoices.', 'kigocloud-for-woocommerce'), 'error');
        }
        if (empty($_POST['kigocloud_vat_invoices_zip'])) {
            wc_add_notice(__('Company postcode is mandatory for R1 invoices.', 'kigocloud-for-woocommerce'), 'error');
        }
        $vat = isset($_POST['kigocloud_vat_invoices_vat_number']) ? (string) $_POST['kigocloud_vat_invoices_vat_number'] : '';
        if (!self::is_valid_oib($vat)) {
            wc_add_notice(__('Invalid OIB. Must be 11 digits and pass the checksum.', 'kigocloud-for-woocommerce'), 'error');
        }
    }

    /**
     * Mode 2 (c): saves the classic checkout R1 fields to order meta.
     */
    public function classic_save_full($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $keys = array(
            'kigocloud_vat_invoices_checkbox',
            'kigocloud_vat_invoices_company',
            'kigocloud_vat_invoices_address',
            'kigocloud_vat_invoices_city',
            'kigocloud_vat_invoices_zip',
            'kigocloud_vat_invoices_vat_number',
        );
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $order->update_meta_data($key, sanitize_text_field((string) $_POST[$key]));
            }
        }
        $order->save();
    }

    /**
     * Mode 2 (d): hides the default billing.company field on classic
     * checkout since the R1 widget collects company info separately.
     */
    public function classic_override_fields($fields)
    {
        unset($fields['billing']['billing_company']);
        return $fields;
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * Croatian OIB checksum (ISO 7064 MOD 11,10).
     *
     * @param string $oib
     * @return bool
     */
    public static function is_valid_oib($oib)
    {
        $oib = trim((string) $oib);
        if (strlen($oib) !== 11 || !ctype_digit($oib)) {
            return false;
        }
        $a = 10;
        for ($i = 0; $i < 10; $i++) {
            $a = ($a + (int) $oib[$i]) % 10;
            if ($a === 0) {
                $a = 10;
            }
            $a = (2 * $a) % 11;
        }
        $check = 11 - $a;
        if ($check === 10) {
            $check = 0;
        }
        return $check === (int) $oib[10];
    }
}
