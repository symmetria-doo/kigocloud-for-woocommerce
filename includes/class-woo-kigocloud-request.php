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

        public static function resolveApiUrl()
        {
            $configured = trim((string) get_option('kigocloud_api_url', ''));
            if ($configured !== '') {
                return rtrim($configured, '/') . '/';
            }
            return self::DEFAULT_API_URL;
        }

        /**
         * @param WC_Order       $order         Order data.
         * @param bool           $sent_to_admin Send to admin (default: false).
         * @param bool           $plain_text    Plain text email (default: false).
         * @param WC_Email_Order $email         Order email object.
         */
        public function send_api_request($order, $sent_to_admin, $plain_text, $email)
        {
            if (is_admin()) { // Skip if its admin.
                return;
            }

            if (1 === (int)get_option( 'kigocloud_skip_status_order_created' )) { // since v1.3.3
                return;
            }
            $posId = get_post_meta($order->get_id(), '_kigocloud_id_pos');

            //$posId = $order->get_meta('_kigocloud_id_pos');
            if (!empty($posId)) { // Skip it if its already created
                return;
            }

            $this->execute_api_call('invoice/create', $order);
        }

        /**
         * @param int $order_id
         */
        public function send_api_request_on_completed($order_id)
        {
            if (!is_admin()) { // This should be only executed on admin
                return;
            }

            if (1 === (int)get_option( 'kigocloud_skip_status_order_completed' )) { // since v1.3.3
                return;
            }

            $order = wc_get_order($order_id);
            $posId = $order->get_meta('_kigocloud_id_pos');
            if (!empty($posId)) { // Skip it if its already created
                return;
            }

            // Execute only if status is changed to completed!
            $order_data   = $order->get_data();
            $order_status = $order_data['status'];

            if ($order_status === 'completed') {
                $this->execute_api_call('invoice/create', $order);
            }
        }

	    /**
	     * @param $order_id
	     * @param $old_status
	     * @param $new_status
	     *
	     * @return void
	     */
	    public function on_order_status_change($order_id, $old_status, $new_status) {
		    $order = wc_get_order($order_id);

		    // Skip API call if KigoCloud POS ID already exists
		    $posId = $order->get_meta('_kigocloud_id_pos');
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
			    // API call for "Order Creation" on specific transitions
			    $shouldCallApi = (
				    ($old_status === 'pending' && $new_status === 'on-hold') ||
				    ($old_status === 'pending' && $new_status === 'processing')
			    );
		    } elseif ($callApi == 1) {
			    // API call for "Order Completed"
			    $shouldCallApi = ($new_status === 'completed' && $old_status !== $new_status);
		    }

		    // Execute the API call if the conditions are met
		    if ($shouldCallApi) {
			    $this->execute_api_call('invoice/create', $order);
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
            if (!empty($order->get_customer_note())){
                $invoiceNote = $order->get_customer_note() . "\n\r";
            }

            $mainBody           = new stdClass();
            $mainBody->pin      = get_option('kigocloud_pin');
            $mainBody->pos_type = $documentType;
            $mainBody->payment  = get_option('kigocloud_payment_type' . '-' . esc_attr($orderData['payment_method']));
            $mainBody->note     = $invoiceNote . __('Web Order #', 'kigocloud-for-woocommerce') . $order->get_order_number();
            $mainBody->internal_number = 'web' . $order->get_order_number();

			$fillMissingSKU = get_option('kigocloud_fill_empty_sku', 0);

            $items = array();
            foreach (array_unique($order->get_items()) as $itemKey => $itemValue) { // this includes any coupons or discounts
                $itemName = $itemValue->get_name();
                $itemData = $itemValue->get_data();
                $product  = $itemValue->get_product();
                $itemSku  = $product->get_sku();

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
					$itemSku = 'sku-' . $itemValue->get_id();
				}

                $item            = new stdClass();
                $item->reference = $itemSku;
                $item->quantity  = $quantity;
                $item->price     = $currentPrice;
                $item->item_name    = $itemName; // set name and tax rate for those items that not exist in kigocloud or not found by reference/sku
                $item->vat_percent  = $taxRate;
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
                $item->vat_percent      = $fee->get_total_tax() > 0 ? round(($fee->get_total_tax() / $fee->get_total()) * 100) : 0;
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
                $item->vat_percent      = $order->get_shipping_tax() > 0 ? round(($order->get_shipping_tax() / $order->get_shipping_total()) * 100) : 0.00;
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

            // Convert mapping string to an associative array
            $mappingRules = [];
            if (!empty($customMapping)) {
                $mappingPairs = explode(',', $customMapping);
                foreach ($mappingPairs as $pair) {
                    list($customKey, $wooKey) = explode(':', trim($pair));
                    $mappingRules[trim($customKey)] = trim($wooKey);
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
            }

            $meta_data = $orderData['meta_data'];
            foreach ( $meta_data as $data => $meta_value ) {
                $data = $meta_value->get_data();
                if ( $data['key'] === $vat_number_field ) {
                    $vat_number = ( isset( $data['value'] ) && $data['value'] !== '' ) ? $data['value'] : '';
                }
                // Iterate through meta_data and apply mapping if applicable
                if (!empty($data['key']) && isset($mappingRules[$data['key']])) {
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

            $postURL = esc_url_raw($this->apiURL . $command);
            $payload = array(
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 0,
                'blocking'    => true,
                'httpversion' => '1.0',
                'sslverify'   => false,
                'data_format' => 'body',
                'headers'     => array(
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
                $error_code    = wp_remote_retrieve_response_code($response);
                $error_message = wp_remote_retrieve_response_message($response);
                return new \WP_Error($error_code, $error_message);
            }

            $body = json_decode($response['body']);

            if (!empty($body->pos_number)) {
                $formattedPosNumber = $body->pos_number . '/' . $body->fina_data_place_short . '/' . $body->fina_data_place_pos;
                $note = __('KigoCloud %1$s created with document number %2$s and payment type %3$s', 'kigocloud-for-woocommerce');
                $order->add_order_note(sprintf($note, $documentTypeTitle, $formattedPosNumber, $body->payment_label));

                //$order->set_meta_data(['_kigocloud_id_pos' => $body->id_pos, 'kigocloud_pos_number' => $body->pos_number]);
                $order->save();

                update_post_meta($order->get_id(), '_kigocloud_id_pos', $body->id_pos);
                update_post_meta($order->get_id(), '_kigocloud_pos_number', $formattedPosNumber);
                update_post_meta($order->get_id(), '_kigocloud_doc_type', $documentTypeTitle);

                $sendPdf = get_option('kigocloud_pdf_payment_type-' . esc_attr($orderData['payment_method']));
                if ($sendPdf === '1') {
                    $this->send_invoice_email($order, $body, sanitize_email($email), $documentTypeTitle, $formattedPosNumber, $fullName);
                }
            }
        }

        public function add_pdf_attachment($attachments, $email_id, $email_object){
            // Avoiding errors and problems
            if ( ! is_a( $email_object, 'WC_Order' ) || ! isset( $email_id ) ) {
                return $attachments;
            }

            // Add attachemnt if manual invoice email is sent from admin
            if( $email_id === 'customer_invoice' ){


            }
        }

        public function send_invoice_email($order, $body, $email, $documentTypeTitle, $formattedPosNumber, $fullName){
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

            $postURL = esc_url_raw($this->apiURL . 'invoice/getAsPdf/' . $body->id_pos);
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
                return new \WP_Error($error_code, $error_message);
            }

            $pdfContent = $response['body'];

            if (!WP_Filesystem( $credentials ) ) {
                \request_filesystem_credentials( $url, '', true, false, null );
                return true;
            }

            $uploadDir = wp_upload_dir();
            $tmpDir = $uploadDir['basedir'] . '/kigocloud-tmp';
            if (!file_exists($tmpDir)) {
                wp_mkdir_p($tmpDir);
            }

            $attachmentFilePath = $tmpDir . '/' . $pdfFilename . '.pdf';
            if (file_exists($attachmentFilePath)){
                $attachmentFilePath = $tmpDir . '/' . $pdfFilename . '_' . wp_rand() . '.pdf';
            }

            WP_Filesystem($credentials);
            $wp_filesystem->put_contents($attachmentFilePath,$pdfContent,FS_CHMOD_FILE);

            $filetype = wp_check_filetype($pdfFilename, null);
            $fileAttr = wp_parse_url($attachmentFilePath);
            $attachment_id = wp_insert_attachment(
                array(
                    'guid'           => $attachmentFilePath,
                    'post_mime_type' => $filetype['type'],
                    'post_title'     => $pdfFilename,
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ),
                $fileAttr['path'],
                0
            );

            $headers = 'MIME-Version: 1.0' . "\r\n";
            $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
            if (!empty($replyTo)){
                $headers .= 'Reply-to: ' . $replyTo ."\r\n";
            }

            wp_mail($email, $mailSubject, $mailText, $headers, array($attachmentFilePath));

            $deleted = wp_delete_attachment($attachment_id, true);
            if ($deleted === false || $deleted === null) {
                if (!file_exists($attachment_id)) {
                    return;
                }
                unlink($attachment_id);
            }

            $this->removeDir($tmpDir);
        }

        /**
         * @param $tmpDir
         */
        private function removeDir($tmpDir)
        {
            $it    = new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($tmpDir);
        }
    }
}