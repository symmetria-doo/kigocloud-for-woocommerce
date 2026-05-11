<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://github.com/dpotocic
 * @since      1.0.0
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/public
 * @author     Dejan Potocic <dpotocic@gmail.com>
 */
class Woo_KigoCloud_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Woo_KigoCloud_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Woo_KigoCloud_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/kigocloud-for-woocommerce-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Woo_KigoCloud_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Woo_KigoCloud_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/kigocloud-for-woocommerce-public.js', array( 'jquery' ), $this->version, false );

	}

    /**
     * @param $fields
     * @return mixed
     */
    public function add_checkout_vat_fields($fields)
    {
        $fields['shipping']['shipping_vat_number'] = array(
            'label' => esc_html__('VAT number', 'kigocloud-for-woocommerce'),
            'placeholder' => _x('12345678901', 'placeholder', 'kigocloud-for-woocommerce'),
            'required' => false,
            'class' => array('form-row-wide'),
            'clear' => true,
        );

        $fields['billing']['billing_vat_number'] = array(
            'label' => esc_html__('VAT number', 'kigocloud-for-woocommerce'),
            'placeholder' => _x('12345678901', 'placeholder', 'kigocloud-for-woocommerce'),
            'required' => false,
            'class' => array('form-row-wide'),
            'clear' => true,
        );

        return $fields;
    }

    /**
     * @param $checkout
     * @return void
     */
    public function add_checkout_vat_invoice_form($checkout)
    {
        echo '<div id="kigocloud_vat_invoices_form"><strong>' . __('VAT Invoice', 'kigocloud-for-woocommerce') . '</strong><p>' . __('Do you need VAT Invoice?', 'kigocloud-for-woocommerce') . '</p>';

        woocommerce_form_field('kigocloud_vat_invoices_checkbox', array(
            'type' => 'checkbox',
            'class' => array('form-row-wide'),
            'label' => __('Yes', 'kigocloud-for-woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_checkbox'));

        echo '<div id="kigocloud_vat_invoices_fields">';
        woocommerce_form_field('kigocloud_vat_invoices_company', array(
            'type' => 'text',
            'class' => array('form-row-wide'),
            'label' => __('Company name', 'kigocloud-for-woocommerce'),
            'required' => true,
            'placeholder' => _x('Enter company name', 'placeholder', 'woocommerce'),
        ), $checkout->get_value('r1_ime_tvrtke'));

        woocommerce_form_field('kigocloud_vat_invoices_address', array(
            'type' => 'text',
            'class' => array('form-row-wide'),
            'label' => __('Company address', 'kigocloud-for-woocommerce'),
            'required' => true,
            'placeholder' => _x('Enter company address', 'placeholder', 'woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_address'));

        woocommerce_form_field('kigocloud_vat_invoices_city', array(
            'type' => 'text',
            'class' => array('form-row-wide'),
            'label' => __('City', 'kigocloud-for-woocommerce'),
            'required' => true,
            'placeholder' => _x('Enter company city', 'placeholder', 'woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_city'));

        woocommerce_form_field('kigocloud_vat_invoices_zip', array(
            'type' => 'text',
            'class' => array('form-row-wide'),
            'label' => __('Zip code', 'kigocloud-for-woocommerce'),
            'required' => true,
            'placeholder' => _x('Enter company zip code', 'placeholder', 'woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_zip'));

        woocommerce_form_field('kigocloud_vat_invoices_vat_number', array(
            'type' => 'text',
            'class' => array('form-row-wide'),
            'label' => __('VAT number', 'kigocloud-for-woocommerce'),
            'required' => true,
            'placeholder' => _x('Enter company VAT number', 'placeholder', 'woocommerce'),
        ), $checkout->get_value('kigocloud_vat_invoices_vat_number'));
        echo '</div>';
        echo '</div>';
    }

    /**
     * @return void
     */
    public function validate_vat_invoice_form()
    {
        if (isset($_POST['kigocloud_vat_invoices_checkbox']) && !empty($_POST['kigocloud_vat_invoices_checkbox'])) {
            if (empty($_POST['kigocloud_vat_invoices_company'])) {
                wc_add_notice(__('Company name is mandatory', 'kigocloud-for-woocommerce'), 'error');
            }

            if (empty($_POST['kigocloud_vat_invoices_address'])) {
                wc_add_notice(__('Company address is mandatory', 'kigocloud-for-woocommerce'), 'error');
            }

            if (empty($_POST['kigocloud_vat_invoices_city'])) {
                wc_add_notice(__('Company city is mandatory', 'kigocloud-for-woocommerce'), 'error');
            }

            if (empty($_POST['kigocloud_vat_invoices_zip'])) {
                wc_add_notice(__('Company zip is mandatory', 'kigocloud-for-woocommerce'), 'error');
            }

            if (strlen($_POST['kigocloud_vat_invoices_vat_number']) !== 11) {
                wc_add_notice(__('Company VAT has to have 11 characters', 'kigocloud-for-woocommerce'), 'error');
            }

            if ($this->validateVAT($_POST['kigocloud_vat_invoices_vat_number']) == false) {
                wc_add_notice(__('Invalid VAT.', 'kigocloud-for-woocommerce'), 'error');
            }
        }
    }

    /**
     * @param $order_id
     * @return void
     */
    public function vat_invoice_form_update_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );

        if (isset($_POST['kigocloud_vat_invoices_checkbox'])) {
            $order->update_meta_data('kigocloud_vat_invoices_checkbox', esc_attr($_POST['kigocloud_vat_invoices_checkbox']));
        }
        if (isset($_POST['kigocloud_vat_invoices_company'])) {
            $order->update_meta_data('kigocloud_vat_invoices_company', esc_attr($_POST['kigocloud_vat_invoices_company']));
        }
        if (isset($_POST['kigocloud_vat_invoices_address'])) {
            $order->update_meta_data('kigocloud_vat_invoices_address', esc_attr($_POST['kigocloud_vat_invoices_address']));
        }
        if (isset($_POST['kigocloud_vat_invoices_city'])) {
            $order->update_meta_data('kigocloud_vat_invoices_city', esc_attr($_POST['kigocloud_vat_invoices_city']));
        }
        if (isset($_POST['kigocloud_vat_invoices_zip'])) {
            $order->update_meta_data('kigocloud_vat_invoices_zip', esc_attr($_POST['kigocloud_vat_invoices_zip']));
        }
        if (isset($_POST['kigocloud_vat_invoices_vat_number'])) {
            $order->update_meta_data('kigocloud_vat_invoices_vat_number', esc_attr($_POST['kigocloud_vat_invoices_vat_number']));
        }

        $order->save();
    }

    /**
     * @param $fields
     * @return mixed
     */
    public function vat_invoice_form_override_checkout_fields( $fields ) {
        unset($fields['billing']['billing_company']);

        return $fields;
    }

    /**
     * @param $oib
     * @return bool
     */
    private function validateVAT($oib)
    {
        if (strlen($oib) == 11 and is_numeric($oib)) {
            $a = 10;
            for ($i = 0; $i < 10; $i++) {
                $a = $a + intval(substr($oib, $i, 1), 10);
                $a = $a % 10;
                if ($a == 0) {
                    $a = 10;
                }
                $a *= 2;
                $a = $a % 11;
            }

            $kontrolni = 11 - $a;
            if ($kontrolni == 10) {
                $kontrolni = 0;
            }
            return $kontrolni == intval(substr($oib, 10, 1), 10);
        }

        return false;
    }
}
