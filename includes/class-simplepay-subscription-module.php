<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\PaymentGateways\Commands\SubscriptionComplete;
use Give\Framework\PaymentGateways\Commands\SubscriptionProcessing;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\SubscriptionModule;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;

/**
 * SimplePay Subscription Module Class
 * Handles recurring donations with SimplePay
 */
class SimplePaySubscriptionModule extends SubscriptionModule {

    /**
     * @inheritDoc
     * @throws PaymentGatewayException
     */
    public function createSubscription(Donation $donation, Subscription $subscription, $gatewayData) {
        try {
            // Get settings
            $merchant_id = give_get_option('simplepay_merchant_id');
            $secret_key = give_get_option('simplepay_secret_key');
            $sandbox = give_get_option('simplepay_sandbox', 'enabled') === 'enabled';
            
            // Create API client
            $api_client = new SimplePayApiClient($merchant_id, $secret_key, $sandbox);
            
            // Get order reference from gateway data or generate one
            $order_ref = $gatewayData['simplepay-order-ref'] ?? 'givewp_sub_' . uniqid('', true);
            
            // Store order reference in subscription meta
            give_update_meta($subscription->id, '_simplepay_order_ref', $order_ref);
            
            // Prepare recurring data based on subscription details
            $recurring_data = [
                'times' => 24, // Maximum allowed by SimplePay
                'until' => date('c', strtotime('+5 years')), // Set a far future date
                'maxAmount' => $subscription->amount->formatToDecimal() * 1.5, // Allow some buffer for amount changes
            ];
            
            // Prepare transaction data
            $transaction_data = [
                'orderRef' => $order_ref,
                'currency' => $donation->amount->getCurrency()->getCode(),
                'total' => $donation->amount->formatToDecimal(),
                'customerEmail' => $donation->email,
                'language' => get_locale() === 'hu_HU' ? 'HU' : 'EN',
                'url' => home_url('?give-listener=simplepay-return&subscription-id=' . $subscription->id),
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
            
            // Create the recurring payment in SimplePay
            $response = $api_client->create_recurring_payment($transaction_data, $recurring_data);
            
            // Store transaction ID and tokens in subscription meta
            if (isset($response['transactionId'])) {
                give_update_meta($subscription->id, '_simplepay_transaction_id', $response['transactionId']);
            }
            
            if (isset($response['tokens']) && is_array($response['tokens'])) {
                // Store all tokens for future use
                give_update_meta($subscription->id, '_simplepay_tokens', $response['tokens']);
                
                // Store the first token as the active one
                if (!empty($response['tokens'][0])) {
                    give_update_meta($subscription->id, '_simplepay_active_token', $response['tokens'][0]);
                    
                    // Also store the token count
                    give_update_meta($subscription->id, '_simplepay_token_count', count($response['tokens']));
                    give_update_meta($subscription->id, '_simplepay_tokens_used', 0);
                }
            }
            
            // Update donation status
            $donation->status = DonationStatus::PROCESSING();
            $donation->gatewayTransactionId = $response['transactionId'] ?? '';
            $donation->save();
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay recurring payment initiated (Transaction ID: %s)', 'simplepay-givewp'),
                    $response['transactionId'] ?? 'N/A'
                )
            ]);
            
            // Return SubscriptionProcessing as we need to wait for IPN to confirm
            return new SubscriptionProcessing();
            
        } catch (Exception $e) {
            // Log error and update donation status
            $donation->status = DonationStatus::FAILED();
            $donation->save();
            
            // Update subscription status
            $subscription->status = SubscriptionStatus::FAILING();
            $subscription->save();
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay recurring payment failed: %s', 'simplepay-givewp'),
                    $e->getMessage()
                )
            ]);
            
            // Throw exception to be caught by GiveWP
            throw new PaymentGatewayException($e->getMessage());
        }
    }

    /**
     * Process a renewal payment for a subscription
     * 
     * @param Subscription $subscription The subscription to renew
     * @param Donation $donation The renewal donation
     * @return void
     * @throws PaymentGatewayException
     */
    public function processRenewal(Subscription $subscription, Donation $donation) {
        try {
            // Get settings
            $merchant_id = give_get_option('simplepay_merchant_id');
            $secret_key = give_get_option('simplepay_secret_key');
            $sandbox = give_get_option('simplepay_sandbox', 'enabled') === 'enabled';
            
            // Create API client
            $api_client = new SimplePayApiClient($merchant_id, $secret_key, $sandbox);
            
            // Get active token
            $token = give_get_meta($subscription->id, '_simplepay_active_token', true);
            
            if (empty($token)) {
                throw new PaymentGatewayException(__('No active token found for renewal', 'simplepay-givewp'));
            }
            
            // Check token status
            $token_response = $api_client->query_token($token);
            
            if ($token_response['status'] !== 'active') {
                throw new PaymentGatewayException(__('Token is not active', 'simplepay-givewp'));
            }
            
            // Generate a unique order reference for this renewal
            $order_ref = 'givewp_renewal_' . uniqid('', true);
            give_update_meta($donation->id, '_simplepay_order_ref', $order_ref);
            
            // Prepare payment data
            $payment_data = [
                'orderRef' => $order_ref,
                'currency' => $donation->amount->getCurrency()->getCode(),
                'total' => $donation->amount->formatToDecimal(),
                'customerEmail' => $donation->email,
            ];
            
            // Process the recurring payment
            $response = $api_client->process_recurring_payment($payment_data, $token);
            
            // If we got here, the payment was successful
            $donation->status = DonationStatus::COMPLETE();
            $donation->gatewayTransactionId = $response['transactionId'] ?? '';
            $donation->save();
            
            // Mark the token as used and update the count
            $tokens_used = (int) give_get_meta($subscription->id, '_simplepay_tokens_used', true);
            give_update_meta($subscription->id, '_simplepay_tokens_used', $tokens_used + 1);
            
            // Check if we need to rotate to the next token
            $this->rotateTokenIfNeeded($subscription);
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay recurring payment successful (Transaction ID: %s)', 'simplepay-givewp'),
                    $response['transactionId'] ?? 'N/A'
                )
            ]);
            
        } catch (Exception $e) {
            // Log error and update donation status
            $donation->status = DonationStatus::FAILED();
            $donation->save();
            
            // Add donation note
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('SimplePay recurring payment failed: %s', 'simplepay-givewp'),
                    $e->getMessage()
                )
            ]);
            
            // Throw exception to be caught by GiveWP
            throw new PaymentGatewayException($e->getMessage());
        }
    }

    /**
     * Rotate to the next token if the current one is used
     * 
     * @param Subscription $subscription The subscription
     * @return void
     */
    private function rotateTokenIfNeeded(Subscription $subscription) {
        // Get all tokens and the current used count
        $tokens = give_get_meta($subscription->id, '_simplepay_tokens', true);
        $tokens_used = (int) give_get_meta($subscription->id, '_simplepay_tokens_used', true);
        
        // If we've used all tokens, we need to create a new set
        if (!is_array($tokens) || empty($tokens) || $tokens_used >= count($tokens)) {
            // This would require a new card registration, which is not supported yet
            // For now, just mark the subscription as failing
            $subscription->status = SubscriptionStatus::FAILING();
            $subscription->save();
            return;
        }
        
        // Otherwise, rotate to the next token
        give_update_meta($subscription->id, '_simplepay_active_token', $tokens[$tokens_used]);
    }

    /**
     * @inheritDoc
     * @throws PaymentGatewayException
     */
    public function cancelSubscription(Subscription $subscription) {
        try {
            // Get settings
            $merchant_id = give_get_option('simplepay_merchant_id');
            $secret_key = give_get_option('simplepay_secret_key');
            $sandbox = give_get_option('simplepay_sandbox', 'enabled') === 'enabled';
            
            // Create API client
            $api_client = new SimplePayApiClient($merchant_id, $secret_key, $sandbox);
            
            // Get all tokens and cancel them
            $tokens = give_get_meta($subscription->id, '_simplepay_tokens', true);
            
            if (is_array($tokens) && !empty($tokens)) {
                foreach ($tokens as $token) {
                    try {
                        $api_client->cancel_token($token);
                    } catch (Exception $e) {
                        // Continue with other tokens even if one fails
                        continue;
                    }
                }
            }
            
            // Update subscription status
            $subscription->status = SubscriptionStatus::CANCELLED();
            $subscription->save();
            
        } catch (Exception $e) {
            throw new PaymentGatewayException($e->getMessage());
        }
    }
}
