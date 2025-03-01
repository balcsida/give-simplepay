<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;

/**
 * SimplePay Offsite Gateway Class
 * Handles offsite (redirect) payments with SimplePay
 */
class SimplePayOffsiteGateway extends PaymentGateway {

    /**
     * @inheritDoc
     */
    public $secureRouteMethods = [
        'handleCreatePaymentRedirect',
    ];

    /**
     * @inheritDoc
     */
    public static function id(): string {
        return 'simplepay-offsite';
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
        return __('SimplePay - Redirect', 'simplepay-givewp');
    }

    /**
     * @inheritDoc
     */
    public function getPaymentMethodLabel(): string {
        return __('SimplePay', 'simplepay-givewp');
    }

    /**
     * @inheritDoc
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string {
        return "<div class='simplepay-offsite-container'>
                    <p>" . __('You will be redirected to SimplePay to complete your donation.', 'simplepay-givewp') . "</p>
                </div>";
    }

    /**
     * Register scripts for donation forms
     */
    public function enqueueScript(int $formId) {
        wp_enqueue_script(
            'simplepay-offsite-gateway',
            SIMPLEPAY_GIVEWP_URL . 'assets/js/simplepay-offsite-gateway.js',
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
            'message' => __('You will be redirected to SimplePay to complete your donation.', 'simplepay-givewp'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function createPayment(Donation $donation, $gatewayData): RedirectOffsite {
        try {
            // Generate a unique order reference
            $order_ref = 'givewp_' . uniqid('', true);
            
            // Store order reference in donation meta
            give_update_meta($donation->id, '_simplepay_order_ref', $order_ref);
            
            // Get settings
            $merchant_id = give_get_option('simplepay_merchant_id');
            $secret_key = give_get_option('simplepay_secret_key');
            $sandbox = give_get_option('simplepay_sandbox', 'enabled') === 'enabled';
            
            // Create API client
            $api_client = new SimplePayApiClient($merchant_id, $secret_key, $sandbox);
            
            // Generate secure return URL
            $return_url = $this->generateSecureGatewayRouteUrl(
                'handleCreatePaymentRedirect',
                $donation->id,
                [
                    'givewp-donation-id' => $donation->id,
                    'givewp-success-url' => urlencode(give_get_success_page_uri()),
                ]
            );
            
            // Prepare donation data
            $transaction_data = [
                'orderRef' => $order_ref,
                'currency' => $donation->amount->getCurrency()->getCode(),
                'total' => $donation->amount->formatToDecimal(),
                'customerEmail' => $donation->email,
                'language' => get_locale() === 'hu_HU' ? 'HU' : 'EN',
                'url' => $return_url,
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
            
            // Return RedirectOffsite command with the payment URL
            return new RedirectOffsite($response['paymentUrl']);
            
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
     * Handle redirect back from SimplePay
     *
     * @param array $queryParams Query parameters from the URL
     * @return RedirectResponse Redirect response
     * @throws PaymentGatewayException
     */
    protected function handleCreatePaymentRedirect(array $queryParams): RedirectResponse {
        // Get donation ID from query parameters
        $donation_id = $queryParams['givewp-donation-id'] ?? 0;
        $success_url = urldecode($queryParams['givewp-success-url'] ?? '');
        
        if (!$donation_id) {
            throw new PaymentGatewayException(__('Missing donation ID', 'simplepay-givewp'));
        }
        
        // Get donation
        $donation = Donation::find($donation_id);
        
        if (!$donation) {
            throw new PaymentGatewayException(__('Donation not found', 'simplepay-givewp'));
        }
        
        // Query the transaction status
        $transaction_id = $donation->gatewayTransactionId;
        
        if (empty($transaction_id)) {
            $transaction_id = give_get_meta($donation->id, '_simplepay_transaction_id', true);
        }
        
        if (empty($transaction_id)) {
            throw new PaymentGatewayException(__('Transaction ID not found', 'simplepay-givewp'));
        }
        
        // Get settings
        $merchant_id = give_get_option('simplepay_merchant_id');
        $secret_key = give_get_option('simplepay_secret_key');
        $sandbox = give_get_option('simplepay_sandbox', 'enabled') === 'enabled';
        
        // Create API client
        $api_client = new SimplePayApiClient($merchant_id, $secret_key, $sandbox);
        
        // Query transaction status
        $response = $api_client->query_transaction($transaction_id);
        
        if (empty($response['transactions']) || !isset($response['transactions'][0])) {
            throw new PaymentGatewayException(__('Transaction not found in SimplePay', 'simplepay-givewp'));
        }
        
        $transaction = $response['transactions'][0];
        
        // Check transaction status
        if ($transaction['status'] === 'FINISHED') {
            // Update donation status
            $donation->status = DonationStatus::COMPLETE();
            $donation->save();
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay payment completed (Transaction ID: %s)', 'simplepay-givewp'),
                    $transaction_id
                )
            ]);
            
            // Redirect to success page
            return new RedirectResponse($success_url);
        } else {
            // If not finished, keep processing status
            $donation->status = DonationStatus::PROCESSING();
            $donation->save();
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay payment in progress with status: %s (Transaction ID: %s)', 'simplepay-givewp'),
                    $transaction['status'],
                    $transaction_id
                )
            ]);
            
            // Redirect to success page but with processing status
            return new RedirectResponse($success_url);
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
