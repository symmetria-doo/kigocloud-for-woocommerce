<?php
/**
 * Public-facing functionality.
 *
 * Currently a thin placeholder. R1 checkout fields live in
 * Woo_KigoCloud_R1 (includes/class-woo-kigocloud-r1.php).
 *
 * @package Woo_KigoCloud
 */

if (!defined('ABSPATH')) {
    exit;
}

class Woo_KigoCloud_Public
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
        // Reserved for future public stylesheet.
    }

    /**
     * The anchor price sits under the price, inside the price markup. Many themes
     * put the price of a product card in a box of one line's height that hides
     * what does not fit (overflow hidden), so the line under it would be cut
     * off. This moves the anchor price out of such a box, right under it, and
     * leaves it where it is everywhere else. No file: an inline script on a
     * handle without a source, printed in the footer.
     */
    public function enqueue_scripts()
    {
        if ('1' !== (string) get_option('kigocloud_show_anchor_price', '0')) {
            return;
        }
        $script = <<<'JS'
(function () {
    function clipped(a) {
        var r = a.getBoundingClientRect();
        if (!r.height) {
            return null;
        }
        var stop = a.closest('li.product, .type-product, .wc-block-grid__product, .wc-block-components-product-price') || document.body;
        for (var e = a.parentElement; e && e !== stop && e !== document.body; e = e.parentElement) {
            var cs = window.getComputedStyle(e);
            if (cs.overflowY === 'visible' && cs.overflowX === 'visible') {
                continue;
            }
            var b = e.getBoundingClientRect();
            if (r.bottom > b.bottom + 1 || r.top < b.top - 1) {
                return e;
            }
        }
        return null;
    }
    function place() {
        var lines = document.querySelectorAll('.kigo-anchor-price:not([data-kigo-placed])');
        for (var i = 0; i < lines.length; i++) {
            var a = lines[i];
            var box = clipped(a);
            if (box && box.parentNode) {
                a.setAttribute('data-kigo-placed', '1');
                a.style.setProperty('margin', '4px 0 0', 'important');
                box.parentNode.insertBefore(a, box.nextSibling);
            }
        }
    }
    document.addEventListener('DOMContentLoaded', place);
    window.addEventListener('load', place);
})();
JS;
        wp_register_script('kigocloud-anchor-price', false, array(), $this->version, true);
        wp_enqueue_script('kigocloud-anchor-price');
        wp_add_inline_script('kigocloud-anchor-price', $script);
    }

    /**
     * Base price (bazna cijena, also called anchor price; Pravilnik o isticanju
     * bazne cijene proizvoda, NN 132/2026, in force 17 November 2026):
     * prints the price that applied on the reference day under the product or
     * variation price. KigoCloud sends it with the product as the
     * _kigo_anchor_price and _kigo_anchor_price_date meta. Without that meta the
     * anchor price comes from the price list the plugin downloads from Kigo.Cloud
     * every day (Price list tab), matched by SKU or GTIN; without either the price
     * HTML is returned unchanged.
     *
     * A variable product shows the base prices of its variations: one amount
     * when they are all the same (WooCommerce then shows no variation price
     * when one is selected, so this is the only line on the page), otherwise
     * the lowest and the highest, like the price range above it.
     *
     * @param string     $price_html
     * @param WC_Product $product
     * @return string
     */
    public function anchor_price_html($price_html, $product)
    {
        // Opt-in: without the setting the price HTML is left untouched.
        if ('1' !== (string) get_option('kigocloud_show_anchor_price', '0')) {
            return $price_html;
        }
        if (!($product instanceof WC_Product)) {
            return $price_html;
        }
        $found = null;
        if ($product->is_type('variable')) {
            $found = $this->variations_anchor($product);
        }
        if ($found === null) {
            $found = $this->own_anchor($product);
        }
        if ($found === null) {
            return $price_html;
        }
        list($anchor, $date, $highest) = $found;
        $date = $this->offered_since($product, (string) $date, isset($found[3]) ? (string) $found[3] : '');
        // "Bazna cijena (10. 9. 2026.): 12,00 €", the name the Pravilnik o
        // isticanju bazne cijene proizvoda (NN 132/2026) gives it and Kigo.Cloud
        // prints on shelf labels and declarations (ItemAnchorDate::getLabelWithDate).
        $label = esc_html__('Bazna cijena', 'kigocloud-for-woocommerce');
        if (!empty($date)) {
            $timestamp = strtotime($date);
            if ($timestamp) {
                /* translators: %s: reference day, for example 10. 9. 2026. */
                $label = esc_html(sprintf(__('Bazna cijena (%s)', 'kigocloud-for-woocommerce'), date_i18n('j. n. Y.', $timestamp)));
            }
        }
        // Plain text amount: the theme's rules for .amount (colour, size, weight)
        // must not reach the anchor price, only the line's own style does.
        // Kigo.Cloud keeps the base price as a retail price, with VAT. A shop
        // that shows prices without VAT gets the base price without VAT and
        // with the same suffix ("+ PDV"), so both are on the same footing.
        $amount = trim(wp_strip_all_tags(wc_price($this->shop_amount($product, (float) $anchor))));
        if ($highest !== null && (float) $highest !== (float) $anchor) {
            $amount .= ' – ' . trim(wp_strip_all_tags(wc_price($this->shop_amount($product, (float) $highest))));
        }
        $amount .= $this->shop_suffix($product);

        // Themes lay the price out as flex rows, absolutely positioned boxes of a
        // fixed height and so on, so the line carries its own style with
        // !important, inline: that also holds in the variation price that
        // WooCommerce puts into the page with JavaScript.
        $text = 'font-size:13px !important;line-height:1.4 !important;font-weight:400 !important;font-style:normal !important;'
            . 'color:#6b7280 !important;text-transform:none !important;letter-spacing:normal !important;text-decoration:none !important;';

        // Always on its own line under the price. The price and the anchor price
        // are stacked in a column of their own, because the theme's price
        // element is often a flex row that would put them side by side.
        // The same line on the product page and on product cards and widgets,
        // there a size smaller.
        $line  = $label . ': ' . esc_html($amount);
        $title = '';
        if (!$this->is_main_price($product)) {
            $text = str_replace('13px', '12px', $text);
        }
        $html = '<span class="kigo-price" style="display:inline-flex !important;flex-direction:column !important;align-items:flex-start !important;gap:2px;vertical-align:top;max-width:100%;">'
            . '<span class="kigo-price-main">' . $price_html . '</span>'
            . '<span class="kigo-anchor-price"' . $title . ' style="display:block !important;margin:0 !important;padding:0 !important;white-space:normal !important;' . $text . '">'
            . $line . '</span></span>';

        /**
         * Lets a theme replace the anchor price markup.
         *
         * @param string     $html       price HTML with the anchor price
         * @param string     $price_html price HTML from WooCommerce
         * @param float      $anchor     anchor price
         * @param string     $date       reference day as stored (Y-m-d)
         * @param WC_Product $product
         */
        return apply_filters('kigocloud_anchor_price_html', $html, $price_html, (float) $anchor, (string) $date, $product);
    }

    /**
     * Base price of the product or variation itself: its meta from KigoCloud,
     * otherwise the price list by SKU or GTIN.
     *
     * @param WC_Product $product
     * @return array|null array(price, date, null)
     */
    private function own_anchor($product)
    {
        $anchor    = $product->get_meta('_kigo_anchor_price', true);
        $date      = $product->get_meta('_kigo_anchor_price_date', true);
        $reference = self::meta_reference_day($product->get_meta('_kigo_anchor_prices', true), (string) $date);
        if ($anchor === '' || $anchor === null || !is_numeric($anchor)) {
            $listed = class_exists('Woo_KigoCloud_Pricelist') ? Woo_KigoCloud_Pricelist::anchor_for($product) : null;
            if ($listed === null) {
                return null;
            }
            $anchor    = $listed[0];
            $date      = $listed[1];
            $reference = isset($listed[2]) ? (string) $listed[2] : '';
        }
        if ((float) $anchor < 0) {
            return null;
        }

        return array($anchor, $date, null, $reference);
    }

    /**
     * Reference day (2026-09-10 and so on) of the entry with this date in the
     * _kigo_anchor_prices list that KigoCloud writes next to the price.
     *
     * @param mixed  $json
     * @param string $date
     * @return string '' when KigoCloud did not send it
     */
    private static function meta_reference_day($json, $date)
    {
        $list = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($list)) {
            return '';
        }
        foreach ($list as $entry) {
            if (is_array($entry) && isset($entry['date'], $entry['reference_date']) && (string) $entry['date'] === $date) {
                return (string) $entry['reference_date'];
            }
        }

        return '';
    }

    /**
     * Day the product has been on offer since, for the label. KigoCloud gives
     * the reference day, or for an item it got later the day the item came
     * into KigoCloud. A product already in this shop before that day was on
     * offer earlier: then its day is the day it was created in the shop, or
     * the reference day when it was in the shop on that day (Pravilnik o
     * isticanju bazne cijene proizvoda, NN 132/2026, cl. 3 st. 1). Without the
     * reference day (an older KigoCloud) such a date is left out.
     *
     * @param WC_Product $product
     * @param string     $date      Y-m-d from KigoCloud
     * @param string     $reference reference day Y-m-d, '' when unknown
     * @return string
     */
    private function offered_since($product, $date, $reference)
    {
        if ($date === '' || ($reference !== '' && $date <= $reference)) {
            return $date;
        }
        $shop_product = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        $created      = $shop_product ? $shop_product->get_date_created() : null;
        if (!$created) {
            return $date;
        }
        $since = $created->date('Y-m-d');
        if ($since >= $date) {
            return $date;
        }
        if ($reference === '') {
            return '';
        }

        return $since <= $reference ? $reference : $since;
    }

    /**
     * The base price as this shop shows prices: KigoCloud sends it with VAT,
     * a shop that shows prices without VAT gets it without VAT at the
     * product's tax rate of the shop's base location.
     *
     * @param WC_Product $product
     * @param float      $gross
     * @return float
     */
    private function shop_amount($product, $gross)
    {
        if (!wc_tax_enabled() || 'excl' !== get_option('woocommerce_tax_display_shop')) {
            return $gross;
        }
        // A product without tax (tax status none; for a variation the parent's
        // status counts) is shown as it is, like WooCommerce shows its price.
        $taxed = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        if ($taxed && !$taxed->is_taxable()) {
            return $gross;
        }
        $rates = WC_Tax::get_base_tax_rates($product->get_tax_class('unfiltered'));
        $taxes = WC_Tax::calc_tax($gross, $rates, true);

        return (float) wc_round_tax_total($gross - array_sum($taxes));
    }

    /**
     * The shop's price suffix ("+ PDV", "s PDV-om"), plain text, so the base
     * price says the same as the price above it. Placeholders are left out,
     * and so is the suffix of a product without tax (the parent decides for a
     * variation), as WooCommerce does for the product price.
     *
     * @param WC_Product $product
     * @return string
     */
    private function shop_suffix($product)
    {
        $suffix = trim((string) get_option('woocommerce_price_display_suffix', ''));
        if ($suffix === '' || !wc_tax_enabled() || strpos($suffix, '{') !== false) {
            return '';
        }
        $taxed = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        if ($taxed && !$taxed->is_taxable()) {
            return '';
        }

        return ' ' . trim(wp_strip_all_tags($suffix));
    }

    /**
     * Base prices of the visible variations of a variable product, read the
     * same way as for a single variation (meta, then the price list by the
     * variation's SKU, which WooCommerce takes from the parent when the
     * variation has none, or GTIN). One meta query for all variations.
     *
     * @param WC_Product $product variable product
     * @return array|null array(lowest, date when all are of the same day, highest)
     */
    private function variations_anchor($product)
    {
        $ids = array_map('intval', (array) $product->get_visible_children());
        if (empty($ids)) {
            return null;
        }
        update_meta_cache('post', $ids);
        $prices = array();
        $dates  = array();
        $refs   = array();
        foreach ($ids as $id) {
            $anchor = get_post_meta($id, '_kigo_anchor_price', true);
            $date   = get_post_meta($id, '_kigo_anchor_price_date', true);
            $ref    = self::meta_reference_day(get_post_meta($id, '_kigo_anchor_prices', true), (string) $date);
            if ($anchor === '' || !is_numeric($anchor)) {
                $sku = (string) get_post_meta($id, '_sku', true);
                if ($sku === '') {
                    $sku = (string) $product->get_sku();
                }
                $listed = class_exists('Woo_KigoCloud_Pricelist')
                    ? Woo_KigoCloud_Pricelist::anchor_for_codes($sku, (string) get_post_meta($id, '_global_unique_id', true))
                    : null;
                if ($listed === null) {
                    continue;
                }
                $anchor = $listed[0];
                $date   = $listed[1];
                $ref    = isset($listed[2]) ? (string) $listed[2] : '';
            }
            if ((float) $anchor < 0) {
                continue;
            }
            $prices[] = (float) $anchor;
            $dates[(string) $date] = true;
            $refs[(string) $ref]   = true;
        }
        if (empty($prices)) {
            return null;
        }

        return array(min($prices), count($dates) === 1 ? (string) key($dates) : '', max($prices), count($refs) === 1 ? (string) key($refs) : '');
    }

    /**
     * Is this the price of the product the page is about (its summary or one of
     * its variations), as opposed to a product card or widget on the page?
     *
     * @param WC_Product $product
     * @return bool
     */
    private function is_main_price($product)
    {
        // Variation price fetched when the product has too many variations to
        // put them all into the page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the WooCommerce AJAX endpoint name.
        if (isset($_REQUEST['wc-ajax']) && sanitize_key(wp_unslash($_REQUEST['wc-ajax'])) === 'get_variation') {
            return true;
        }
        if (!function_exists('is_product') || !is_product()) {
            return false;
        }
        $page = (int) get_queried_object_id();

        return $page > 0 && ($page === (int) $product->get_id() || $page === (int) $product->get_parent_id());
    }
}
