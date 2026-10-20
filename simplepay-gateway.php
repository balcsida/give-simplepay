<?php
/**
 * Plugin Name: SimplePay Gateway for GiveWP
 * Plugin URI: https://github.com/balcsida/give-simplepay
 * Description: Accept bank card donations and recurring donations in GiveWP through SimplePay.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: give
 * Author: balcsida
 * Author URI: https://github.com/balcsida
 * License: GPL-2.0-or-later
 * Text Domain: give-simplepay
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('SIMPLEPAY_GIVEWP_VERSION', '1.0.0');
define('SIMPLEPAY_GIVEWP_DIR', plugin_dir_path(__FILE__));
define('SIMPLEPAY_GIVEWP_URL', plugin_dir_url(__FILE__));
define('SIMPLEPAY_GIVEWP_CRON_HOOK', 'simplepay_givewp_process_renewals');

function simplepay_givewp_load(): void
{
    if (!class_exists('Give\Framework\PaymentGateways\PaymentGateway')) {
        return;
    }

    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-api-client.php';
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-gateway.php';
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-subscription-module.php';
}

add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    simplepay_givewp_load();
    $paymentGatewayRegister->registerGateway(SimplePayGateway::class);
});

add_filter('givewp_gateway_simplepay_subscription_module', static function () {
    return SimplePaySubscriptionModule::class;
});

if (is_admin()) {
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/admin/class-simplepay-settings.php';
    new SimplePaySettings();
}

// SimplePay requires the result and transaction ID on the page the donor lands on; handleReturn adds these query args
add_filter('the_content', static function ($content) {
    if (!is_main_query() || !in_the_loop() || empty($_GET['simplepay-transaction'])) {
        return $content;
    }

    $messages = [
        'SUCCESS' => __('Successful SimplePay transaction. Transaction ID: %s', 'give-simplepay'),
        'FAIL'    => __('Unsuccessful SimplePay transaction. Transaction ID: %s. Please check the details you entered or contact your card issuer.', 'give-simplepay'),
        'CANCEL'  => __('You cancelled the payment. No charge was made. SimplePay transaction ID: %s', 'give-simplepay'),
        'TIMEOUT' => __('The payment time limit was exceeded. No charge was made. SimplePay transaction ID: %s', 'give-simplepay'),
    ];
    $event = strtoupper(sanitize_key($_GET['simplepay-event'] ?? ''));
    if (!isset($messages[$event])) {
        return $content;
    }

    return '<p class="simplepay-result">' . esc_html(sprintf($messages[$event], absint($_GET['simplepay-transaction']))) . '</p>' . $content;
});

add_action('init', static function () {
    if (!wp_next_scheduled(SIMPLEPAY_GIVEWP_CRON_HOOK)) {
        wp_schedule_event(time(), 'hourly', SIMPLEPAY_GIVEWP_CRON_HOOK);
    }
});

add_action(SIMPLEPAY_GIVEWP_CRON_HOOK, static function () {
    simplepay_givewp_load();
    if (class_exists('SimplePaySubscriptionModule')) {
        SimplePaySubscriptionModule::processDueRenewals();
    }
});

register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook(SIMPLEPAY_GIVEWP_CRON_HOOK);
});
