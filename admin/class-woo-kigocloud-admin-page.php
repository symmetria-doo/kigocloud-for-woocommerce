<?php
/**
 * Standalone KigoCloud admin page.
 *
 * Registered as a top-level menu item with a dashicons-cloud icon and
 * laid out as a tabbed Settings page using WordPress native nav-tab
 * styling. No React, no build step, works on WP 5.5+.
 *
 * Each tab renders into the same wrap; navigation is via ?tab= query
 * param and the form posts to options.php through the Settings API.
 *
 * @package Woo_KigoCloud
 * @since   2.0.0
 */

class Woo_KigoCloud_Admin_Page
{
    const PAGE_SLUG  = 'kigocloud';
    const CAPABILITY = 'manage_woocommerce';
    const NONCE      = 'kigocloud_settings_nonce';

    public function register_menu()
    {
        add_menu_page(
            __('KigoCloud', 'kigocloud-for-woocommerce'),
            __('KigoCloud', 'kigocloud-for-woocommerce'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array($this, 'render_page'),
            'dashicons-cloud',
            56
        );
    }

    public function register_settings()
    {
        // Each tab uses its own option group. We hand-render the fields
        // inside each tab so we don't have to deal with add_settings_field
        // ceremony, but we still register the settings so the options.php
        // POST endpoint accepts the values.
        $opts_by_group = $this->settings_map();
        foreach ($opts_by_group as $group => $opts) {
            foreach ($opts as $opt) {
                register_setting($group, $opt);
            }
        }

        // Per-gateway settings are dynamic; register them all on load
        // if WC is available.
        if (function_exists('WC') && WC()->payment_gateways) {
            foreach (WC()->payment_gateways->payment_gateways() as $gateway) {
                if ('yes' !== $gateway->enabled) {
                    continue;
                }
                $gid = esc_attr($gateway->id);
                foreach (array('pos_type', 'payment_type', 'on_status', 'pdf_payment_type') as $stem) {
                    register_setting('kigocloud_orders', 'kigocloud_' . $stem . '-' . $gid);
                }
            }
        }
    }

    public function enqueue_admin_css($hook)
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        $inline = '
            .kigocloud-admin .nav-tab-wrapper { margin-bottom: 1.5em; }
            .kigocloud-admin .kc-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 16px 20px;
                margin-bottom: 16px;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .kigocloud-admin .kc-card h2 {
                margin-top: 0;
                padding-bottom: 8px;
                border-bottom: 1px solid #f0f0f1;
                font-size: 14px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: .04em;
                color: #50575e;
            }
            .kigocloud-admin .kc-card p.kc-desc { color: #50575e; margin: 0 0 1em; }
            .kigocloud-admin .form-table th { padding: 14px 10px 14px 0; width: 220px; }
            .kigocloud-admin .form-table td input[type=text],
            .kigocloud-admin .form-table td input[type=email],
            .kigocloud-admin .form-table td input[type=password],
            .kigocloud-admin .form-table td input[type=url],
            .kigocloud-admin .form-table td textarea {
                width: 100%; max-width: 480px;
            }
            .kigocloud-admin .form-table td textarea { min-height: 120px; font-family: Consolas, monospace; }
            .kigocloud-admin .kc-status-ok { color: #00a32a; font-weight: 600; }
            .kigocloud-admin .kc-status-bad { color: #d63638; font-weight: 600; }
            .kigocloud-admin .kc-gateway-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            .kigocloud-admin .kc-gateway-table th,
            .kigocloud-admin .kc-gateway-table td {
                padding: 10px; border-bottom: 1px solid #f0f0f1; text-align: left; vertical-align: middle;
            }
            .kigocloud-admin .kc-gateway-table th { background: #f6f7f7; }
            .kigocloud-admin .kc-gateway-table select { width: 100%; }
            .kigocloud-admin .kc-logs { width: 100%; border-collapse: collapse; font-size: 12px; }
            .kigocloud-admin .kc-logs th, .kigocloud-admin .kc-logs td { padding: 6px 8px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
            .kigocloud-admin .kc-logs th { background: #f6f7f7; text-align: left; }
            .kigocloud-admin .kc-logs .kc-method { font-family: Consolas, monospace; color: #2271b1; }
            .kigocloud-admin .kc-empty { color: #8c8f94; padding: 1em 0; font-style: italic; }
            .kigocloud-admin .kc-version-pill {
                display: inline-block; background: #f0f0f1; color: #50575e;
                padding: 2px 8px; border-radius: 10px; font-size: 11px;
                font-weight: 600; vertical-align: middle; margin-left: 6px;
            }
            .kigocloud-admin .kc-preview {
                border: 1px solid #dcdcde; border-radius: 6px;
                background: #f6f7f7; padding: 18px 20px; margin-top: 8px;
                max-width: 520px;
            }
            .kigocloud-admin .kc-preview-label {
                font-size: 11px; text-transform: uppercase;
                color: #8c8f94; font-weight: 600; letter-spacing: .04em;
                margin-bottom: 8px;
            }
            .kigocloud-admin .kc-preview-field {
                background: #fff; border: 1px solid #c3c4c7;
                border-radius: 4px; padding: 8px 10px;
                margin-bottom: 10px; font-size: 13px;
                display: flex; flex-direction: column;
            }
            .kigocloud-admin .kc-preview-field strong {
                font-size: 11px; color: #50575e;
                font-weight: 500; margin-bottom: 2px;
            }
            .kigocloud-admin .kc-preview-field .kc-placeholder {
                color: #a7aaad; font-style: italic;
            }
            .kigocloud-admin .kc-preview-field.kc-req strong::after {
                content: " *"; color: #d63638;
            }
            .kigocloud-admin .kc-preview-toggle {
                background: #fff; border: 1px solid #c3c4c7;
                border-radius: 4px; padding: 10px;
                margin-bottom: 10px; font-size: 13px;
                display: flex; align-items: center; gap: 8px;
            }
            .kigocloud-admin .kc-preview-toggle::before {
                content: ""; width: 16px; height: 16px;
                border: 1px solid #8c8f94; border-radius: 3px;
                display: inline-block; background: #fff;
            }
            .kigocloud-admin .kc-test-result {
                margin-top: 10px; padding: 8px 12px; border-radius: 4px;
                font-size: 13px; max-width: 520px;
            }
            .kigocloud-admin .kc-test-running { background: #f0f6fc; color: #2271b1; border: 1px solid #2271b1; }
            .kigocloud-admin .kc-test-ok      { background: #edfaef; color: #00a32a; border: 1px solid #00a32a; }
            .kigocloud-admin .kc-test-bad     { background: #fcf0f1; color: #d63638; border: 1px solid #d63638; }
        ';
        wp_register_style('kigocloud-admin', false, array(), Woo_KigoCloud::PLUGIN_VERSION);
        wp_enqueue_style('kigocloud-admin');
        wp_add_inline_style('kigocloud-admin', $inline);

        wp_enqueue_script(
            'kigocloud-admin',
            WOO_KIGOCLOUD_PLUGIN_URL . 'admin/js/kigocloud-admin.js',
            array(),
            Woo_KigoCloud::PLUGIN_VERSION,
            true
        );
    }

    public function render_page()
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $tabs = $this->tabs();
        $current = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'connection';
        if (!isset($tabs[$current])) {
            $current = 'connection';
        }
        ?>
        <div class="wrap kigocloud-admin">
            <h1>
                <?php echo esc_html__('KigoCloud for WooCommerce', 'kigocloud-for-woocommerce'); ?>
                <span class="kc-version-pill">v<?php echo esc_html(Woo_KigoCloud::PLUGIN_VERSION); ?></span>
            </h1>

            <?php settings_errors('kigocloud'); ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug)); ?>"
                       class="nav-tab <?php echo $current === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="kigocloud-tab-content">
                <?php
                $method = 'render_tab_' . $current;
                if (method_exists($this, $method)) {
                    $this->{$method}();
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ---------- helpers ----------

    private function tabs()
    {
        return array(
            'connection' => __('Connection', 'kigocloud-for-woocommerce'),
            'orders'     => __('Orders', 'kigocloud-for-woocommerce'),
            'r1'         => __('R1', 'kigocloud-for-woocommerce'),
            'email'      => __('Email', 'kigocloud-for-woocommerce'),
            'mapping'    => __('Mapping', 'kigocloud-for-woocommerce'),
            'logs'       => __('Logs', 'kigocloud-for-woocommerce'),
            'about'      => __('About', 'kigocloud-for-woocommerce'),
        );
    }

    private function settings_map()
    {
        return array(
            'kigocloud_connection' => array(
                'kigocloud_api_url',
                'kigocloud_username',
                'kigocloud_password',
                'kigocloud_pin',
            ),
            'kigocloud_orders' => array(
                'kigocloud_shipping_reference',
                'kigocloud_fill_empty_sku',
            ),
            'kigocloud_r1' => array(
                'kigocloud_vat_invoices',
                'kigocloud_require_billing_company',
            ),
            'kigocloud_email' => array(
                'kigocloud_email_from_name',
                'kigocloud_email_from',
                'kigocloud_reply_to',
            ),
            'kigocloud_mapping' => array(
                'kigocloud_custom_mapping',
            ),
        );
    }

    private function open_form($option_group)
    {
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
        settings_fields($option_group);
        echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $this->current_tab_for_form($option_group))) . '" />';
    }

    private function current_tab_for_form($option_group)
    {
        $map = array(
            'kigocloud_connection' => 'connection',
            'kigocloud_orders'     => 'orders',
            'kigocloud_r1'         => 'r1',
            'kigocloud_email'      => 'email',
            'kigocloud_mapping'    => 'mapping',
        );
        return isset($map[$option_group]) ? $map[$option_group] : 'connection';
    }

    private function close_form($submit_label = null)
    {
        if ($submit_label === null) {
            $submit_label = __('Save changes', 'kigocloud-for-woocommerce');
        }
        submit_button($submit_label);
        echo '</form>';
    }

    private function text_field($key, $label, $args = array())
    {
        $defaults = array(
            'type'        => 'text',
            'placeholder' => '',
            'description' => '',
            'default'     => '',
        );
        $args  = array_merge($defaults, $args);
        $value = get_option($key, $args['default']);
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="<?php echo esc_attr($args['type']); ?>"
                       id="<?php echo esc_attr($key); ?>"
                       name="<?php echo esc_attr($key); ?>"
                       value="<?php echo esc_attr($value); ?>"
                       placeholder="<?php echo esc_attr($args['placeholder']); ?>" />
                <?php if ($args['description'] !== ''): ?>
                    <p class="description"><?php echo wp_kses_post($args['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function select_field($key, $label, $options, $args = array())
    {
        $defaults = array('description' => '', 'default' => '');
        $args  = array_merge($defaults, $args);
        $value = (string) get_option($key, $args['default']);
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>">
                    <?php foreach ($options as $val => $opt_label): ?>
                        <option value="<?php echo esc_attr($val); ?>"<?php selected((string) $val, $value); ?>>
                            <?php echo esc_html($opt_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($args['description'] !== ''): ?>
                    <p class="description"><?php echo wp_kses_post($args['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function textarea_field($key, $label, $args = array())
    {
        $defaults = array('description' => '', 'default' => '', 'placeholder' => '');
        $args  = array_merge($defaults, $args);
        $value = get_option($key, $args['default']);
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <textarea id="<?php echo esc_attr($key); ?>"
                          name="<?php echo esc_attr($key); ?>"
                          placeholder="<?php echo esc_attr($args['placeholder']); ?>"><?php echo esc_textarea($value); ?></textarea>
                <?php if ($args['description'] !== ''): ?>
                    <p class="description"><?php echo wp_kses_post($args['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    // ---------- tabs ----------

    private function render_tab_connection()
    {
        ?>
        <div class="kc-card">
            <h2><?php esc_html_e('API credentials', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc"><?php esc_html_e('Endpoint and login used to talk to KigoCloud. Leave the URL blank to use the default https://app.kigo.cloud/hr/api/v1/', 'kigocloud-for-woocommerce'); ?></p>
            <?php $this->open_form('kigocloud_connection'); ?>
            <table class="form-table" role="presentation">
                <?php
                $this->text_field('kigocloud_api_url', __('API endpoint', 'kigocloud-for-woocommerce'), array(
                    'type'        => 'url',
                    'placeholder' => 'https://app.kigo.cloud/hr/api/v1/',
                    'description' => __('Override only if you talk to a non-default KigoCloud instance.', 'kigocloud-for-woocommerce'),
                ));
                $this->text_field('kigocloud_username', __('API username', 'kigocloud-for-woocommerce'), array(
                    'default' => 'admin_demo',
                ));
                $this->text_field('kigocloud_password', __('API password', 'kigocloud-for-woocommerce'), array(
                    'type'    => 'password',
                    'default' => 'admin_demo',
                ));
                $this->text_field('kigocloud_pin', __('Employee PIN', 'kigocloud-for-woocommerce'), array(
                    'default'     => '1',
                    'description' => __('KigoCloud employee PIN used when creating documents.', 'kigocloud-for-woocommerce'),
                ));
                ?>
            </table>
            <?php $this->close_form(); ?>
        </div>
        <?php
    }

    private function render_tab_orders()
    {
        ?>
        <div class="kc-card">
            <h2><?php esc_html_e('Order options', 'kigocloud-for-woocommerce'); ?></h2>
            <?php $this->open_form('kigocloud_orders'); ?>
            <table class="form-table" role="presentation">
                <?php
                $this->text_field('kigocloud_shipping_reference', __('Shipping reference', 'kigocloud-for-woocommerce'), array(
                    'placeholder' => 'shipping',
                    'description' => __('KigoCloud item reference used for the shipping line. Leave blank for "shipping".', 'kigocloud-for-woocommerce'),
                ));
                $this->select_field('kigocloud_fill_empty_sku', __('Fill empty SKU', 'kigocloud-for-woocommerce'), array(
                    '0' => __('No', 'kigocloud-for-woocommerce'),
                    '1' => __('Yes - use product ID', 'kigocloud-for-woocommerce'),
                ), array(
                    'default'     => '0',
                    'description' => __('When enabled, products with missing SKU are sent as sku-<item_id> to avoid KigoCloud lookup failures.', 'kigocloud-for-woocommerce'),
                ));
                ?>
            </table>
            <?php $this->close_form(); ?>
        </div>

        <div class="kc-card">
            <h2><?php esc_html_e('Payment gateways', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc">
                <?php esc_html_e('For each enabled gateway, choose the document type, payment method, and the order status that triggers the KigoCloud API call.', 'kigocloud-for-woocommerce'); ?>
            </p>
            <?php $this->render_gateways_table(); ?>
        </div>
        <?php
    }

    private function render_gateways_table()
    {
        if (!function_exists('WC') || !WC()->payment_gateways) {
            echo '<p class="kc-empty">' . esc_html__('WooCommerce is not active.', 'kigocloud-for-woocommerce') . '</p>';
            return;
        }
        $gateways = array();
        foreach (WC()->payment_gateways->payment_gateways() as $gw) {
            if ('yes' === $gw->enabled) {
                $gateways[] = $gw;
            }
        }
        if (empty($gateways)) {
            echo '<p class="kc-empty">' . esc_html__('No payment gateways are currently enabled in WooCommerce.', 'kigocloud-for-woocommerce') . '</p>';
            return;
        }

        $this->open_form('kigocloud_orders');
        ?>
        <table class="kc-gateway-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Gateway', 'kigocloud-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Document type', 'kigocloud-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Payment method', 'kigocloud-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Trigger on', 'kigocloud-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Send PDF', 'kigocloud-for-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gateways as $gw):
                    $gid = esc_attr($gw->id);
                    $pos_type      = (string) get_option('kigocloud_pos_type-' . $gid, '0');
                    $payment_type  = (string) get_option('kigocloud_payment_type-' . $gid, 'T');
                    $on_status     = (string) get_option('kigocloud_on_status-' . $gid, '1');
                    $pdf           = (string) get_option('kigocloud_pdf_payment_type-' . $gid, '0');
                ?>
                <tr>
                    <td><strong><?php echo esc_html($gw->title); ?></strong><br><small><code><?php echo esc_html($gid); ?></code></small></td>
                    <td>
                        <select name="kigocloud_pos_type-<?php echo $gid; ?>">
                            <option value="0"<?php selected($pos_type, '0'); ?>><?php esc_html_e('Disabled', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="1"<?php selected($pos_type, '1'); ?>><?php esc_html_e('Invoice', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="2"<?php selected($pos_type, '2'); ?>><?php esc_html_e('Offer', 'kigocloud-for-woocommerce'); ?></option>
                        </select>
                    </td>
                    <td>
                        <select name="kigocloud_payment_type-<?php echo $gid; ?>">
                            <option value="T"<?php selected($payment_type, 'T'); ?>><?php esc_html_e('Transaction account', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="K"<?php selected($payment_type, 'K'); ?>><?php esc_html_e('Card', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="G"<?php selected($payment_type, 'G'); ?>><?php esc_html_e('Cash', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="C"<?php selected($payment_type, 'C'); ?>><?php esc_html_e('Cheque', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="O"<?php selected($payment_type, 'O'); ?>><?php esc_html_e('Other', 'kigocloud-for-woocommerce'); ?></option>
                        </select>
                    </td>
                    <td>
                        <select name="kigocloud_on_status-<?php echo $gid; ?>">
                            <option value="0"<?php selected($on_status, '0'); ?>><?php esc_html_e('Order created', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="1"<?php selected($on_status, '1'); ?>><?php esc_html_e('Order completed', 'kigocloud-for-woocommerce'); ?></option>
                        </select>
                    </td>
                    <td>
                        <select name="kigocloud_pdf_payment_type-<?php echo $gid; ?>">
                            <option value="0"<?php selected($pdf, '0'); ?>><?php esc_html_e('No', 'kigocloud-for-woocommerce'); ?></option>
                            <option value="1"<?php selected($pdf, '1'); ?>><?php esc_html_e('Yes', 'kigocloud-for-woocommerce'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $this->close_form(__('Save gateways', 'kigocloud-for-woocommerce'));
    }

    private function render_tab_r1()
    {
        $wc_supports_block_r1 = defined('WC_VERSION') && version_compare(WC_VERSION, '8.6.0', '>=');
        $mode = (int) get_option('kigocloud_vat_invoices', 0);
        ?>
        <div class="kc-card">
            <h2><?php esc_html_e('R1 customer fields', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc">
                <?php esc_html_e('Adds company fields to the checkout for B2B invoicing: company name, address, OIB. Works on both classic and block checkout.', 'kigocloud-for-woocommerce'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Block checkout support:', 'kigocloud-for-woocommerce'); ?></strong>
                <?php if ($wc_supports_block_r1): ?>
                    <span class="kc-status-ok"><?php esc_html_e('Active (WC 8.6+)', 'kigocloud-for-woocommerce'); ?></span>
                <?php else: ?>
                    <span class="kc-status-bad"><?php esc_html_e('Requires WC 8.6+', 'kigocloud-for-woocommerce'); ?></span>
                <?php endif; ?>
            </p>
            <?php $this->open_form('kigocloud_r1'); ?>
            <table class="form-table" role="presentation">
                <?php
                $this->select_field('kigocloud_vat_invoices', __('Mode', 'kigocloud-for-woocommerce'), array(
                    '0' => __('Off', 'kigocloud-for-woocommerce'),
                    '1' => __('Show OIB / VAT field only', 'kigocloud-for-woocommerce'),
                    '2' => __('Show full R1 block (company, address, city, postcode, OIB)', 'kigocloud-for-woocommerce'),
                ), array('default' => '0'));

                $this->select_field('kigocloud_require_billing_company', __('Force billing company required', 'kigocloud-for-woocommerce'), array(
                    '0' => __('No (use WooCommerce default)', 'kigocloud-for-woocommerce'),
                    '1' => __('Yes (make billing.company a required field)', 'kigocloud-for-woocommerce'),
                ), array(
                    'default'     => '0',
                    'description' => __('When R1 mode 2 is on, customers must enter a company name. Enabling this option marks the standard WooCommerce billing.company field as required so the block checkout enforces it everywhere.', 'kigocloud-for-woocommerce'),
                ));
                ?>
            </table>
            <?php $this->close_form(); ?>
        </div>

        <div class="kc-card">
            <h2><?php esc_html_e('Preview - what the customer will see', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc">
                <?php esc_html_e('Live preview of the R1 fields that appear on the checkout based on the current mode. Asterisk marks required fields.', 'kigocloud-for-woocommerce'); ?>
            </p>
            <?php $this->render_r1_preview($mode); ?>
        </div>

        <div class="kc-card">
            <h2><?php esc_html_e('Block checkout diagnostics', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc"><?php esc_html_e('Live status of the Additional Checkout Fields integration for the WooCommerce block checkout. If the R1 fields are missing from the checkout, the row that is red below points to the cause.', 'kigocloud-for-woocommerce'); ?></p>
            <?php $this->render_r1_diagnostics(); ?>
        </div>

        <div class="kc-card">
            <h2><?php esc_html_e('Test KigoCloud connection', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc">
                <?php esc_html_e('Sends a one-shot synthetic R1 invoice to KigoCloud using the saved API credentials and a dummy OIB. No real order is created. Shows the raw KigoCloud response so you can verify credentials, network and endpoint before going live.', 'kigocloud-for-woocommerce'); ?>
            </p>
            <p>
                <button type="button" class="button button-secondary" id="kigocloud-test-push"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('kigocloud_test_push')); ?>"
                        data-running-label="<?php esc_attr_e('Sending test invoice to KigoCloud...', 'kigocloud-for-woocommerce'); ?>">
                    <?php esc_html_e('Send test R1 invoice', 'kigocloud-for-woocommerce'); ?>
                </button>
            </p>
            <div id="kigocloud-test-push-result" class="kc-test-result" style="display:none;"></div>
            <script>
                /* If JS is disabled the result box stays hidden; with JS we
                   reveal it so the test button has somewhere to render to. */
                document.getElementById('kigocloud-test-push-result').style.display = '';
            </script>
        </div>
        <?php
    }

    private function render_r1_diagnostics()
    {
        $wc_version            = defined('WC_VERSION') ? WC_VERSION : null;
        $fn_exists             = function_exists('woocommerce_register_additional_checkout_field');
        $mode                  = Woo_KigoCloud_R1::mode();
        $block_supported       = Woo_KigoCloud_R1::block_supported();
        $checkout_uses_block   = Woo_KigoCloud_R1::checkout_uses_block();
        $register_attempted    = Woo_KigoCloud_R1::$register_attempted;
        $register_status       = Woo_KigoCloud_R1::$register_status;
        $checkout_page_id      = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : 0;
        $checkout_edit_link    = $checkout_page_id ? get_edit_post_link($checkout_page_id) : '';

        $rows = array(
            array(
                'label'   => __('WooCommerce version', 'kigocloud-for-woocommerce'),
                'ok'      => $wc_version && version_compare($wc_version, '8.9', '>='),
                'value'   => $wc_version ? $wc_version : __('not detected', 'kigocloud-for-woocommerce'),
                'hint'    => __('Additional Checkout Fields API was officially shipped in WooCommerce 8.9. On 8.6-8.8 the function exists but may not render fields reliably; upgrading WC is the fix.', 'kigocloud-for-woocommerce'),
            ),
            array(
                'label'   => __('Field API function available', 'kigocloud-for-woocommerce'),
                'ok'      => $fn_exists,
                'value'   => $fn_exists ? 'woocommerce_register_additional_checkout_field()' : __('missing', 'kigocloud-for-woocommerce'),
                'hint'    => __('If missing, WooCommerce is too old to register checkout fields via PHP; upgrade WC.', 'kigocloud-for-woocommerce'),
            ),
            array(
                'label'   => __('R1 mode', 'kigocloud-for-woocommerce'),
                'ok'      => $mode > 0,
                'value'   => $mode === 0 ? __('Off', 'kigocloud-for-woocommerce') : ($mode === 1 ? __('OIB only', 'kigocloud-for-woocommerce') : __('Full R1 block', 'kigocloud-for-woocommerce')),
                'hint'    => __('When set to Off the plugin registers nothing on the checkout.', 'kigocloud-for-woocommerce'),
            ),
            array(
                'label'   => __('Checkout page uses block', 'kigocloud-for-woocommerce'),
                'ok'      => $checkout_uses_block,
                'value'   => $checkout_uses_block ? __('Yes', 'kigocloud-for-woocommerce') : __('No (classic [woocommerce_checkout] shortcode)', 'kigocloud-for-woocommerce'),
                'hint'    => $checkout_edit_link
                    ? sprintf(__('Block checkout fields only appear if the Checkout page actually contains the WooCommerce checkout block. <a href="%s">Edit checkout page</a>.', 'kigocloud-for-woocommerce'), esc_url($checkout_edit_link))
                    : __('Block checkout fields only appear if the Checkout page actually contains the WooCommerce checkout block.', 'kigocloud-for-woocommerce'),
            ),
            array(
                'label'   => __('Hook fired this request', 'kigocloud-for-woocommerce'),
                'ok'      => $register_attempted,
                'value'   => $register_attempted ? __('Yes, register_block_fields() was called', 'kigocloud-for-woocommerce') : __('No - woocommerce_init never reached our handler', 'kigocloud-for-woocommerce'),
                'hint'    => __('If No, another plugin or fatal error is preventing woocommerce_init from running through to our handler. Check the WP debug.log.', 'kigocloud-for-woocommerce'),
            ),
            array(
                'label'   => __('Registration status', 'kigocloud-for-woocommerce'),
                'ok'      => $register_status === 'ok',
                'value'   => $register_status === '' ? __('not yet attempted', 'kigocloud-for-woocommerce') : $register_status,
                'hint'    => __('"ok" means WC accepted both field registrations. Any other value points to the rejection reason.', 'kigocloud-for-woocommerce'),
            ),
        );
        ?>
        <table class="kc-gateway-table" style="margin-top:6px;">
            <thead>
                <tr>
                    <th style="width:280px;"><?php esc_html_e('Check', 'kigocloud-for-woocommerce'); ?></th>
                    <th><?php esc_html_e('Result', 'kigocloud-for-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?php echo esc_html($r['label']); ?></strong></td>
                        <td>
                            <span class="<?php echo $r['ok'] ? 'kc-status-ok' : 'kc-status-bad'; ?>">
                                <?php echo $r['ok'] ? 'OK' : '!'; ?>
                            </span>
                            &nbsp;<?php echo esc_html($r['value']); ?>
                            <?php if (!empty($r['hint']) && !$r['ok']): ?>
                                <div style="color:#50575e;font-size:12px;margin-top:4px;"><?php echo wp_kses_post($r['hint']); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><strong><?php esc_html_e('Field IDs registered', 'kigocloud-for-woocommerce'); ?></strong></td>
                    <td>
                        <code><?php echo esc_html(Woo_KigoCloud_R1::FIELD_R1_VAT_NUMBER); ?></code>
                        <?php if (Woo_KigoCloud_R1::mode() === 2): ?>
                            <br><code><?php echo esc_html(Woo_KigoCloud_R1::FIELD_R1_COMPANY); ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private function render_r1_preview($mode)
    {
        if ($mode === 0) {
            echo '<p class="kc-empty">' . esc_html__('R1 mode is off. No extra fields are added to the checkout.', 'kigocloud-for-woocommerce') . '</p>';
            return;
        }
        ?>
        <div class="kc-preview">
            <div class="kc-preview-label"><?php esc_html_e('On the checkout, inside the billing address block:', 'kigocloud-for-woocommerce'); ?></div>

            <?php if ($mode === 2): ?>
                <div class="kc-preview-field kc-req">
                    <strong><?php esc_html_e('Company name (for invoice)', 'kigocloud-for-woocommerce'); ?></strong>
                    <span class="kc-placeholder">Symmetria d.o.o.</span>
                </div>
            <?php endif; ?>

            <div class="kc-preview-field <?php echo $mode === 2 ? 'kc-req' : ''; ?>">
                <strong><?php esc_html_e('OIB / VAT number', 'kigocloud-for-woocommerce'); ?></strong>
                <span class="kc-placeholder">11 digits, e.g. 49942588956</span>
            </div>

            <?php if ($mode === 2): ?>
                <div class="kc-preview-label" style="margin-top:14px;">
                    <?php esc_html_e('The standard WooCommerce billing address (address, city, postcode, country) is shown above these fields and is used as the company address.', 'kigocloud-for-woocommerce'); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX handler for the "Send test R1 invoice" button. Builds a
     * minimal synthetic payload and pushes it to the configured
     * KigoCloud endpoint using the saved credentials. Returns a
     * human-readable summary suitable for inline display.
     */
    public function ajax_test_push()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'kigocloud-for-woocommerce')), 403);
        }
        check_ajax_referer('kigocloud_test_push', 'nonce');

        $username = get_option('kigocloud_username');
        $password = get_option('kigocloud_password');
        if (empty($username) || empty($password)) {
            wp_send_json_error(array('message' => __('API username or password is empty. Fill them on the Connection tab first.', 'kigocloud-for-woocommerce')));
        }

        if ($username !== 'admin_demo') {
            $password = md5($password);
        }

        $url = Woo_KigoCloud_Request::resolveApiUrl() . 'invoice/create';

        $body = new stdClass();
        $body->pin             = get_option('kigocloud_pin', '1');
        $body->pos_type        = 1; // Invoice
        $body->payment         = 'T'; // Transaction account
        $body->note            = 'KigoCloud admin test push at ' . current_time('mysql');
        $body->internal_number = 'test-' . wp_rand(1000, 9999);

        $item              = new stdClass();
        $item->reference   = 'kigocloud-test';
        $item->item_name   = 'KigoCloud test item';
        $item->quantity    = 1;
        $item->price       = 1.25;
        $item->vat_percent = 25;
        $body->items       = array($item);

        $client                  = new stdClass();
        $client->oib             = '49942588956'; // valid sample OIB
        $client->company_name    = 'KigoCloud test buyer';
        $client->street          = 'Test 1';
        $client->city            = 'Zagreb';
        $client->zip             = '10000';
        $client->email           = '';
        $client->phone           = '';
        $client->contact_person  = 'KigoCloud test buyer';
        $client->country_iso     = 'HR';
        $client->client_vat_type = 0;
        $body->client            = $client;

        $response = wp_remote_post($url, array(
            'method'      => 'POST',
            'timeout'     => 20,
            'redirection' => 0,
            'blocking'    => true,
            'sslverify'   => false,
            'data_format' => 'body',
            'headers'     => array(
                'HTTP_X_USERNAME' => $username,
                'HTTP_X_PASSWORD' => $password,
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
            ),
            'body'        => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            Woo_KigoCloud_Request::log_call(0, 'invoice/create (test)', false, $response->get_error_message());
            wp_send_json_error(array('message' => sprintf(
                /* translators: %s: error message */
                __('Network error: %s', 'kigocloud-for-woocommerce'),
                $response->get_error_message()
            )));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw);

        if ($code >= 200 && $code < 300 && is_object($json) && !empty($json->pos_number)) {
            $msg = sprintf(
                /* translators: 1: doc number, 2: place short, 3: pos number */
                __('OK. KigoCloud accepted the test invoice: %1$s/%2$s/%3$s', 'kigocloud-for-woocommerce'),
                $json->pos_number,
                isset($json->fina_data_place_short) ? $json->fina_data_place_short : '',
                isset($json->fina_data_place_pos) ? $json->fina_data_place_pos : ''
            );
            Woo_KigoCloud_Request::log_call(0, 'invoice/create (test)', true, $msg);
            wp_send_json_success(array('message' => $msg));
        }

        $hint = '';
        if (is_object($json) && !empty($json->error)) {
            $hint = (string) $json->error;
        } elseif (is_object($json) && !empty($json->message)) {
            $hint = (string) $json->message;
        }
        $message = sprintf(
            /* translators: 1: http status, 2: response body */
            __('KigoCloud returned HTTP %1$d. %2$s', 'kigocloud-for-woocommerce'),
            $code,
            $hint !== '' ? $hint : wp_trim_words(wp_strip_all_tags((string) $raw), 30, '...')
        );
        Woo_KigoCloud_Request::log_call(0, 'invoice/create (test)', false, $message);
        wp_send_json_error(array('message' => $message));
    }

    private function render_tab_email()
    {
        ?>
        <div class="kc-card">
            <h2><?php esc_html_e('Outgoing mail', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc"><?php esc_html_e('Overrides the From and Reply-To headers on outgoing WordPress mail. Global, not just KigoCloud emails.', 'kigocloud-for-woocommerce'); ?></p>
            <?php $this->open_form('kigocloud_email'); ?>
            <table class="form-table" role="presentation">
                <?php
                $this->text_field('kigocloud_email_from_name', __('From name', 'kigocloud-for-woocommerce'));
                $this->text_field('kigocloud_email_from', __('From email', 'kigocloud-for-woocommerce'), array('type' => 'email'));
                $this->text_field('kigocloud_reply_to', __('Reply-To email', 'kigocloud-for-woocommerce'), array('type' => 'email'));
                ?>
            </table>
            <?php $this->close_form(); ?>
        </div>
        <?php
    }

    private function render_tab_mapping()
    {
        ?>
        <div class="kc-card">
            <h2><?php esc_html_e('Custom meta mapping', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc">
                <?php esc_html_e('Override billing data with values from other order meta keys. Useful when another plugin already stores R1 data under its own meta names.', 'kigocloud-for-woocommerce'); ?>
                <br>
                <?php esc_html_e('Format: source_meta:target.field, comma-separated.', 'kigocloud-for-woocommerce'); ?>
            </p>
            <?php $this->open_form('kigocloud_mapping'); ?>
            <table class="form-table" role="presentation">
                <?php
                $this->textarea_field('kigocloud_custom_mapping', __('Mapping rules', 'kigocloud-for-woocommerce'), array(
                    'placeholder' => 'r1_oib_tvrtke:_billing_vat_number, r1_ime_tvrtke:billing.company',
                    'description' => __('Example: r1_oib_tvrtke:_billing_vat_number, r1_ime_tvrtke:billing.company, r1_adresa_tvrtke:billing.address_1', 'kigocloud-for-woocommerce'),
                ));
                ?>
            </table>
            <?php $this->close_form(); ?>
        </div>
        <?php
    }

    private function render_tab_logs()
    {
        $logs = (array) get_option('kigocloud_recent_logs', array());
        ?>
        <div class="kc-card">
            <h2><?php esc_html_e('Recent API calls', 'kigocloud-for-woocommerce'); ?></h2>
            <p class="kc-desc"><?php esc_html_e('Last 50 KigoCloud API calls. Useful for debugging order push failures.', 'kigocloud-for-woocommerce'); ?></p>
            <?php if (empty($logs)): ?>
                <p class="kc-empty"><?php esc_html_e('No API calls have been logged yet. Once an order triggers KigoCloud, recent attempts will show up here.', 'kigocloud-for-woocommerce'); ?></p>
            <?php else: ?>
                <table class="kc-logs">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('When', 'kigocloud-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Order', 'kigocloud-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Endpoint', 'kigocloud-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Result', 'kigocloud-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice(array_reverse($logs), 0, 50) as $entry): ?>
                            <?php $entry = (array) $entry; ?>
                            <tr>
                                <td><?php echo esc_html(isset($entry['time']) ? $entry['time'] : '-'); ?></td>
                                <td>
                                    <?php if (!empty($entry['order_id'])): ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?action=edit&post=' . (int) $entry['order_id'])); ?>">#<?php echo (int) $entry['order_id']; ?></a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><span class="kc-method"><?php echo esc_html(isset($entry['endpoint']) ? $entry['endpoint'] : '-'); ?></span></td>
                                <td>
                                    <?php
                                    $ok = !empty($entry['ok']);
                                    $cls = $ok ? 'kc-status-ok' : 'kc-status-bad';
                                    $txt = isset($entry['message']) && $entry['message'] !== '' ? $entry['message'] : ($ok ? __('OK', 'kigocloud-for-woocommerce') : __('Error', 'kigocloud-for-woocommerce'));
                                    echo '<span class="' . esc_attr($cls) . '">' . esc_html($txt) . '</span>';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=logs&kigocloud_clear_logs=1'), 'kigocloud_clear_logs')); ?>"
                       class="button"><?php esc_html_e('Clear logs', 'kigocloud-for-woocommerce'); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function maybe_clear_logs()
    {
        if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }
        if (empty($_GET['kigocloud_clear_logs'])) {
            return;
        }
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        check_admin_referer('kigocloud_clear_logs');
        delete_option('kigocloud_recent_logs');
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=logs'));
        exit;
    }

    private function render_tab_about()
    {
        ?>
        <div class="kc-card">
            <h2><?php esc_html_e('About', 'kigocloud-for-woocommerce'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Version', 'kigocloud-for-woocommerce'); ?></th>
                    <td><code><?php echo esc_html(Woo_KigoCloud::PLUGIN_VERSION); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Source', 'kigocloud-for-woocommerce'); ?></th>
                    <td><a href="https://github.com/dpotocic/kigocloud-for-woocommerce" target="_blank" rel="noopener">github.com/dpotocic/kigocloud-for-woocommerce</a></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Releases', 'kigocloud-for-woocommerce'); ?></th>
                    <td><a href="https://github.com/dpotocic/kigocloud-for-woocommerce/releases" target="_blank" rel="noopener">github.com/dpotocic/kigocloud-for-woocommerce/releases</a></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('API endpoint', 'kigocloud-for-woocommerce'); ?></th>
                    <td><code><?php echo esc_html(Woo_KigoCloud_Request::resolveApiUrl()); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('WooCommerce', 'kigocloud-for-woocommerce'); ?></th>
                    <td><?php echo defined('WC_VERSION') ? esc_html(WC_VERSION) : esc_html__('not detected', 'kigocloud-for-woocommerce'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('PHP', 'kigocloud-for-woocommerce'); ?></th>
                    <td><?php echo esc_html(PHP_VERSION); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('WordPress', 'kigocloud-for-woocommerce'); ?></th>
                    <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                </tr>
            </table>
        </div>
        <?php
    }
}
