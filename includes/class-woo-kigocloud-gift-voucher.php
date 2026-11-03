<?php
/**
 * Kigo.Cloud gift vouchers in the cart.
 *
 * The voucher lives in Kigo.Cloud only, as with Shopify: the buyer types its number in the coupon field,
 * the plugin checks it in Kigo.Cloud (giftVoucher/check) and WooCommerce takes it as a coupon of what is
 * left on the voucher. The order sends Kigo.Cloud the item prices before the voucher and the number and
 * amount of the voucher (gift_vouchers); Kigo.Cloud puts the used voucher on the invoice.
 *
 * A gift voucher bought in the shop is a product with the SKU KIGO-BON-<amount>, Kigo.Cloud issues it
 * with the invoice; nothing in the plugin is needed for that.
 *
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Woo_KigoCloud_Gift_Voucher')) {
    class Woo_KigoCloud_Gift_Voucher
    {
        /** Order coupon item meta: number of the Kigo.Cloud voucher */
        const META_CODE = '_kigocloud_gift_voucher';

        /** How long a checked balance is reused in the cart, in seconds */
        const CACHE_SECONDS = 120;

        /** @var array code => balance, checked in this request */
        private static $balances = array();

        /**
         * A voucher number: XXXX-XXXX, with or without the dash.
         *
         * @param string $code
         * @return bool
         */
        public static function looks_like_code($code)
        {
            return (bool) preg_match('/^[a-z0-9]{4}-?[a-z0-9]{4}$/i', trim((string) $code));
        }

        /**
         * @param string $code
         * @return string XXXX-XXXX
         */
        public static function format_code($code)
        {
            $code = strtoupper(str_replace('-', '', trim((string) $code)));
            return substr($code, 0, 4) . '-' . substr($code, 4);
        }

        /**
         * What is left on an active voucher, 0 when there is no such active voucher (or Kigo.Cloud could not be asked).
         *
         * @param string $code
         * @return float
         */
        public static function balance($code)
        {
            $code = self::format_code($code);
            if (isset(self::$balances[$code])) {
                return self::$balances[$code];
            }
            $cacheKey = 'kigocloud_gv_' . md5($code);
            $cached   = get_transient($cacheKey);
            if ($cached !== false) {
                return self::$balances[$code] = (float) $cached;
            }

            $balance = 0.0;
            $body    = Woo_KigoCloud_Request::call('GET', 'giftVoucher/check?code=' . rawurlencode($code));
            if (!is_wp_error($body) && is_object($body) && isset($body->state) && $body->state === 'active' && isset($body->balance)) {
                $balance = max(0.0, (float) $body->balance);
            }
            set_transient($cacheKey, (string) $balance, self::CACHE_SECONDS);
            return self::$balances[$code] = $balance;
        }

        /**
         * The order changed what is left on its vouchers (sent or cancelled): the next cart asks Kigo.Cloud again.
         *
         * @param WC_Order $order
         */
        public static function forget_order_vouchers($order)
        {
            foreach ($order->get_items('coupon') as $couponItem) {
                $code = $couponItem->get_meta(self::META_CODE);
                if (!empty($code)) {
                    delete_transient('kigocloud_gv_' . md5(self::format_code($code)));
                    unset(self::$balances[self::format_code($code)]);
                }
            }
        }

        /**
         * woocommerce_get_shop_coupon_data: a voucher number in the coupon field becomes a coupon of what is
         * left on the voucher, tax included. A real coupon with the same code wins.
         *
         * @param mixed $data
         * @param mixed $code
         * @param mixed $coupon
         * @return mixed
         */
        public function virtual_coupon($data, $code, $coupon = null)
        {
            // orders go to Kigo.Cloud only from this plugin, not while the KigoKasa plugin still sends them
            if ($data !== false || !is_string($code) || !self::looks_like_code($code) || !Woo_KigoCloud_Request::has_credentials()
                || Woo_KigoCloud_KigoKasa_Switch::is_kigokasa_active()) {
                return $data;
            }
            if (wc_get_coupon_id_by_code($code)) {
                return $data;
            }
            $balance = self::balance($code);
            if ($balance <= 0) {
                return $data;
            }
            return array(
                'code'          => $code,
                'discount_type' => 'fixed_cart',
                'amount'        => self::coupon_amount($balance),
                'virtual'       => true,
            );
        }

        /**
         * The voucher pays what the buyer sees, tax included. With prices entered without tax WooCommerce
         * takes a fixed coupon before tax, so the amount is brought back to the cart's prices without tax.
         *
         * @param float $balance
         * @return float
         */
        private static function coupon_amount($balance)
        {
            if (wc_prices_include_tax() || !wc_tax_enabled() || !function_exists('WC') || !WC()->cart) {
                return $balance;
            }
            $net = (float) WC()->cart->get_subtotal();
            $tax = (float) WC()->cart->get_subtotal_tax();
            return $net > 0 ? round($balance * $net / ($net + $tax), wc_get_price_decimals()) : $balance;
        }

        /**
         * woocommerce_checkout_create_order_coupon_item: the order coupon line keeps the voucher number.
         *
         * @param WC_Order_Item_Coupon $item
         * @param string               $code
         * @param WC_Coupon            $coupon
         */
        public function mark_order_coupon($item, $code, $coupon)
        {
            if ($coupon && $coupon->get_virtual() && self::looks_like_code($code) && !wc_get_coupon_id_by_code($code)) {
                $item->add_meta_data(self::META_CODE, self::format_code($code), true);
            }
        }

        /**
         * woocommerce_cart_totals_coupon_label: the voucher row is named as a voucher.
         *
         * @param string    $label
         * @param WC_Coupon $coupon
         * @return string
         */
        public function coupon_label($label, $coupon)
        {
            if ($coupon && $coupon->get_virtual() && self::looks_like_code($coupon->get_code())) {
                return sprintf(
                    /* translators: %s: gift voucher number */
                    __('Gift voucher %s', 'kigocloud-for-woocommerce'),
                    self::format_code($coupon->get_code())
                );
            }
            return $label;
        }

        /**
         * Vouchers the order was paid with, for the gift_vouchers field of invoice/create, and what they took
         * off each item (tax included), so the items go to Kigo.Cloud at their price before the voucher.
         *
         * @param WC_Order $order
         * @return array vouchers: list of code, amount; by_item: order item id => amount
         */
        public static function from_order($order)
        {
            $vouchers  = array();
            $voucherTotal = 0.0;
            $allTotal  = 0.0;
            foreach ($order->get_items('coupon') as $couponItem) {
                $amount = (float) $couponItem->get_discount() + (float) $couponItem->get_discount_tax();
                $allTotal += $amount;
                $code = $couponItem->get_meta(self::META_CODE);
                if (!empty($code) && $amount > 0) {
                    $vouchers[] = array('code' => (string) $code, 'amount' => round($amount, 2));
                    $voucherTotal += $amount;
                }
            }
            $byItem = array();
            if ($voucherTotal <= 0) {
                return array('vouchers' => array(), 'by_item' => array());
            }
            // each item's discount, split between the vouchers and other coupons as the order totals are
            $share = $allTotal > 0 ? $voucherTotal / $allTotal : 0;
            foreach ($order->get_items() as $itemId => $item) {
                $discount = ((float) $item->get_subtotal() + (float) $item->get_subtotal_tax()) - ((float) $item->get_total() + (float) $item->get_total_tax());
                if ($discount > 0) {
                    $byItem[$itemId] = $discount * $share;
                }
            }
            return array('vouchers' => $vouchers, 'by_item' => $byItem);
        }
    }
}
