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

    /**
     * Anchor prices read from Kigo.Cloud (item/list): array('day' => Y-m-d,
     * 'ref' => array(sifra => array(price, date)), 'barcode' => array(barkod => array(price, date))).
     * Not autoloaded.
     */
    const OPTION_ANCHOR = 'kigocloud_anchor_index';

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
        self::init_anchor();
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
        // Only from the licence (Standard and above, publication on in
        // Kigo.Cloud): nothing is typed in by hand.
        if (!class_exists('Woo_KigoCloud_Licence')) {
            return '';
        }
        $url = Woo_KigoCloud_Licence::price_list_url();
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        return ($scheme === 'https' || $scheme === 'http') ? $url : '';
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
            // Written aside and moved into place, so a visitor never gets half a file.
            $fs = self::filesystem();
            if (!$fs->put_contents($target . '.tmp', $body, FS_CHMOD_FILE) || !$fs->move($target . '.tmp', $target, true)) {
                return self::store_status('error', __('The price list could not be saved in the uploads folder.', 'kigocloud-for-woocommerce'));
            }
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
     * Anchor prices for the shop. They have nothing to do with the price list
     * publication above (the Price list tab, the price list on the shop's own
     * domain): they come from Kigo.Cloud's Sidrene cijene through the API.
     *
     * When "Anchor price" is set to show, the plugin reads the item list of the
     * v1 API (GET item/list), which carries anchor_prices for every item: one
     * entry per switched-on reference day that applies to the item and has a
     * price, oldest day first. The newest one is the anchor price the shop
     * shows. Anchor prices are in every Kigo.Cloud package. Read once a day after
     * 8:00 and when the settings are saved; nothing is typed in by hand.
     */

    /** Last read: array('time', 'count', 'reason', 'message'). Not autoloaded. */
    const OPTION_ANCHOR_SOURCE = 'kigocloud_anchor_source';

    /** Daily refresh of the anchor prices, after Kigo.Cloud publishes at 8:00. */
    const ANCHOR_CRON = 'kigocloud_anchor_refresh';

    /**
     * Hooks of the anchor prices. Called from init().
     */
    private static function init_anchor()
    {
        add_action(self::ANCHOR_CRON, array(__CLASS__, 'cron_refresh'));
        add_action('init', array(__CLASS__, 'schedule_anchor'));
        // Every Save reads them again, also when nothing changed here (a login
        // switched in Kigo.Cloud, a reference day changed there).
        add_action('admin_init', array(__CLASS__, 'on_settings_form'));
        // Switching the anchor price on, or a new login, fetches them at once.
        foreach (array('kigocloud_show_anchor_price', 'kigocloud_api_key', 'kigocloud_username', 'kigocloud_password') as $option) {
            add_action('update_option_' . $option, array(__CLASS__, 'on_anchor_settings_saved'), 20, 0);
            add_action('add_option_' . $option, array(__CLASS__, 'on_anchor_settings_saved'), 20, 0);
        }
    }

    /**
     * @return bool the anchor price is shown and there is a login to ask Kigo.Cloud with
     */
    private static function anchor_wanted()
    {
        return '1' === (string) get_option('kigocloud_show_anchor_price', '0')
            && class_exists('Woo_KigoCloud_Request')
            && Woo_KigoCloud_Request::has_credentials()
            && (!class_exists('Woo_KigoCloud_Licence') || Woo_KigoCloud_Licence::allows_anchor());
    }

    /**
     * Hooked on `init`: the daily refresh at 08:15 while the anchor price is shown.
     */
    public static function schedule_anchor()
    {
        $next = wp_next_scheduled(self::ANCHOR_CRON);
        if (!self::anchor_wanted()) {
            if ($next) {
                wp_unschedule_event($next, self::ANCHOR_CRON);
            }
            return;
        }
        if (!$next) {
            wp_schedule_event(strtotime('tomorrow 08:15'), 'daily', self::ANCHOR_CRON);
        }
    }

    /**
     * The anchor price was switched on or the login changed. The settings form
     * saves every option in one request, so the refresh waits for the end of it
     * and asks Kigo.Cloud with the login just saved.
     */
    public static function on_anchor_settings_saved()
    {
        if (!has_action('shutdown', array(__CLASS__, 'refresh_after_save'))) {
            add_action('shutdown', array(__CLASS__, 'refresh_after_save'));
        }
    }

    /**
     * The daily refresh. A login Kigo.Cloud refused at the last check is not
     * tried again every morning (each try is a refused login in Kigo.Cloud's
     * log): the next Save or "Test connection" checks it again.
     */
    public static function cron_refresh()
    {
        $check = Woo_KigoCloud_Request::login_check();
        if (isset($check['ok']) && !$check['ok']) {
            return 0;
        }

        return self::refresh_anchor_prices();
    }

    /**
     * Hooked on `admin_init`.
     */
    public static function on_settings_form()
    {
        if (Woo_KigoCloud_Request::is_settings_save()) {
            self::on_anchor_settings_saved();
        }
    }

    /**
     * Hooked on `shutdown` by on_anchor_settings_saved().
     */
    public static function refresh_after_save()
    {
        $check = Woo_KigoCloud_Request::login_check();
        if (isset($check['ok']) && !$check['ok']) {
            self::store_anchor_source(array('reason' => 'error', 'message' => isset($check['message']) ? (string) $check['message'] : ''));
        } elseif (self::anchor_wanted()) {
            self::refresh_anchor_prices();
        }
        self::schedule_anchor();
    }

    /**
     * @return array the last read (time, count, reason, message), array() before the first one
     */
    public static function anchor_source()
    {
        $source = get_option(self::OPTION_ANCHOR_SOURCE, array());

        return is_array($source) ? $source : array();
    }

    /** Items per page of item/list (its maximum). */
    const ANCHOR_PAGE = 3000;

    /**
     * Reads the anchor prices of all items from Kigo.Cloud (GET item/list, page
     * by page) and stores the newest one of each item. A failed call keeps the
     * anchor prices read before.
     *
     * @return int number of items with an anchor price, 0 when nothing was read
     */
    public static function refresh_anchor_prices()
    {
        if (!self::anchor_wanted()) {
            return 0;
        }
        $index = array('day' => current_time('Y-m-d'), 'ref' => array(), 'barcode' => array());
        $count = 0;
        // 20 pages of 3000: far more items than a shop has.
        for ($page = 0; $page < 20; $page++) {
            $answer = Woo_KigoCloud_Request::call('GET', 'item/list?pageSize=' . self::ANCHOR_PAGE . ($page > 0 ? '&page=' . $page : ''));
            if (is_wp_error($answer)) {
                self::store_anchor_source(array('reason' => 'error', 'message' => $answer->get_error_message()));
                return 0;
            }
            $rows = (isset($answer->data) && is_array($answer->data)) ? $answer->data : array();
            foreach ($rows as $row) {
                $entry = self::newest_anchor($row);
                if ($entry === null) {
                    continue;
                }
                $added = false;
                foreach (array('reference' => 'ref', 'barcode' => 'barcode') as $field => $key) {
                    $code = isset($row->$field) ? self::anchor_key((string) $row->$field) : '';
                    if ($code !== '') {
                        $index[$key][$code] = $entry;
                        $added = true;
                    }
                }
                if ($added) {
                    $count++;
                }
            }
            if (count($rows) < self::ANCHOR_PAGE) {
                break;
            }
        }
        update_option(self::OPTION_ANCHOR, $index, false);
        self::$anchor_index = null;
        self::store_anchor_source(array('reason' => $count > 0 ? '' : 'empty', 'count' => $count));

        return $count;
    }

    /**
     * The anchor price of the newest reference day of an item/list row.
     *
     * @param object $row
     * @return array|null array(price, date)
     */
    private static function newest_anchor($row)
    {
        if (empty($row->anchor_prices) || !is_array($row->anchor_prices)) {
            return null;
        }
        // Kigo.Cloud lists the reference days oldest first.
        $last = end($row->anchor_prices);
        if (!is_object($last) || !isset($last->price) || !is_numeric($last->price) || (float) $last->price < 0) {
            return null;
        }

        return array((float) $last->price, isset($last->date) ? (string) $last->date : '');
    }

    /**
     * @param array $source
     */
    private static function store_anchor_source(array $source)
    {
        $source['time'] = time();
        update_option(self::OPTION_ANCHOR_SOURCE, $source, false);
    }

    /** @var array|null index loaded in this request */
    private static $anchor_index = null;

    /**
     * @param string $code item code or barcode
     * @return string the code as it is compared: trimmed, lower case
     */
    private static function anchor_key($code)
    {
        $code = trim((string) $code);

        return function_exists('mb_strtolower') ? mb_strtolower($code, 'UTF-8') : strtolower($code);
    }

    /**
     * Anchor price from Kigo.Cloud for a product or variation:
     * matched by SKU against the Kigo.Cloud item code (sifra), the same match
     * the orders use, then by GTIN/EAN against the barcode (barkod).
     *
     * @param WC_Product $product
     * @return array|null array(price, date) or null when there is none
     */
    public static function anchor_for($product)
    {
        if (self::$anchor_index === null) {
            $index = get_option(self::OPTION_ANCHOR, null);
            self::$anchor_index = is_array($index) ? $index : array();
        }
        if (empty(self::$anchor_index)) {
            return null;
        }
        $sku = self::anchor_key($product->get_sku());
        if ($sku !== '' && isset(self::$anchor_index['ref'][$sku])) {
            return self::$anchor_index['ref'][$sku];
        }
        if (is_callable(array($product, 'get_global_unique_id'))) {
            $gtin = self::anchor_key($product->get_global_unique_id());
            if ($gtin !== '' && isset(self::$anchor_index['barcode'][$gtin])) {
                return self::$anchor_index['barcode'][$gtin];
            }
        }

        return null;
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
                wp_delete_file($file);
            }
        }
    }

    /**
     * Direct file access for the plugin's own folder in uploads, which PHP can
     * always write to. Also used from WP-Cron, where no FTP credentials can be asked.
     *
     * FS_CHMOD_FILE and FS_CHMOD_DIR are defined by WP_Filesystem(), which is not
     * called here, so they are defined the same way when nothing defined them yet.
     * Without them saving the price list address ended in a fatal error.
     *
     * @return WP_Filesystem_Direct
     */
    private static function filesystem()
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

        if (!defined('FS_CHMOD_DIR')) {
            define('FS_CHMOD_DIR', (fileperms(ABSPATH) & 0777 | 0755));
        }
        if (!defined('FS_CHMOD_FILE')) {
            define('FS_CHMOD_FILE', (fileperms(ABSPATH . 'index.php') & 0777 | 0644));
        }

        return new WP_Filesystem_Direct(null);
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
        if (!current_user_can('manage_options') || !Woo_KigoCloud::is_notice_screen() || self::base_url() === '' || self::is_current()) {
            return;
        }
        $status = self::status();
        $detail = isset($status['message']) ? $status['message'] : '';
        echo '<div class="notice notice-warning"><p><strong>KigoCloud</strong> '
            . esc_html__('The published price list is not from today.', 'kigocloud-for-woocommerce')
            . ' ' . esc_html($detail) . '</p></div>';
    }

    /**
     * [kigo_cjenik] shows the price list the way Kigo.Cloud's public page does:
     * the heading (company, sales point), the current files (CSV, XML) and every
     * publication of the last 30 days. No table of items: the files are the
     * publication. Every part has its own class, so the theme hides or restyles
     * it with CSS (the built-in rules are not !important):
     *   .kigo-cjenik (wrapper), .kc-head, .kc-company, .kc-company-meta, .kc-logo,
     *   .kc-doc-title, .kc-doc-sub, .kc-current, .kc-archive, .kc-note
     * Attributes, all optional: header, logo, current, archive, note ="no" hide
     * that part; title="..." own title; limit="7" rows of the last 30 days;
     * style="none" drops the built-in look; class="..." extra wrapper class.
     * No call to KigoCloud happens while a page is being rendered, except the
     * once-a-day refresh of a copy that is not from today.
     *
     * @param array $atts
     * @return string
     */
    public static function shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'header'  => 'yes',
            'logo'    => 'yes',
            'title'   => '',
            'current' => 'yes',
            'archive' => 'yes',
            'limit'   => '0',
            'note'    => 'yes',
            'style'   => 'kigo',
            'class'   => '',
        ), $atts, 'kigo_cjenik');
        $on = function ($key) use ($atts) {
            return !in_array(strtolower(trim((string) $atts[$key])), array('no', 'ne', '0', 'false', 'off'), true);
        };
        self::maybe_refresh();

        $path = self::path('csv');
        if ($path === '' || !file_exists($path)) {
            return '<p>' . esc_html__('The price list is not available yet.', 'kigocloud-for-woocommerce') . '</p>';
        }
        $archive = self::archive();
        $current = !empty($archive) ? $archive[0] : null;
        $facts = $current ? self::publication_facts($current['name'], $path) : array('published' => '', 'number' => '', 'items' => 0, 'services' => 0);

        // The look of Kigo.Cloud's public price list page, scoped to .kigo-cjenik.
        $out = strtolower(trim((string) $atts['style'])) === 'none' ? '' : '<style>'
            . '.kigo-cjenik{max-width:940px;color:#1f2430;line-height:1.5;}'
            . '.kigo-cjenik .kc-head{margin-bottom:26px;}'
            . '.kigo-cjenik .kc-head-top{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;flex-wrap:wrap;}'
            . '.kigo-cjenik .kc-company{font-size:22px;font-weight:700;letter-spacing:-0.3px;}'
            . '.kigo-cjenik .kc-company-meta{color:#5b6270;font-size:14px;margin-top:4px;}'
            . '.kigo-cjenik .kc-logo img{max-height:70px;max-width:240px;}'
            . '.kigo-cjenik .kc-rule{border:0;border-top:3px solid #1f2430;margin:18px 0 0 0;}'
            . '.kigo-cjenik .kc-doc-title{font-size:19px;font-weight:700;text-align:center;margin:16px 0 4px 0;}'
            . '.kigo-cjenik .kc-doc-sub{text-align:center;color:#5b6270;font-size:14px;padding-bottom:18px;border-bottom:1px solid #e2e5ec;}'
            . '.kigo-cjenik .kc-h2{font-size:13px;text-transform:uppercase;letter-spacing:1.6px;color:#5b6270;font-weight:700;margin:0 0 12px 0;}'
            . '.kigo-cjenik .kc-card{border:1px solid #e2e5ec;border-radius:10px;padding:20px 22px;margin-bottom:34px;background:#fbfcfe;}'
            . '.kigo-cjenik .kc-facts{display:flex;flex-wrap:wrap;gap:26px;margin-bottom:18px;}'
            . '.kigo-cjenik .kc-fact span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:#8a90a0;font-weight:600;}'
            . '.kigo-cjenik .kc-fact strong{font-size:17px;font-weight:600;}'
            . '.kigo-cjenik .kc-buttons{display:flex;flex-wrap:wrap;gap:10px;}'
            . '.kigo-cjenik a.kc-btn{display:inline-block;padding:11px 22px;border-radius:7px;text-decoration:none;font-weight:600;font-size:14px;background:#1f2430;color:#fff;border:1px solid #1f2430;}'
            . '.kigo-cjenik a.kc-btn:hover{background:#333a4a;color:#fff;}'
            . '.kigo-cjenik a.kc-btn-ghost{background:#fff;color:#1f2430;}'
            . '.kigo-cjenik a.kc-btn-ghost:hover{background:#f1f3f7;color:#1f2430;}'
            . '.kigo-cjenik .kc-table-wrap{overflow-x:auto;border:1px solid #e2e5ec;border-radius:10px;}'
            . '.kigo-cjenik table.kc-table{width:100%;border-collapse:collapse;font-size:14px;background:#fff;margin:0;}'
            . '.kigo-cjenik table.kc-table th{text-align:left;background:#f6f7fa;color:#5b6270;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:1.1px;border:0;border-bottom:1px solid #e2e5ec;white-space:nowrap;}'
            . '.kigo-cjenik table.kc-table td{padding:10px 14px;border:0;border-bottom:1px solid #eef0f4;white-space:nowrap;}'
            . '.kigo-cjenik table.kc-table tr:last-child td{border-bottom:0;}'
            . '.kigo-cjenik table.kc-table td.kc-num,.kigo-cjenik table.kc-table th.kc-num{text-align:right;}'
            . '.kigo-cjenik table.kc-table a{color:#1f2430;font-weight:600;text-decoration:none;border-bottom:1px solid #c9cedb;}'
            . '.kigo-cjenik .kc-now{background:#f3f8f4;}'
            . '.kigo-cjenik .kc-badge{display:inline-block;font-size:10px;text-transform:uppercase;letter-spacing:1px;font-weight:700;color:#0d7a47;background:#ddf0e5;border-radius:4px;padding:2px 7px;margin-left:8px;}'
            . '.kigo-cjenik .kc-note{margin-top:30px;padding-top:18px;border-top:1px solid #e2e5ec;color:#6b7280;font-size:13px;}'
            . '.kigo-cjenik .kc-note p{margin:0 0 8px 0;}'
            . '@media (max-width:520px){.kigo-cjenik a.kc-btn{display:block;text-align:center;}}'
            . '</style>';
        $extra = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) $atts['class']));
        $out .= '<div class="kigo-cjenik' . ($extra !== '' ? ' ' . esc_attr($extra) : '') . '">';
        if ($on('header')) {
            $out .= self::heading_html($on('logo'), (string) $atts['title']);
        }

        if ($on('current')) {
        $out .= '<div class="kc-current">';
        $out .= '<div class="kc-h2">' . esc_html__('Current price list', 'kigocloud-for-woocommerce') . '</div>';
        $out .= '<div class="kc-card"><div class="kc-facts">';
        foreach (array(
            __('Published', 'kigocloud-for-woocommerce')      => $facts['published'],
            __('Storage number', 'kigocloud-for-woocommerce') => $facts['number'],
            __('Products', 'kigocloud-for-woocommerce')       => (string) $facts['items'],
            __('Services', 'kigocloud-for-woocommerce')       => (string) $facts['services'],
        ) as $label => $value) {
            if ($value === '') {
                continue;
            }
            $out .= '<div class="kc-fact"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
        }
        $out .= '</div><div class="kc-buttons">'
            . '<a class="kc-btn" href="' . esc_url(self::url('csv')) . '">' . esc_html__('Download CSV', 'kigocloud-for-woocommerce') . '</a>'
            . '<a class="kc-btn kc-btn-ghost" href="' . esc_url(self::url('xml')) . '">' . esc_html__('Download XML', 'kigocloud-for-woocommerce') . '</a>'
            . '</div></div>';
        $out .= '</div>';
        }

        $limit = max(0, (int) $atts['limit']);
        if ($on('archive')) {
        $out .= '<div class="kc-archive">';
        $out .= '<div class="kc-h2">' . esc_html__('Published price lists (last 30 days)', 'kigocloud-for-woocommerce') . '</div>';
        $out .= '<div class="kc-table-wrap"><table class="kc-table"><thead><tr>'
            . '<th>' . esc_html__('Published', 'kigocloud-for-woocommerce') . '</th>'
            . '<th>' . esc_html__('Storage number', 'kigocloud-for-woocommerce') . '</th>'
            . '<th class="kc-num">' . esc_html__('Products', 'kigocloud-for-woocommerce') . '</th>'
            . '<th class="kc-num">' . esc_html__('Services', 'kigocloud-for-woocommerce') . '</th>'
            . '<th>' . esc_html__('Files', 'kigocloud-for-woocommerce') . '</th>'
            . '</tr></thead><tbody>';
        $first = true;
        foreach (($limit > 0 ? array_slice($archive, 0, $limit) : $archive) as $item) {
            $f = self::publication_facts($item['name'], self::archive_path($item['name']));
            $links = array();
            foreach (array('csv', 'xml') as $format) {
                if ($item[$format] !== '') {
                    $links[] = '<a href="' . esc_url($item[$format]) . '" title="' . esc_attr($item['name'] . '.' . $format) . '">' . strtoupper($format) . '</a>';
                }
            }
            $out .= '<tr' . ($first ? ' class="kc-now"' : '') . '>'
                . '<td>' . esc_html($f['published']) . ($first ? '<span class="kc-badge">' . esc_html__('Current', 'kigocloud-for-woocommerce') . '</span>' : '') . '</td>'
                . '<td>' . esc_html($f['number']) . '</td>'
                . '<td class="kc-num">' . (int) $f['items'] . '</td>'
                . '<td class="kc-num">' . (int) $f['services'] . '</td>'
                . '<td>' . implode(' &middot; ', $links) . '</td>'
                . '</tr>';
            $first = false;
        }
        $out .= '</tbody></table></div>';
        $out .= '</div>';
        }

        if ($on('note')) {
            $out .= '<div class="kc-note">'
                . '<p>' . esc_html__('The price list holds the retail prices of the products and services of this shop, together with the anchor price that applied on the reference day.', 'kigocloud-for-woocommerce') . '</p>'
                . '<p>' . esc_html__('The price list is published every day, and every publication stays available for 30 days.', 'kigocloud-for-woocommerce') . '</p>'
                . '</div>';
        }
        $out .= '</div>';

        return $out;
    }

    /**
     * The heading of Kigo.Cloud's public price list page: company, address,
     * OIB and logo, the title and the sales point, from the licence
     * (licence/info). Only the title while Kigo.Cloud does not send them.
     *
     * @param bool   $show_logo
     * @param string $title own title, '' for the default
     * @return string
     */
    private static function heading_html($show_logo = true, $title = '')
    {
        $data = class_exists('Woo_KigoCloud_Licence') ? Woo_KigoCloud_Licence::data() : null;
        $company = ($data !== null && isset($data['company']) && is_array($data['company'])) ? $data['company'] : array();
        $point = ($data !== null && isset($data['sales_point']) && is_array($data['sales_point'])) ? $data['sales_point'] : array();
        $get = function ($a, $k) {
            return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : '';
        };
        $name = $get($company, 'name');
        $meta = trim($get($company, 'address') . ' ' . $get($company, 'place'));
        $oib = $get($company, 'oib');
        $logo = $show_logo ? $get($company, 'logo') : '';
        $object = trim($get($point, 'shape') . ' ' . $get($point, 'address'));
        $code = $get($point, 'code');

        $out = '<div class="kc-head">';
        if ($name !== '' || $logo !== '') {
            $out .= '<div class="kc-head-top"><div>';
            if ($name !== '') {
                $out .= '<div class="kc-company">' . esc_html($name) . '</div>';
            }
            if ($meta !== '' || $oib !== '') {
                $out .= '<div class="kc-company-meta">' . esc_html($meta)
                    . ($oib !== '' ? ($meta !== '' ? '<br>' : '') . 'OIB: ' . esc_html($oib) : '')
                    . '</div>';
            }
            $out .= '</div>';
            if ($logo !== '') {
                $out .= '<div class="kc-logo"><img src="' . esc_url($logo) . '" alt="' . esc_attr($name) . '"></div>';
            }
            $out .= '</div><hr class="kc-rule">';
        }
        $out .= '<div class="kc-doc-title">' . esc_html(trim($title) !== '' ? trim($title) : __('Price list of products and services', 'kigocloud-for-woocommerce')) . '</div>';
        if ($object !== '' || $code !== '') {
            $out .= '<div class="kc-doc-sub">' . esc_html($object) . ($code !== '' ? ' (' . esc_html($code) . ')' : '') . '</div>';
        }
        $out .= '</div>';

        return $out;
    }

    /**
     * Local path of an archived CSV by its base name, '' when it is not there.
     *
     * @param string $name
     * @return string
     */
    private static function archive_path($name)
    {
        $file = trailingslashit(self::dir()) . $name . '.csv';

        return is_file($file) ? $file : '';
    }

    /**
     * Publication date and storage number from the prescribed file name
     * (..._000005_20261113_080000), and the number of products and services
     * in its CSV (column Vrsta). Counts are kept per file, a file never changes.
     *
     * @param string $name base name without extension
     * @param string $csv  path of its CSV, '' when unknown
     * @return array array('published', 'number', 'items', 'services')
     */
    private static function publication_facts($name, $csv)
    {
        $facts = array('published' => '', 'number' => '', 'items' => 0, 'services' => 0);
        if (preg_match('/_(\d{6})_(\d{8})_(\d{6})$/', $name, $m)) {
            $facts['number'] = $m[1];
            $time = mktime((int) substr($m[3], 0, 2), (int) substr($m[3], 2, 2), 0, (int) substr($m[2], 4, 2), (int) substr($m[2], 6, 2), (int) substr($m[2], 0, 4));
            $facts['published'] = date_i18n(get_option('date_format') . ' H:i', $time);
        } elseif ($csv !== '') {
            $facts['published'] = date_i18n(get_option('date_format') . ' H:i', filemtime($csv));
        }
        if ($csv === '') {
            return $facts;
        }
        $key = 'kigocloud_pl_counts_' . md5($name);
        $counts = get_transient($key);
        if (!is_array($counts)) {
            $counts = array(0, 0);
            $handle = @fopen($csv, 'r');
            if ($handle) {
                $header = fgetcsv($handle, 0, ';', '"', '\\');
                $col = false;
                if (is_array($header)) {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                    $col = array_search('vrsta', array_map('strtolower', array_map('trim', $header)), true);
                }
                while ($col !== false && ($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                    $kind = isset($row[$col]) ? strtolower(trim((string) $row[$col])) : '';
                    if ($kind === 'proizvod') {
                        $counts[0]++;
                    } elseif ($kind === 'usluga') {
                        $counts[1]++;
                    }
                }
                fclose($handle);
            }
            set_transient($key, $counts, 31 * DAY_IN_SECONDS);
        }
        $facts['items'] = (int) $counts[0];
        $facts['services'] = (int) $counts[1];

        return $facts;
    }
}
