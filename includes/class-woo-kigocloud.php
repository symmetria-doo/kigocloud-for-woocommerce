<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://github.com/dpotocic
 * @since      1.0.0
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 * @author     Dejan Potocic <dpotocic@gmail.com>
 */
class Woo_KigoCloud {

    /**
     * Plugin name
     *
     * @since 1.0.0
     */
    const PLUGIN_NAME = 'kigocloud-for-woocommerce';

    /**
     * Plugin version
     *
     * @since 1.0.0
     */
    const PLUGIN_VERSION = '2.0.0';

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Woo_KigoCloud_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'WOO_KIGOCLOUD_PLUGIN_NAME_VERSION' ) ) {
			$this->version = WOO_KIGOCLOUD_PLUGIN_NAME_VERSION;
		} else {
			$this->version = self::PLUGIN_VERSION;
		}
		$this->plugin_name = 'kigocloud-for-woocommerce';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Woo_KigoCloud_Loader. Orchestrates the hooks of the plugin.
	 * - Woo_KigoCloud_i18n. Defines internationalization functionality.
	 * - Woo_KigoCloud_Admin. Defines all hooks for the admin area.
	 * - Woo_KigoCloud_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-woo-kigocloud-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-woo-kigocloud-i18n.php';

        /**
         * The class responsible for API calls.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-woo-kigocloud-request.php';

        /**
         * The class responsible for custom REST API endpoints.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-woo-kigocloud-rest.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-woo-kigocloud-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-woo-kigocloud-public.php';

		/**
		 * The class responsible for updating the plugin.
		 */
		require_once plugin_dir_path(dirname( __FILE__ ) ) . 'includes/class-woo-kigocloud-updater.php';


		$this->loader = new Woo_KigoCloud_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Woo_KigoCloud_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Woo_KigoCloud_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Woo_KigoCloud_Admin($this->get_plugin_name(), $this->get_version());
        $plugin_public = new Woo_KigoCloud_Public($this->get_plugin_name(), $this->get_version());
        $plugin_request = new Woo_KigoCloud_Request();
        $plugin_rest = new Woo_KigoCloud_Rest();
		$plugin_updater = new Woo_KigoCloud_Updater();

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

        $this->loader->add_action('woocommerce_order_status_changed', $plugin_request, 'on_order_status_change',14,3);

        $enableVatInvoice = get_option('kigocloud_vat_invoices', 0);
		if ($enableVatInvoice){
			$this->loader->add_action('admin_notices',$plugin_admin,'checkout_block_admin_notice');
		}
        if ($enableVatInvoice == 2) {
            $this->loader->add_action('woocommerce_after_checkout_billing_form', $plugin_public, 'add_checkout_vat_invoice_form');
            $this->loader->add_action('woocommerce_init', $plugin_public, 'validate_vat_invoice_form');
            $this->loader->add_action('woocommerce_checkout_update_order_meta', $plugin_public, 'vat_invoice_form_update_order_meta');
            $this->loader->add_filter('woocommerce_checkout_fields', $plugin_public, 'vat_invoice_form_override_checkout_fields');
        }else if ($enableVatInvoice == 1){
            $this->loader->add_action('woocommerce_checkout_fields', $plugin_public, 'add_checkout_vat_fields');
        }

        $this->loader->add_action('woocommerce_admin_order_data_after_shipping_address', $plugin_admin, 'display_admin_order_meta');
        $this->loader->add_action('woocommerce_admin_order_data_after_order_details', $plugin_admin, 'display_admin_order_kigocloud');


        $this->loader->add_filter( 'woocommerce_settings_tabs_array',$plugin_admin, 'add_woocommerce_settings_tab', 50 );
        $this->loader->add_action( 'woocommerce_settings_tabs_kigocloud', $plugin_admin, 'add_woocommerce_settings' );
        $this->loader->add_action( 'woocommerce_update_options_kigocloud', $plugin_admin, 'update_woocommerce_settings' );

        $this->loader->add_filter( 'woocommerce_email_attachments', $plugin_request, 'add_pdf_attachment', 10, 3 );

        $this->loader->add_filter( 'wp_mail_from', $plugin_admin, 'change_wp_email_from');
        $this->loader->add_filter( 'wp_mail_from_name', $plugin_admin, 'change_wp_email_from_name');

		$this->loader->add_action( 'init', $plugin_updater, 'check_for_updates' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'show_admin_notice' );

        $this->loader->add_action('rest_api_init', $plugin_rest, 'register_sku_endpoint');
		$this->loader->add_action('rest_api_init', $plugin_rest, 'register_product_sku_endpoint');

		// Plugins list helpers (Settings link + GitHub link).
		if ( defined( 'WOO_KIGOCLOUD_PLUGIN_FILE' ) ) {
			$basename = plugin_basename( WOO_KIGOCLOUD_PLUGIN_FILE );
			$this->loader->add_filter( 'plugin_action_links_' . $basename, $plugin_admin, 'plugin_action_links' );
			$this->loader->add_filter( 'plugin_row_meta', $plugin_admin, 'plugin_row_meta', 10, 2 );
		}
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Woo_KigoCloud_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Woo_KigoCloud_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
