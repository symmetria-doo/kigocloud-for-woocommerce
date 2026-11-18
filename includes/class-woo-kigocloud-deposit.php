<?php
/**
 * Deposit on beverage packaging (povratna naknada) on the product.
 *
 * The deposit is part of the product, as on the item in Kigo.Cloud: the product price includes it, and the
 * product (or its variation) has the field "Povratna naknada" with the amount per piece (0,10 EUR for a
 * bottle, 0,60 EUR for a pack of six). The order sends that amount with the line (pet); Kigo.Cloud puts it
 * on the invoice line as the deposit, charges VAT only on the rest of the price and fiscalizes it as the
 * deposit. A product without the field is sent as before.
 *
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Woo_KigoCloud_Deposit')) {
    class Woo_KigoCloud_Deposit
    {
        /** Product / variation meta: deposit per piece, in the shop currency */
        const META = '_kigocloud_deposit';

        /**
         * Field under the price on the General tab of a simple product.
         */
        public function product_field()
        {
            if (!Woo_KigoCloud_Licence::allows_deposit()) {
                return;
            }
            echo '<div class="options_group show_if_simple show_if_variable">';
            woocommerce_wp_text_input(array(
                'id'          => self::META,
                'value'       => self::display_value(get_post_meta(get_the_ID(), self::META, true)),
                'label'       => __('Deposit (povratna naknada)', 'kigocloud-for-woocommerce') . ' (' . get_woocommerce_currency_symbol() . ')',
                // plain text, not data_type price: the price script of WooCommerce drops a comma in a shop
                // with the decimal point (0,10 became 010); parse_amount() takes both
                'class'       => 'short',
                'desc_tip'    => true,
                'description' => __('Deposit on the packaging per piece, for example 0,10 for a bottle or 0,60 for a pack of six. The price includes it. Kigo.Cloud shows it on the invoice as the deposit, without VAT. Leave empty for a product without a deposit.', 'kigocloud-for-woocommerce'),
            ));
            echo '</div>';
        }

        /**
         * @param WC_Product $product
         */
        public function save_product($product)
        {
            if (!isset($_POST[self::META])) {
                return;
            }
            self::store($product, wp_unslash($_POST[self::META]));
        }

        /**
         * Field under the price of a variation. Empty takes the deposit of the parent product.
         *
         * @param int     $loop
         * @param array   $variationData
         * @param WP_Post $variation
         */
        public function variation_field($loop, $variationData, $variation)
        {
            if (!Woo_KigoCloud_Licence::allows_deposit()) {
                return;
            }
            $value = get_post_meta($variation->ID, self::META, true);
            woocommerce_wp_text_input(array(
                'id'            => self::META . '_' . $loop,
                'name'          => self::META . '[' . $loop . ']',
                'value'         => self::display_value($value),
                'label'         => __('Deposit (povratna naknada)', 'kigocloud-for-woocommerce') . ' (' . get_woocommerce_currency_symbol() . ')',
                'class'         => 'short',
                'desc_tip'      => true,
                'description'   => __('Deposit per piece of this variation. Leave empty to use the deposit of the product.', 'kigocloud-for-woocommerce'),
                'wrapper_class' => 'form-row form-row-full',
            ));
        }

        /**
         * Before WooCommerce saves the variation.
         *
         * @param WC_Product_Variation $variation
         * @param int                  $loop
         */
        public function save_variation($variation, $loop)
        {
            if (!isset($_POST[self::META]) || !is_array($_POST[self::META]) || !isset($_POST[self::META][$loop])) {
                return;
            }
            self::store($variation, wp_unslash($_POST[self::META][$loop]));
        }

        /**
         * Saves the amount typed in the field; an empty or zero field removes it.
         *
         * @param WC_Product $product
         * @param string     $raw
         */
        private static function store($product, $raw)
        {
            $value = self::parse_amount($raw);
            if ($value === null || $value <= 0) {
                $product->delete_meta_data(self::META);
                return;
            }
            $product->update_meta_data(self::META, wc_format_decimal($value, 4));
        }

        /**
         * Stored amount as the shop writes prices (0,10 with the decimal comma), empty without one.
         *
         * @param string $stored
         * @return string
         */
        private static function display_value($stored)
        {
            if ($stored === '' || $stored === null) {
                return '';
            }

            return wc_format_localized_price(wc_format_decimal($stored, wc_get_price_decimals()));
        }

        /**
         * Amount as typed: 0,10 and 0.10 are both ten cents, whatever the decimal separator of the shop
         * (wc_format_decimal alone reads 0,10 as 10 in a shop with the decimal point).
         *
         * @param string $raw
         * @return float|null null when it is not a number
         */
        public static function parse_amount($raw)
        {
            $value = str_replace(array(' ', "\xc2\xa0"), '', trim((string) $raw));
            if ($value === '') {
                return null;
            }
            if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
                // both: the last one is the decimal separator (1.234,56 or 1,234.56)
                $decimal = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
                $value = str_replace($decimal === ',' ? '.' : ',', '', $value);
            }
            $value = str_replace(',', '.', $value);

            return is_numeric($value) ? (float) $value : null;
        }

        /**
         * Deposit per piece of a product: its own, for a variation without its own that of the parent product.
         *
         * @param WC_Product|false|null $product
         * @return float 0 when the product has no deposit
         */
        public static function for_product($product)
        {
            if (!$product) {
                return 0.0;
            }
            $value = $product->get_meta(self::META, true);
            if ($value === '' && $product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                $value = $parent ? $parent->get_meta(self::META, true) : '';
            }

            return $value === '' ? 0.0 : max(0.0, (float) $value);
        }
    }
}
