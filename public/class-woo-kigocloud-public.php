<?php
/**
 * Public-facing functionality.
 *
 * Currently a thin placeholder. R1 checkout fields live in
 * Woo_KigoCloud_R1 (includes/class-woo-kigocloud-r1.php).
 *
 * @package Woo_KigoCloud
 */

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

    public function enqueue_scripts()
    {
        // Reserved for future public script.
    }

    /**
     * Anchor price (Croatian Government decision, in force 1 October 2026):
     * prints the price that applied on the reference day under the product or
     * variation price. KigoCloud sends it with the product as the
     * _kigo_anchor_price and _kigo_anchor_price_date meta; without that meta
     * the price HTML is returned unchanged.
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
        if ($anchor === '' || $anchor === null || !is_numeric($anchor) || (float) $anchor < 0) {
            return $price_html;
        }
        $label = __('Sidrena cijena', 'kigocloud-for-woocommerce');
        $date  = $product->get_meta('_kigo_anchor_price_date', true);
        if (!empty($date)) {
            $timestamp = strtotime($date);
            if ($timestamp) {
                $label .= ' (' . date_i18n('j. n. Y.', $timestamp) . ')';
            }
        }

        return $price_html
            . '<span class="kigo-anchor-price" style="display:block;font-size:0.85em;font-weight:normal;">'
            . esc_html($label) . ': ' . wc_price((float) $anchor)
            . '</span>';
    }
}
