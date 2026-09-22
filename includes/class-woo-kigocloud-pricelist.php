<?php
/**
 * Price list published on the shop's own domain.
 *
 * The Croatian decision on price list publication (NN 101/2026, in force
 * 1 October 2026) requires the trader to publish the price list, in CSV or XML,
 * on the trader's own website. A link to a third party address is arguable; a
 * file on the trader's own domain is not. So the plugin pulls the files from
 * KigoCloud once a day and writes them into the uploads folder, where the shop's
 * own web server serves them statically.
 *
 * Consequences of that design, on purpose:
 * - the shop's visitors and price crawlers never touch KigoCloud
 * - KigoCloud sees at most two conditional requests per shop per day
 * - a KigoCloud outage does not put the trader out of compliance
 *
 * @package Woo_KigoCloud
 */

if (!defined('ABSPATH')) {
    exit;
}

class Woo_KigoCloud_Pricelist
{
    /** Address of the KigoCloud price list page (Sidrene cijene -> Cjenik za objavu). */
    const OPTION_URL = 'kigocloud_pricelist_url';

    /** Last fetch result, kept for the admin screen: array(time, status, message). */
    const OPTION_STATUS = 'kigocloud_pricelist_status';

    /** Cron hook name. */
    const CRON_HOOK = 'kigocloud_pricelist_fetch';

    /** Folder inside wp-content/uploads. */
    const DIR = 'kigo-cjenik';

    /** A file older than this is refreshed on the next visit even without cron. */
    const MAX_AGE = 93600; // 26 h, so a daily cron that slips by an hour is fine

    /**
     * Cron registration and the shortcode. Called from the plugin bootstrap.
     */
    public static function init()
    {
        add_shortcode('kigo_cjenik', array(__CLASS__, 'shortcode'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'fetch'));
        add_action('init', array(__CLASS__, 'schedule'));
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        // Saving the address fetches at once, so the shop owner sees straight away
        // whether it works instead of waiting for tomorrow morning.
        add_action('update_option_' . self::OPTION_URL, array(__CLASS__, 'on_url_saved'), 10, 0);
        add_action('add_option_' . self::OPTION_URL, array(__CLASS__, 'on_url_saved'), 10, 0);
    }

    /**
     * Daily fetch at 07:00 local time, before the 8:00 deadline. WP-Cron only
     * runs when the site gets a visit, so maybe_refresh() covers quiet sites.
     */
    public static function schedule()
    {
        if (self::base_url() === '') {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            if ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $seven = strtotime('tomorrow 07:00');
            wp_schedule_event($seven, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * The address changed: drop the old copy and fetch the new one now.
     */
    public static function on_url_saved()
    {
        foreach (array('csv', 'xml') as $format) {
            $path = self::path($format);
            if (file_exists($path)) {
                unlink($path);
            }
        }
        self::schedule();
        self::fetch(true);
    }

    /**
     * Configured KigoCloud address without a trailing slash, or '' when unset.
     *
     * @return string
     */
    public static function base_url()
    {
        $url = trim((string) get_option(self::OPTION_URL, ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        return rtrim($url, '/');
    }

    /**
     * Absolute path of a stored file.
     *
     * @param string $format csv|xml
     * @return string
     */
    public static function path($format)
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . self::DIR . '/cjenik.' . $format;
    }

    /**
     * Public address of a stored file, on the shop's own domain.
     *
     * @param string $format csv|xml
     * @return string
     */
    public static function url($format)
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['baseurl']) . self::DIR . '/cjenik.' . $format;
    }

    /**
     * Downloads both formats when the local copy is missing or stale.
     *
     * @param bool $force ignore the age check (admin button)
     * @return array status array as stored in OPTION_STATUS
     */
    public static function fetch($force = false)
    {
        $base = self::base_url();
        if ($base === '') {
            return self::store_status('error', __('The KigoCloud price list address is not set.', 'kigocloud-for-woocommerce'));
        }

        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . self::DIR;
        if (!wp_mkdir_p($dir)) {
            return self::store_status('error', __('The price list folder could not be created.', 'kigocloud-for-woocommerce'));
        }

        $fetched = 0;
        foreach (array('csv', 'xml') as $format) {
            $path = self::path($format);
            if (!$force && file_exists($path) && (time() - filemtime($path)) < self::MAX_AGE) {
                continue;
            }
            $args = array('timeout' => 30);
            if (file_exists($path)) {
                // Nothing changed since our copy: KigoCloud answers 304 with no body.
                $args['headers'] = array('If-Modified-Since' => gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
            }
            $response = wp_remote_get($base . '/' . $format, $args);
            if (is_wp_error($response)) {
                return self::store_status('error', $response->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 304) {
                touch($path);
                continue;
            }
            if ($code !== 200) {
                return self::store_status('error', sprintf(
                    /* translators: %d: HTTP status code */
                    __('KigoCloud answered with status %d. Check the address and your plan.', 'kigocloud-for-woocommerce'),
                    $code
                ));
            }
            $body = wp_remote_retrieve_body($response);
            if ($body === '') {
                return self::store_status('error', __('KigoCloud returned an empty price list.', 'kigocloud-for-woocommerce'));
            }
            file_put_contents($path . '.tmp', $body);
            rename($path . '.tmp', $path);
            $fetched++;
        }

        return self::store_status('ok', $fetched > 0
            ? __('The price list has been refreshed.', 'kigocloud-for-woocommerce')
            : __('The price list is already up to date.', 'kigocloud-for-woocommerce'));
    }

    /**
     * Refreshes a stale copy on a normal page view, so sites with little traffic
     * (where WP-Cron rarely fires) still publish a current price list.
     */
    public static function maybe_refresh()
    {
        $path = self::path('csv');
        if (file_exists($path) && (time() - filemtime($path)) < self::MAX_AGE) {
            return;
        }
        // One visitor does the work; the rest render whatever is on disk.
        if (get_transient('kigocloud_pricelist_lock')) {
            return;
        }
        set_transient('kigocloud_pricelist_lock', 1, 300);
        self::fetch();
        delete_transient('kigocloud_pricelist_lock');
    }

    /**
     * @param string $status ok|error
     * @param string $message
     * @return array
     */
    private static function store_status($status, $message)
    {
        $data = array('time' => time(), 'status' => $status, 'message' => $message);
        update_option(self::OPTION_STATUS, $data, false);

        return $data;
    }

    /**
     * @return array
     */
    public static function status()
    {
        $data = get_option(self::OPTION_STATUS, array());

        return is_array($data) ? $data : array();
    }

    /**
     * Warns in wp-admin when the published copy is stale, instead of letting it
     * age silently: a stale price list is a breach, and nobody would notice.
     */
    public static function admin_notice()
    {
        if (!current_user_can('manage_options') || self::base_url() === '') {
            return;
        }
        $path = self::path('csv');
        if (file_exists($path) && (time() - filemtime($path)) < 2 * DAY_IN_SECONDS) {
            return;
        }
        $status = self::status();
        $detail = isset($status['message']) ? $status['message'] : '';
        echo '<div class="notice notice-warning"><p><strong>KigoCloud</strong> '
            . esc_html__('The published price list has not been refreshed in over two days.', 'kigocloud-for-woocommerce')
            . ' ' . esc_html($detail) . '</p></div>';
    }

    /**
     * [kigo_cjenik] renders the current price list from the local copy. No call
     * to KigoCloud happens while a page is being rendered.
     *
     * @param array $atts
     * @return string
     */
    public static function shortcode($atts)
    {
        $atts = shortcode_atts(array('limit' => 0), $atts, 'kigo_cjenik');
        self::maybe_refresh();

        $path = self::path('csv');
        if (!file_exists($path)) {
            return '<p>' . esc_html__('The price list is not available yet.', 'kigocloud-for-woocommerce') . '</p>';
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return '<p>' . esc_html__('The price list is not available yet.', 'kigocloud-for-woocommerce') . '</p>';
        }

        $limit = max(0, (int) $atts['limit']);
        $header = fgetcsv($handle, 0, ';');
        if (is_array($header)) {
            // The first column may carry a UTF-8 BOM written for spreadsheet apps.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $out = '<div class="kigo-cjenik">';
        $out .= '<p class="kigo-cjenik-files">'
            . '<a href="' . esc_url(self::url('csv')) . '">CSV</a> &middot; '
            . '<a href="' . esc_url(self::url('xml')) . '">XML</a> &middot; '
            . esc_html(sprintf(
                /* translators: %s: date and time */
                __('Updated: %s', 'kigocloud-for-woocommerce'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($path))
            ))
            . '</p>';
        $out .= '<table class="kigo-cjenik-table"><thead><tr>';
        foreach ((array) $header as $cell) {
            $out .= '<th>' . esc_html(ucfirst(str_replace('_', ' ', (string) $cell))) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        $rows = 0;
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $out .= '<tr>';
            foreach ($row as $cell) {
                $out .= '<td>' . esc_html((string) $cell) . '</td>';
            }
            $out .= '</tr>';
            $rows++;
            if ($limit > 0 && $rows >= $limit) {
                break;
            }
        }
        fclose($handle);
        $out .= '</tbody></table></div>';

        return $out;
    }
}
