<?php

/**
 * Adds the SimplePay section to the GiveWP gateway settings.
 */
class SimplePaySettings {

    public function __construct() {
        add_filter('give_get_sections_gateways', [$this, 'register_section']);
        add_filter('give_get_settings_gateways', [$this, 'register_settings']);
    }

    public function register_section($sections) {
        $sections['simplepay'] = __('SimplePay', 'give-simplepay');
        return $sections;
    }

    public function register_settings($settings) {
        if (give_get_current_setting_section() !== 'simplepay') {
            return $settings;
        }

        $ipn_url = give()->gateways->hasPaymentGateway('simplepay')
            ? give()->gateways->getPaymentGateway('simplepay')->generateGatewayRouteUrl('handleIpn')
            : add_query_arg(
                ['give-listener' => 'give-gateway', 'give-gateway-id' => 'simplepay', 'give-gateway-method' => 'handleIpn'],
                home_url('/')
            );

        $desc = '<p>' . esc_html__('Sandbox is used while GiveWP Test Mode is enabled; live otherwise. The same merchant ID and secret key work in both.', 'give-simplepay') . '</p>'
            . '<p>' . esc_html__('IPN URL: set this in your SimplePay merchant account (Technical details):', 'give-simplepay')
            . ' <code>' . esc_html($ipn_url) . '</code></p>';

        return [
            [
                'id'    => 'give_title_simplepay',
                'type'  => 'title',
                'title' => __('SimplePay Settings', 'give-simplepay'),
                'desc'  => $desc,
            ],
            [
                'id'   => 'simplepay_merchant_id',
                'name' => __('Merchant ID', 'give-simplepay'),
                'desc' => __('Enter your SimplePay merchant ID', 'give-simplepay'),
                'type' => 'text',
            ],
            [
                'id'   => 'simplepay_secret_key',
                'name' => __('Secret Key', 'give-simplepay'),
                'desc' => __('Enter your SimplePay secret key', 'give-simplepay'),
                'type' => 'password',
            ],
            [
                'id'   => 'give_title_simplepay',
                'type' => 'sectionend',
            ],
        ];
    }
}
