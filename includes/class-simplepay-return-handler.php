<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;

/**
 * Handles SimplePay browser return requests.
 */
class SimplePayReturnHandler {
    /**
     * Process an onsite listener request and redirect the donor.
     */
    public function handleListenerRequest(array $queryParams): void {
        $donationId = isset($queryParams['donation-id']) ? (int) $queryParams['donation-id'] : 0;

        if (!$donationId) {
            wp_die(__('Missing donation reference in SimplePay return URL.', 'simplepay-givewp'));
        }

        $donation = Donation::find($donationId);

        if (!$donation) {
            wp_die(__('Unable to locate the donation referenced by SimplePay.', 'simplepay-givewp'));
        }

        try {
            $redirectUrl = $this->handleDonationReturn($donation, $queryParams, [
                'success' => $queryParams['success-url'] ?? ($queryParams['givewp-success-url'] ?? ''),
                'fail' => $queryParams['failure-url'] ?? ($queryParams['givewp-failure-url'] ?? ''),
                'cancel' => $queryParams['cancel-url'] ?? ($queryParams['givewp-failure-url'] ?? ''),
                'timeout' => $queryParams['timeout-url'] ?? ($queryParams['givewp-failure-url'] ?? ''),
            ]);
        } catch (PaymentGatewayException $exception) {
            wp_die($exception->getMessage());
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * Applies the SimplePay browser return payload to a donation and returns the redirect URL.
     *
     * @throws PaymentGatewayException
     */
    public function handleDonationReturn(Donation $donation, array $queryParams, array $redirectHints = []): string {
        $payload = $this->extractPayload($queryParams);

        $event = $payload['e'] ?? '';
        $transactionId = $payload['t'] ?? '';

        if ($transactionId) {
            give_update_meta($donation->id, '_simplepay_transaction_id', $transactionId);

            if (empty($donation->gatewayTransactionId)) {
                $donation->gatewayTransactionId = $transactionId;
            }
        }

        $responseCode = $payload['r'] ?? '';

        switch ($event) {
            case 'SUCCESS':
                $donation->status = DonationStatus::PROCESSING();
                $this->addDonationNote($donation, sprintf(
                    __('SimplePay reported SUCCESS (Transaction ID: %1$s, Result: %2$s). Awaiting IPN for final confirmation.', 'simplepay-givewp'),
                    $transactionId ?: __('unknown', 'simplepay-givewp'),
                    $responseCode ?: __('n/a', 'simplepay-givewp')
                ));
                break;
            case 'FAIL':
                $donation->status = DonationStatus::FAILED();
                $this->addDonationNote($donation, sprintf(
                    __('SimplePay reported FAIL (Transaction ID: %1$s, Result: %2$s).', 'simplepay-givewp'),
                    $transactionId ?: __('unknown', 'simplepay-givewp'),
                    $responseCode ?: __('n/a', 'simplepay-givewp')
                ));
                break;
            case 'CANCEL':
                $donation->status = DonationStatus::CANCELLED();
                $this->addDonationNote($donation, sprintf(
                    __('SimplePay reported CANCEL (Transaction ID: %1$s).', 'simplepay-givewp'),
                    $transactionId ?: __('unknown', 'simplepay-givewp')
                ));
                break;
            case 'TIMEOUT':
                $donation->status = DonationStatus::FAILED();
                $this->addDonationNote($donation, sprintf(
                    __('SimplePay reported TIMEOUT (Transaction ID: %1$s).', 'simplepay-givewp'),
                    $transactionId ?: __('unknown', 'simplepay-givewp')
                ));
                break;
            default:
                $this->addDonationNote($donation, __('Received SimplePay return without a recognised event code.', 'simplepay-givewp'));
                break;
        }

        $donation->save();

        return $this->determineRedirectUrl($event, $redirectHints);
    }

    /**
     * Extracts and validates the SimplePay browser return payload from query parameters.
     *
     * @throws PaymentGatewayException
     */
    private function extractPayload(array $queryParams): array {
        if (empty($queryParams['r']) || empty($queryParams['s'])) {
            throw new PaymentGatewayException(__('Missing SimplePay response parameters.', 'simplepay-givewp'));
        }

        $payload = base64_decode($queryParams['r'], true);

        if ($payload === false) {
            throw new PaymentGatewayException(__('Failed to decode SimplePay response payload.', 'simplepay-givewp'));
        }

        $signature = $queryParams['s'];

        if (!$this->verifySignature($payload, $signature)) {
            throw new PaymentGatewayException(__('Invalid SimplePay response signature.', 'simplepay-givewp'));
        }

        $data = json_decode($payload, true);

        if (!is_array($data)) {
            throw new PaymentGatewayException(__('Invalid SimplePay response payload.', 'simplepay-givewp'));
        }

        return $data;
    }

    private function verifySignature(string $payload, string $signature): bool {
        $secretKey = give_get_option('simplepay_secret_key');

        if (!$secretKey) {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha384', $payload, $secretKey, true));

        return hash_equals($calculated, $signature);
    }

    private function determineRedirectUrl(string $event, array $redirectHints): string {
        $defaults = [
            'success' => give_get_success_page_uri(),
            'fail' => give_get_failed_transaction_uri(),
            'cancel' => give_get_failed_transaction_uri(),
            'timeout' => give_get_failed_transaction_uri(),
        ];

        $redirects = array_merge($defaults, array_filter($redirectHints));

        switch ($event) {
            case 'SUCCESS':
                return $this->decodeUrl($redirects['success']);
            case 'CANCEL':
                return $this->decodeUrl($redirects['cancel']);
            case 'TIMEOUT':
                return $this->decodeUrl($redirects['timeout']);
            case 'FAIL':
            default:
                return $this->decodeUrl($redirects['fail']);
        }
    }

    private function decodeUrl(string $url): string {
        $decoded = rawurldecode($url);

        return $decoded ?: $url;
    }

    private function addDonationNote(Donation $donation, string $content): void {
        DonationNote::create([
            'donationId' => $donation->id,
            'content' => $content,
        ]);
    }
}
