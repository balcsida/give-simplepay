# SimplePay Gateway for GiveWP

This plugin integrates the SimplePay payment gateway with GiveWP, allowing you to accept credit card payments and recurring donations through SimplePay.

## Features

- Accept one-time credit card payments through SimplePay
- Support for recurring donations
- Two payment methods: onsite and offsite (redirect)
- Full support for IPN notifications
- Support for refunds
- Sandbox mode for testing

## Requirements

- WordPress 5.6 or higher
- PHP 7.2 or higher
- GiveWP 2.24.0 or higher
- SimplePay merchant account

## Installation

1. Upload the plugin files to the `/wp-content/plugins/simplepay-gateway-givewp` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to GiveWP > Settings > Payment Gateways > SimplePay to configure the plugin.

## Configuration

1. Log in to your SimplePay account and get your Merchant ID and Secret Key.
2. In WordPress admin, go to GiveWP > Settings > Payment Gateways > SimplePay.
3. Enter your Merchant ID and Secret Key.
4. Enable or disable Sandbox mode as needed. Use Sandbox mode for testing.
5. Copy the IPN URL shown in the settings and set it in your SimplePay account.
6. Enable the SimplePay gateways in the Payment Gateways section.

## Payment Methods

### SimplePay - Credit Card (Onsite)

This method keeps the donor on your website throughout the donation process. The donor will provide their payment details directly on the SimplePay payment page that appears in a secure context.

### SimplePay - Redirect (Offsite)

This method redirects the donor to the SimplePay website to complete their payment. After the payment is completed, the donor will be redirected back to your website.

## Recurring Donations

The plugin supports recurring donations through SimplePay's token-based recurring payment system. When a donor sets up a recurring donation:

1. The plugin registers their card with SimplePay and receives a set of tokens.
2. For each renewal, the plugin uses one of these tokens to process the payment.
3. The plugin automatically rotates tokens as needed.

## Webhooks

The plugin automatically handles SimplePay IPN (Instant Payment Notification) webhooks to update donation statuses. Make sure to configure the IPN URL in your SimplePay account settings.

## Troubleshooting

If you encounter any issues with the plugin:

1. Enable Debug Mode in the plugin settings to log API requests and responses.
2. Check the logs in the wp-content/uploads/give-logs directory.
3. Verify that your Merchant ID and Secret Key are correctly entered.
4. Ensure your server can make outbound HTTPS requests to the SimplePay API.

## Support

If you need help with the plugin, please contact your SimplePay representative or open an issue on GitHub.

## License

This plugin is released under the GPL v2 or later.
