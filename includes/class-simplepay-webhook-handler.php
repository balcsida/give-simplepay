<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;

/**
 * SimplePay Webhook Handler Class
 * Processes incoming webhook notifications from SimplePay
 */
class SimplePayWebhookHandler {

    /**
     * Process webhook request
     */
    public function process_webhook() {
        // Get the raw POST data
        $json = file_get_contents('php://input');
        
        // Get the signature from the header
        $signature = $this->get_signature_from_header();
        
        if (!$this->verify_signature($json, $signature)) {
            http_response_code(400);
            exit('Invalid signature');
        }
        
        // Process the IPN data
        $ipn_data = json_decode($json, true);
        
        if (!$ipn_data) {
            http_response_code(400);
            exit('Invalid JSON');
        }
        
        // Add the current timestamp as receiveDate
        $ipn_data['receiveDate'] = date('c');
        
        // Process different status types
        $this->process_ipn_status($ipn_data);
        
        // Send confirmation response
        $this->send_confirmation($ipn_data, $signature);
    }
    
    /**
     * Get signature from header
     * 
     * @return string Signature from header
     */
    private function get_signature_from_header() {
        $headers = [];
        
        // Try getallheaders() first
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback for servers where getallheaders() doesn't exist
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) === 'HTTP_') {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
        }
        
        // Look for Signature in the headers
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'signature') {
                return trim($value);
            }
        }
        
        return '';
    }
    
    /**
     * Verify signature of the IPN message
     * 
     * @param string $data IPN data
     * @param string $signature Signature to verify
     * @return bool Whether the signature is valid
     */
    private function verify_signature($data, $signature) {
        // Get the merchant settings
        $merchant_id = give_get_option('simplepay_merchant_id');
        $secret_key = give_get_option('simplepay_secret_key');
        
        // Calculate signature
        $calculated_signature = base64_encode(hash_hmac('sha384', $data, $secret_key, true));
        
        // Compare signatures
        return hash_equals($calculated_signature, $signature);
    }
    
    /**
     * Process the IPN status
     * 
     * @param array $ipn_data IPN data
     */
    private function process_ipn_status($ipn_data) {
        // Check if we have orderRef and transactionId
        if (!isset($ipn_data['orderRef']) || !isset($ipn_data['transactionId'])) {
            return;
        }
        
        // Look up the donation by meta value
        $donation_id = $this->get_donation_id_by_order_ref($ipn_data['orderRef']);
        
        if (!$donation_id) {
            return;
        }
        
        $donation = Donation::find($donation_id);
        
        if (!$donation) {
            return;
        }
        
        // Process based on status
        switch ($ipn_data['status']) {
            case 'FINISHED':
                $this->process_finished_status($donation, $ipn_data);
                break;
            
            case 'REFUND':
                $this->process_refund_status($donation, $ipn_data);
                break;
                
            case 'CANCELLED':
                $this->process_cancelled_status($donation, $ipn_data);
                break;
                
            case 'TIMEOUT':
                $this->process_timeout_status($donation, $ipn_data);
                break;
                
            case 'AUTHORISED':
                $this->process_authorised_status($donation, $ipn_data);
                break;
                
            case 'REVERSED':
                $this->process_reversed_status($donation, $ipn_data);
                break;
        }
    }
    
    /**
     * Process FINISHED status
     * 
     * @param Donation $donation Donation object
     * @param array $ipn_data IPN data
     */
    private function process_finished_status($donation, $ipn_data) {
        // Update donation status
        $donation->status = DonationStatus::COMPLETE();
        
        // Update gateway transaction ID if not set
        if (empty($donation->gatewayTransactionId)) {
            $donation->gatewayTransactionId = $ipn_data['transactionId'];
        }
        
        // Add donation note
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('SimplePay payment completed (Transaction ID: %s)', 'simplepay-givewp'),
                $ipn_data['transactionId']
            )
        ]);
        
        // Save the donation
        $donation->save();
        
        // Check if this is a subscription payment
        $subscription = $this->get_subscription_by_donation_id($donation->id);
        
        if ($subscription) {
            // Update subscription if this is the first payment
            if ($subscription->status->isInitial()) {
                $subscription->status = SubscriptionStatus::ACTIVE();
                $subscription->save();
            }
        }
    }
    
    /**
     * Process REFUND status
     * 
     * @param Donation $donation Donation object
     * @param array $ipn_data IPN data
     */
    private function process_refund_status($donation, $ipn_data) {
        // Update donation status
        $donation->status = DonationStatus::REFUNDED();
        
        // Add donation note
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('SimplePay payment refunded (Transaction ID: %s)', 'simplepay-givewp'),
                $ipn_data['transactionId']
            )
        ]);
        
        // Save the donation
        $donation->save();
    }
    
    /**
     * Process CANCELLED status
     * 
     * @param Donation $donation Donation object
     * @param array $ipn_data IPN data
     */
    private function process_cancelled_status($donation, $ipn_data) {
        // Update donation status
        $donation->status = DonationStatus::CANCELLED();
        
        // Add donation note
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('SimplePay payment cancelled (Transaction ID: %s)', 'simplepay-givewp'),
                $ipn_data['transactionId']
            )
        ]);
        
        // Save the donation
        $donation->save();
    }
    
    /**
     * Process TIMEOUT status
     * 
     * @param Donation $donation Donation object
     * @param array $ipn_data IPN data
     */
    private function process_timeout_status($donation, $ipn_data) {
        // Update donation status
        $donation->status = DonationStatus::FAILED();
        
        // Add donation note
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('SimplePay payment timed out (Transaction ID: %s)', 'simplepay-givewp'),
                $ipn_data['transactionId']
            )
        ]);
        
        // Save the donation
        $donation->save();
    }
    
    /**
     * Process AUTHORISED status
     * 
     * @param Donation $donation Donation object
     * @param array $ipn_data IPN data
     */
    private function process_authorised_status($donation, $ipn_data) {
        // Update donation status
        $donation->status = DonationStatus::PROCESSING();
        
        // Update gateway transaction ID if not set
        if (empty($donation->gatewayTransactionId)) {
            $donation->gatewayTransactionId = $ipn_data['transactionId'];
        }
        
        // Add donation note
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('SimplePay payment authorised (Transaction ID: %s)', 'simplepay-givewp'),
                $ipn_data['transactionId']
            )
        ]);
        
        // Save the donation
        $donation->save();
    }
    
    /**
     * Process REVERSED status
     * 
     * @param Donation $donation Donation object
     * @param array $ipn_data IPN data
     */
    private function process_reversed_status($donation, $ipn_data) {
        // Update donation status
        $donation->status = DonationStatus::CANCELLED();
        
        // Add donation note
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => sprintf(
                __('SimplePay payment reversed (Transaction ID: %s)', 'simplepay-givewp'),
                $ipn_data['transactionId']
            )
        ]);
        
        // Save the donation
        $donation->save();
    }
    
    /**
     * Send confirmation response to SimplePay
     * 
     * @param array $ipn_data IPN data
     * @param string $received_signature Signature received from SimplePay
     */
    private function send_confirmation($ipn_data, $received_signature) {
        // Calculate confirmation signature
        $secret_key = give_get_option('simplepay_secret_key');
        $json_response = json_encode($ipn_data);
        $signature = base64_encode(hash_hmac('sha384', $json_response, $secret_key, true));
        
        // Send response headers
        header('Accept-language: EN');
        header('Content-type: application/json');
        header('Signature: ' . $signature);
        
        // Output the JSON response
        echo $json_response;
        exit;
    }
    
    /**
     * Get donation ID by order reference
     * 
     * @param string $order_ref Order reference
     * @return int|false Donation ID or false if not found
     */
    private function get_donation_id_by_order_ref($order_ref) {
        global $wpdb;
        
        $donation_id = $wpdb->get_var($wpdb->prepare(
            "SELECT donation_id
            FROM {$wpdb->prefix}give_donationmeta
            WHERE meta_key = '_simplepay_order_ref'
            AND meta_value = %s
            LIMIT 1",
            $order_ref
        ));
        
        return $donation_id ? (int) $donation_id : false;
    }
    
    /**
     * Get subscription by donation ID
     * 
     * @param int $donation_id Donation ID
     * @return Subscription|null Subscription object or null if not found
     */
    private function get_subscription_by_donation_id($donation_id) {
        global $wpdb;
        
        $subscription_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id
            FROM {$wpdb->prefix}give_subscriptions
            WHERE parent_payment_id = %d
            LIMIT 1",
            $donation_id
        ));
        
        return $subscription_id ? Subscription::find($subscription_id) : null;
    }
}
