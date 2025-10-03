<?php

/**
 * SimplePay API Client Class
 * Handles communication with the SimplePay API
 */
class SimplePayApiClient {
    
    private $merchant_id;
    private $secret_key;
    private $sandbox;
    private $api_url;

    /**
     * Constructor
     * 
     * @param string $merchant_id The merchant ID from SimplePay
     * @param string $secret_key The secret key from SimplePay
     * @param bool $sandbox Whether to use the sandbox environment
     */
    public function __construct($merchant_id, $secret_key, $sandbox = true) {
        $this->merchant_id = $merchant_id;
        $this->secret_key = $secret_key;
        $this->sandbox = $sandbox;
        
        // Set the API URL based on sandbox mode
        if ($sandbox) {
            $this->api_url = 'https://sandbox.simplepay.hu/payment/v2';
        } else {
            $this->api_url = 'https://secure.simplepay.hu/payment/v2';
        }
    }

    /**
     * Create a payment transaction
     * 
     * @param array $transaction_data Transaction data
     * @return array Response from the API
     */
    public function create_payment($transaction_data) {
        $data = array_merge([
            'salt' => $this->generate_salt(),
            'merchant' => $this->merchant_id,
            'sdkVersion' => 'GiveWP_SimplePay_1.0.0',
            'methods' => ['CARD'],
            'timeout' => 1800,
        ], $transaction_data);

        return $this->make_request('start', $data);
    }

    /**
     * Create a recurring payment transaction
     * 
     * @param array $transaction_data Transaction data
     * @param array $recurring_data Recurring payment data
     * @return array Response from the API
     */
    public function create_recurring_payment($transaction_data, $recurring_data) {
        $data = array_merge([
            'salt' => $this->generate_salt(),
            'merchant' => $this->merchant_id,
            'sdkVersion' => 'GiveWP_SimplePay_1.0.0',
            'methods' => ['CARD'],
            'timeout' => 1800,
            'recurring' => $recurring_data
        ], $transaction_data);

        return $this->make_request('start', $data);
    }

    /**
     * Process a recurring payment using a token
     * 
     * @param array $payment_data Payment data
     * @param string $token Token from SimplePay
     * @return array Response from the API
     */
    public function process_recurring_payment($payment_data, $token) {
        $data = array_merge([
            'salt' => $this->generate_salt(),
            'merchant' => $this->merchant_id,
            'sdkVersion' => 'GiveWP_SimplePay_1.0.0',
            'token' => $token,
            'type' => 'MIT', // Merchant Initiated Transaction
            'threeDSReqAuthMethod' => '02', // Registered with merchant
        ], $payment_data);

        return $this->make_request('dorecurring', $data);
    }

    /**
     * Query transaction status
     * 
     * @param string $transaction_id SimplePay transaction ID
     * @return array Response from the API
     */
    public function query_transaction($transaction_id) {
        $data = [
            'salt' => $this->generate_salt(),
            'merchant' => $this->merchant_id,
            'transactionIds' => [$transaction_id],
            'sdkVersion' => 'GiveWP_SimplePay_1.0.0'
        ];

        return $this->make_request('query', $data);
    }

    /**
     * Refund a transaction
     * 
     * @param string $transaction_id SimplePay transaction ID
     * @param float $amount Amount to refund
     * @param string $currency Currency code
     * @return array Response from the API
     */
    public function refund_transaction($transaction_id, $amount, $currency) {
        $data = [
            'salt' => $this->generate_salt(),
            'merchant' => $this->merchant_id,
            'transactionId' => $transaction_id,
            'refundTotal' => $amount,
            'currency' => $currency,
            'sdkVersion' => 'GiveWP_SimplePay_1.0.0'
        ];

        return $this->make_request('refund', $data);
    }

    /**
     * Query token status
     * 
     * @param string $token SimplePay token
     * @return array Response from the API
     */
    public function query_token($token) {
        $data = [
            'salt' => $this->generate_salt(),
            'merchant' => $this->merchant_id,
            'token' => $token,
            'sdkVersion' => 'GiveWP_SimplePay_1.0.0'
        ];

        return $this->make_request('tokenquery', $data);
    }

    /**
     * Cancel a token
     * 
     * @param string $token SimplePay token
     * @return array Response from the API
     */
    public function cancel_token($token) {
        $data = [
            'salt' => $this->generate_salt(),
            'merchant' => $this->merchant_id,
            'token' => $token,
            'sdkVersion' => 'GiveWP_SimplePay_1.0.0'
        ];

        return $this->make_request('tokencancel', $data);
    }

    /**
     * Make a request to the SimplePay API
     * 
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array Response from the API
     */
    private function make_request($endpoint, $data) {
        $url = $this->api_url . '/' . $endpoint;
        $json_data = json_encode($data);
        $signature = $this->generate_signature($json_data);

        $headers = [
            'Content-Type: application/json',
            'Signature: ' . $signature
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception(sprintf(__('SimplePay API connection error: %s', 'simplepay-givewp'), $error));
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        curl_close($ch);

        if ($status_code < 200 || $status_code >= 300) {
            throw new Exception(sprintf(__('SimplePay API returned HTTP %d', 'simplepay-givewp'), $status_code));
        }

        // Extract signature from header
        $signature = '';
        foreach (explode("\r\n", $header) as $line) {
            if (stripos($line, 'Signature:') === 0) {
                $signature = trim(substr($line, 10));
                break;
            }
        }

        // Verify signature
        $valid_signature = $this->verify_signature($body, $signature);
        $response_data = json_decode($body, true);

        if (!$valid_signature) {
            throw new Exception(__('Invalid response signature from SimplePay', 'simplepay-givewp'));
        }

        if (isset($response_data['errorCodes'])) {
            $error_codes = implode(', ', $response_data['errorCodes']);
            throw new Exception(sprintf(__('SimplePay API error: %s', 'simplepay-givewp'), $error_codes));
        }

        return $response_data;
    }

    /**
     * Generate a random salt
     * 
     * @return string Random salt
     */
    private function generate_salt() {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $exception) {
            return md5(uniqid(mt_rand(), true));
        }
    }

    /**
     * Generate a signature for the request
     * 
     * @param string $data JSON data to sign
     * @return string Base64 encoded signature
     */
    private function generate_signature($data) {
        return base64_encode(hash_hmac('sha384', $data, $this->secret_key, true));
    }

    /**
     * Verify the signature from the response
     * 
     * @param string $data Response data
     * @param string $signature Signature to verify
     * @return bool Whether the signature is valid
     */
    private function verify_signature($data, $signature) {
        $calculated_signature = $this->generate_signature($data);
        return hash_equals($calculated_signature, $signature);
    }
}
