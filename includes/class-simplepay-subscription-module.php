<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Log\PaymentGatewayLog;
use Give\Framework\PaymentGateways\SubscriptionModule;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;

/**
 * Recurring donations: the first payment registers the card and returns tokens, one per later charge.
 * An hourly cron job (processDueRenewals) charges one token per due renewal.
 */
class SimplePaySubscriptionModule extends SubscriptionModule {

    const TOKENS_META = '_simplepay_tokens';
    const FAILURES_META = '_simplepay_renewal_failures';
    const MAX_TOKENS = 24;
    const MAX_FAILURES = 3;

    public function createSubscription(Donation $donation, Subscription $subscription, $gatewayData) {
        // The initial payment is installment one
        $times = $subscription->installments > 0 ? max(1, min(self::MAX_TOKENS, $subscription->installments - 1)) : self::MAX_TOKENS;
        $code = $subscription->amount->getCurrency()->getCode();

        $response = $this->gateway->startTransaction($donation, (array) $gatewayData, [
            'recurring' => [
                'times'     => $times,
                'until'     => date('c', self::tokensValidUntil($subscription, $times)),
                'maxAmount' => SimplePayApiClient::formatAmount($subscription->amount->formatToDecimal(), $code),
            ],
        ]);

        $tokens = $response['tokens'] ?? [];
        if (empty($tokens)) {
            throw new PaymentGatewayException(
                __('SimplePay did not return recurring tokens. Check that card registration is enabled on your merchant account.', 'give-simplepay')
            );
        }

        give()->subscription_meta->update_meta($subscription->id, self::TOKENS_META, array_values($tokens));

        $subscription->gatewaySubscriptionId = (string) $response['transactionId'];
        $subscription->transactionId = (string) $response['transactionId'];
        $subscription->save();

        return new RedirectOffsite($response['paymentUrl']);
    }

    /**
     * Tokens must outlive the last renewal they cover, plus a month of cron slack.
     * shortcut: capped at 5 years because SimplePay does not document the maximum token validity; lower it if start returns error 5086.
     */
    private static function tokensValidUntil(Subscription $subscription, int $times): int {
        $period = $subscription->period->getValue();
        $count = $times * $subscription->frequency * ($period === 'quarter' ? 3 : 1);
        $unit = $period === 'quarter' ? 'month' : $period;

        return min(strtotime("+{$count} {$unit} +1 month"), strtotime('+5 years'));
    }

    public function cancelSubscription(Subscription $subscription) {
        try {
            $client = SimplePayApiClient::fromSettings($subscription->mode->isTest());
            foreach (array_filter((array) give()->subscription_meta->get_meta($subscription->id, self::TOKENS_META, true)) as $token) {
                $client->tokenCancel($token);
            }
        } catch (\Throwable $e) {
            // Best effort: once cancelled here the remaining tokens are never charged anyway
            PaymentGatewayLog::warning('SimplePay tokens could not be cancelled', ['subscriptionId' => $subscription->id, 'reason' => $e->getMessage()]);
        }

        give()->subscription_meta->delete_meta($subscription->id, self::TOKENS_META);

        $subscription->status = SubscriptionStatus::CANCELLED();
        $subscription->save();
    }

    public static function processDueRenewals(): void {
        $due = Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE()->getValue())
            ->where('expiration', current_time('mysql'), '<=')
            ->getAll() ?: [];

        foreach ($due as $subscription) {
            if ($subscription->gatewayId !== SimplePayGateway::id()) {
                continue;
            }

            if ($subscription->shouldEndSubscription()) {
                $subscription->status = SubscriptionStatus::COMPLETED();
                $subscription->save();
                continue;
            }

            try {
                self::chargeRenewal($subscription);
            } catch (\Throwable $e) {
                self::recordFailure($subscription, $e->getMessage());
            }
        }
    }

    private static function chargeRenewal(Subscription $subscription): void {
        $tokens = array_values(array_filter((array) give()->subscription_meta->get_meta($subscription->id, self::TOKENS_META, true)));
        if (empty($tokens)) {
            $subscription->status = SubscriptionStatus::FAILING();
            $subscription->save();
            PaymentGatewayLog::warning(
                'SimplePay tokens exhausted or missing; the donor must set up a new recurring donation',
                ['subscriptionId' => $subscription->id]
            );
            return;
        }

        $initial = $subscription->initialDonation();
        if (!$initial) {
            throw new \RuntimeException('Initial donation not found');
        }

        $client = SimplePayApiClient::fromSettings($subscription->mode->isTest());
        $code = $subscription->amount->getCurrency()->getCode();

        $request = [
            'orderRef'             => 'givewp-' . $subscription->id . '-' . time(),
            'currency'             => $code,
            'total'                => SimplePayApiClient::formatAmount($subscription->amount->formatToDecimal(), $code),
            'customerEmail'        => $initial->email,
            'customer'             => trim($initial->firstName . ' ' . $initial->lastName),
            'language'             => SimplePayApiClient::language(get_locale()),
            'methods'              => ['CARD'],
            'token'                => $tokens[0],
            'type'                 => 'MIT',
            'threeDSReqAuthMethod' => '02',
            'twoStep'              => false,
        ];
        if ($invoice = SimplePayGateway::invoice($initial)) {
            $request['invoice'] = $invoice;
        }

        $response = $client->doRecurring($request);
        if (empty($response['transactionId'])) {
            throw new \RuntimeException('SimplePay did not return a transaction ID');
        }

        // The card is charged from here on: a failure to record it must stop the hourly retry, not repeat the charge
        try {
            array_shift($tokens);
            give()->subscription_meta->update_meta($subscription->id, self::TOKENS_META, $tokens);
            give()->subscription_meta->update_meta($subscription->id, self::FAILURES_META, 0);

            $renewal = $subscription->createRenewal([
                'gatewayTransactionId' => (string) $response['transactionId'],
                'status'               => DonationStatus::PROCESSING(),
            ]);
            DonationNote::create([
                'donationId' => $renewal->id,
                'content'    => sprintf('SimplePay recurring charge started (transaction %s); awaiting IPN.', $response['transactionId']),
            ]);

            // Skip periods missed while the site was down instead of charging them one per hour
            for ($i = 0; $i < 100 && $subscription->renewsAt->getTimestamp() <= time(); $i++) {
                $subscription->bumpRenewalDate();
            }
            $subscription->save();
        } catch (\Throwable $e) {
            $subscription->status = SubscriptionStatus::FAILING();
            $subscription->save();
            PaymentGatewayLog::error('SimplePay renewal charged but not recorded', [
                'subscriptionId' => $subscription->id,
                'transactionId'  => $response['transactionId'],
                'reason'         => $e->getMessage(),
            ]);
        }
    }

    private static function recordFailure(Subscription $subscription, string $reason): void {
        $failures = (int) give()->subscription_meta->get_meta($subscription->id, self::FAILURES_META, true) + 1;
        give()->subscription_meta->update_meta($subscription->id, self::FAILURES_META, $failures);

        PaymentGatewayLog::error('SimplePay renewal failed', [
            'subscriptionId' => $subscription->id,
            'attempt'        => $failures,
            'reason'         => $reason,
        ]);

        if ($failures >= self::MAX_FAILURES) {
            $subscription->status = SubscriptionStatus::FAILING();
            $subscription->save();
        }
    }
}
