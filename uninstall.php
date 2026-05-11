<?php
if (!current_user_can('activate_plugins')) {
    return;
}

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('kigocloud_username');
delete_option('kigocloud_password');
delete_option('kigocloud_employee_pin');
delete_option('kigocloud_shipping_reference');
delete_option('kigocloud_email_from_name');
delete_option('kigocloud_email_from');


$available_woo_gateways = \WC()->payment_gateways->get_available_payment_gateways();
foreach ($available_woo_gateways as $gateway_woo_sett => $gateway_woo_val) {
    delete_option('kigocloud_pos_type-' . esc_attr($gateway_woo_val->id));
    delete_option('kigocloud_payment_type-' . esc_attr($gateway_woo_val->id));
    delete_option('kigocloud_pdf_payment_type-' . esc_attr($gateway_woo_val->id));
}

add_action('wp_mail_from_name', 'kigocloud_revert_default_email_name');
function kigocloud_revert_default_email_name($name)
{
    return 'WordPress';
}


