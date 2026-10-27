<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://github.com/symmetria-doo
 * @since      1.0.0
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 * @author     Dejan Potocic <dpotocic@gmail.com>
 */
class Woo_KigoCloud_i18n {


	/**
	 * Loads the translation the plugin ships for the current locale
	 * (Croatian, German, English). Without one, WordPress loads the
	 * wordpress.org language pack on its own when a string is first used.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		$mofile = dirname( dirname( __FILE__ ) ) . '/languages/kigocloud-for-woocommerce-' . determine_locale() . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'kigocloud-for-woocommerce', $mofile );
		}

	}



}
