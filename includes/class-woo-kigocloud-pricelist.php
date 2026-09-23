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
 * What the decision asks for, and where it is handled:
 * - "najkasnije do 8:00 sati ujutro za tekuci radni dan": WP-Cron fetches at
 *   07:00, and a page view refreshes a copy that is not from today. KigoCloud
 *   builds the day's publication on the first request of the day.
 * - "Nazivi datoteka ukljucuju oblik prodajnog objekta, adresu prodajnog
 *   objekta, oznaku prodajnog objekta, broj pohrane te vremensku oznaku":
 *   KigoCloud sends that name with the file (Content-Disposition) and the file
 *   is stored under it.
 * - "pohranjuje i cuva objavljene cjenike na mreznim stranicama te osigurava
 *   dostupnost istih 30 dana": every day's files stay in the folder for 30 days
 *   and the shortcode links to them.
 * - automated price collection (point VII): the files are plain static files on
 *   the shop's domain.
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

    /** Current files: array('csv' => file name, 'xml' => file name, 'day' => Y-m-d). */
    const OPTION_CURRENT = 'kigocloud_pricelist_current';

    /** Cron hook name. */
    const CRON_HOOK = 'kigocloud_pricelist_fetch';

    /** Folder inside wp-content/uploads. */
    const DIR = 'kigo-cjenik';

    /** Every published version stays available this many days. */
    const KEEP_DAYS = 30;

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
     * The address changed: the next fetch starts a new current version. Files
     * already published stay, the decision wants them available for 30 days.
     */
    public static function on_url_saved()
    {
        delete_option(self::OPTION_CURRENT);
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
     * Folder that holds the published files.
     *
     * @return string
     */
    private static function dir()
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . self::DIR;
    }

    /**
     * Current files as stored by the last fetch.
     *
     * @return array
     */
    private static function current()
    {
        $current = get_option(self::OPTION_CURRENT, array());

        return is_array($current) ? $current : array();
    }

    /**
     * Absolute path of the current file of a format, or '' when there is none.
     *
     * @param string $format csv|xml
     * @return string
     */
    public static function path($format)
    {
        $current = self::current();
        if (empty($current[$format])) {
            return '';
        }

        return trailingslashit(self::dir()) . $current[$format];
    }

    /**
     * Public address of the current file of a format, on the shop's own domain.
     *
     * @param string $format csv|xml
     * @return string
     */
    public static function url($format)
    {
        $current = self::current();
        if (empty($current[$format])) {
            return '';
        }

        return self::file_url($current[$format]);
    }

    /**
     * @param string $name file name inside the folder
     * @return string
     */
    private static function file_url($name)
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['baseurl']) . self::DIR . '/' . rawurlencode($name);
    }

    /**
     * Is the current copy from today (site time zone)? The decision asks for the
     * day's price list, so yesterday's copy is stale even when it is a few hours old.
     *
     * @return bool
     */
    public static function is_current()
    {
        $current = self::current();
        $path = self::path('csv');

        return isset($current['day']) && $current['day'] === current_time('Y-m-d')
            && $path !== '' && file_exists($path);
    }

    /**
     * Prescribed file name from the Content-Disposition header. Only a plain
     * name with the expected extension is accepted, so a response can never
     * write outside the folder.
     *
     * @param array|WP_Error $response
     * @param string $format csv|xml
     * @return string '' when the header is missing or not usable
     */
    private static function file_name($response, $format)
    {
        $header = (string) wp_remote_retrieve_header($response, 'content-disposition');
        if (!preg_match('/filename="?([^";]+)"?/i', $header, $match)) {
            return '';
        }
        $name = basename(trim($match[1]));
        if (!preg_match('/^[A-Za-z0-9._-]+\.' . $format . '$/', $name)) {
            return '';
        }

        return $name;
    }

    /**
     * Downloads both formats when today's copy is missing.
     *
     * @param bool $force ignore the day check (admin button)
     * @return array status array as stored in OPTION_STATUS
     */
    public static function fetch($force = false)
    {
        $base = self::base_url();
        if ($base === '') {
            return self::store_status('error', __('The KigoCloud price list address is not set.', 'kigocloud-for-woocommerce'));
        }
        if (!$force && self::is_current() && file_exists(self::path('xml'))) {
            return self::store_status('ok', __('The price list is already up to date.', 'kigocloud-for-woocommerce'));
        }

        $dir = self::dir();
        if (!wp_mkdir_p($dir)) {
            return self::store_status('error', __('The price list folder could not be created.', 'kigocloud-for-woocommerce'));
        }

        $current = self::current();
        $fetched = 0;
        foreach (array('csv', 'xml') as $format) {
            $path = self::path($format);
            $args = array('timeout' => 30);
            if ($path !== '' && file_exists($path)) {
                // Nothing changed since our copy: KigoCloud answers 304 with no body.
                $args['headers'] = array('If-Modified-Since' => gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
            }
            $response = wp_remote_get($base . '/' . $format, $args);
            if (is_wp_error($response)) {
                return self::store_status('error', $response->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 304) {
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
            $name = self::file_name($response, $format);
            if ($name === '') {
                return self::store_status('error', __('KigoCloud did not send the prescribed file name. Update KigoCloud or contact support.', 'kigocloud-for-woocommerce'));
            }
            $target = trailingslashit($dir) . $name;
            file_put_contents($target . '.tmp', $body);
            rename($target . '.tmp', $target);
            $current[$format] = $name;
            $fetched++;
        }
        $current['day'] = current_time('Y-m-d');
        update_option(self::OPTION_CURRENT, $current, false);
        self::purge();

        return self::store_status('ok', $fetched > 0
            ? __('The price list has been refreshed.', 'kigocloud-for-woocommerce')
            : __('The price list is already up to date.', 'kigocloud-for-woocommerce'));
    }

    /**
     * Removes files older than 30 days, never the current ones. Also removes the
     * cjenik.csv and cjenik.xml that version 2.1.17 wrote without the prescribed
     * name.
     */
    private static function purge()
    {
        $keep = array_filter(array(basename(self::path('csv')), basename(self::path('xml'))));
        $limit = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
        foreach ((array) glob(trailingslashit(self::dir()) . '*') as $file) {
            if (!is_file($file) || in_array(basename($file), $keep, true)) {
                continue;
            }
            $legacy = in_array(basename($file), array('cjenik.csv', 'cjenik.xml'), true);
            if ($legacy || filemtime($file) < $limit) {
                unlink($file);
            }
        }
    }

    /**
     * Published files of the last 30 days, newest first, grouped by the name
     * without its extension (the CSV and the XML of one publication).
     *
     * @return array list of array('name' => base name, 'time' => timestamp, 'csv' => url|'', 'xml' => url|'')
     */
    public static function archive()
    {
        $list = array();
        foreach ((array) glob(trailingslashit(self::dir()) . '*') as $file) {
            if (!is_file($file)) {
                continue;
            }
            $name = basename($file);
            $format = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (($format !== 'csv' && $format !== 'xml') || $name === 'cjenik.csv' || $name === 'cjenik.xml') {
                continue;
            }
            $key = substr($name, 0, -strlen($format) - 1);
            if (!isset($list[$key])) {
                $list[$key] = array('name' => $key, 'time' => filemtime($file), 'csv' => '', 'xml' => '');
            }
            $list[$key][$format] = self::file_url($name);
        }
        // The prescribed name ends with the storage number, date and time
        // (..._000014_20261001_080000); that time orders the publications, also
        // across a change of the outlet's address. A name without it falls back
        // to the file time.
        foreach ($list as $key => $item) {
            $list[$key]['sort'] = preg_match('/_(\d{8})_(\d{6})$/', $item['name'], $m)
                ? $m[1] . $m[2]
                : gmdate('YmdHis', $item['time']);
        }
        uasort($list, function ($a, $b) {
            return strcmp($b['sort'], $a['sort']);
        });

        return array_values($list);
    }

    /**
     * Refreshes a copy that is not from today on a normal page view, so sites
     * with little traffic (where WP-Cron rarely fires) still publish the day's
     * price list.
     */
    public static function maybe_refresh()
    {
        if (self::base_url() === '' || self::is_current()) {
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
     * Warns in wp-admin when the published copy is not from today, instead of
     * letting it age silently: a stale price list is a breach, and nobody would
     * notice.
     */
    public static function admin_notice()
    {
        if (!current_user_can('manage_options') || self::base_url() === '' || self::is_current()) {
            return;
        }
        $status = self::status();
        $detail = isset($status['message']) ? $status['message'] : '';
        echo '<div class="notice notice-warning"><p><strong>KigoCloud</strong> '
            . esc_html__('The published price list is not from today.', 'kigocloud-for-woocommerce')
            . ' ' . esc_html($detail) . '</p></div>';
    }

    /**
     * [kigo_cjenik] renders the current price list from the local copy, with
     * links to the current files and to every publication of the last 30 days.
     * No call to KigoCloud happens while a page is being rendered, except the
     * once-a-day refresh of a copy that is not from today.
     *
     * @param array $atts
     * @return string
     */
    public static function shortcode($atts)
    {
        $atts = shortcode_atts(array('limit' => 0), $atts, 'kigo_cjenik');
        self::maybe_refresh();

        $path = self::path('csv');
        if ($path === '' || !file_exists($path)) {
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
        $out .= '</tbody></table>';

        $archive = self::archive();
        if (!empty($archive)) {
            $out .= '<h3 class="kigo-cjenik-archive-title">' . esc_html__('Published price lists (last 30 days)', 'kigocloud-for-woocommerce') . '</h3>';
            $out .= '<ul class="kigo-cjenik-archive">';
            foreach ($archive as $item) {
                $links = array();
                foreach (array('csv', 'xml') as $format) {
                    if ($item[$format] !== '') {
                        $links[] = '<a href="' . esc_url($item[$format]) . '">' . strtoupper($format) . '</a>';
                    }
                }
                $out .= '<li>' . esc_html($item['name']) . ' &middot; ' . implode(' &middot; ', $links) . '</li>';
            }
            $out .= '</ul>';
        }
        $out .= '</div>';

        return $out;
    }
}
