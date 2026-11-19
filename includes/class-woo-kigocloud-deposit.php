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

        /** Order line meta: deposit per piece taken out of the line price into the deposit fee (since 2.3.1) */
        const LINE_META = '_kigocloud_deposit';

        /** Order fee meta: the fee is the deposit of the order lines, not an item for Kigo.Cloud */
        const FEE_META = '_kigocloud_deposit_fee';

        /**
         * Cart product objects whose price is already without the deposit in this request, with the deposit.
         * SplObjectStorage keeps the object, so a removed line cannot hand its object id to a new one.
         *
         * @var SplObjectStorage|null
         */
        private static $takenOut = null;

        /**
         * Name of the deposit row in the cart, the checkout and the order.
         *
         * @return string
         */
        public static function fee_name()
        {
            return __('Deposit (povratna naknada)', 'kigocloud-for-woocommerce');
        }

        /**
         * Hooked on woocommerce_before_calculate_totals (late): the catalogue price includes the deposit, as in
         * Kigo.Cloud; in the cart the deposit leaves the price of the line and goes to its own row without VAT,
         * so WooCommerce charges VAT on the rest only, as Kigo.Cloud does. The total paid is the same.
         *
         * @param WC_Cart $cart
         */
        public function cart_take_out($cart)
        {
            if (!Woo_KigoCloud_Licence::allows_deposit()) {
                return;
            }
            if (self::$takenOut === null) {
                self::$takenOut = new SplObjectStorage();
            }
            foreach ($cart->cart_contents as $key => $cartItem) {
                if (empty($cartItem['data']) || !is_object($cartItem['data'])) {
                    continue;
                }
                $product = $cartItem['data'];
                if (self::$takenOut->contains($product)) {
                    // totals are calculated more than once in a request: the price is already without the deposit
                    $cart->cart_contents[$key]['kigocloud_deposit'] = self::$takenOut[$product];
                    continue;
                }
                $deposit = self::for_product($product);
                $price = (float) $product->get_price();
                if ($deposit <= 0 || $deposit > $price) {
                    // no deposit, or a deposit above the price (a wrong field): the line stays as it is
                    unset($cart->cart_contents[$key]['kigocloud_deposit']);
                    continue;
                }
                $product->set_price(round($price - $deposit, 4));
                // the regular and the sale price too, or the cart shows the deposit as a discount (3,00 -> 2,90)
                $regular = $product->get_regular_price('edit');
                if ($regular !== '' && (float) $regular >= $deposit) {
                    $product->set_regular_price(round((float) $regular - $deposit, 4));
                }
                $sale = $product->get_sale_price('edit');
                if ($sale !== '' && (float) $sale >= $deposit) {
                    $product->set_sale_price(round((float) $sale - $deposit, 4));
                }
                self::$takenOut[$product] = $deposit;
                $cart->cart_contents[$key]['kigocloud_deposit'] = $deposit;
            }
        }

        /**
         * Hooked on woocommerce_cart_calculate_fees: one row with the deposit of all lines, without VAT.
         *
         * @param WC_Cart $cart
         */
        public function cart_fee($cart)
        {
            $sum = 0.0;
            foreach ($cart->cart_contents as $cartItem) {
                if (!empty($cartItem['kigocloud_deposit'])) {
                    $sum += (float) $cartItem['kigocloud_deposit'] * (float) $cartItem['quantity'];
                }
            }
            if ($sum > 0) {
                $cart->add_fee(self::fee_name(), round($sum, 2), false);
            }
        }

        /**
         * Hooked on woocommerce_checkout_create_order_line_item (classic and block checkout): the deposit per
         * piece taken out of this line goes on the order line, so the order sends it to Kigo.Cloud as it was sold.
         *
         * @param WC_Order_Item_Product $item
         * @param string                $cartItemKey
         * @param array                 $values
         */
        public function order_line_meta($item, $cartItemKey, $values)
        {
            if (!empty($values['kigocloud_deposit'])) {
                $item->add_meta_data(self::LINE_META, wc_format_decimal($values['kigocloud_deposit'], 4), true);
            }
        }

        /**
         * Hooked on woocommerce_checkout_create_order_fee_item: marks the deposit row of the order.
         *
         * @param WC_Order_Item_Fee $item
         * @param string            $feeKey
         * @param object            $fee
         */
        public function order_fee_meta($item, $feeKey, $fee)
        {
            if (isset($fee->id) && $fee->id === sanitize_title(self::fee_name())) {
                $item->add_meta_data(self::FEE_META, 1, true);
            }
        }

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
