<?php
/**
 * @author    Dejan Potocic <dpotocic@gmail.com>
 * @since     24.2.2020.
 * @copyright Symmetria d.o.o. Zagreb 2020
 */
if (!class_exists('Woo_KigoCloud_Request')) {
    class Woo_KigoCloud_Request
    {
        const DEFAULT_API_URL = 'https://app.kigo.cloud/hr/api/v1/';

        public $apiURL;

        public function __construct()
        {
            $this->apiURL = self::resolveApiUrl();
        }

        /**
         * The endpoint is no longer editable from the admin screen. Pointing the
         * integration at another host would send the API credentials and the order
         * data there, so that switch does not belong in a screen every shop admin
         * can open. Support can still override it, but only from code the shop
         * owner controls:
         *
         *   define( 'WOO_KIGOCLOUD_API_URL', 'https://…/hr/api/v1/' );  // wp-config.php
         *   add_filter( 'kigocloud_api_url', function () { return '…'; } );
         *
         * A value stored by an earlier version is still honoured so shops that talk
         * to a non-default instance keep working after the update.
         */
        public static function resolveApiUrl()
        {
            $configured = '';
            if (defined('WOO_KIGOCLOUD_API_URL')) {
                $configured = (string) WOO_KIGOCLOUD_API_URL;
            }
            if ($configured === '') {
                $configured = (string) get_option('kigocloud_api_url', '');
            }
            $configured = trim((string) apply_filters('kigocloud_api_url', $configured));
            if ($configured !== '') {
                return rtrim($configured, '/') . '/';
            }
            return self::DEFAULT_API_URL;
        }

        /**
         * With the "English for foreign customers" option on, a customer
         * whose country on the order is not Croatia gets the document and
         * the e-mail in English. The plugin is the one deciding: it asks
         * KigoCloud for English by calling the API under /en/ instead of
         * /hr/, and KigoCloud takes the language from the address.
         *
         * @param string $country Two-letter country code from the order.
         * @return bool
         */
        public static function use_english($country)
        {
            if ((string) get_option('kigocloud_english_for_foreign', '0') !== '1') {
                return false;
            }
            $country = strtoupper(trim((string) $country));
            return $country !== '' && $country !== 'HR';
        }

        /**
         * @param string $apiUrl
         * @return string The same endpoint with the language part set to English.
         */
        public static function english_api_url($apiUrl)
        {
            return str_replace('/hr/api/v1/', '/en/api/v1/', $apiUrl);
        }

        /**
         * Hooked on `woocommerce_order_status_changed` (4 args, but we
         * pull the order via wc_get_order for HPOS compatibility, so the
         * 4th `$order` arg is intentionally unused).
         *
         * @param int    $order_id
         * @param string $old_status
         * @param string $new_status
         */
        public function on_order_status_change($order_id, $old_status, $new_status)
        {
            // The KigoKasa plugin keeps sending until the shop switches over.
            if (Woo_KigoCloud_KigoKasa_Switch::is_kigokasa_active()) {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Skip if a document already exists for this order, including one
            // created by the KigoKasa plugin before this shop switched over.
            $posId = $order->get_meta('_kigocloud_id_pos');
            if (empty($posId)) {
                $posId = $order->get_meta('_kigokasa_id_pos');
            }
            if (!empty($posId)) {
                return;
            }

		    // Get payment method and document type
		    $payment_method = $order->get_payment_method();
		    $documentType = get_option('kigocloud_pos_type-' . esc_attr($payment_method), '0'); // Default to '0' (Disabled)
		    // If the document type is disabled, skip further processing
		    if ($documentType === '0') {
			    return;
		    }

		    // Check if the gateway is enabled
		    $gateway = wc_get_payment_gateway_by_order($order);
		    if (!$gateway) {
			    return; // Skip if no gateway is associated with the order
		    }

		    $gateway_setting_key = 'kigocloud_on_status-' . esc_attr($payment_method);
		    $callApi = get_option($gateway_setting_key, 0); // Default to 'Order Creation' (0)

		    /**
		     * $callApi options:
		     * '0' => __('Order Creation', 'kigocloud-for-woocommerce'),
		     * '1' => __('Order Completed', 'kigocloud-for-woocommerce'),
		     */

		    // Determine if API call should be made based on status changes and settings
		    $shouldCallApi = false;
		    if ($callApi == 0) {
			    // API call for "Order Creation": the first time the order enters one of these statuses,
			    // whatever it was before (a failed payment paid on the second try, a block checkout draft,
			    // a virtual order that goes straight to completed). Moving between them does not send again.
			    $orderedStatuses = array('on-hold', 'processing', 'completed');
			    $shouldCallApi = in_array($new_status, $orderedStatuses, true) && !in_array($old_status, $orderedStatuses, true);
		    } elseif ($callApi == 1) {
			    // API call for "Order Completed"
			    $shouldCallApi = ($new_status === 'completed' && $old_status !== $new_status);
		    }

		    // Execute the API call if the conditions are met
		    if ($shouldCallApi) {
			    $this->send_once($order);
		    }
	    }

        /**
         * Sends the order while holding a database lock, so two status changes
         * arriving together (payment callback and the customer's return) cannot
         * both create a document.
         *
         * @param WC_Order $order
         */
        private function send_once($order)
        {
            global $wpdb;

            $lock   = 'kigocloud_order_' . $order->get_id();
            $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
            if ($locked !== null && (int) $locked === 0) {
                return; // another request is sending this order right now
            }
            // NULL means the database could not give a lock at all: send as before rather than not at all.

            try {
                // The other request may have finished between the check above and the lock.
                $order->read_meta_data(true);
                if (empty($order->get_meta('_kigocloud_id_pos')) && empty($order->get_meta('_kigokasa_id_pos'))) {
                    $this->execute_api_call('invoice/create', $order);
                }
            } finally {
                if ((int) $locked === 1) {
                    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                }
            }
        }

        /**
         * @param string   $command
         * @param WC_Order $order
         * @return WP_Error
         */
        private function execute_api_call($command, $order)
        {
            $orderData         = $order->get_data();
            $documentType      = get_option('kigocloud_pos_type' . '-' . esc_attr($orderData['payment_method']));
            $documentTypeTitle = __('pos_type_' . $documentType, 'kigocloud-for-woocommerce');
            if ($documentType === '0') { // Disabled
                return;
            }

            $invoiceNote = '';
            $customerNote = $order->get_customer_note();
            if ($customerNote !== '') {
                $invoiceNote = $customerNote . "\r\n";
            }

            $mainBody           = new stdClass();
            $mainBody->pin      = get_option('kigocloud_pin');
            $mainBody->pos_type = $documentType;
            $mainBody->payment  = get_option('kigocloud_payment_type' . '-' . esc_attr($orderData['payment_method']));
            $mainBody->note     = $invoiceNote . __('Web Order #', 'kigocloud-for-woocommerce') . $order->get_order_number();
            $mainBody->internal_number = 'web' . $order->get_order_number();

			$fillMissingSKU = get_option('kigocloud_fill_empty_sku', 0);

            // With taxes switched off in WooCommerce the order carries no tax at all, which says nothing
            // about the real rate: then no rate is sent and the document uses the rate of the item itself.
            $sendVatPercent = wc_tax_enabled();

            $items = array();
            foreach (array_unique($order->get_items()) as $itemKey => $itemValue) { // this includes any coupons or discounts
                $itemName = $itemValue->get_name();
                $itemData = $itemValue->get_data();
                $product  = $itemValue->get_product();
                $itemSku  = $product ? $product->get_sku() : ''; // the product may be deleted since the order

                // Manually calculate tax rate (tax can be only rounded number without decimals and 5, 13, 25 are only allowed)
                $taxRate = $itemValue->get_total_tax() > 0 ? round(($itemValue->get_total_tax() / $itemValue->get_total()) * 100) : 0;

                $quantity    = (float)($itemData['quantity'] !== 0) ? $itemData['quantity'] : 1;
                $singlePrice = (float)$itemData['total'] / $quantity;

                $currentPrice = round($singlePrice, 4); // price without tax
                if ($taxRate > 0) {
                    // modify current price to include tax if tax rate exits
                    $currentPrice = round($currentPrice * (($taxRate / 100) + 1), 4);
                }

				if (empty($itemSku) && $fillMissingSKU == 1) {
					// from the product (or its variation), so the same product gets the same SKU on every order;
					// the order line id is used only when the line has no product at all
					$skuSource = $itemValue->get_variation_id() ? $itemValue->get_variation_id() : $itemValue->get_product_id();
					$itemSku = 'sku-' . ($skuSource ? $skuSource : $itemValue->get_id());
				}

                $item            = new stdClass();
                $item->reference = $itemSku;
                $item->quantity  = $quantity;
                $item->price     = $currentPrice;
                $item->item_name    = $itemName; // set name and tax rate for those items that not exist in kigocloud or not found by reference/sku
                if ($sendVatPercent) {
                    $item->vat_percent = $taxRate;
                }
                // $item->discount_percent = 0.00; TODO add option for this

                $items[] = $item;
            }

            // Get all fees in the order
            foreach ($order->get_fees() as $fee) {
                $fee_name = $fee->get_name(); // Fee name
                $fee_total = $fee->get_total(); // Fee amount
                $fee_tax = $fee->get_total_tax(); // Fee tax

                $item                   = new stdClass();
                $item->item_type        = 2; // service
                $item->reference        = 'fee-' . sanitize_title($fee_name);
                $item->item_name        = $fee_name;
                $item->price            = (float)$fee_total + (float)$fee_tax;
                if ($sendVatPercent) {
                    $item->vat_percent  = $fee->get_total_tax() > 0 ? round(($fee->get_total_tax() / $fee->get_total()) * 100) : 0;
                }
                $item->discount_percent = 0.00; // This could be done without discount_percent set, because fees are Services not Articles.
                $item->quantity         = 1;
                $items[]                = $item;
            }

            // Get shipping
            $shippingItemReference = get_option('kigocloud_shipping_reference', 'shipping');
            $totalShipping         = $order->get_shipping_total() + $order->get_shipping_tax(); // total price is base + tax
            if ((float)$totalShipping > 0) {
                $item                   = new stdClass();
                $item->reference        = empty($shippingItemReference) ? 'shipping' : $shippingItemReference;
                $item->item_name        = __('Dostava', 'kigocloud-for-woocommerce');
                $item->price            = $totalShipping;
                if ($sendVatPercent) {
                    $item->vat_percent  = $order->get_shipping_tax() > 0 ? round(($order->get_shipping_tax() / $order->get_shipping_total()) * 100) : 0.00;
                }
                $item->discount_percent = 0.00; // This could be done without discount_percent set, because shipping should be Service not Article.
                $item->quantity         = 1; //always one
                $item->item_type        = 2; // service
                $items[]                = $item;
            }

            $mainBody->items = $items;

            $client   = new stdClass();
            $vatField = 'shipping';
            $vat_number = null;

            if ((isset($orderData['billing']['first_name']) && $orderData['billing']['first_name'] !== '')) {
                $vatField = 'billing';
            }
            $vat_number_field = '_' . $vatField . '_vat_number';

            // Retrieve the custom mapping configuration from plugin settings
            $customMapping = get_option('kigocloud_custom_mapping', '');

            // Convert mapping string to an associative array.
            // Skip any pair that does not contain a colon to avoid
            // PHP undefined-index notices on malformed input.
            $mappingRules = array();
            if (!empty($customMapping)) {
                foreach (explode(',', $customMapping) as $pair) {
                    $parts = explode(':', trim($pair), 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    $key = trim($parts[0]);
                    $val = trim($parts[1]);
                    if ($key === '' || $val === '') {
                        continue;
                    }
                    $mappingRules[$key] = $val;
                }
            }

            $show_vat_invoice = get_option('kigocloud_vat_invoices', 0);
            if ($show_vat_invoice == 2) {
                // Add custom mapping rules for VAT number fields: kigocloud_vat_invoices_company, kigocloud_vat_invoices_address, kigocloud_vat_invoices_city, kigocloud_vat_invoices_zip, kigocloud_vat_invoices_vat_number
                $mappingRules['kigocloud_vat_invoices_company'] = $vatField . '.company';
                $mappingRules['kigocloud_vat_invoices_address'] = $vatField . '.address_1';
                $mappingRules['kigocloud_vat_invoices_city'] = $vatField . '.city';
                $mappingRules['kigocloud_vat_invoices_zip'] = $vatField . '.postcode';
                $mappingRules['kigocloud_vat_invoices_vat_number'] = $vat_number_field;

                // R1 data on orders placed while the KigoKasa plugin was active.
                $mappingRules['woo_kigokasa_api_vat_invoices_company'] = $vatField . '.company';
                $mappingRules['woo_kigokasa_api_vat_invoices_address'] = $vatField . '.address_1';
                $mappingRules['woo_kigokasa_api_vat_invoices_city'] = $vatField . '.city';
                $mappingRules['woo_kigokasa_api_vat_invoices_zip'] = $vatField . '.postcode';
                $mappingRules['woo_kigokasa_api_vat_invoices_vat_number'] = $vat_number_field;
            }

            $meta_data = $orderData['meta_data'];
            foreach ( $meta_data as $data => $meta_value ) {
                $data = $meta_value->get_data();
                if ( $data['key'] === $vat_number_field ) {
                    $vat_number = ( isset( $data['value'] ) && $data['value'] !== '' ) ? $data['value'] : '';
                }
                // Iterate through meta_data and apply mapping if applicable
                // An empty source field is skipped: with the full R1 block on the classic checkout the hidden
                // company fields are saved empty when the buyer does not ask for R1, and they must not
                // wipe the buyer's own address.
                if (!empty($data['key']) && isset($mappingRules[$data['key']]) && isset($data['value']) && $data['value'] !== '') {
                    $wooKey = $mappingRules[$data['key']];

                    // Check if the WooCommerce key is a nested key like "billing.company" or "shipping.address_1"
                    if (strpos($wooKey, '.') !== false) {
                        $keyParts = explode('.', $wooKey);
                        if (isset($keyParts[0]) && isset($keyParts[1])) {
                            $section = $keyParts[0]; // billing, shipping, etc.
                            $field   = $keyParts[1]; // actual field (e.g., company, vat_number)

                            // Ensure the section exists in orderData before assigning
                            if (isset($orderData[$section])) {
                                $orderData[$section][$field] = $data['value'];
                            }
                        }
                    } else {
                        // If it's a direct key, just assign normally
                        $orderData[$wooKey] = $data['value'];
                    }

                    // Special case: If it's a VAT number, store it separately
                    if ($wooKey === $vat_number_field) {
                        $vat_number = !empty($data['value']) ? $data['value'] : '';
                    }
                }
            }

            $first_name = ( isset( $orderData[ $vatField ]['first_name'] ) && $orderData[ $vatField ]['first_name'] !== '' ) ? $orderData[ $vatField ]['first_name'] : '';
            $last_name  = ( isset( $orderData[ $vatField ]['last_name'] ) && $orderData[ $vatField ]['last_name'] !== '' ) ? $orderData[ $vatField ]['last_name'] : '';
            $address_1  = ( isset( $orderData[ $vatField ]['address_1'] ) && $orderData[ $vatField ]['address_1'] !== '' ) ? $orderData[ $vatField ]['address_1'] : '';
            //$address_2  = ( isset( $orderData[ $vatField ]['address_2'] ) && $orderData[ $vatField ]['address_2'] !== '' ) ? $orderData[ $vatField ]['address_2'] : '';
            $city       = ( isset( $orderData[ $vatField ]['city'] ) && $orderData[ $vatField ]['city'] !== '' ) ? $orderData[ $vatField ]['city'] : '';
            //$state      = ( isset( $orderData[ $vatField ]['state'] ) && $orderData[ $vatField ]['state'] !== '' ) ? $orderData[ $vatField ]['state'] : '';
            $country    = ( isset( $orderData[ $vatField ]['country'] ) && $orderData[ $vatField ]['country'] !== '' ) ? $orderData[ $vatField ]['country'] : '';
            $postcode   = ( isset( $orderData[ $vatField ]['postcode'] ) && $orderData[ $vatField ]['postcode'] !== '' ) ? $orderData[ $vatField ]['postcode'] : '';
            $email      = ( isset( $orderData[ $vatField ]['email'] ) && $orderData[ $vatField ]['email'] !== '' ) ? $orderData[ $vatField ]['email'] : '';
            $phone      = ( isset( $orderData[ $vatField ]['phone'] ) && $orderData[ $vatField ]['phone'] !== '' ) ? $orderData[ $vatField ]['phone'] : '';
            $company    = ( isset( $orderData[ $vatField ]['company'] ) && $orderData[ $vatField ]['company'] !== '' ) ? $orderData[ $vatField ]['company'] : '';

            $fullName = $first_name . ' ' . $last_name;

            $client->oib            = $vat_number ? $vat_number : '-ORDER-' . $order->get_order_number(); // It tells API to add new client but not to render OIB
            $client->company_name   = $company ? $company : $fullName;
            $client->street         = $address_1;
            $client->city           = $city;
            $client->zip            = $postcode;
            $client->email          = $email;
            $client->phone          = $phone;
            $client->contact_person = $fullName;
            $client->country_iso    = $country; // 2-letter country code
	        $client->client_vat_type = $vat_number ? 0 : 1; // 0 - B2B with VAT number, 1 - B2C with or without VAT number

            $mainBody->client = $client;

            $username = get_option('kigocloud_username');
            $password = get_option('kigocloud_password');
            if ($username != 'admin_demo') { //dummy for now - for demo acc
                $password = md5($password);
            }

            $english = self::use_english($country);
            $apiUrl  = $english ? self::english_api_url($this->apiURL) : $this->apiURL;

            $postURL = esc_url_raw($apiUrl . $command);
            $payload = array(
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 0,
                'blocking'    => true,
                'httpversion' => '1.0',
                'sslverify'   => false,
                'data_format' => 'body',
                'headers'     => array(
                    // KigoCloud server reads literal header names via
                    // apache_request_headers() and matches keys
                    // "HTTP_X_USERNAME" / "HTTP_X_PASSWORD" (see
                    // protected/modules/v1/components/RestController.php
                    // line 102). Do NOT change these to the more standard
                    // "X-Username" form - the server will not see them.
                    'HTTP_X_USERNAME' => $username,
                    'HTTP_X_PASSWORD' => $password,
                    'Expect'          => '',
                    'Content-Type'    => 'application/json',
                    'Charset'         => 'utf-8',
                    'Accept'          => 'application/json',
                ),
                'body'        => json_encode($mainBody),
            );

            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                // phpcs:disable WordPress.PHP.DevelopmentFunctions
                error_log(print_r($payload, true));
                // phpcs:enable
            }

            $response = wp_remote_post($postURL, $payload);
            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                // phpcs:disable WordPress.PHP.DevelopmentFunctions
                error_log(print_r($response, true));
                // phpcs:enable
            }

            if (is_wp_error($response)) {
                $error_code    = $response->get_error_code();
                $error_message = $response->get_error_message();
                self::log_call($order->get_id(), $command, false, $error_code . ': ' . $error_message);
                return new \WP_Error($error_code, $error_message);
            }

            $rawBody = wp_remote_retrieve_body($response);
            $body    = json_decode($rawBody);

            if (!is_object($body)) {
                $excerpt = wp_strip_all_tags((string) $rawBody);
                $excerpt = function_exists('mb_substr') ? mb_substr($excerpt, 0, 200) : substr($excerpt, 0, 200);
                self::log_call($order->get_id(), $command, false, 'Invalid JSON: ' . $excerpt);
                return;
            }

            if (empty($body->pos_number) && isset($body->data->existing) && is_object($body->data->existing) && !empty($body->data->existing->pos_number)) {
                // KigoCloud already has a document for this order: link it instead of leaving the order without one.
                $body = $body->data->existing;
            }

            if (!empty($body->pos_number)) {
                $formattedPosNumber = $body->pos_number
                    . '/' . (isset($body->fina_data_place_short) ? $body->fina_data_place_short : '')
                    . '/' . (isset($body->fina_data_place_pos) ? $body->fina_data_place_pos : '');
                $paymentLabel = isset($body->payment_label) ? $body->payment_label : '';
                $note = __('KigoCloud %1$s created with document number %2$s and payment type %3$s', 'kigocloud-for-woocommerce');
                $order->add_order_note(sprintf($note, $documentTypeTitle, $formattedPosNumber, $paymentLabel));

                // HPOS-compatible meta storage. update_post_meta would
                // bypass the HPOS orders table on stores that have
                // migrated away from CPT order storage.
                $order->update_meta_data('_kigocloud_id_pos', isset($body->id_pos) ? $body->id_pos : '');
                $order->update_meta_data('_kigocloud_pos_number', $formattedPosNumber);
                $order->update_meta_data('_kigocloud_doc_type', $documentTypeTitle);
                $order->save();

                self::log_call($order->get_id(), $command, true, $documentTypeTitle . ' ' . $formattedPosNumber);

                $sendPdf = get_option('kigocloud_pdf_payment_type-' . esc_attr($orderData['payment_method']));
                if ($sendPdf === '1') {
                    // The order note above stays in the shop's language, the
                    // e-mail to a customer outside Croatia goes out in English.
                    $switched  = $english && $this->switch_to_english();
                    $mailTitle = $switched ? __('pos_type_' . $documentType, 'kigocloud-for-woocommerce') : $documentTypeTitle;
                    $this->send_invoice_email($order, $body, sanitize_email($email), $mailTitle, $formattedPosNumber, $fullName, $apiUrl);
                    if ($switched) {
                        $this->restore_language();
                    }
                }
            } else {
                $msg = isset($body->error) ? $body->error : (isset($body->message) ? $body->message : __('No document number returned', 'kigocloud-for-woocommerce'));
                self::log_call($order->get_id(), $command, false, is_scalar($msg) ? (string) $msg : wp_json_encode($msg));
            }
        }

        /**
         * Appends a single entry to the kigocloud_recent_logs option,
         * trimmed to the last 50 entries. Shown on the Logs tab in the
         * KigoCloud admin page.
         *
         * @param int    $order_id
         * @param string $endpoint
         * @param bool   $ok
         * @param string $message
         */
        public static function log_call($order_id, $endpoint, $ok, $message = '')
        {
            $logs = (array) get_option('kigocloud_recent_logs', array());
            $logs[] = array(
                'time'     => current_time('mysql'),
                'order_id' => (int) $order_id,
                'endpoint' => (string) $endpoint,
                'ok'       => (bool) $ok,
                'message'  => (string) $message,
            );
            if (count($logs) > 50) {
                $logs = array_slice($logs, -50);
            }
            update_option('kigocloud_recent_logs', $logs, false);
        }

        /**
         * Switches WordPress and this plugin's strings to English.
         *
         * @return bool False when nothing was switched, e.g. the site already runs in English.
         */
        private function switch_to_english()
        {
            if (!function_exists('switch_to_locale') || !switch_to_locale('en_US')) {
                return false;
            }
            $this->reload_textdomain();
            return true;
        }

        private function restore_language()
        {
            restore_previous_locale();
            $this->reload_textdomain();
        }

        /**
         * Loads this plugin's strings for the current locale. Since 6.1
         * switch_to_locale() reloads them by itself; before 6.1 it does not
         * know the plugin's own languages folder. No unload_textdomain()
         * here: since 6.7 it would block the reload for the rest of the
         * request.
         */
        private function reload_textdomain()
        {
            load_plugin_textdomain('kigocloud-for-woocommerce', false, dirname(dirname(plugin_basename(__FILE__))) . '/languages/');
        }

        public function add_pdf_attachment($attachments, $email_id, $email_object){
            return $attachments;
        }

        /**
         * @param string|null $apiUrl Endpoint used for the order; English for a customer outside Croatia.
         */
        public function send_invoice_email($order, $body, $email, $documentTypeTitle, $formattedPosNumber, $fullName, $apiUrl = null){
            global $wp_filesystem;

            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . '/wp-admin/includes/file.php';
            }

            $pdfFilename = $body->pos_number . '_' . $body->fina_data_place_short . '_' . $body->fina_data_place_pos;

            $checkout_url = \wc_get_checkout_url();
            $url          = wp_nonce_url($checkout_url, '_wpnonce', '_wpnonce');
            $credentials  = \request_filesystem_credentials($url, '', false, false, null);

            if ($credentials === false) {
                return;
            }

            $mailSubject =  sprintf(__('%1$s sending you %2$s no. %3$s', 'kigocloud-for-woocommerce'), get_bloginfo('name'), $documentTypeTitle, $formattedPosNumber);
            $mailText =
                /**
                 * translators: %1$s: Username
                 * translators: %2$s: Document Type (Invoice/Offer)
                 * translators: %3$s: Document Number
                 */
                sprintf(
                    __(
                        'Hi %1$s,<br /><br />Thank you for your order.<br /><br />Your %2$s with number <b>%3$s</b> is in the attachment.<br /><br />We look forward to fulfilling your order soon.',
                        'kigocloud-for-woocommerce'
                    ),
                    $fullName,
                    $documentTypeTitle,
                    $formattedPosNumber
                );

            // Get PDF from API
            $username = get_option('kigocloud_username');
            $password = md5(get_option('kigocloud_password'));
            $replyTo = get_option('kigocloud_reply_to');

            if ($username == 'admin_demo') { //dummy for now - for demo acc
                $password = get_option('kigocloud_password');
            }

            $postURL = esc_url_raw(($apiUrl ? $apiUrl : $this->apiURL) . 'invoice/getAsPdf/' . $body->id_pos);
            $payload = array(
                'method'      => 'GET',
                'timeout'     => 30,
                'redirection' => 0,
                'blocking'    => true,
                'httpversion' => '1.0',
                'sslverify'   => false,
                'headers'     => array(
                    'HTTP_X_USERNAME' => $username,
                    'HTTP_X_PASSWORD' => $password,
                ),
            );

            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                // phpcs:disable WordPress.PHP.DevelopmentFunctions
                error_log(print_r($payload, true));
                // phpcs:enable
            }

            $response = wp_remote_post($postURL, $payload);
            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                // phpcs:disable WordPress.PHP.DevelopmentFunctions
                error_log(print_r($response, true));
                // phpcs:enable
            }

            if (is_wp_error($response)) {
                $error_code    = wp_remote_retrieve_response_code($response);
                $error_message = wp_remote_retrieve_response_message($response);
                self::log_call($order->get_id(), 'invoice/getAsPdf', false, 'HTTP ' . $error_code . ': ' . $error_message);
                return new \WP_Error($error_code, $error_message);
            }

            $pdfContent = wp_remote_retrieve_body($response);
            if ($pdfContent === '' || strpos($pdfContent, '%PDF') !== 0) {
                self::log_call($order->get_id(), 'invoice/getAsPdf', false, 'PDF download returned no usable body');
                return;
            }

            if (!WP_Filesystem($credentials)) {
                \request_filesystem_credentials($url, '', true, false, null);
                return true;
            }

            $uploadDir = wp_upload_dir();
            $tmpDir    = $uploadDir['basedir'] . '/kigocloud-tmp';
            if (!file_exists($tmpDir)) {
                wp_mkdir_p($tmpDir);
            }

            $attachmentFilePath = $tmpDir . '/' . $pdfFilename . '.pdf';
            if (file_exists($attachmentFilePath)) {
                $attachmentFilePath = $tmpDir . '/' . $pdfFilename . '_' . wp_rand() . '.pdf';
            }

            $wp_filesystem->put_contents($attachmentFilePath, $pdfContent, FS_CHMOD_FILE);

            $headers  = 'MIME-Version: 1.0' . "\r\n";
            $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
            if (!empty($replyTo)) {
                $headers .= 'Reply-to: ' . $replyTo . "\r\n";
            }

            wp_mail($email, $mailSubject, $mailText, $headers, array($attachmentFilePath));

            // Delete the temporary PDF straight off disk. The previous
            // implementation registered the file as a WP Attachment and
            // then tried to delete by attachment id - but the cleanup
            // branch called file_exists() / unlink() on the integer id,
            // which always fails. We never need the Attachment row, so
            // skip it entirely and just unlink the file.
            if (file_exists($attachmentFilePath)) {
                @unlink($attachmentFilePath);
            }
        }

    }
}