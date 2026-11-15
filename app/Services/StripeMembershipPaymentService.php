<?php

namespace App\Services;

use App\Models\EcommerceSubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StripeMembershipPaymentService
{
    public function authorizePlanPurchase(EcommerceSubscriptionPlan $plan, Request $request): array
    {
        $paymentMethod = strtolower((string) $request->input('payment_method', 'stripe'));
        if (! in_array($paymentMethod, ['stripe', 'card', 'credit_card'], true)) {
            return [
                'payment_status' => $request->input('payment_status', 'paid'),
                'payment_method' => $paymentMethod,
                'payment_processor' => $request->input('payment_processor'),
                'transaction_reference' => $request->input('transaction_reference', 'membership_' . $plan->id . '_' . now()->timestamp),
                'payment_details' => [],
            ];
        }

        // A free plan never charges anything, now or at renewal: no card to collect.
        if ((float) $plan->price <= 0 && $this->amountDueToday($plan, $request) <= 0) {
            return [
                'payment_status' => 'paid',
                'payment_method' => 'stripe',
                'payment_processor' => 'system',
                'transaction_reference' => 'free_plan_' . $plan->id . '_' . now()->timestamp,
                'payment_details' => ['amount_due_today' => 0],
            ];
        }

        // A card is always needed for a paid plan, even when nothing is due today (free trial, deferred first
        // payment): it is what the first charge and every renewal are taken from.
        $cards = app(MembershipCardService::class);
        $user = $request->user();
        $card = $cards->resolveForPurchase($user, $request->all());
        $testMode = $card['mode'] === MembershipCardService::MODE_TEST;
        $processor = $testMode ? 'stripe_test' : 'stripe';

        $amount = $this->amountDueToday($plan, $request);
        if ($amount <= 0) {
            return [
                'payment_status' => 'paid',
                'payment_method' => 'stripe',
                'payment_processor' => $processor,
                'transaction_reference' => 'stripe_zero_due_' . $plan->id . '_' . now()->timestamp,
                'payment_details' => ['amount_due_today' => 0, 'test_mode' => $testMode, 'card' => $cards->publicCard($card)],
            ];
        }

        $charge = $cards->charge(
            $card,
            $amount,
            (string) ($plan->currency ?: 'USD'),
            'Membership purchase: ' . $plan->name,
            ['plan_id' => $plan->id, 'plan_code' => $plan->internal_code, 'purpose' => 'membership_purchase', 'user_id' => $user?->id],
            // Same customer, plan, card and amount within the hour is one purchase (double clicks, retries).
            'mem_buy_' . sha1(implode('|', [$user?->id, $plan->id, $card['payment_method'], $amount, now()->format('Y-m-d H')]))
        );

        if (! $charge['paid']) {
            throw ValidationException::withMessages(['stripe' => [$charge['reason'] ?: 'Stripe payment did not succeed.']]);
        }

        return [
            'payment_status' => 'paid',
            'payment_method' => 'stripe',
            'payment_processor' => $processor,
            'transaction_reference' => $testMode
                ? 'test_stripe_membership_' . $plan->id . '_' . now()->timestamp
                : (string) $charge['reference'],
            'payment_details' => $charge['details'] + ['amount_due_today' => $amount],
        ];
    }

    private function amountDueToday(EcommerceSubscriptionPlan $plan, Request $request): float
    {
        $summaryAmount = data_get($request->input('payment_summary', []), 'amount_due_today');
        if (is_numeric($summaryAmount)) {
            return round(max(0, (float) $summaryAmount), 2);
        }

        return round(max(0, (float) $plan->price + (float) ($plan->setup_fee ?? 0)), 2);
    }
}
