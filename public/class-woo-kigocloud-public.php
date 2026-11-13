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
     * Anchor price (Croatian Government decision, in force 1 October 2026):
     * prints the price that applied on the reference day under the product or
     * variation price. KigoCloud sends it with the product as the
     * _kigo_anchor_price and _kigo_anchor_price_date meta. Without that meta the
     * anchor price comes from the price list the plugin downloads from Kigo.Cloud
     * every day (Price list tab), matched by SKU or GTIN; without either the price
     * HTML is returned unchanged.
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
        $anchor = $product->get_meta('_kigo_anchor_price', true);
        $date   = $product->get_meta('_kigo_anchor_price_date', true);
        if ($anchor === '' || $anchor === null || !is_numeric($anchor)) {
            $listed = class_exists('Woo_KigoCloud_Pricelist') ? Woo_KigoCloud_Pricelist::anchor_for($product) : null;
            if ($listed === null) {
                return $price_html;
            }
            $anchor = $listed[0];
            $date   = $listed[1];
        }
        if ((float) $anchor < 0) {
            return $price_html;
        }
        // "Sidrena cijena (10. 9. 2026.): 12,00 €", as Kigo.Cloud prints it on
        // shelf labels and declarations (ItemAnchorDate::getLabelWithDate).
        $label = esc_html__('Sidrena cijena', 'kigocloud-for-woocommerce');
        if (!empty($date)) {
            $timestamp = strtotime($date);
            if ($timestamp) {
                /* translators: %s: reference day, for example 10. 9. 2026. */
                $label = esc_html(sprintf(__('Sidrena cijena (%s)', 'kigocloud-for-woocommerce'), date_i18n('j. n. Y.', $timestamp)));
            }
        }
        // Plain text amount: the theme's rules for .amount (colour, size, weight)
        // must not reach the anchor price, only the line's own style does.
        $amount = trim(wp_strip_all_tags(wc_price((float) $anchor)));

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
