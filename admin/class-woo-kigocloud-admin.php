<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://github.com/dpotocic
 * @since      1.0.0
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/admin
 * @author     Dejan Potocic <dpotocic@gmail.com>
 */
class Woo_KigoCloud_Admin
{

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $plugin_name The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $version The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string $plugin_name The name of this plugin.
     * @param      string $version     The version of this plugin.
     */
    public function __construct($plugin_name, $version)
    {

        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles()
    {

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

        /*wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'css/kigocloud-for-woocommerce-admin.css',
            array(),
            $this->version,
            'all'
        );*/
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {

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

        /*wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/kigocloud-for-woocommerce-admin.js',
            array('jquery'),
            $this->version,
            false
        );*/
    }

    /**
     * Change From email field of WP mails
     *
     * @param  string $changeEmail
     * @return string
     */
    public function change_wp_email_from($email)
    {
        $changeEmail = esc_attr(get_option('kigocloud_email_from'));
        if (!empty($changeEmail) && $changeEmail !== '') {
            return $changeEmail;
        }

        return $email;
    }

    /**
     * Change From field of WP mails
     *
     * @param  string $fromName
     * @return string
     */
    public function change_wp_email_from_name($fromName)
    {
        $changeFromName = esc_attr(get_option('kigocloud_email_from_name'));
        if (!empty($changeFromName) && $changeFromName !== '') {
            return $changeFromName;
        }

        return $fromName;
    }

    /**
     * Add plugin options page
     *
     * @since  1.0.0
     */
    public function add_plugin_options_page()
    {
        add_submenu_page(
            'woocommerce',
            esc_html__('KigoCloud', 'kigocloud-for-woocommerce'),
            esc_html__('KigoCloud', 'kigocloud'),
            'manage_options',
            $this->plugin_name,
            array($this, 'render_admin_options_page')
        );
    }

    /**
     * @param $settings_tabs
     * @return mixed
     */
    public static function add_woocommerce_settings_tab($settings_tabs)
    {
        $settings_tabs['kigocloud'] = __('KigoCloud', 'kigocloud-for-woocommerce');
        return $settings_tabs;
    }

    public static function update_woocommerce_settings()
    {
        woocommerce_update_options(self::get_woocommerce_settings_options());
	    // Mark settings as saved and remove the migration notice
	    update_option('kigocloud_show_migration_notice', 0);
    }

    public static function add_woocommerce_settings()
    {
		update_option('kigocloud_show_migration_notice', 0);

        woocommerce_admin_fields(self::get_woocommerce_settings_options());
    }

    public static function get_woocommerce_settings_options()
    {
        __('pos_type_0', 'kigocloud-for-woocommerce');
        __('pos_type_1', 'kigocloud-for-woocommerce');
        __('pos_type_2', 'kigocloud-for-woocommerce');

        /*$paymentFields = [
            'payment_title' => array(
                'name' => __('Payment settings', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => '',
            ),
        ];*/
        $paymentFields = [];

        // $gateways = WC()->payment_gateways->get_available_payment_gateways();
	    $all_gateways = WC()->payment_gateways->payment_gateways();
	    $gateways = array();
	    foreach ( $all_gateways as $gateway ) {
		    if ( 'yes' === $gateway->enabled ) {
			    $gateways[] = $gateway;
		    }
	    }
        foreach ($gateways as $k => $gateway) {
            $paymentFields['start_'.esc_attr($gateway->id)] = array(
                'name' => esc_html($gateway->title),
                'type' => 'title',
            );

            $paymentFields['pos_type-' . esc_attr($gateway->id)] = array(
                'title'   => __('Document type', 'kigocloud-for-woocommerce'),
                'type'    => 'select',
                'name'        => 'pos_type-' . esc_attr($gateway->id),
                'id'          => 'kigocloud_pos_type-' . esc_attr($gateway->id),
                'options' => array(
                    '0' => __('Disabled', 'kigocloud-for-woocommerce'),
                    '1' => __('Invoice', 'kigocloud-for-woocommerce'),
                    '2' => __('Offer', 'kigocloud-for-woocommerce'),
                ),
                'default' => '0',
                'css'     => 'width:150px;',
            );

            $paymentFields['payment_type-' . esc_attr($gateway->id)] = array(
                'title'   => __('Payment type', 'kigocloud-for-woocommerce'),
                'name'        => 'payment_type-' . esc_attr($gateway->id),
                'id'          => 'kigocloud_payment_type-' . esc_attr($gateway->id),
                'type'    => 'select',
                'options' => array(
                    'T' => __('Transaction account', 'kigocloud-for-woocommerce'),
                    'K' => __('Card', 'kigocloud-for-woocommerce'),
                    'G' => __('Cash', 'kigocloud-for-woocommerce'),
                    'C' => __('Cheque', 'kigocloud-for-woocommerce'),
                    'O' => __('Other', 'kigocloud-for-woocommerce'),
                ),
                'default' => 'T',
                'css'     => 'width:200px;',
            );
	        $paymentFields['on_status-' . esc_attr($gateway->id)] = array(
		        'title'   => __('On status', 'kigocloud-for-woocommerce'),
		        'name'        => 'on_status-' . esc_attr($gateway->id),
		        'id'          => 'kigocloud_on_status-' . esc_attr($gateway->id),
		        'type'    => 'select',
		        'options' => array(
			        '0' => __('Order Creation', 'kigocloud-for-woocommerce'),
			        '1' => __('Order Completed', 'kigocloud-for-woocommerce'),
		        ),
		        'default' => '1',
		        'css'     => 'width:200px;',
	        );
            $paymentFields['pdf_payment_type-' . esc_attr($gateway->id)] = array(
                'title'   => __('Send Email with document PDF', 'kigocloud-for-woocommerce'),
                'name'        => 'pdf_payment_type-' . esc_attr($gateway->id),
                'id'          => 'kigocloud_pdf_payment_type-' . esc_attr($gateway->id),
                'type'    => 'select',
                'options' => array(
                    '0' => __('No', 'kigocloud-for-woocommerce'),
                    '1' => __('Yes', 'kigocloud-for-woocommerce'),
                ),
                'default' => '0',
                'css'     => 'width:200px;',
            );
            $paymentFields['end_'.esc_attr($gateway->id)] = array(
                'type' => 'sectionend',
            );
        }

        //$paymentFields['payment_section_end'] = array('type' => 'sectionend');

        $form_fields = array(
            'api_account_title'       => array(
                'name' => __('API account', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => '',
            ),
            'username'                => array(
                'title'       => __('API Username', 'kigocloud-for-woocommerce'),
                'name'        => 'username',
                'type'        => 'text',
                'id'          => 'kigocloud_username',
                'description' => __('Enter API username here', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => 'admin_demo',
                'css'         => 'width:150px;',
            ),
            'password'                => array(
                'title'       => __('API Password', 'kigocloud-for-woocommerce'),
                'type'        => 'text',
                'name'        => 'password',
                'id'          => 'kigocloud_password',
                'description' => __('Enter API password here', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => 'admin_demo',
                'css'         => 'width:150px;',
            ),
            'api_account_section_end' => array(
                'type' => 'sectionend',
            ),
        );

        $form_fields += $paymentFields;

        $form_fields += array(
            'api_misc_title'          => array(
                'name' => __('Misc', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => '',
            ),
            'pin'                     => array(
                'title'       => __('Employee PIN', 'kigocloud-for-woocommerce'),
                'type'        => 'text',
                'name'        => 'pin',
                'id'          => 'kigocloud_pin',
                'description' => __('Enter API employee PIN here', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => '1',
                'css'         => 'width:150px;',
            ),
            'shipping_reference'      => array(
                'title'       => __('Shipping Reference Number', 'kigocloud-for-woocommerce'),
                'type'        => 'text',
                'name'        => 'shipping_reference',
                'id'          => 'kigocloud_shipping_reference',
                'placeholder' => __('Enter Shipping Reference Number here', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'css'         => 'width:250px;',
            ),
            'api_misc_section_end'    => array(
                'type' => 'sectionend',
            ),
            'email_title_section'       => array(
                'name' => __('E-mail settings', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('This change is global.', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_email_from_name'                => array(
                'title'       => __('From name', 'kigocloud-for-woocommerce'),
                'name'        => 'from_name',
                'type'        => 'text',
                'id'          => 'kigocloud_email_from_name',
                'description' => __('Enter \'From\' Name field here', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => null,
                'css'         => 'width:150px;',
            ),
            'kigocloud_email_from'                => array(
                'title'       => __('From e-mail', 'kigocloud-for-woocommerce'),
                'type'        => 'text',
                'name'        => 'password',
                'id'          => 'kigocloud_email_from',
                'description' => __('Enter \'From\' E-mail here', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => null,
                'css'         => 'width:150px;',
            ),
            'kigocloud_reply_to'                => array(
                'title'       => __('Reply-To E-mail', 'kigocloud-for-woocommerce'),
                'type'        => 'text',
                'name'        => 'reply_to',
                'id'          => 'kigocloud_reply_to',
                'description' => __('Enter \'Reply-To\' E-mail here', 'kigocloud-for-woocommerce'),
                'desc_tip'    => true,
                'default'     => null,
                'css'         => 'width:150px;',
            ),
            'kigocloud_fill_empty_sku'                => array(
	            'title'       => __('Fill empty SKU before sending order to API', 'kigocloud-for-woocommerce'),
	            'type'    => 'select',
	            'options' => array(
		            0 => __('No', 'kigocloud-for-woocommerce'),
		            1 => __('Yes', 'kigocloud-for-woocommerce'),
	            ),
	            'name'        => 'fill_empty_sku',
	            'id'          => 'kigocloud_fill_empty_sku',
	            'description' => __('Enter \'Reply-To\' E-mail here', 'kigocloud-for-woocommerce'),
	            'desc_tip'    => true,
	            'default'     => null,
	            'css'         => 'width:150px;',
            ),
            'email_title_section_end' => array(
                'type' => 'sectionend',
            )
        );

        $order_form_fields = array(
            'api_status_title'       => array(
                'name' => __('Order Statuses', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('Before any Kigo API call, plugin will check if call has already been made for same order', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_skip_status_order_created'                => array(
                'title'       => __('Skip create Kigo document on Order Status Created', 'kigocloud-for-woocommerce'),
                'name'        => 'skip_status_order_created',
                'type'        => 'select',
                'id'          => 'kigocloud_skip_status_order_created',
                'desc_tip'    => true,
                'default'     => 0,
                'options'     => array(
                    '0' => __('No, create Kigo document on Order Created status', 'kigocloud-for-woocommerce'),
                    '1' => __('Yes, skip this order status', 'kigocloud-for-woocommerce'),
                ),
                'css'         => 'width:400px;',
            ),
            'kigocloud_skip_status_order_completed'                => array(
                'title'       => __('Skip create Kigo document on Order Status Completed', 'kigocloud-for-woocommerce'),
                'name'        => 'skip_status_order_completed',
                'type'        => 'select',
                'id'          => 'kigocloud_skip_status_order_completed',
                'desc_tip'    => true,
                'default'     => 0,
                'options'     => array(
                    '0' => __('No, create Kigo document on Order Completed status', 'kigocloud-for-woocommerce'),
                    '1' => __('Yes, skip skip this order status', 'kigocloud-for-woocommerce'),
                ),
                'css'         => 'width:400px;',
            ),
            'api_status_title_section_end' => array(
                'type' => 'sectionend',
            ),
        );

		// @deprecated
        // $form_fields += $order_form_fields;

        $vat_invoice_form_fields = array(
            'vat_invoices_section'       => array(
                'name' => __('VAT Invoice', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('Enable VAT Invoice fields on Checkout form: Company Name, Company Adress, Company VAT', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_vat_invoices'                => array(
                'title'       => __('Checkout fields', 'kigocloud-for-woocommerce'),
                'name'        => 'api_vat_invoices',
                'type'        => 'select',
                'id'          => 'kigocloud_vat_invoices',
                'desc_tip'    => true,
                'default'     => 0,
                'options'     => array(
                    '0' => __('Do not show VAT Invoice fields on Checkout', 'kigocloud-for-woocommerce'),
                    '1' => __('Show VAT field only', 'kigocloud-for-woocommerce'),
                    '2' => __('Show All VAT Invoice fields on Checkout', 'kigocloud-for-woocommerce'),
                ),
                'css'         => 'width:400px;',
            ),
            'vat_invoices_section_end' => array(
                'type' => 'sectionend',
            ),
        );

        $form_fields += $vat_invoice_form_fields;

        $custom_mapping_fileds = array(
            'custom_mapping_start'       => array(
                'name' => __('Custom mapping', 'kigocloud-for-woocommerce'),
                'type' => 'title',
                'desc' => __('Use this option to override default billing information with custom data from order meta data using format: source_meta:target.data, source_meta1:target.data1', 'kigocloud-for-woocommerce'),
            ),
            'kigocloud_custom_mapping'                => array(
                'title'       => __('Custom mapping', 'kigocloud-for-woocommerce'),
                'name'        => 'custom_mapping',
                'type'        => 'textarea',
                'id'          => 'kigocloud_custom_mapping',
                'desc_tip'    =>  __('Example: r1_oib_tvrtke:_billing_vat_number, r1_ime_tvrtke:billing.company, r1_adresa_tvrtke:billing.address_1', 'kigocloud-for-woocommerce'),
                'default'     => '',
                'css'         => 'width:400px;min-height:100px;',
            ),
            'custom_mapping_end' => array(
                'type' => 'sectionend',
            ),
        );

        $form_fields += $custom_mapping_fileds;

        return apply_filters('kigocloud', $form_fields);
    }

    /**
     * @param WC_Order $order
     */
    public function display_admin_order_meta($order)
    {
        $shipping_vat = get_post_meta($order->get_id(), '_shipping_vat_number', true);
        $billing_vat  = get_post_meta($order->get_id(), '_billing_vat_number', true);

        if (!empty($shipping_vat)) {
            echo '<p><strong>'
                 . __('Shipping VAT number', 'kigocloud-for-woocommerce')
                 . '</strong><br />'
                 . esc_html($shipping_vat)
                 . '</p>';
        }

        if (!empty($billing_vat)) {
            echo '<p><strong>'
                 . __('Billing VAT number', 'kigocloud-for-woocommerce')
                 . '</strong><br />'
                 . esc_html($billing_vat)
                 . '</p>';
        }

        $isVATInvoice  = get_post_meta($order->get_id(), 'kigocloud_vat_invoices_checkbox', true);
        if (!empty($isVATInvoice)) {
            echo '<strong>' . __('Is VAT Invoice', 'kigocloud-for-woocommerce') . ':</strong>&nbsp;' . ($isVATInvoice ? esc_html__('Yes', 'kigocloud-for-woocommerce') : esc_html__('No', 'kigocloud-for-woocommerce')) . '<br>';
        }
        $vatCompany  = get_post_meta($order->get_id(), 'kigocloud_vat_invoices_company', true);
        if (!empty($vatCompany)) {
            echo '<strong>' . __('Company Name', 'kigocloud-for-woocommerce') . ':</strong>&nbsp;' . esc_html($vatCompany) . '<br>';
        }
        $vatAddress  = get_post_meta($order->get_id(), 'kigocloud_vat_invoices_address', true);
        if (!empty($vatAddress)) {
            echo '<strong>'. __('Company Address', 'kigocloud-for-woocommerce'). ':</strong>&nbsp;'. esc_html($vatAddress) . '<br>';
        }
        $vatCity  = get_post_meta($order->get_id(), 'kigocloud_vat_invoices_city', true);
        if (!empty($vatCity)) {
            echo '<strong>'. __('Company City', 'kigocloud-for-woocommerce'). ':</strong>&nbsp;'. esc_html($vatCity) . '<br>';
        }
        $vatZip  = get_post_meta($order->get_id(), 'kigocloud_vat_invoices_zip', true);
        if (!empty($vatZip)) {
            echo '<strong>'. __('Company ZIP', 'kigocloud-for-woocommerce') . ':</strong>&nbsp;' . esc_html($vatZip) . '<br>';
        }

        $vatVAT = get_post_meta($order->get_id(), 'kigocloud_vat_invoices_vat_number', true);
        if (!empty($vatVAT)) {
            echo '<strong> ' . __('Company VAT number', 'kigocloud-for-woocommerce') . ':</strong>&nbsp;' . esc_html($vatVAT) . '<br>';
        }
    }

    /**
     * @param WC_Order $order
     */
    public function display_admin_order_kigocloud($order)
    {
        $pos_number = get_post_meta($order->get_id(), '_kigocloud_pos_number', true);
        $document_type = get_post_meta($order->get_id(), '_kigocloud_doc_type', true);

        if (!empty($pos_number)) {
            echo '<p class="form-field form-field-wide wc-order-pos-number"><strong> '
                 . __('KigoCloud document', 'kigocloud-for-woocommerce')
                 . ': </strong>'
                 . (!empty($document_type) ? esc_html($document_type) . ' ' : '')
                 . esc_html($pos_number)
                 . '</p>';
        }
    }

	public function show_admin_notice(){
		$old_version = get_option('kigocloud_version', '1.0.0');
		$new_version = Woo_KigoCloud::PLUGIN_VERSION;
		$this->show_migration_admin_notice($old_version, $new_version);
	}

	/**
	 * @param $old_version
	 * @param $new_version
	 *
	 * @return void
	 */
	public function show_migration_admin_notice($old_version, $new_version) {
		// Only show the notice if the current user can manage options
		if (!current_user_can('manage_options')) {
			return;
		}

		// Check if settings have been saved
		$settings_saved = get_option('kigocloud_show_migration_notice', 0); // Default to '0' (not saved)
		if ($settings_saved != 1) {
			return; // Exit early if settings were saved
		}

		// Show the notice only if the plugin was updated to 1.7.0 from an earlier version
		//if (version_compare($old_version, '1.7.0', '<') && version_compare($new_version, '1.7.0', '>=')) {
			echo '<div class="notice notice-warning">';
			echo '<p><strong>' . esc_html__('KigoCloud for WooCommerce has been updated to version', 'kigocloud-for-woocommerce') . ' ' . esc_html($new_version) . '.</strong></p>';
			echo '<p>' . esc_html__('Please review and save your settings to ensure everything works correctly.', 'kigocloud-for-woocommerce') . '</p>';
			echo '<p><a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=kigocloud')) . '" class="button button-primary">' . esc_html__('Review & Save Settings', 'kigocloud-for-woocommerce') . '</a></p>';
			echo '</div>';
		//}
	}

	/**
	 * Show admin notice if WooCommerce Checkout Block is used
	 * and VAT invoice feature is enabled.
	 */
	public function checkout_block_admin_notice()
	{
		// samo u adminu
		if (!is_admin()) {
			return;
		}

		// samo ako je VAT opcija uključena
		$enableVatInvoice = get_option('kigocloud_vat_invoices', 0);
		if ((int)$enableVatInvoice === 0) {
			return;
		}

		// provjera postoji li Checkout block
		if (!function_exists('has_block')) {
			return;
		}

		$checkout_page_id = wc_get_page_id('checkout');
		if (!$checkout_page_id) {
			return;
		}

		$content = get_post_field('post_content', $checkout_page_id);

		if (has_block('woocommerce/checkout', $content)) {
			echo '<div class="notice notice-error is-dismissible">
			    <p><strong>KigoCloud plugin – R1 računi</strong></p>			
			    <p>
			        Na stranici za naplatu (Checkout) detektiran je <strong>WooCommerce Checkout Block</strong>.
			        WooCommerce Checkout Blokovi trenutno ne podržavaju dodavanje prilagođenih polja potrebnih za izdavanje R1 računa (npr. OIB, naziv tvrtke, adresa i slično).
			        Kako bi funkcionalnost R1 računa ispravno radila, potrebno je koristiti <strong>Classic Checkout</strong>.
			    </p>			
			    <p>
			        Molimo zamijenite Checkout Block s klasičnim checkoutom pomoću shortcode <code>[woocommerce_checkout]</code>
			    </p>
			</div>';
		}
	}
}
