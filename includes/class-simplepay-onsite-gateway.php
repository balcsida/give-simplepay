<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\PaymentGateways\Commands\GatewayCommand;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;

/**
 * SimplePay Onsite Gateway Class
 * Handles onsite (iframe) payments with SimplePay
 */
class SimplePayOnsiteGateway extends PaymentGateway {

    /**
     * @inheritDoc
     */
    public static function id(): string {
        return 'simplepay-onsite';
    }

    /**
     * @inheritDoc
     */
    public function getId(): string {
        return self::id();
    }

    /**
     * @inheritDoc
     */
    public function getName(): string {
        return __('SimplePay - Credit Card', 'simplepay-givewp');
    }

    /**
     * @inheritDoc
     */
    public function getPaymentMethodLabel(): string {
        return __('Credit Card', 'simplepay-givewp');
    }

    /**
     * @inheritDoc
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string {
        $currency = give_get_currency($formId);
        
        // Add a container for the SimplePay card fields
        return "<div class='simplepay-card-container'>
                    <input type='hidden' name='gatewayData[simplepay-order-ref]' value='" . uniqid('givewp_', true) . "' />
                    <p>" . __('You will enter your payment details after submitting the form.', 'simplepay-givewp') . "</p>
                </div>";
    }

    /**
     * Register scripts for donation forms
     */
    public function enqueueScript(int $formId) {
        wp_enqueue_script(
            'simplepay-onsite-gateway',
            SIMPLEPAY_GIVEWP_URL . 'assets/js/simplepay-onsite-gateway.js',
            ['react', 'wp-element'],
            SIMPLEPAY_GIVEWP_VERSION,
            true
        );
    }

    /**
     * Send settings to the frontend script
     */
    public function formSettings(int $formId): array {
        return [
            'merchantId' => give_get_option('simplepay_merchant_id'),
            'isSandbox' => give_get_option('simplepay_sandbox', 'enabled') === 'enabled',
            'currency' => give_get_currency($formId),
        ];
    }

    /**
     * @inheritDoc
     */
    public function createPayment(Donation $donation, $gatewayData): GatewayCommand {
        try {
            // Validate the order reference
            if (empty($gatewayData['simplepay-order-ref'])) {
                throw new PaymentGatewayException(__('Missing order reference', 'simplepay-givewp'));
            }
            
            // Store order reference in donation meta
            give_update_meta($donation->id, '_simplepay_order_ref', $gatewayData['simplepay-order-ref']);
            
            // Get settings
            $merchant_id = give_get_option('simplepay_merchant_id');
            $secret_key = give_get_option('simplepay_secret_key');
            $sandbox = give_get_option('simplepay_sandbox', 'enabled') === 'enabled';
            
            // Create API client
            $api_client = new SimplePayApiClient($merchant_id, $secret_key, $sandbox);
            
            // Prepare return URLs
            $return_base = add_query_arg([
                'give-listener' => 'simplepay-return',
                'donation-id' => $donation->id,
                'success-url' => rawurlencode(give_get_success_page_uri()),
                'failure-url' => rawurlencode(give_get_failed_transaction_uri()),
                'cancel-url' => rawurlencode(give_get_failed_transaction_uri()),
                'timeout-url' => rawurlencode(give_get_failed_transaction_uri()),
            ], home_url('/'));

            // Prepare donation data
            $transaction_data = [
                'orderRef' => $gatewayData['simplepay-order-ref'],
                'currency' => $donation->amount->getCurrency()->getCode(),
                'total' => $donation->amount->formatToDecimal(),
                'customerEmail' => $donation->email,
                'language' => get_locale() === 'hu_HU' ? 'HU' : 'EN',
                'urls' => [
                    'success' => $return_base,
                    'fail' => $return_base,
                    'cancel' => $return_base,
                    'timeout' => $return_base,
                ],
                'invoice' => [
                    'name' => $donation->firstName . ' ' . $donation->lastName,
                    'country' => $donation->billingCountry ?: 'US',
                    'state' => $donation->billingState ?: '',
                    'city' => $donation->billingCity ?: '',
                    'zip' => $donation->billingPostCode ?: '',
                    'address' => $donation->billingAddress1 ?: '',
                    'address2' => $donation->billingAddress2 ?: '',
                ],
                'threeDSReqAuthMethod' => '02', // Registered with merchant
            ];
            
            // Create the payment in SimplePay
            $response = $api_client->create_payment($transaction_data);
            
            // Store transaction ID in donation meta
            if (isset($response['transactionId'])) {
                give_update_meta($donation->id, '_simplepay_transaction_id', $response['transactionId']);
                give_update_meta($donation->id, '_simplepay_payment_url', $response['paymentUrl']);
            }
            
            // Update donation status
            $donation->status = DonationStatus::PROCESSING();
            $donation->gatewayTransactionId = $response['transactionId'] ?? '';
            $donation->save();
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay payment initiated (Transaction ID: %s)', 'simplepay-givewp'),
                    $response['transactionId'] ?? 'N/A'
                )
            ]);
            
            // Return PaymentComplete command
            return new PaymentComplete($response['transactionId']);
            
        } catch (Exception $e) {
            // Log error and update donation status
            $donation->status = DonationStatus::FAILED();
            $donation->save();
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay payment failed: %s', 'simplepay-givewp'),
                    $e->getMessage()
                )
            ]);
            
            // Throw exception to be caught by GiveWP
            throw new PaymentGatewayException($e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function refundDonation(Donation $donation): PaymentRefunded {
        try {
            // Get settings
            $merchant_id = give_get_option('simplepay_merchant_id');
            $secret_key = give_get_option('simplepay_secret_key');
            $sandbox = give_get_option('simplepay_sandbox', 'enabled') === 'enabled';
            
            // Create API client
            $api_client = new SimplePayApiClient($merchant_id, $secret_key, $sandbox);
            
            // Get transaction ID
            $transaction_id = $donation->gatewayTransactionId;
            
            if (empty($transaction_id)) {
                throw new PaymentGatewayException(__('No transaction ID found for refund', 'simplepay-givewp'));
            }
            
            // Refund the transaction
            $api_client->refund_transaction(
                $transaction_id,
                $donation->amount->formatToDecimal(),
                $donation->amount->getCurrency()->getCode()
            );
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay payment refunded (Transaction ID: %s)', 'simplepay-givewp'),
                    $transaction_id
                )
            ]);
            
            return new PaymentRefunded();
            
        } catch (Exception $e) {
            throw new PaymentGatewayException($e->getMessage());
        }
    }
}