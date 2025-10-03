<?php
/**
 * Plugin Name: SimplePay Gateway for GiveWP
 * Description: Integrate SimplePay payment gateway with GiveWP
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * Author: Your Name
 * Text Domain: simplepay-givewp
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('SIMPLEPAY_GIVEWP_VERSION', '1.0.0');
define('SIMPLEPAY_GIVEWP_DIR', plugin_dir_path(__FILE__));
define('SIMPLEPAY_GIVEWP_URL', plugin_dir_url(__FILE__));

// Register the SimplePay gateways
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-api-client.php';
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-webhook-handler.php';
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-return-handler.php';
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-onsite-gateway.php';
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-offsite-gateway.php';
    
    $paymentGatewayRegister->registerGateway(SimplePayOnsiteGateway::class);
    $paymentGatewayRegister->registerGateway(SimplePayOffsiteGateway::class);
});

// Register the subscription module for recurring donations
add_filter('givewp_gateway_simplepay-onsite_subscription_module', static function () {
    require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-subscription-module.php';
    return SimplePaySubscriptionModule::class;
});

// Add settings page to the GiveWP admin
add_action('admin_init', function () {
    if (class_exists('Give_Settings_Page')) {
        require_once SIMPLEPAY_GIVEWP_DIR . 'includes/admin/class-simplepay-settings.php';
        new SimplePaySettings();
    }
});

// Register webhook listener
add_action('init', function () {
    if (isset($_GET['give-listener']) && $_GET['give-listener'] === 'simplepay') {
        require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-webhook-handler.php';
        $webhook_handler = new SimplePayWebhookHandler();
        $webhook_handler->process_webhook();
        exit;
    }
    if (isset($_GET['give-listener']) && $_GET['give-listener'] === 'simplepay-return') {
        require_once SIMPLEPAY_GIVEWP_DIR . 'includes/class-simplepay-return-handler.php';
        $return_handler = new SimplePayReturnHandler();
        $return_handler->handleListenerRequest($_GET);
    }
});

// Load text domain
add_action('plugins_loaded', function () {
    load_plugin_textdomain('simplepay-givewp', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
