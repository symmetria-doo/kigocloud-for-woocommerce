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
    const FIELD_R1_TOGGLE     = 'kigocloud/r1-invoice';
    const FIELD_R1_VAT_NUMBER = 'kigocloud/r1-vat-number';
    const FIELD_R1_COMPANY    = 'kigocloud/r1-company';
    const FIELD_R1_ADDRESS    = 'kigocloud/r1-address';
    const FIELD_R1_CITY       = 'kigocloud/r1-city';
    const FIELD_R1_ZIP        = 'kigocloud/r1-zip';

    /**
     * Flips to true the moment register_block_fields() actually
     * gets called this request. Read by the diagnostics panel on
     * the R1 admin tab so we can tell whether the woocommerce_init
     * hook even fired for the plugin.
     *
     * @var bool
     */
    public static $register_attempted = false;

    /**
     * Holds the last exception / WP_Error / message from a failed
     * registration attempt for the diagnostics panel.
     *
     * @var string
     */
    public static $register_status = '';

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
     *
     * Kept deliberately minimal (id + label + location + required + type)
     * so we hit exactly the documented WC contract. Validation moves
     * to a separate filter so a sanitize_callback / validate_callback
     * type mismatch can't silently kill the registration.
     */
    public function register_block_fields()
    {
        self::$register_attempted = true;

        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            self::$register_status = 'function-missing';
            return;
        }

        $mode = self::mode();
        if ($mode === 0) {
            self::$register_status = 'mode-off';
            return;
        }

        try {
            woocommerce_register_additional_checkout_field(array(
                'id'       => self::FIELD_R1_VAT_NUMBER,
                'label'    => __('OIB / VAT number', 'kigocloud-for-woocommerce'),
                'location' => 'address',
                'type'     => 'text',
                'required' => ($mode === 2),
            ));

            if ($mode === 2) {
                woocommerce_register_additional_checkout_field(array(
                    'id'       => self::FIELD_R1_COMPANY,
                    'label'    => __('Company name (for invoice)', 'kigocloud-for-woocommerce'),
                    'location' => 'address',
                    'type'     => 'text',
                    'required' => true,
                ));
            }

            self::$register_status = 'ok';
        } catch (\Throwable $e) {
            self::$register_status = 'exception: ' . $e->getMessage();
        }
    }

    /**
     * Sanitizes the OIB additional checkout field (block) before WC
     * persists it on the order. Hook: woocommerce_sanitize_additional_field.
     *
     * @param mixed  $value
     * @param string $key   namespaced field id, e.g. "kigocloud/r1-vat-number"
     * @return mixed
     */
    public function sanitize_block_additional_field($value, $key)
    {
        if ($key === self::FIELD_R1_VAT_NUMBER) {
            return preg_replace('/[^0-9]/', '', (string) $value);
        }
        return $value;
    }

    /**
     * Validates the OIB additional checkout field (block) on submit.
     * Hook: woocommerce_validate_additional_field.
     *
     * @param \WP_Error|null $errors
     * @param string         $key
     * @param mixed          $value
     * @return \WP_Error|null
     */
    public function validate_block_additional_field($errors, $key, $value)
    {
        if ($key !== self::FIELD_R1_VAT_NUMBER) {
            return $errors;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return $errors;
        }
        if (!self::is_valid_oib($value)) {
            if (!($errors instanceof \WP_Error)) {
                $errors = new \WP_Error();
            }
            $errors->add(
                'kigocloud_invalid_oib',
                __('OIB must be 11 digits and pass the checksum.', 'kigocloud-for-woocommerce')
            );
        }
        return $errors;
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
     *
     * WooCommerce stores additional fields under three different meta
     * prefixes depending on the field's `location`:
     *   - 'address' (billing+shipping) -> _wc_billing/<id> + _wc_shipping/<id>
     *   - 'contact'                    -> _wc_other/<id>
     *   - 'order'                      -> _wc_other/<id>
     * Our R1 fields use 'address' location, so billing is the canonical
     * source; we keep shipping and other as fallbacks for robustness.
     *
     * @param WC_Order $order
     * @param string   $field_id  namespaced id, e.g. "kigocloud/r1-vat-number"
     * @return string             value or '' if not set
     */
    private static function get_additional_field_value($order, $field_id)
    {
        if (!method_exists($order, 'get_meta')) {
            return '';
        }
        $prefixes = array('_wc_billing/', '_wc_shipping/', '_wc_other/');
        foreach ($prefixes as $prefix) {
            $value = $order->get_meta($prefix . $field_id, true);
            if ($value !== '' && $value !== null && $value !== false) {
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
