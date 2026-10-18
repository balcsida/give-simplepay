<?php

use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Log\PaymentGatewayLog;

/**
 * SimplePay v2.1 API client. The static helpers do not use WordPress so tests/self-check.php can run them.
 */
class SimplePayApiClient {

    const SDK_VERSION = 'GiveWP_SimplePay_1.0.0';
    const LANGUAGES = ['AR', 'BG', 'CS', 'DE', 'EN', 'ES', 'FR', 'HR', 'HU', 'IT', 'PL', 'RO', 'RU', 'SK', 'TR', 'ZH'];

    private $merchantId;
    private $secretKey;
    private $baseUrl;

    public function __construct(string $merchantId, string $secretKey, bool $sandbox) {
        $this->merchantId = $merchantId;
        $this->secretKey = $secretKey;
        $this->baseUrl = $sandbox
            ? 'https://sandbox.simplepay.hu/payment/v2/'
            : 'https://secure.simplepay.hu/payment/v2/';
    }

    public static function fromSettings(bool $sandbox): self {
        $merchantId = (string) give_get_option('simplepay_merchant_id');
        $secretKey = (string) give_get_option('simplepay_secret_key');

        if ($merchantId === '' || $secretKey === '') {
            throw new PaymentGatewayException(
                __('SimplePay is not configured. Enter the merchant ID and secret key in the SimplePay gateway settings.', 'give-simplepay')
            );
        }

        return new self($merchantId, $secretKey, $sandbox);
    }

    public function start(array $data): array {
        return $this->post('start', $data);
    }

    public function doRecurring(array $data): array {
        return $this->post('dorecurring', $data);
    }

    public function refund(array $data): array {
        return $this->post('refund', $data);
    }

    public function tokenCancel(string $token): array {
        return $this->post('tokencancel', ['token' => $token]);
    }

    private function post(string $endpoint, array $data): array {
        $data = array_merge($data, [
            'salt'       => bin2hex(random_bytes(16)),
            'merchant'   => $this->merchantId,
            'sdkVersion' => self::SDK_VERSION,
        ]);
        $json = wp_json_encode($data);

        $response = wp_remote_post($this->baseUrl . $endpoint, [
            'headers' => ['Content-Type' => 'application/json', 'Signature' => self::sign($json, $this->secretKey)],
            'body'    => $json,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            PaymentGatewayLog::error('SimplePay request failed', ['endpoint' => $endpoint, 'error' => $response->get_error_message()]);
            throw new PaymentGatewayException(__('Could not reach SimplePay. Please try again later.', 'give-simplepay'));
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        // Error responses may be unsigned (e.g. wrong merchant ID), so report their codes before checking the signature
        if (is_array($decoded) && !empty($decoded['errorCodes'])) {
            PaymentGatewayLog::error('SimplePay API error', [
                'endpoint'   => $endpoint,
                'errorCodes' => $decoded['errorCodes'],
                'orderRef'   => $data['orderRef'] ?? '',
            ]);
            throw new PaymentGatewayException(sprintf(
                __('SimplePay rejected the request (error %s).', 'give-simplepay'),
                implode(', ', (array) $decoded['errorCodes'])
            ));
        }

        if (!self::verify($body, (string) wp_remote_retrieve_header($response, 'signature'), $this->secretKey)) {
            PaymentGatewayLog::error('SimplePay response signature is invalid', ['endpoint' => $endpoint]);
            throw new PaymentGatewayException(__('Invalid response signature from SimplePay.', 'give-simplepay'));
        }

        if (!is_array($decoded)) {
            throw new PaymentGatewayException(__('Invalid response from SimplePay.', 'give-simplepay'));
        }

        return $decoded;
    }

    public static function sign(string $json, string $secret): string {
        return base64_encode(hash_hmac('sha384', $json, $secret, true));
    }

    public static function verify(string $json, string $signature, string $secret): bool {
        return hash_equals(self::sign($json, $secret), trim($signature));
    }

    public static function decodeBackPayload(string $r, string $s, string $secret): ?array {
        // URL decoding turns the '+' of base64 into spaces
        $r = str_replace(' ', '+', $r);
        $s = str_replace(' ', '+', $s);

        $json = base64_decode($r, true);
        if ($json === false || !self::verify($json, $s, $secret)) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array{0: string, 1: string} The acknowledgement JSON and its Signature header value.
     */
    public static function buildIpnAck(array $ipn, string $secret): array {
        $json = json_encode($ipn + ['receiveDate' => date('c')]);

        return [$json, self::sign($json, $secret)];
    }

    /**
     * HUF amounts must be integers, EUR/USD may have two decimals. Numeric strings avoid float noise in JSON.
     */
    public static function formatAmount(string $decimal, string $currency): string {
        return $currency === 'HUF' ? (string) (int) round((float) $decimal) : number_format((float) $decimal, 2, '.', '');
    }

    public static function normalizeStatus(string $status): string {
        // The English guide spells AUTHORISED, the SDK AUTHORIZED
        return str_replace('ISED', 'IZED', strtoupper(trim($status)));
    }

    public static function language(string $locale): string {
        $language = strtoupper(substr($locale, 0, 2));

        return in_array($language, self::LANGUAGES, true) ? $language : 'EN';
    }
}
