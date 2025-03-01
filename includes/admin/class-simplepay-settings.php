<?php

/**
 * SimplePay Settings Class
 * Adds settings to the GiveWP admin
 */
class SimplePaySettings {

    /**
     * Constructor
     */
    public function __construct() {
        add_filter('give_get_sections_gateways', [$this, 'register_section']);
        add_filter('give_get_settings_gateways', [$this, 'register_settings']);
    }

    /**
     * Register settings section
     * 
     * @param array $sections Array of sections
     * @return array Modified array of sections
     */
    public function register_section($sections) {
        $sections['simplepay'] = __('SimplePay', 'simplepay-givewp');
        return $sections;
    }

    /**
     * Register settings
     * 
     * @param array $settings Array of settings
     * @return array Modified array of settings
     */
    public function register_settings($settings) {
        $current_section = give_get_current_setting_section();

        if ($current_section == 'simplepay') {
            $settings = [
                [
                    'id'    => 'give_title_simplepay',
                    'type'  => 'title',
                    'title' => __('SimplePay Settings', 'simplepay-givewp'),
                ],
                [
                    'id'      => 'simplepay_sandbox',
                    'name'    => __('Sandbox Mode', 'simplepay-givewp'),
                    'desc'    => __('Enable sandbox mode for testing', 'simplepay-givewp'),
                    'type'    => 'radio_inline',
                    'default' => 'enabled',
                    'options' => [
                        'enabled'  => __('Enabled', 'simplepay-givewp'),
                        'disabled' => __('Disabled', 'simplepay-givewp'),
                    ],
                ],
                [
                    'id'   => 'simplepay_merchant_id',
                    'name' => __('Merchant ID', 'simplepay-givewp'),
                    'desc' => __('Enter your SimplePay merchant ID', 'simplepay-givewp'),
                    'type' => 'text',
                ],
                [
                    'id'   => 'simplepay_secret_key',
                    'name' => __('Secret Key', 'simplepay-givewp'),
                    'desc' => __('Enter your SimplePay secret key', 'simplepay-givewp'),
                    'type' => 'password',
                ],
                [
                    'id'   => 'simplepay_ipn_url',
                    'name' => __('IPN URL', 'simplepay-givewp'),
                    'desc' => __('Copy this URL to your SimplePay account settings', 'simplepay-givewp') . '<br><code>' . home_url('?give-listener=simplepay') . '</code>',
                    'type' => 'readonly',
                    'default' => home_url('?give-listener=simplepay'),
                ],
                [
                    'id'      => 'simplepay_debug',
                    'name'    => __('Debug Mode', 'simplepay-givewp'),
                    'desc'    => __('Enable debug mode to log API requests and responses', 'simplepay-givewp'),
                    'type'    => 'radio_inline',
                    'default' => 'disabled',
                    'options' => [
                        'enabled'  => __('Enabled', 'simplepay-givewp'),
                        'disabled' => __('Disabled', 'simplepay-givewp'),
                    ],
                ],
                [
                    'id'    => 'give_title_simplepay_end',
                    'type'  => 'sectionend',
                    'title' => __('SimplePay Settings', 'simplepay-givewp'),
                ],
            ];
        }

        return $settings;
    }
}