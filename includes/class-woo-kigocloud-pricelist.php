<?php
/**
 * Price list published on the shop's own domain.
 *
 * The Pravilnik o nacinu objave cjenika proizvoda (NN 132/2026, in force
 * 17 November 2026) requires the trader to publish the price list, in CSV or
 * XML, on the trader's own website. A link to a third party address is
 * arguable; a file on the trader's own domain is not. So the plugin pulls the
 * files from KigoCloud and writes them into the uploads folder, where the
 * shop's own web server serves them statically.
 *
 * What the Pravilnik asks for, and where it is handled:
 * - a price list of goods and a price list of services (cl. 3 st. 2 and 3,
 *   cl. 6 st. 3 "naznaku radi li se o cjeniku robe ili usluge"): KigoCloud
 *   publishes a ROBA file and, when the sales point has services, a USLUGE
 *   file, each as CSV and XML, listed in licence/info price_list.files. The
 *   plugin downloads every one of them.
 * - "objaviti izmijenjen cjenik najkasnije do 11.00 sati na dan izmjene"
 *   (cl. 3 st. 5): WP-Cron checks every hour at :50, and a page view checks a
 *   copy that was not checked in the last hour. The checks are conditional
 *   requests (If-None-Match, If-Modified-Since): KigoCloud answers 304 until it
 *   publishes a changed price list, so a new file appears only then.
 * - the file name with OIB, sales point, ROBA/USLUGE, storage number and
 *   GGGGMMDD_HHMM (cl. 6 st. 3): KigoCloud sends that name with the file
 *   (Content-Disposition) and the file is stored under it.
 * - a changed price list at the same place of the site (cl. 6 st. 2): the page
 *   with [kigo_cjenik] is that place; it always links the current files, each
 *   under its prescribed name, and the archive. No file is published under any
 *   other name (the cjenik-roba.csv copies of 2.2.17 are removed).
 * - "dostupnost arhivskog cjenika 15 dana od njegove zamjene" (cl. 3 st. 6):
 *   a replaced file stays in the folder for 30 days from its replacement and
 *   the shortcode links to it.
 * - automated price collection (cl. 5): the files are plain static files on
 *   the shop's domain.
 *
 * Consequences of that design, on purpose:
 * - the shop's visitors and price crawlers never touch KigoCloud
 * - KigoCloud sees at most four small conditional requests per shop per hour
 * - a KigoCloud outage does not take the published price list down
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

    /**
     * Current files and what the next check needs:
     * array('files' => array(roba|usluge => array(csv|xml => file name)),
     * 'tags' => array('roba.csv' => array('etag' => ..., 'modified' => ...)),
     * 'checked' => time of the last check without an error,
     * 'replaced' => array(file name => time it stopped being current)).
     * Up to 2.2.16: array('csv' => file name, 'xml' => file name, 'day' => Y-m-d),
     * read as the goods.
     */
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

    /** A replaced file stays available this many days from its replacement (the Pravilnik asks for 15). */
    const KEEP_DAYS = 30;

    /** A copy checked longer ago than this is checked again on a page view. */
    const CHECK_EVERY = 3600;

    /** The admin screens warn when the last good check is older than this. */
    const STALE_AFTER = 10800;

    /** Kinds of price list, in the order they are shown. */
    private static $kinds = array('roba', 'usluge');

    /** Where the optional "Price list made with Kigo.Cloud" line links to (option kigocloud_pricelist_credit). */
    const CREDIT_URL = 'https://www.kigocloud.com/sidrene-cijene-woocommerce/';

    /**
     * Cron registration and the shortcode. Called from the plugin bootstrap.
     */
    public static function init()
    {
        add_shortcode('kigo_cjenik', array(__CLASS__, 'shortcode'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_fetch'));
        add_action('init', array(__CLASS__, 'schedule'));
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        // Saving the address fetches at once, so the shop owner sees straight away
        // whether it works instead of waiting for the next hourly check.
        self::init_anchor();
    }

    /**
     * Hourly check at :50, so a price list KigoCloud changed before 10:50 is on
     * the site before the 11:00 deadline. WP-Cron only runs when the site gets
     * a visit, so maybe_refresh() covers quiet sites. The daily event of 2.2.16
     * and earlier is replaced.
     */
    public static function schedule()
    {
        if (self::base_url() === '') {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        if (wp_next_scheduled(self::CRON_HOOK) && wp_get_schedule(self::CRON_HOOK) === 'hourly') {
            return;
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
        // Croatian time differs from UTC by whole hours, so :50 UTC is :50 there.
        $now = time();
        $next = $now - ($now % HOUR_IN_SECONDS) + 50 * MINUTE_IN_SECONDS;
        if ($next <= $now) {
            $next += HOUR_IN_SECONDS;
        }
        wp_schedule_event($next, 'hourly', self::CRON_HOOK);
    }

    /**
     * The hourly event: always asks KigoCloud (a conditional request, answered
     * 304 while nothing changed), whatever a page view checked meanwhile.
     */
    public static function cron_fetch()
    {
        self::fetch(true);
    }

    /**
     * The addresses changed: the next fetch starts a new current version. Files
     * already published stay and count as replaced now, the Pravilnik wants them
     * available after their replacement.
     */
    public static function on_url_saved()
    {
        $current = self::current();
        foreach (self::current_names() as $name) {
            $current['replaced'][$name] = time();
        }
        $current['files'] = array();
        $current['tags'] = array();
        $current['checked'] = 0;
        update_option(self::OPTION_CURRENT, $current, false);
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
     * Current state as stored by the last fetch, with the layout of 2.2.16 and
     * earlier (one csv and xml) read as the goods.
     *
     * @return array
     */
    private static function current()
    {
        $current = get_option(self::OPTION_CURRENT, array());
        if (!is_array($current)) {
            $current = array();
        }
        if (!isset($current['files']) || !is_array($current['files'])) {
            $current['files'] = array();
            foreach (array('csv', 'xml') as $format) {
                if (!empty($current[$format])) {
                    $current['files']['roba'][$format] = (string) $current[$format];
                }
            }
        }
        unset($current['csv'], $current['xml'], $current['day']);
        foreach (array('tags', 'replaced') as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = array();
            }
        }
        $current['checked'] = isset($current['checked']) ? (int) $current['checked'] : 0;

        return $current;
    }

    /**
     * File names (with extension) of the current files.
     *
     * @return array
     */
    private static function current_names()
    {
        $names = array();
        foreach (self::current()['files'] as $formats) {
            foreach ((array) $formats as $name) {
                if ($name !== '') {
                    $names[] = (string) $name;
                }
            }
        }

        return $names;
    }

    /**
     * Kinds with a current file, in the order they are shown.
     *
     * @return array roba and/or usluge
     */
    public static function current_kinds()
    {
        $files = self::current()['files'];

        return array_values(array_filter(self::$kinds, function ($kind) use ($files) {
            return !empty($files[$kind]['csv']) || !empty($files[$kind]['xml']);
        }));
    }

    /**
     * Absolute path of the current file of a format, or '' when there is none.
     *
     * @param string $format csv|xml
     * @param string $kind   roba (goods) or usluge (services)
     * @return string
     */
    public static function path($format, $kind = 'roba')
    {
        $files = self::current()['files'];
        if (empty($files[$kind][$format])) {
            return '';
        }

        return trailingslashit(self::dir()) . $files[$kind][$format];
    }

    /**
     * Public address of the current file of a format, on the shop's own domain.
     *
     * @param string $format csv|xml
     * @param string $kind   roba (goods) or usluge (services)
     * @return string
     */
    public static function url($format, $kind = 'roba')
    {
        $files = self::current()['files'];
        if (empty($files[$kind][$format])) {
            return '';
        }

        return self::file_url($files[$kind][$format]);
    }

    /**
     * Helper name of a copy that 2.2.17 kept next to the named files
     * (cjenik-roba.csv, cjenik-roba.xml, cjenik-usluge.csv, cjenik-usluge.xml);
     * only used to remove those copies.
     *
     * @param string $format csv|xml
     * @param string $kind   roba|usluge
     * @return string
     */
    public static function stable_name($format, $kind = 'roba')
    {
        return 'cjenik-' . $kind . '.' . $format;
    }

    /**
     * @return array every stable name, current or not
     */
    private static function stable_names()
    {
        $names = array();
        foreach (self::$kinds as $kind) {
            foreach (array('csv', 'xml') as $format) {
                $names[] = self::stable_name($format, $kind);
            }
        }

        return $names;
    }

    /**
     * Removes the copies under helper names (cjenik-roba.csv and so on) that
     * 2.2.17 kept: the Pravilnik (cl. 6 st. 3) wants every published file under
     * its prescribed name, so only the named files stay.
     */
    private static function remove_stable()
    {
        $dir = trailingslashit(self::dir());
        foreach (self::stable_names() as $name) {
            if (is_file($dir . $name)) {
                wp_delete_file($dir . $name);
            }
        }
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
     * Time of the last check with KigoCloud that ended without an error, 0 before the first one.
     *
     * @return int
     */
    public static function checked()
    {
        return self::current()['checked'];
    }

    /**
     * Was the copy checked with KigoCloud in the last hour, and is the goods
     * price list on disk? A changed price list must be on the site by 11:00 of
     * the day of the change, so the copy is checked every hour.
     *
     * @param int $age seconds, CHECK_EVERY by default
     * @return bool
     */
    public static function is_current($age = self::CHECK_EVERY)
    {
        return self::checked() > time() - $age && self::has_files();
    }

    /**
     * Is at least one current price list (goods or services) on disk as CSV?
     *
     * @return bool
     */
    public static function has_files()
    {
        foreach (self::current_kinds() as $kind) {
            $path = self::path('csv', $kind);
            if ($path !== '' && file_exists($path)) {
                return true;
            }
        }

        return false;
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
     * Asks KigoCloud for every file of the price list (goods and services, CSV
     * and XML, from licence/info price_list.files) with a conditional request.
     * KigoCloud answers 304 while the publication did not change; a changed
     * one comes with its new prescribed name and replaces the current file of
     * its kind and format. A kind KigoCloud no longer lists (the services price
     * list of a sales point without services) or answers 404 for stops being
     * current; its files stay in the archive.
     *
     * @param bool $force ask KigoCloud even when the copy was checked in the last hour (cron, admin)
     * @return array status array as stored in OPTION_STATUS
     */
    public static function fetch($force = false)
    {
        $base = self::base_url();
        if ($base === '') {
            return self::store_status('error', __('The KigoCloud price list address is not set.', 'kigocloud-for-woocommerce'));
        }
        if (!$force && self::is_current()) {
            return self::store_status('ok', __('The price list is already up to date.', 'kigocloud-for-woocommerce'));
        }

        $dir = self::dir();
        if (!wp_mkdir_p($dir)) {
            return self::store_status('error', __('The price list folder could not be created.', 'kigocloud-for-woocommerce'));
        }

        $current = self::current();
        $listed = array();
        $fetched = 0;
        $error = '';
        foreach (Woo_KigoCloud_Licence::price_list_files() as $file) {
            $kind = $file['kind'];
            $format = $file['format'];
            $slot = $kind . '.' . $format;
            $listed[$slot] = true;
            $old = isset($current['files'][$kind][$format]) ? (string) $current['files'][$kind][$format] : '';
            $path = $old !== '' ? trailingslashit($dir) . $old : '';
            $args = array('timeout' => 30, 'headers' => array());
            if ($path !== '' && file_exists($path)) {
                // Nothing changed since our copy: KigoCloud answers 304 with no body.
                $tag = isset($current['tags'][$slot]) ? (array) $current['tags'][$slot] : array();
                if (!empty($tag['etag'])) {
                    $args['headers']['If-None-Match'] = (string) $tag['etag'];
                }
                $args['headers']['If-Modified-Since'] = !empty($tag['modified'])
                    ? (string) $tag['modified']
                    : gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';
            }
            $response = wp_remote_get($file['url'], $args);
            if (is_wp_error($response)) {
                $error = $response->get_error_message();
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 304) {
                continue;
            }
            if ($code === 404 && $kind === 'usluge') {
                // No services price list (any more): the sales point has no services.
                unset($listed[$slot]);
                continue;
            }
            if ($code !== 200) {
                $error = sprintf(
                    /* translators: %d: HTTP status code */
                    __('KigoCloud answered with status %d. Check the address and your plan.', 'kigocloud-for-woocommerce'),
                    $code
                );
                continue;
            }
            $body = wp_remote_retrieve_body($response);
            if ($body === '') {
                $error = __('KigoCloud returned an empty price list.', 'kigocloud-for-woocommerce');
                continue;
            }
            $name = self::file_name($response, $format);
            if ($name === '') {
                $error = __('KigoCloud did not send the prescribed file name. Update KigoCloud or contact support.', 'kigocloud-for-woocommerce');
                continue;
            }
            $target = trailingslashit($dir) . $name;
            // Written aside and moved into place, so a visitor never gets half a file.
            $fs = self::filesystem();
            if (!$fs->put_contents($target . '.tmp', $body, FS_CHMOD_FILE) || !$fs->move($target . '.tmp', $target, true)) {
                $error = __('The price list could not be saved in the uploads folder.', 'kigocloud-for-woocommerce');
                continue;
            }
            if ($old !== '' && $old !== $name) {
                $current['replaced'][$old] = time();
            }
            $current['files'][$kind][$format] = $name;
            $current['tags'][$slot] = array(
                'etag'     => (string) wp_remote_retrieve_header($response, 'etag'),
                'modified' => (string) wp_remote_retrieve_header($response, 'last-modified'),
            );
            $fetched++;
        }
        // Kinds KigoCloud does not publish any more stop being current.
        foreach ($current['files'] as $kind => $formats) {
            foreach ((array) $formats as $format => $name) {
                if ($error === '' && !isset($listed[$kind . '.' . $format])) {
                    $current['replaced'][(string) $name] = time();
                    unset($current['files'][$kind][$format], $current['tags'][$kind . '.' . $format]);
                }
            }
            if (empty($current['files'][$kind])) {
                unset($current['files'][$kind]);
            }
        }
        if ($error === '') {
            $current['checked'] = time();
        }
        update_option(self::OPTION_CURRENT, $current, false);
        self::remove_stable();
        self::purge();

        if ($error !== '') {
            return self::store_status('error', $error);
        }

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
     * shows. Anchor prices are in every Kigo.Cloud package. Read every hour, on
     * a page view when WP-Cron did not run for an hour, when the settings are
     * saved, and for its SKU when a product or variation is saved; nothing is
     * typed in by hand.
     */

    /** Last read: array('time', 'count', 'reason', 'message'). Not autoloaded. */
    const OPTION_ANCHOR_SOURCE = 'kigocloud_anchor_source';

    /** Hourly refresh of the anchor prices (daily at 08:15 up to 2.3.1). */
    const ANCHOR_CRON = 'kigocloud_anchor_refresh';

    /** Anchor prices read longer ago than this are read again on a page view. */
    const ANCHOR_EVERY = 3600;

    /** More saved SKUs than this in one request read the whole list instead, one call per page. */
    const ANCHOR_SKU_CALLS = 5;

    /** @var string[] SKUs of the variations saved in this request, read at its end */
    private static $saved_skus = array();

    /**
     * Hooks of the anchor prices. Called from init().
     */
    private static function init_anchor()
    {
        add_action(self::ANCHOR_CRON, array(__CLASS__, 'cron_refresh'));
        add_action('init', array(__CLASS__, 'schedule_anchor'));
        // WP-Cron runs only when the site gets a visit and is often switched off
        // or blocked by a page cache: then a page view reads what is overdue.
        add_action('shutdown', array(__CLASS__, 'on_shutdown'), 1000);
        // A saved product reads its own base price at once, and its edit screen
        // says which base price the shop shows and where it comes from.
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'on_product_saved'), 99);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'on_variation_saved'), 99);
        add_action('woocommerce_product_options_pricing', array(__CLASS__, 'product_anchor_field'));
        add_action('woocommerce_variation_options_pricing', array(__CLASS__, 'variation_anchor_field'), 20, 3);
        add_action('admin_notices', array(__CLASS__, 'anchor_notice'));
        add_action('admin_notices', array(__CLASS__, 'missing_notice'));
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
     * Hooked on `init`: the hourly refresh while the anchor price is shown. The
     * daily event of 2.3.1 and earlier is replaced.
     */
    public static function schedule_anchor()
    {
        if (!self::anchor_wanted()) {
            wp_clear_scheduled_hook(self::ANCHOR_CRON);
            return;
        }
        if (wp_next_scheduled(self::ANCHOR_CRON) && wp_get_schedule(self::ANCHOR_CRON) === 'hourly') {
            return;
        }
        wp_clear_scheduled_hook(self::ANCHOR_CRON);
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::ANCHOR_CRON);
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
     * The hourly refresh. A login Kigo.Cloud refused at the last check is not
     * tried again every hour (each try is a refused login in Kigo.Cloud's
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

    /** Published products and variations of this shop without a base price, after the last read. */
    const OPTION_ANCHOR_MISSING = 'kigocloud_anchor_missing';

    /** Products named in the warning; the rest are counted. */
    const MISSING_LISTED = 5;

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
        if ($count > 0) {
            self::store_missing();
        } else {
            delete_option(self::OPTION_ANCHOR_MISSING);
        }

        return $count;
    }

    /**
     * Finds the published products and variations that have a price but no
     * base price (no meta from Kigo.Cloud, and no Kigo.Cloud item with their
     * SKU or GTIN) and keeps them for the warning (missing_notice). A product
     * shows no base price line then, and the shop owner would not know it
     * without opening each product. Direct queries, so a shop with many
     * products is read in a few calls.
     */
    private static function store_missing()
    {
        global $wpdb;
        // Without a good read every product would look like one without a base price.
        $source = self::anchor_source();
        if (!isset($source['time']) || (isset($source['reason']) && $source['reason'] !== '') || !self::anchor_wanted()) {
            delete_option(self::OPTION_ANCHOR_MISSING);
            return;
        }
        $products = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'", OBJECT_K);
        if (empty($products)) {
            delete_option(self::OPTION_ANCHOR_MISSING);
            return;
        }
        // Variable and grouped products have no price of their own (a variable one is judged by its variations).
        $no_price_types = $wpdb->get_col(
            "SELECT tr.object_id FROM {$wpdb->term_relationships} tr"
            . " JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . " JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
            . " WHERE tt.taxonomy = 'product_type' AND t.slug IN ('variable', 'grouped')"
        );
        $no_price_types = array_flip(array_map('intval', $no_price_types));
        $rows = array();
        foreach ($products as $id => $product) {
            if (!isset($no_price_types[(int) $id])) {
                $rows[(int) $id] = array('id' => (int) $id, 'parent' => 0, 'title' => (string) $product->post_title);
            }
        }
        $variations = $wpdb->get_results("SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish'");
        $siblings = array();
        foreach ($variations as $variation) {
            $parent = (int) $variation->post_parent;
            if (isset($products[$parent])) {
                $rows[(int) $variation->ID] = array('id' => (int) $variation->ID, 'parent' => $parent, 'title' => (string) $products[$parent]->post_title);
                $siblings[$parent] = isset($siblings[$parent]) ? $siblings[$parent] + 1 : 1;
            }
        }
        $meta = array();
        $ids = array_merge(array_keys($rows), array_keys($products));
        foreach (array_chunk(array_unique($ids), 1000) as $chunk) {
            $found = $wpdb->get_results(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}"
                . " WHERE meta_key IN ('_sku', '_price', '_global_unique_id', '_kigo_anchor_price')"
                . ' AND post_id IN (' . implode(',', array_map('intval', $chunk)) . ')'
            );
            foreach ($found as $one) {
                $meta[(int) $one->post_id][$one->meta_key] = (string) $one->meta_value;
            }
        }
        $get = function ($id, $key) use ($meta) {
            return isset($meta[$id][$key]) ? trim($meta[$id][$key]) : '';
        };
        $missing = array();
        foreach ($rows as $id => $row) {
            if ($get($id, '_price') === '') {
                continue;
            }
            $anchor = $get($id, '_kigo_anchor_price');
            if ($anchor !== '' && is_numeric($anchor)) {
                continue;
            }
            $sku = $get($id, '_sku');
            // The product's SKU only for its only variation (sku_of).
            if ($sku === '' && $row['parent'] > 0 && $siblings[$row['parent']] === 1) {
                $sku = $get($row['parent'], '_sku');
            }
            if (self::anchor_for_codes($sku, $get($id, '_global_unique_id')) !== null) {
                continue;
            }
            // One entry per product: its SKUs Kigo.Cloud does not know, and whether a variation has none.
            $product_id = $row['parent'] > 0 ? $row['parent'] : $id;
            if (!isset($missing[$product_id])) {
                $missing[$product_id] = array('id' => $product_id, 'title' => $row['title'], 'skus' => array(), 'no_sku' => false);
            }
            if ($sku === '') {
                $missing[$product_id]['no_sku'] = true;
            } elseif (count($missing[$product_id]['skus']) < 3 && !in_array($sku, $missing[$product_id]['skus'], true)) {
                $missing[$product_id]['skus'][] = $sku;
            }
        }
        update_option(self::OPTION_ANCHOR_MISSING, array(
            'time' => time(),
            'count' => count($missing),
            'items' => array_slice(array_values($missing), 0, self::MISSING_LISTED),
        ), false);
    }

    /**
     * Hooked on `admin_notices`: names the published products that show no
     * base price, and why (the SKU is not an item code in Kigo.Cloud), so the
     * shop owner can fix the SKU or the item in Kigo.Cloud. Gone once the next
     * read finds a base price for all of them.
     */
    public static function missing_notice()
    {
        if (!current_user_can('manage_options') || !Woo_KigoCloud::is_notice_screen() || !self::anchor_wanted()) {
            return;
        }
        $missing = get_option(self::OPTION_ANCHOR_MISSING, array());
        if (!is_array($missing) || empty($missing['count'])) {
            return;
        }
        $names = array();
        foreach ((array) $missing['items'] as $item) {
            if (!isset($item['skus'])) {
                continue;
            }
            $why = array();
            if (!empty($item['skus'])) {
                /* translators: %s: SKU or SKUs */
                $why[] = sprintf(esc_html__('SKU %s', 'kigocloud-for-woocommerce'), esc_html(implode(', ', (array) $item['skus'])));
            }
            if (!empty($item['no_sku'])) {
                $why[] = esc_html__('variation without its own SKU', 'kigocloud-for-woocommerce');
            }
            $name = esc_html((string) $item['title']) . ' (' . implode('; ', $why) . ')';
            $link = get_edit_post_link((int) $item['id']);
            $names[] = $link ? '<a href="' . esc_url($link) . '">' . $name . '</a>' : $name;
        }
        $more = (int) $missing['count'] - count($names);
        $list = implode(', ', $names);
        if ($more > 0) {
            /* translators: 1: list of products, 2: number of other products */
            $list = sprintf(esc_html__('%1$s and %2$d more', 'kigocloud-for-woocommerce'), $list, $more);
        }
        $text = sprintf(
            /* translators: 1: number of products, 2: list of products */
            esc_html__('Products in the shop without a base price: %1$d. Kigo.Cloud has no item with their SKU or GTIN, or a variation has no SKU of its own: %2$s.', 'kigocloud-for-woocommerce'),
            (int) $missing['count'],
            $list
        );
        $help = esc_html__('The SKU of a product and of each variation must be the same as the item code (šifra) in Kigo.Cloud: correct the SKU here or the code in Kigo.Cloud, or link the product to its items in Kigo.Cloud (WooCommerce, Spajanje s trgovinom).', 'kigocloud-for-woocommerce');
        echo '<div class="notice notice-warning"><p><strong>KigoCloud</strong> ' . $text . ' ' . $help . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
    }

    /**
     * Hooked on `shutdown`: reads the base prices of the variations saved in
     * this request, otherwise reads what WP-Cron left overdue (the base prices
     * and the published price list, each after an hour). A page view answers
     * the visitor first where PHP-FPM allows it. Skipped in WP-Cron's own
     * request, which runs the events itself.
     */
    public static function on_shutdown()
    {
        if (!empty(self::$saved_skus)) {
            $skus = array_keys(self::$saved_skus);
            self::$saved_skus = array();
            self::read_skus($skus);
            return;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }
        $anchor = self::anchor_overdue();
        $pricelist = self::base_url() !== '' && !self::is_current();
        if (!$anchor && !$pricelist) {
            return;
        }
        if (!is_admin() && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        // One request does the work; the others render what is stored.
        if ($anchor && !get_transient('kigocloud_anchor_lock')) {
            set_transient('kigocloud_anchor_lock', 1, 300);
            self::cron_refresh();
            delete_transient('kigocloud_anchor_lock');
        }
        if ($pricelist) {
            self::maybe_refresh();
        }
    }

    /**
     * @return bool the base prices are shown and were last read (or tried) more
     *              than an hour ago, with a login Kigo.Cloud did not refuse
     */
    private static function anchor_overdue()
    {
        if (!self::anchor_wanted()) {
            return false;
        }
        $check = Woo_KigoCloud_Request::login_check();
        if (isset($check['ok']) && !$check['ok']) {
            return false;
        }
        $source = self::anchor_source();

        return !isset($source['time']) || (time() - (int) $source['time']) > self::ANCHOR_EVERY;
    }

    /**
     * Hooked on `woocommerce_process_product_meta`: the saved product reads its
     * base price from Kigo.Cloud by its SKU before the edit screen opens again.
     *
     * @param int $product_id
     */
    public static function on_product_saved($product_id)
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($product && !$product->is_type('variable') && self::anchor_wanted()) {
            $sku = trim((string) $product->get_sku());
            if ($sku !== '') {
                self::read_skus(array($sku));
            }
        }
    }

    /**
     * Hooked on `woocommerce_save_product_variation`: collects the SKU (the
     * parent's when the variation has none); all are read once at the end of
     * the request.
     *
     * @param int $variation_id
     */
    public static function on_variation_saved($variation_id)
    {
        if (!self::anchor_wanted()) {
            return;
        }
        $sku = trim((string) get_post_meta($variation_id, '_sku', true));
        if ($sku === '') {
            $sku = self::only_variation_sku((int) wp_get_post_parent_id($variation_id), (int) $variation_id);
        }
        if ($sku !== '') {
            self::$saved_skus[$sku] = true;
        }
    }

    /**
     * Reads the base price of these SKUs from Kigo.Cloud (item/getByReference),
     * or the whole list when there are more than a few. An SKU Kigo.Cloud does
     * not know loses its base price; a failed call keeps what was read before.
     *
     * @param string[] $skus
     */
    private static function read_skus(array $skus)
    {
        $check = Woo_KigoCloud_Request::login_check();
        if (isset($check['ok']) && !$check['ok']) {
            return;
        }
        if (count($skus) > self::ANCHOR_SKU_CALLS) {
            self::refresh_anchor_prices();
            return;
        }
        $index = get_option(self::OPTION_ANCHOR, array());
        if (!is_array($index)) {
            $index = array();
        }
        foreach (array('ref', 'barcode', 'checked') as $key) {
            if (!isset($index[$key]) || !is_array($index[$key])) {
                $index[$key] = array();
            }
        }
        foreach ($skus as $sku) {
            $key = self::anchor_key($sku);
            $answer = Woo_KigoCloud_Request::call('GET', 'item/getByReference?reference=' . rawurlencode($sku));
            if (is_wp_error($answer)) {
                if ($answer->get_error_code() !== 'kigocloud_404') {
                    continue;
                }
                unset($index['ref'][$key]);
            } else {
                // item/getByReference answers with the item itself, item/list wraps rows in data.
                $entry = self::newest_anchor((isset($answer->data) && is_object($answer->data)) ? $answer->data : $answer);
                if ($entry === null) {
                    unset($index['ref'][$key]);
                } else {
                    $index['ref'][$key] = $entry;
                }
            }
            $index['checked'][$key] = time();
        }
        update_option(self::OPTION_ANCHOR, $index, false);
        self::$anchor_index = null;
        self::store_missing();
    }

    /**
     * Hooked on `woocommerce_product_options_pricing`: under the prices of a
     * simple product, which base price the shop shows and where it comes from.
     */
    public static function product_anchor_field()
    {
        global $product_object;
        if ($product_object instanceof WC_Product && !$product_object->is_type('variable')) {
            echo self::anchor_field_html($product_object, (string) $product_object->get_sku()); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in anchor_field_html()
        }
    }

    /**
     * Hooked on `woocommerce_variation_options_pricing`.
     *
     * @param int     $loop
     * @param array   $variation_data
     * @param WP_Post $variation
     */
    public static function variation_anchor_field($loop, $variation_data, $variation)
    {
        $product = function_exists('wc_get_product') ? wc_get_product($variation->ID) : null;
        if (!$product) {
            return;
        }
        echo self::anchor_field_html($product, self::sku_of($product)); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in anchor_field_html()
    }

    /**
     * One line on the product edit screen: the base price and where it comes
     * from, or what to do so the shop shows one.
     *
     * @param WC_Product $product
     * @param string     $sku SKU the base price is looked up by
     * @return string HTML
     */
    private static function anchor_field_html($product, $sku)
    {
        if ('1' !== (string) get_option('kigocloud_show_anchor_price', '0')) {
            return '';
        }
        $sku = trim($sku);
        $meta = $product->get_meta('_kigo_anchor_price', true);
        $ok = true;
        if ($meta !== '' && $meta !== null && is_numeric($meta)) {
            $text = sprintf(
                /* translators: 1: amount, 2: date */
                __('%1$s (%2$s), sent by Kigo.Cloud with the product sync.', 'kigocloud-for-woocommerce'),
                self::admin_amount($meta),
                self::admin_date((string) $product->get_meta('_kigo_anchor_price_date', true))
            );
        } elseif ($sku === '') {
            $ok = false;
            $text = __('None. Enter the SKU: it must be the same as the item code (šifra) in Kigo.Cloud, the base price is taken from that item.', 'kigocloud-for-woocommerce');
        } else {
            $found = self::anchor_for_codes($sku, is_callable(array($product, 'get_global_unique_id')) ? (string) $product->get_global_unique_id() : '');
            $read = self::sku_read_time($sku);
            if ($found !== null) {
                $text = sprintf(
                    /* translators: 1: amount, 2: date, 3: item code, 4: date and time */
                    __('%1$s (%2$s), from the item %3$s in Kigo.Cloud, read %4$s.', 'kigocloud-for-woocommerce'),
                    self::admin_amount($found[0]),
                    self::admin_date((string) $found[1]),
                    $sku,
                    $read
                );
            } else {
                $ok = false;
                $text = sprintf(
                    /* translators: 1: item code, 2: date and time */
                    __('None. Kigo.Cloud has no item with the code %1$s, or the item has no base price (read %2$s). The SKU must be the same as the item code (šifra) in Kigo.Cloud; saving the product reads it again.', 'kigocloud-for-woocommerce'),
                    $sku,
                    $read
                );
            }
        }

        return '<p class="form-field kigocloud-anchor-field"><label>' . esc_html__('Base price', 'kigocloud-for-woocommerce') . '</label>'
            . '<span style="display:inline-block;max-width:32em;' . ($ok ? '' : 'color:#b32d2e;') . '">'
            . esc_html($text) . '</span></p>';
    }

    /**
     * @param string $sku
     * @return string when the base price of this SKU was last read, for the admin
     */
    private static function sku_read_time($sku)
    {
        $index = get_option(self::OPTION_ANCHOR, array());
        $key = self::anchor_key($sku);
        $time = (is_array($index) && isset($index['checked'][$key])) ? (int) $index['checked'][$key] : 0;
        $source = self::anchor_source();
        if (isset($source['time']) && (int) $source['time'] > $time && (!isset($source['reason']) || $source['reason'] === '')) {
            $time = (int) $source['time'];
        }
        if ($time === 0) {
            return __('never', 'kigocloud-for-woocommerce');
        }

        return wp_date('j. n. Y. H:i', $time);
    }

    /**
     * @param mixed $amount
     * @return string the amount as Kigo.Cloud keeps it, with VAT
     */
    private static function admin_amount($amount)
    {
        $text = function_exists('wc_price') ? wp_strip_all_tags(wc_price((float) $amount)) : number_format((float) $amount, 2, ',', '.');

        return sprintf(
            /* translators: %s: amount */
            __('%s with VAT', 'kigocloud-for-woocommerce'),
            html_entity_decode($text, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * @param string $date Y-m-d
     * @return string
     */
    private static function admin_date($date)
    {
        $time = strtotime($date);

        return $time ? wp_date('j. n. Y.', $time, new DateTimeZone('UTC')) : '';
    }

    /**
     * Hooked on `admin_notices`: warns when the base prices were not read from
     * Kigo.Cloud for more than three hours, so a changed or new base price is
     * not missing from the shop unnoticed.
     */
    public static function anchor_notice()
    {
        if (!current_user_can('manage_options') || !Woo_KigoCloud::is_notice_screen() || !self::anchor_wanted()) {
            return;
        }
        $source = self::anchor_source();
        $reason = isset($source['reason']) ? (string) $source['reason'] : '';
        $good = isset($source['time']) && $reason === '';
        // No base prices in Kigo.Cloud or not read yet: the settings page says so.
        if (($good && (time() - (int) $source['time']) <= self::STALE_AFTER) || (!$good && $reason !== 'error')) {
            return;
        }
        if ($good) {
            $text = sprintf(
                /* translators: %s: date and time */
                __('The base prices were last read from Kigo.Cloud on %s, so a changed or new base price may be missing from the shop. Click Save all settings on the Kigo.Cloud page to read them now.', 'kigocloud-for-woocommerce'),
                wp_date('j. n. Y. H:i', (int) $source['time'])
            );
        } else {
            $text = __('The base prices could not be read from Kigo.Cloud. The Kigo.Cloud page says why.', 'kigocloud-for-woocommerce');
        }
        echo '<div class="notice notice-warning"><p><strong>KigoCloud</strong> ' . esc_html($text) . '</p></div>';
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

        return array((float) $last->price, isset($last->date) ? (string) $last->date : '',
            isset($last->reference_date) ? (string) $last->reference_date : '');
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
        $gtin = is_callable(array($product, 'get_global_unique_id')) ? (string) $product->get_global_unique_id('edit') : '';

        return self::anchor_for_codes(self::sku_of($product), $gtin);
    }

    /**
     * SKU a product or variation is matched by. A variation without its own
     * SKU shows its product's SKU in WooCommerce, but that SKU is the item of
     * one variation at most: taken only when the product has no other
     * variation, otherwise every variation would show the base price of that
     * one item (a printer's variations Fan filter and Ink with the printer's
     * base price, or a printer with the base price of an ink coded like the
     * product). Such a variation needs its own SKU, the warning on the admin
     * screens names it.
     *
     * @param WC_Product $product
     * @return string
     */
    public static function sku_of($product)
    {
        if (!$product->get_parent_id()) {
            return (string) $product->get_sku();
        }
        $own = trim((string) $product->get_sku('edit'));
        if ($own !== '') {
            return $own;
        }

        return self::only_variation_sku((int) $product->get_parent_id(), (int) $product->get_id());
    }

    /**
     * @param int $parent_id variable product
     * @param int $variation_id
     * @return string the product's SKU when this is its only published variation, otherwise ''
     */
    public static function only_variation_sku($parent_id, $variation_id)
    {
        $children = get_posts(array(
            'post_type' => 'product_variation',
            'post_parent' => $parent_id,
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => 2,
            'suppress_filters' => true,
        ));
        if (count($children) !== 1 || (int) $children[0] !== $variation_id) {
            return '';
        }

        return trim((string) get_post_meta($parent_id, '_sku', true));
    }

    /**
     * Base price from Kigo.Cloud for an SKU, then a GTIN/EAN.
     *
     * @param string $sku
     * @param string $gtin
     * @return array|null array(price, date) or null when there is none
     */
    public static function anchor_for_codes($sku, $gtin)
    {
        if (self::$anchor_index === null) {
            $index = get_option(self::OPTION_ANCHOR, null);
            self::$anchor_index = is_array($index) ? $index : array();
        }
        if (empty(self::$anchor_index)) {
            return null;
        }
        $sku = self::anchor_key($sku);
        if ($sku !== '' && isset(self::$anchor_index['ref'][$sku])) {
            return self::$anchor_index['ref'][$sku];
        }
        $gtin = self::anchor_key($gtin);
        if ($gtin !== '' && isset(self::$anchor_index['barcode'][$gtin])) {
            return self::$anchor_index['barcode'][$gtin];
        }

        return null;
    }

    /**
     * Removes files replaced more than 30 days ago, never the current ones. The
     * Pravilnik counts the 15 days from the replacement, and a price list can
     * stay current for months, so the time of its replacement counts, not the
     * time it was published. Files replaced before 2.2.17 (one a day, no time
     * of replacement kept) count from their file time. Also removes the
     * cjenik.csv and cjenik.xml that version 2.1.17 wrote without the
     * prescribed name.
     */
    private static function purge()
    {
        $current = self::current();
        $keep = self::current_names();
        $limit = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
        $present = array();
        foreach ((array) glob(trailingslashit(self::dir()) . '*') as $file) {
            $name = basename($file);
            if (!is_file($file) || in_array($name, $keep, true)) {
                continue;
            }
            $legacy = in_array($name, array('cjenik.csv', 'cjenik.xml'), true);
            $since = isset($current['replaced'][$name]) ? (int) $current['replaced'][$name] : filemtime($file);
            if ($legacy || $since < $limit) {
                wp_delete_file($file);
                continue;
            }
            $present[$name] = true;
        }
        // Forget the replacement times of files that are gone.
        $replaced = array_intersect_key($current['replaced'], $present);
        if (count($replaced) !== count($current['replaced'])) {
            $current['replaced'] = $replaced;
            update_option(self::OPTION_CURRENT, $current, false);
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
     * What the prescribed file name says, for both formats KigoCloud used:
     * - since 17 November 2026 (Pravilnik, cl. 6 st. 3):
     *   {OIB}_{oblik}_{adresa}_{oznaka}_{ROBA|USLUGE}_{nnnnnn}_{YYYYMMDD_HHMM}
     * - up to 16 November 2026: {...}_{nnnnnn}_{YYYYMMDD_HHMMSS}, goods and
     *   services in one file (kind '').
     * The goods and services files of one publication carry the same storage
     * number and are one publication ('group').
     *
     * @param string $name base name without extension
     * @return array array('kind' => roba|usluge|'', 'number' => nnnnnn|'', 'stamp' => YmdHis|'', 'group' => key)
     */
    private static function parse_name($name)
    {
        if (preg_match('/^(.*)_(ROBA|USLUGE)_(\d{6})_(\d{8})_(\d{4})$/', $name, $m)) {
            return array('kind' => strtolower($m[2]), 'number' => $m[3], 'stamp' => $m[4] . $m[5] . '00', 'group' => $m[1] . '_' . $m[3]);
        }
        if (preg_match('/_(\d{6})_(\d{8})_(\d{6})$/', $name, $m)) {
            return array('kind' => '', 'number' => $m[1], 'stamp' => $m[2] . $m[3], 'group' => $name);
        }

        return array('kind' => '', 'number' => '', 'stamp' => '', 'group' => $name);
    }

    /**
     * Published files still in the folder (the current ones and those replaced
     * in the last 30 days), one entry per publication, newest first. The goods
     * and services files of a publication, each as CSV and XML, are one entry.
     *
     * @return array list of array('number', 'sort', 'time', 'current' => bool,
     *               'files' => array(roba|usluge|'' => array('name' => base name, 'csv' => url|'', 'xml' => url|'')))
     */
    public static function archive()
    {
        $current = self::current_names();
        $list = array();
        foreach ((array) glob(trailingslashit(self::dir()) . '*') as $file) {
            if (!is_file($file)) {
                continue;
            }
            $name = basename($file);
            $format = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (($format !== 'csv' && $format !== 'xml') || $name === 'cjenik.csv' || $name === 'cjenik.xml' || in_array($name, self::stable_names(), true)) {
                continue;
            }
            $base = substr($name, 0, -strlen($format) - 1);
            $parsed = self::parse_name($base);
            $group = $parsed['group'];
            if (!isset($list[$group])) {
                // A name without the storage number and time falls back to the file time.
                $list[$group] = array(
                    'number'  => $parsed['number'],
                    'sort'    => $parsed['stamp'] !== '' ? $parsed['stamp'] : gmdate('YmdHis', filemtime($file)),
                    'time'    => filemtime($file),
                    'current' => false,
                    'files'   => array(),
                );
            }
            $kind = $parsed['kind'];
            if (!isset($list[$group]['files'][$kind])) {
                $list[$group]['files'][$kind] = array('name' => $base, 'csv' => '', 'xml' => '');
            }
            $list[$group]['files'][$kind][$format] = self::file_url($name);
            if ($parsed['stamp'] > $list[$group]['sort']) {
                $list[$group]['sort'] = $parsed['stamp'];
            }
            if (in_array($name, $current, true)) {
                $list[$group]['current'] = true;
            }
        }
        // The time in the name orders the publications, also across a change
        // of the outlet's address; the storage number breaks a tie.
        uasort($list, function ($a, $b) {
            $order = strcmp($b['sort'], $a['sort']);

            return $order !== 0 ? $order : strcmp($b['number'], $a['number']);
        });
        foreach ($list as $group => $item) {
            $files = array();
            foreach (array('roba', 'usluge', '') as $kind) {
                if (isset($item['files'][$kind])) {
                    $files[$kind] = $item['files'][$kind];
                }
            }
            $list[$group]['files'] = $files;
        }

        return array_values($list);
    }

    /**
     * Checks a copy that was not checked in the last hour on a normal page
     * view, so sites with little traffic (where WP-Cron rarely fires) still
     * publish a changed price list on the day of the change.
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
     * Warns in wp-admin when the copy was not checked with KigoCloud for three
     * hours, instead of letting it age silently: a changed price list that is
     * not on the site by 11:00 is a breach, and nobody would notice.
     */
    public static function admin_notice()
    {
        if (!current_user_can('manage_options') || !Woo_KigoCloud::is_notice_screen() || self::base_url() === '' || self::is_current(self::STALE_AFTER)) {
            return;
        }
        $status = self::status();
        $detail = isset($status['message']) ? $status['message'] : '';
        echo '<div class="notice notice-warning"><p><strong>KigoCloud</strong> '
            . esc_html__('The published price list has not been checked with Kigo.Cloud for more than three hours, so a changed price list may be missing from the site.', 'kigocloud-for-woocommerce')
            . ' ' . esc_html($detail) . '</p></div>';
    }

    /**
     * Label of a kind of price list.
     *
     * @param string $kind roba|usluge
     * @return string
     */
    private static function kind_label($kind)
    {
        return $kind === 'usluge'
            ? __('Price list of services', 'kigocloud-for-woocommerce')
            : __('Price list of goods', 'kigocloud-for-woocommerce');
    }

    /**
     * Facts of a publication made of one or more files: the newest time and
     * its storage number, the goods and the services counted together.
     *
     * @param array $files roba|usluge|'' => array('name' => base name, ...)
     * @return array array('published', 'number', 'items', 'services')
     */
    private static function publication_summary(array $files)
    {
        $summary = array('published' => '', 'number' => '', 'items' => 0, 'services' => 0);
        $newest = '';
        foreach ($files as $file) {
            $facts = self::publication_facts($file['name'], self::archive_path($file['name']));
            $summary['items'] += $facts['items'];
            $summary['services'] += $facts['services'];
            if ($summary['published'] === '' || $facts['sort'] > $newest) {
                $newest = $facts['sort'];
                $summary['published'] = $facts['published'];
                $summary['number'] = $facts['number'];
            }
        }

        return $summary;
    }

    /**
     * [kigo_cjenik] shows the price list the way Kigo.Cloud's public page does:
     * the heading (company, sales point), the current files (goods and, when
     * the sales point has services, services, each CSV and XML) and every
     * publication still kept (replaced in the last 30 days). No table of items:
     * the files are the publication. Every part has its own class, so the theme
     * hides or restyles it with CSS (the built-in rules are not !important):
     *   .kigo-cjenik (wrapper), .kc-head, .kc-company, .kc-company-meta, .kc-logo,
     *   .kc-doc-title, .kc-doc-sub, .kc-current, .kc-archive, .kc-note
     * Attributes, all optional: header, logo, current, archive, note ="no" hide
     * that part; title="..." own title; limit="7" rows of the archive;
     * style="none" drops the built-in look; class="..." extra wrapper class.
     * No call to KigoCloud happens while a page is being rendered, except the
     * check of a copy that was not checked in the last hour.
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

        if (!self::has_files()) {
            return '<p>' . esc_html__('The price list is not available yet.', 'kigocloud-for-woocommerce') . '</p>';
        }
        $archive = self::archive();
        $current_files = array();
        foreach (self::current_kinds() as $kind) {
            $name = self::path('csv', $kind) !== '' ? basename(self::path('csv', $kind)) : basename(self::path('xml', $kind));
            $current_files[$kind] = array('name' => substr($name, 0, -4));
        }
        $facts = self::publication_summary($current_files);

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
            . '.kigo-cjenik .kc-buttons + .kc-buttons{margin-top:10px;}'
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
            . '.kigo-cjenik .kc-kind{color:#5b6270;}'
            . '.kigo-cjenik .kc-now{background:#f3f8f4;}'
            . '.kigo-cjenik .kc-badge{display:inline-block;font-size:10px;text-transform:uppercase;letter-spacing:1px;font-weight:700;color:#0d7a47;background:#ddf0e5;border-radius:4px;padding:2px 7px;margin-left:8px;}'
            . '.kigo-cjenik .kc-note{margin-top:30px;padding-top:18px;border-top:1px solid #e2e5ec;color:#6b7280;font-size:13px;}'
            . '.kigo-cjenik .kc-note p{margin:0 0 8px 0;}'
            . '.kigo-cjenik .kc-credit{margin-top:14px;color:#8a90a0;font-size:12px;}'
            . '.kigo-cjenik .kc-credit a{color:inherit;}'
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
        $out .= '</div>';
        foreach (array_keys($current_files) as $kind) {
            // A file of before 17 November 2026 holds goods and services together.
            $legacy = self::parse_name($current_files[$kind]['name'])['kind'] === '';
            $out .= '<div class="kc-buttons">';
            $ghost = '';
            foreach (array('csv', 'xml') as $format) {
                if (self::url($format, $kind) === '') {
                    continue;
                }
                $text = $legacy
                    ? ($format === 'csv' ? __('Download CSV', 'kigocloud-for-woocommerce') : __('Download XML', 'kigocloud-for-woocommerce'))
                    : self::kind_label($kind) . ' (' . strtoupper($format) . ')';
                // The current file under its prescribed name (cl. 6 st. 3); this
                // page is the same place for a changed price list (cl. 6 st. 2).
                $out .= '<a class="kc-btn' . $ghost . '" href="' . esc_url(self::url($format, $kind)) . '"'
                    . ' title="' . esc_attr(basename(self::path($format, $kind))) . '">' . esc_html($text) . '</a>';
                $ghost = ' kc-btn-ghost';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
        $out .= '</div>';
        }

        $limit = max(0, (int) $atts['limit']);
        if ($on('archive')) {
        $out .= '<div class="kc-archive">';
        $out .= '<div class="kc-h2">' . esc_html__('Published price lists', 'kigocloud-for-woocommerce') . '</div>';
        $out .= '<div class="kc-table-wrap"><table class="kc-table"><thead><tr>'
            . '<th>' . esc_html__('Published', 'kigocloud-for-woocommerce') . '</th>'
            . '<th>' . esc_html__('Storage number', 'kigocloud-for-woocommerce') . '</th>'
            . '<th class="kc-num">' . esc_html__('Products', 'kigocloud-for-woocommerce') . '</th>'
            . '<th class="kc-num">' . esc_html__('Services', 'kigocloud-for-woocommerce') . '</th>'
            . '<th>' . esc_html__('Files', 'kigocloud-for-woocommerce') . '</th>'
            . '</tr></thead><tbody>';
        foreach (($limit > 0 ? array_slice($archive, 0, $limit) : $archive) as $item) {
            $f = self::publication_summary($item['files']);
            $groups = array();
            foreach ($item['files'] as $kind => $file) {
                $links = array();
                foreach (array('csv', 'xml') as $format) {
                    if ($file[$format] !== '') {
                        $links[] = '<a href="' . esc_url($file[$format]) . '" title="' . esc_attr($file['name'] . '.' . $format) . '">' . strtoupper($format) . '</a>';
                    }
                }
                $groups[] = ($kind !== '' ? '<span class="kc-kind">' . esc_html(self::kind_label($kind)) . ':</span> ' : '') . implode(' &middot; ', $links);
            }
            $out .= '<tr' . ($item['current'] ? ' class="kc-now"' : '') . '>'
                . '<td>' . esc_html($f['published']) . ($item['current'] ? '<span class="kc-badge">' . esc_html__('Current', 'kigocloud-for-woocommerce') . '</span>' : '') . '</td>'
                . '<td>' . esc_html($f['number']) . '</td>'
                . '<td class="kc-num">' . (int) $f['items'] . '</td>'
                . '<td class="kc-num">' . (int) $f['services'] . '</td>'
                . '<td>' . implode('<br>', $groups) . '</td>'
                . '</tr>';
        }
        $out .= '</tbody></table></div>';
        $out .= '</div>';
        }

        if ($on('note')) {
            $out .= '<div class="kc-note">'
                . '<p>' . esc_html__('The price list holds the retail prices of the goods and services of this shop, together with the base price (bazna cijena) that applied on the reference day.', 'kigocloud-for-woocommerce') . '</p>'
                . '<p>' . esc_html__('A changed price list is published on the day of the change, and every replaced price list stays available for 30 days.', 'kigocloud-for-woocommerce') . '</p>'
                . '</div>';
        }
        // Only when the site owner ticked it on the Price list tab (off by default).
        if ('1' === (string) get_option('kigocloud_pricelist_credit', '0')) {
            $out .= '<div class="kc-credit">' . sprintf(
                /* translators: %1$s and %2$s: start and end of the link to Kigo.Cloud */
                esc_html__('Price list made with %1$sKigo.Cloud%2$s', 'kigocloud-for-woocommerce'),
                '<a href="' . esc_url(self::CREDIT_URL) . '">',
                '</a>'
            ) . '</div>';
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
     * Publication date and storage number from the prescribed file name, and
     * the number of products and services in its CSV. A ROBA file holds goods
     * only and a USLUGE file services only, so their data rows are counted; a
     * file of before 17 November 2026 holds both and is counted by its column
     * Vrsta. Counts are kept per file, a file never changes.
     *
     * @param string $name base name without extension
     * @param string $csv  path of its CSV, '' when unknown
     * @return array array('published', 'number', 'items', 'services', 'sort' => YmdHis|'')
     */
    private static function publication_facts($name, $csv)
    {
        $facts = array('published' => '', 'number' => '', 'items' => 0, 'services' => 0, 'sort' => '');
        $parsed = self::parse_name($name);
        if ($parsed['stamp'] !== '') {
            $stamp = $parsed['stamp'];
            $facts['number'] = $parsed['number'];
            $facts['sort'] = $stamp;
            $time = mktime((int) substr($stamp, 8, 2), (int) substr($stamp, 10, 2), 0, (int) substr($stamp, 4, 2), (int) substr($stamp, 6, 2), (int) substr($stamp, 0, 4));
            $facts['published'] = date_i18n(get_option('date_format') . ' H:i', $time);
        } elseif ($csv !== '') {
            $facts['published'] = date_i18n(get_option('date_format') . ' H:i', filemtime($csv));
            $facts['sort'] = gmdate('YmdHis', filemtime($csv));
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
                $slot = $parsed['kind'] === 'usluge' ? 1 : 0;
                while (is_array($header) && ($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                    if ($row === array(null)) {
                        continue; // empty line
                    }
                    if ($parsed['kind'] !== '') {
                        $counts[$slot]++;
                        continue;
                    }
                    $kind = ($col !== false && isset($row[$col])) ? strtolower(trim((string) $row[$col])) : '';
                    if ($kind === 'proizvod') {
                        $counts[0]++;
                    } elseif ($kind === 'usluga') {
                        $counts[1]++;
                    }
                }
                fclose($handle);
            }
            set_transient($key, $counts, (self::KEEP_DAYS + 1) * DAY_IN_SECONDS);
        }
        $facts['items'] = (int) $counts[0];
        $facts['services'] = (int) $counts[1];

        return $facts;
    }
}
