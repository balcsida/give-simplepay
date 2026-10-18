<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\GatewayCommand;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Contracts\PaymentGatewayRefundable;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Log\PaymentGatewayLog;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;

/**
 * SimplePay v2.1 has no embeddable card form: the donor is always redirected to SimplePay,
 * returns via handleReturn, and the IPN (handleIpn) is the authoritative result.
 */
class SimplePayGateway extends PaymentGateway implements PaymentGatewayRefundable {

    public $routeMethods = ['handleIpn'];
    public $secureRouteMethods = ['handleReturn'];

    public static function id(): string {
        return 'simplepay';
    }

    public function getId(): string {
        return self::id();
    }

    public function getName(): string {
        return 'SimplePay';
    }

    public function getPaymentMethodLabel(): string {
        return __('SimplePay (bank card)', 'give-simplepay');
    }

    public function enqueueScript(int $formId) {
        wp_enqueue_script(
            'simplepay-gateway',
            SIMPLEPAY_GIVEWP_URL . 'assets/js/simplepay-gateway.js',
            ['wp-element'],
            SIMPLEPAY_GIVEWP_VERSION,
            true
        );
    }

    public function formSettings(int $formId): array {
        return [
            'message'         => $this->message(),
            'declaration'     => $this->declaration(),
            'privacyUrl'      => 'https://simplepay.hu/vasarlo-aff',
            'privacyLinkText' => __('SimplePay data processing information', 'give-simplepay'),
        ];
    }

    private function message(): string {
        return __('You will be redirected to SimplePay to pay securely by bank card.', 'give-simplepay');
    }

    // SimplePay requires this data-transfer statement to be shown at checkout.
    private function declaration(): string {
        return sprintf(
            __('By donating you acknowledge that the following personal data stored by %1$s (%2$s) will be handed over to SimplePay Zrt. as data processor: name, email address, billing address. The nature and purpose of the data processing is described in the SimplePay data processing information.', 'give-simplepay'),
            get_bloginfo('name'),
            home_url()
        );
    }

    public function getLegacyFormFieldMarkup(int $formId, array $args): string {
        $settings = $this->formSettings($formId);

        return '<fieldset class="no-fields"><p>' . esc_html($settings['message']) . '</p>'
            . '<p class="simplepay-declaration">' . esc_html($settings['declaration']) . ' '
            . '<a href="' . esc_url($settings['privacyUrl']) . '" target="_blank" rel="noopener">' . esc_html($settings['privacyLinkText']) . '</a></p></fieldset>';
    }

    public function createPayment(Donation $donation, $gatewayData): GatewayCommand {
        $response = $this->startTransaction($donation, (array) $gatewayData);

        return new RedirectOffsite($response['paymentUrl']);
    }

    /**
     * Public so the subscription module can reuse it for the card registration payment.
     */
    public function startTransaction(Donation $donation, array $gatewayData, array $extra = []): array {
        $client = SimplePayApiClient::fromSettings($donation->mode->isTest());
        $code = $donation->amount->getCurrency()->getCode();

        $returnUrl = $this->generateSecureGatewayRouteUrl('handleReturn', $donation->id, [
            'givewp-donation-id' => $donation->id,
            'givewp-return-url'  => $gatewayData['successUrl'] ?? give_get_success_page_uri(),
            'givewp-cancel-url'  => $gatewayData['cancelUrl'] ?? give_get_failed_transaction_uri(),
        ]);

        $request = [
            'orderRef'             => 'givewp-' . $donation->id,
            'currency'             => $code,
            'total'                => SimplePayApiClient::formatAmount($donation->amount->formatToDecimal(), $code),
            'customerEmail'        => $donation->email,
            'customer'             => trim($donation->firstName . ' ' . $donation->lastName),
            'language'             => SimplePayApiClient::language(get_locale()),
            'methods'              => ['CARD'],
            'timeout'              => date('c', time() + 30 * MINUTE_IN_SECONDS),
            'url'                  => $returnUrl,
            'twoStep'              => false,
            'maySelectInvoice'     => true,
            'threeDSReqAuthMethod' => is_user_logged_in() ? '02' : '01',
        ] + $extra;
        if ($invoice = self::invoice($donation)) {
            $request['invoice'] = $invoice;
        }

        $response = $client->start($request);
        if (empty($response['paymentUrl']) || empty($response['transactionId'])) {
            throw new PaymentGatewayException(__('SimplePay did not return a payment URL. Please try again later.', 'give-simplepay'));
        }

        $donation->gatewayTransactionId = (string) $response['transactionId'];
        $donation->save();

        DonationNote::create([
            'donationId' => $donation->id,
            'content'    => sprintf('SimplePay transaction %s started (order %s)', $response['transactionId'], 'givewp-' . $donation->id),
        ]);

        return $response;
    }

    /**
     * SimplePay rejects billing details without city, postcode and street (errors 5310-5312), so the block is
     * omitted when the form did not collect them and maySelectInvoice lets the donor enter them on the payment page.
     */
    public static function invoice(Donation $donation): array {
        $address = $donation->billingAddress;
        if (!$address || !$address->city || !$address->zip || !$address->address1) {
            return [];
        }

        return [
            'name'     => trim($donation->firstName . ' ' . $donation->lastName),
            'country'  => $address->country ?: 'HU',
            'state'    => (string) $address->state,
            'city'     => $address->city,
            'zip'      => $address->zip,
            'address'  => $address->address1,
            'address2' => (string) $address->address2,
        ];
    }

    protected function handleReturn(array $queryParams): RedirectResponse {
        $donation = Donation::find((int) ($queryParams['givewp-donation-id'] ?? 0));
        if (!$donation) {
            throw new PaymentGatewayException(__('Donation not found.', 'give-simplepay'));
        }

        // give_clean() mangles the base64 values in $queryParams, so read them raw
        $payload = SimplePayApiClient::decodeBackPayload(
            (string) wp_unslash($_GET['r'] ?? ''),
            (string) wp_unslash($_GET['s'] ?? ''),
            (string) give_get_option('simplepay_secret_key')
        );
        if ($payload === null) {
            throw new PaymentGatewayException(__('Invalid SimplePay response.', 'give-simplepay'));
        }

        if ((string) ($payload['t'] ?? '') !== (string) $donation->gatewayTransactionId) {
            throw new PaymentGatewayException(__('SimplePay transaction does not match the donation.', 'give-simplepay'));
        }

        $event = strtoupper((string) ($payload['e'] ?? ''));

        // Only the IPN moves a donation past Pending, so a late or replayed return cannot downgrade it
        if ($donation->status->isPending()) {
            $status = [
                'SUCCESS' => DonationStatus::PROCESSING(),
                'CANCEL'  => DonationStatus::CANCELLED(),
                'TIMEOUT' => DonationStatus::CANCELLED(),
                'FAIL'    => DonationStatus::FAILED(),
            ][$event] ?? null;

            if ($status) {
                $this->setStatus($donation, $status, sprintf('SimplePay returned %s for transaction %s', $event, $payload['t']));
            }
        }

        if ($event === 'SUCCESS') {
            $url = wp_validate_redirect($queryParams['givewp-return-url'] ?? '', give_get_success_page_uri());
        } elseif ($event === 'CANCEL' || $event === 'TIMEOUT') {
            $url = wp_validate_redirect($queryParams['givewp-cancel-url'] ?? '', give_get_failed_transaction_uri());
        } else {
            $url = give_get_failed_transaction_uri();
        }

        // Read by the the_content filter in simplepay-gateway.php: SimplePay requires the result and transaction ID on screen
        return new RedirectResponse(add_query_arg(['simplepay-event' => $event, 'simplepay-transaction' => (int) $payload['t']], $url));
    }

    public function handleIpn(array $queryParams) {
        $body = (string) file_get_contents('php://input');
        $signature = (string) ($_SERVER['HTTP_SIGNATURE'] ?? '');
        $secret = (string) give_get_option('simplepay_secret_key');

        if ($secret === '' || !SimplePayApiClient::verify($body, $signature, $secret)) {
            status_header(403);
            exit('Invalid signature');
        }

        $ipn = json_decode($body, true);
        if (!is_array($ipn) || empty($ipn['transactionId']) || empty($ipn['status'])) {
            status_header(400);
            exit('Invalid IPN');
        }

        $donation = give()->donations->getByGatewayTransactionId((string) $ipn['transactionId']);
        if (!$donation) {
            // No ack, so SimplePay retries; covers a renewal IPN racing the renewal record
            PaymentGatewayLog::warning('SimplePay IPN for unknown transaction', ['transactionId' => $ipn['transactionId']]);
            status_header(404);
            exit('Unknown transaction');
        }

        $this->applyIpnStatus($donation, $ipn);

        [$json, $sig] = SimplePayApiClient::buildIpnAck($ipn, $secret);
        status_header(200);
        header('Content-Type: application/json');
        header('Signature: ' . $sig);
        echo $json;
        exit;
    }

    private function applyIpnStatus(Donation $donation, array $ipn): void {
        $status = SimplePayApiClient::normalizeStatus((string) $ipn['status']);
        $transactionId = $ipn['transactionId'];

        $newStatus = [
            'FINISHED'      => DonationStatus::COMPLETE(),
            'AUTHORIZED'    => DonationStatus::PROCESSING(),
            'NOTAUTHORIZED' => DonationStatus::FAILED(),
            'TIMEOUT'       => DonationStatus::CANCELLED(),
            'CANCELLED'     => DonationStatus::CANCELLED(),
            'REVERSED'      => DonationStatus::CANCELLED(),
            'REFUND'        => DonationStatus::REFUNDED(),
        ][$status] ?? null;

        if (!$newStatus) {
            DonationNote::create(['donationId' => $donation->id, 'content' => sprintf('SimplePay IPN status %s received for transaction %s', $status, $transactionId)]);
            return;
        }

        // IPN retries must not duplicate notes
        if ($donation->status->getValue() === $newStatus->getValue()) {
            return;
        }

        // A late retry must not reopen a completed or refunded donation
        if (($donation->status->isComplete() || $donation->status->isRefunded()) && $status !== 'REFUND') {
            DonationNote::create(['donationId' => $donation->id, 'content' => sprintf('SimplePay IPN %s ignored: transaction %s is already %s', $status, $transactionId, $donation->status->getValue())]);
            return;
        }

        $this->setStatus($donation, $newStatus, sprintf('SimplePay IPN: transaction %s is %s', $transactionId, $status));
    }

    /**
     * Applies a status from the browser return or the IPN and keeps a pending subscription in step with its first payment.
     */
    private function setStatus(Donation $donation, DonationStatus $status, string $note): void {
        $donation->status = $status;
        $donation->save();
        DonationNote::create(['donationId' => $donation->id, 'content' => $note]);

        if (!$donation->type->isSubscription()) {
            return;
        }

        $subscription = $donation->subscription;
        if (!$subscription || !$subscription->status->isPending()) {
            return;
        }

        if ($status->isComplete()) {
            $subscription->status = SubscriptionStatus::ACTIVE();
            $subscription->save();
        } elseif ($status->isFailed() || $status->isCancelled()) {
            $subscription->status = SubscriptionStatus::CANCELLED();
            $subscription->save();
        }
    }

    public function refundDonation(Donation $donation): PaymentRefunded {
        if (empty($donation->gatewayTransactionId)) {
            throw new PaymentGatewayException(__('This donation has no SimplePay transaction ID.', 'give-simplepay'));
        }

        $client = SimplePayApiClient::fromSettings($donation->mode->isTest());
        $code = $donation->amount->getCurrency()->getCode();
        $response = $client->refund([
            'transactionId' => (int) $donation->gatewayTransactionId,
            'currency'      => $code,
            'refundTotal'   => SimplePayApiClient::formatAmount($donation->amount->formatToDecimal(), $code),
        ]);

        DonationNote::create([
            'donationId' => $donation->id,
            'content'    => sprintf('SimplePay refund issued for transaction %s (refund transaction %s)', $donation->gatewayTransactionId, $response['refundTransactionId'] ?? ''),
        ]);

        // Passing the existing id keeps it, the handler otherwise overwrites it
        return new PaymentRefunded($donation->gatewayTransactionId);
    }
}
