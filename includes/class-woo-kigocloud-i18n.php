<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://github.com/dpotocic
 * @since      1.0.0
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 */

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
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'kigocloud-for-woocommerce',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
