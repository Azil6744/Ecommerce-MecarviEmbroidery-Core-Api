<?php

namespace App\Services;

use App\Models\EcommerceSubscriptionPlan;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Omnipay\Omnipay;

/**
 * PayPal payments for membership changes (upgrade, plan change, reactivation).
 *
 * PayPal needs the customer to approve the payment on paypal.com, so this is a
 * two-step flow:
 *   1. start()    — create the PayPal payment and remember what it is for
 *                   (user, plan, amount, action); the customer is redirected.
 *   2. complete() — after PayPal sends the customer back, capture the payment,
 *                   but only if it matches what was approved.
 *
 * In local/testing without PayPal credentials, a sandbox simulation is used so
 * the flow can be exercised end to end (same idea as the Stripe 4242 test mode).
 */
class PayPalMembershipPaymentService
{
    private const INTENT_TTL_MINUTES = 60;
    private const TEST_PREFIX = 'PAYID-TEST-';

    public function isTestMode(): bool
    {
        return app()->environment(['local', 'testing']) && ! $this->gateway();
    }

    /**
     * @return array{payment_id: string, redirect_url: string}
     */
    public function start(User $user, EcommerceSubscriptionPlan $plan, float $amount, string $action, string $returnUrl, string $cancelUrl): array
    {
        return $this->startFor($user, ['plan_id' => (int) $plan->id, 'action' => $action], $amount, 'Membership: ' . $plan->name, $returnUrl, $cancelUrl);
    }

    /**
     * Same two-step flow for any purchase (e.g. a Mecarvi Cash top-up). $purpose identifies what the
     * approval is for; complete() only captures when it is called with the same purpose.
     *
     * @return array{payment_id: string, redirect_url: string}
     */
    public function startFor(User $user, array $purpose, float $amount, string $description, string $returnUrl, string $cancelUrl): array
    {
        $amount = round($amount, 2);

        if ($this->isTestMode()) {
            $paymentId = self::TEST_PREFIX . Str::upper(Str::random(16));
            $redirect = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . http_build_query(['paymentId' => $paymentId, 'PayerID' => 'TESTPAYER']);
        } else {
            $gateway = $this->gateway();
            if (! $gateway) {
                throw ValidationException::withMessages(['payment_method' => 'PayPal is not configured. Choose another payment method.']);
            }
            $response = $gateway->purchase([
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => env('CASHIER_CURRENCY', 'USD'),
                'description' => $description,
                'returnUrl' => $returnUrl,
                'cancelUrl' => $cancelUrl,
            ])->send();

            if (! $response->isRedirect()) {
                throw ValidationException::withMessages(['payment_method' => $response->getMessage() ?: 'PayPal could not start the payment.']);
            }
            $paymentId = (string) $response->getTransactionReference();
            $redirect = (string) $response->getRedirectUrl();
        }

        Cache::put($this->intentKey($paymentId), [
            'user_id' => $user->id,
            'amount' => $amount,
            'purpose' => $purpose,
        ], now()->addMinutes(self::INTENT_TTL_MINUTES));

        return ['payment_id' => $paymentId, 'redirect_url' => $redirect];
    }

    /**
     * Capture an approved payment. The amount due now must still match what the
     * customer approved (within a cent); otherwise nothing is captured.
     *
     * @return array{payment_method: string, payment_processor: string, transaction_reference: string, amount: float}
     */
    public function complete(User $user, EcommerceSubscriptionPlan $plan, float $amountDue, string $action, string $paymentId, string $payerId): array
    {
        return $this->completeFor($user, ['plan_id' => (int) $plan->id, 'action' => $action], $amountDue, $paymentId, $payerId, 'This PayPal approval is for a different membership change.');
    }

    /** Whether this PayPal payment id is an approval still waiting to be captured. */
    public function isPendingApproval(string $paymentId): bool
    {
        return Cache::has($this->intentKey($paymentId));
    }

    /** Capture an approval made with startFor() for the same user and purpose. See complete(). */
    public function completeFor(User $user, array $purpose, float $amountDue, string $paymentId, string $payerId, string $mismatchMessage = 'This PayPal approval is for a different payment.'): array
    {
        $intent = Cache::get($this->intentKey($paymentId));
        if (! $intent) {
            throw ValidationException::withMessages(['payment_method' => 'This PayPal approval has expired. Please start again.']);
        }
        // Approvals cached before startFor() existed stored plan_id/action at the top level.
        $intentPurpose = $intent['purpose'] ?? ['plan_id' => (int) ($intent['plan_id'] ?? 0), 'action' => $intent['action'] ?? null];
        if ((int) $intent['user_id'] !== (int) $user->id || $intentPurpose != $purpose) {
            throw ValidationException::withMessages(['payment_method' => $mismatchMessage]);
        }
        if (abs(round($amountDue, 2) - (float) $intent['amount']) > 0.01) {
            throw ValidationException::withMessages([
                'payment_method' => 'The amount due changed from $' . number_format($intent['amount'], 2) . ' to $' . number_format($amountDue, 2) . '. Please approve the new amount.',
            ]);
        }

        // One capture per approval, even if the request is repeated.
        if (! Cache::add($this->captureLockKey($paymentId), true, now()->addMinutes(self::INTENT_TTL_MINUTES))) {
            throw ValidationException::withMessages(['payment_method' => 'This PayPal payment was already used.']);
        }

        try {
            if (str_starts_with($paymentId, self::TEST_PREFIX)) {
                if (! $this->isTestMode()) {
                    throw ValidationException::withMessages(['payment_method' => 'Invalid PayPal payment.']);
                }
                $reference = 'PAYPAL-TEST-' . substr($paymentId, strlen(self::TEST_PREFIX));
            } else {
                $response = $this->gateway()->completePurchase([
                    'payer_id' => $payerId,
                    'transactionReference' => $paymentId,
                ])->send();
                if (! $response->isSuccessful()) {
                    throw ValidationException::withMessages(['payment_method' => $response->getMessage() ?: 'PayPal payment was not completed.']);
                }
                $reference = (string) ($response->getTransactionReference() ?: $paymentId);
            }
        } catch (\Throwable $e) {
            Cache::forget($this->captureLockKey($paymentId));
            throw $e;
        }

        Cache::forget($this->intentKey($paymentId));

        return [
            'payment_method' => 'paypal',
            'payment_processor' => 'paypal',
            'transaction_reference' => $reference,
            'amount' => round((float) $intent['amount'], 2),
        ];
    }

    /** Refund a captured PayPal sale (used when the membership change fails after payment). */
    public function refund(string $saleReference, float $amount): bool
    {
        if (str_starts_with($saleReference, 'PAYPAL-TEST-')) {
            return true;
        }
        $gateway = $this->gateway();
        if (! $gateway) {
            return false;
        }
        $response = $gateway->refund([
            'transactionReference' => $saleReference,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => env('CASHIER_CURRENCY', 'USD'),
        ])->send();

        return $response->isSuccessful();
    }

    private function gateway()
    {
        $model = PaymentGateway::where('provider', 'paypal')->first();
        $active = $model && $model->is_active;
        $clientId = $active && filled($model->public_key) ? $model->public_key : config('services.paypal.client_id');
        $secret = $active && filled($model->secret_key) ? $model->secret_key : config('services.paypal.secret');
        $mode = $model ? ($model->is_test_mode ? 'sandbox' : 'live') : config('services.paypal.mode', 'sandbox');

        // Omnipay missing (not installed on this server): PayPal is unavailable rather than a crash.
        if (! filled($clientId) || ! filled($secret) || ! class_exists(Omnipay::class)) {
            return null;
        }

        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->setClientId($clientId);
        $gateway->setSecret($secret);
        $gateway->setTestMode($mode === 'sandbox');

        return $gateway;
    }

    private function intentKey(string $paymentId): string
    {
        return 'membership_paypal_intent_' . $paymentId;
    }

    private function captureLockKey(string $paymentId): string
    {
        return 'membership_paypal_captured_' . $paymentId;
    }
}
