<?php
/**
 * The Kigo.Cloud licence of the shop (GET licence/info): package, expiry, and
 * what the package allows of what the plugin does besides sending orders.
 *
 * - Anchor prices: every package, while the Sidrene cijene module is switched
 *   on in Kigo.Cloud.
 * - Price list on the shop's own domain: Standard and above, while Kigo.Cloud
 *   publishes it (Sidrene cijene > Cjenik za objavu). The plugin takes the
 *   price list address from the licence, nothing is copied by hand.
 *
 * Read when the settings are saved and once a day. Before the first read, or
 * against a Kigo.Cloud without licence/info, nothing is switched off.
 *
 * @package Woo_KigoCloud
 */

if (!defined('ABSPATH')) {
    exit;
}

class Woo_KigoCloud_Licence
{
    /** Last read: array('time', 'ok', 'message', 'data' => answer data). Not autoloaded. */
    const OPTION = 'kigocloud_licence';

    const CRON_HOOK = 'kigocloud_licence_refresh';

    public static function init()
    {
        add_action(self::CRON_HOOK, array(__CLASS__, 'refresh'));
        add_action('init', array(__CLASS__, 'schedule'));
        add_action('admin_init', array(__CLASS__, 'on_settings_form'));
    }

    /**
     * Hooked on `init`: daily at 06:45, before the price list (07:00) and the
     * anchor prices (08:15).
     */
    public static function schedule()
    {
        $next = wp_next_scheduled(self::CRON_HOOK);
        if (!Woo_KigoCloud_Request::has_credentials()) {
            if ($next) {
                wp_unschedule_event($next, self::CRON_HOOK);
            }
            return;
        }
        if (!$next) {
            wp_schedule_event(strtotime('tomorrow 06:45'), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Hooked on `admin_init`: every Save of the settings form reads the licence
     * again at its end, after the login check and before the anchor prices.
     */
    public static function on_settings_form()
    {
        if (Woo_KigoCloud_Request::is_settings_save() && !has_action('shutdown', array(__CLASS__, 'refresh'))) {
            add_action('shutdown', array(__CLASS__, 'refresh'), 6);
        }
    }

    /**
     * Reads the licence from Kigo.Cloud. A failed read keeps the licence read
     * before. A changed price list address fetches the price list at once.
     *
     * @return array the stored read
     */
    public static function refresh()
    {
        if (!Woo_KigoCloud_Request::has_credentials()) {
            delete_option(self::OPTION);
            return array();
        }
        $check = Woo_KigoCloud_Request::login_check();
        if (isset($check['ok']) && !$check['ok'] && Woo_KigoCloud_Request::is_settings_save()) {
            // The login was just refused: no second refused call in Kigo.Cloud's log.
            return self::get();
        }
        $before = self::price_list_url();
        $old = self::get();
        $answer = Woo_KigoCloud_Request::call('GET', 'licence/info');
        if (is_wp_error($answer)) {
            $old['time'] = time();
            $old['ok'] = false;
            $old['message'] = $answer->get_error_message();
            update_option(self::OPTION, $old, false);
            return $old;
        }
        // Kigo.Cloud answers the licence object itself (type json); a wrapped
        // answer (data) is taken too.
        $data = json_decode(wp_json_encode(isset($answer->data) ? $answer->data : $answer), true);
        if (!is_array($data) || !isset($data['tier'])) {
            $data = null;
        }
        $read = array('time' => time(), 'ok' => is_array($data), 'message' => is_array($data) ? '' : 'Invalid answer', 'data' => is_array($data) ? $data : (isset($old['data']) ? $old['data'] : null));
        update_option(self::OPTION, $read, false);

        if (class_exists('Woo_KigoCloud_Pricelist') && self::price_list_url() !== $before) {
            Woo_KigoCloud_Pricelist::on_url_saved();
        }

        return $read;
    }

    /**
     * Reads the licence when it was never read or is older than 12 hours, at
     * most once a minute. Called when the settings page opens.
     */
    public static function maybe_refresh()
    {
        if (!Woo_KigoCloud_Request::has_credentials() || get_transient('kigocloud_licence_lock')) {
            return;
        }
        if (Woo_KigoCloud_Request::login_check() === array()) {
            // Right after an update the login was never checked: check it now.
            set_transient('kigocloud_licence_lock', 1, 60);
            Woo_KigoCloud_Request::check_login();
        }
        $read = self::get();
        if (self::data() !== null && isset($read['time']) && (int) $read['time'] > time() - 12 * HOUR_IN_SECONDS) {
            return;
        }
        $check = Woo_KigoCloud_Request::login_check();
        if (isset($check['ok']) && !$check['ok'] && self::data() !== null) {
            return; // a refused login is checked again by Save or Test connection
        }
        set_transient('kigocloud_licence_lock', 1, 60);
        self::refresh();
    }

    /**
     * @return array the last read, array() before the first one
     */
    public static function get()
    {
        $read = get_option(self::OPTION, array());

        return is_array($read) ? $read : array();
    }

    /**
     * @return array|null licence data, null while unknown
     */
    public static function data()
    {
        $read = self::get();

        return (isset($read['data']) && is_array($read['data'])) ? $read['data'] : null;
    }

    /**
     * @return bool the licence allows anchor prices and the module is on (true while unknown)
     */
    public static function allows_anchor()
    {
        $data = self::data();
        if ($data === null || !isset($data['anchor_prices'])) {
            return true;
        }

        return !empty($data['anchor_prices']['available']) && !empty($data['anchor_prices']['enabled']);
    }

    /**
     * @return bool|null the package has the public price list; null while unknown
     */
    public static function allows_price_list()
    {
        $data = self::data();
        if ($data === null || !isset($data['price_list'])) {
            return null;
        }

        return !empty($data['price_list']['available']);
    }

    /**
     * @return string address of the published price list from the licence, '' when none
     */
    public static function price_list_url()
    {
        $data = self::data();
        if ($data === null || empty($data['price_list']['published']) || empty($data['price_list']['url'])) {
            return '';
        }
        $url = (string) $data['price_list']['url'];

        return filter_var($url, FILTER_VALIDATE_URL) ? rtrim($url, '/') : '';
    }

    /**
     * @return string why there is no price list address: package, not_in_use, not_published, '' otherwise
     */
    public static function price_list_reason()
    {
        $data = self::data();

        return ($data !== null && isset($data['price_list']['reason'])) ? (string) $data['price_list']['reason'] : '';
    }
}
