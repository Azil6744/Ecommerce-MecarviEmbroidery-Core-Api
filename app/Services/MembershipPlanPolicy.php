<?php

namespace App\Services;

use App\Models\EcommerceSubscriptionPlan;
use Carbon\CarbonImmutable;

/**
 * One reading of the "Pricing & Billing" step of the admin plan editor.
 *
 * The plan editor stores human labels ("Renew at current plan price", "After grace period", ...)
 * in benefit_config / failed_payment_settings. This class turns them into machine values that
 * the subscribe flow (Core), the self-service actions (Core) and the billing job (Central) all
 * read, so a setting changed in the admin panel behaves the same everywhere.
 *
 * The normalised policy is also copied onto each membership as metadata.billing_policy, which
 * is what Central's daily billing job reads.
 */
class MembershipPlanPolicy
{
    public const PAYMENT_METHODS = ['card', 'paypal', 'wallet'];

    /** Normalised policy for a plan. */
    public static function for(EcommerceSubscriptionPlan $plan): array
    {
        $cfg = is_array($plan->benefit_config) ? $plan->benefit_config : [];
        $fail = is_array($plan->failed_payment_settings) ? $plan->failed_payment_settings : [];
        $price = round((float) $plan->price, 2);

        $methods = collect($cfg['payment_methods'] ?? ['credit_card', 'paypal'])
            ->map(fn ($m) => self::paymentMethod((string) $m))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if (empty($methods)) {
            $methods = ['card'];
        }

        $trialEnabled = (bool) ($plan->trial_available ?? false) && (int) ($plan->trial_duration_days ?? 0) > 0;
        $firstPayment = self::pick($cfg['first_payment_timing'] ?? 'immediately', [
            'after_trial' => ['after_trial', 'after trial'],
            'on_fixed_date' => ['on_fixed_date', 'fixed'],
        ], 'immediately');
        if ($firstPayment === 'after_trial' && ! $trialEnabled) {
            $firstPayment = 'immediately';
        }
        $fixedDate = MembershipProrationService::parseDate($cfg['fixed_first_payment_date'] ?? null);
        if ($firstPayment === 'on_fixed_date' && ! $fixedDate) {
            $firstPayment = 'immediately';
        }

        $renewal = self::pick($cfg['renewal_price_behavior'] ?? '', [
            'locked' => ['lock', 'original', 'signup'],
            'custom' => ['custom'],
        ], 'current');
        $customRenewal = isset($cfg['custom_renewal_price']) && is_numeric($cfg['custom_renewal_price'])
            ? round((float) $cfg['custom_renewal_price'], 2)
            : null;
        if ($renewal === 'custom' && $customRenewal === null) {
            $renewal = 'current';
        }

        $reminder = strtolower((string) ($cfg['renewal_reminder_timing'] ?? '7 days before renewal'));
        $reminderDays = str_contains($reminder, 'not') || str_contains($reminder, 'none') ? 0 : (int) (preg_match('/(\d+)/', $reminder, $m) ? $m[1] : 7);

        $suspension = self::pick($fail['suspension_timing'] ?? '', [
            'immediately' => ['immediate'],
            'after_retries' => ['retr'],
        ], 'after_grace_period');

        return [
            'plan_id' => $plan->id,
            'plan_price' => $price,
            'currency' => strtoupper((string) ($plan->currency ?: 'USD')),
            'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
            'cycle_months' => MembershipProrationService::cycleMonths($plan->billing_cycle, $plan->billing_interval_count),
            'billing_display_name' => self::text($cfg['billing_display_name'] ?? null) ?: $plan->name,
            'billing_description' => self::text($cfg['billing_description'] ?? null),
            'tax_treatment' => self::pick($plan->tax_treatment ?? '', [
                'included' => ['included'],
                'checkout' => ['checkout', 'calculated'],
            ], 'none'),
            'discounts_allowed' => ! str_contains(strtolower((string) ($cfg['discount_eligibility'] ?? 'allow')), 'not'),
            'compare_at_price' => (float) ($plan->introductory_price ?? 0) > $price ? round((float) $plan->introductory_price, 2) : null,
            'payment_methods' => $methods,
            'billing_timing' => str_contains(strtolower((string) ($cfg['billing_cycle_timing'] ?? '')), 'arrear') ? 'arrears' : 'advance',
            'billing_anchor' => self::pick($cfg['billing_anchor'] ?? '', [
                'first_of_month' => ['first'],
                'custom_day' => ['custom', 'fixed'],
            ], 'subscription_date'),
            'align_billing_dates' => self::pick($cfg['align_billing_dates'] ?? '', [
                'first_of_month' => ['first', 'calendar'],
                'anniversary' => ['anniversary'],
            ], 'subscription_date'),
            'custom_billing_day' => max(1, min(28, (int) ($cfg['custom_billing_day'] ?? 1) ?: 1)),
            'prorate_first_cycle' => (bool) ($cfg['prorate_first_cycle'] ?? true),
            'auto_renew' => ($cfg['auto_renew'] ?? true) !== false,
            'renewal_price_behavior' => $renewal,
            'custom_renewal_price' => $customRenewal,
            'renewal_reminder_days' => $reminderDays,
            'first_payment_timing' => $firstPayment,
            'fixed_first_payment_date' => $fixedDate?->toDateString(),
            'trial_enabled' => $trialEnabled,
            'trial_days' => $trialEnabled ? (int) $plan->trial_duration_days : 0,
            'trial_eligibility' => self::pick($cfg['trial_eligibility'] ?? '', [
                'all' => ['all'],
                'returning' => ['returning'],
            ], 'first_time'),
            'after_trial' => self::pick($cfg['after_trial_ends'] ?? '', [
                'require_confirmation' => ['confirm'],
                'cancel' => ['cancel'],
            ], 'auto_start'),
            'min_commitment_months' => self::months($cfg['min_commitment_period'] ?? null),
            'max_duration_months' => self::months($cfg['max_subscription_duration'] ?? null),
            'suspension_timing' => $suspension,
            'grace_period_days' => max(0, (int) ($fail['grace_period_days'] ?? 7)),
            'pause_billing_behavior' => self::pick($fail['pause_billing_behavior'] ?? '', [
                'accumulate' => ['accumul', 'past due'],
                'cancel' => ['cancel'],
            ], 'stop'),
            'send_receipt' => (bool) ($cfg['send_receipt_each_payment'] ?? true),
        ];
    }

    /** "credit_card" / "Credit / Debit Card" / "stripe" → "card", etc. Null when unknown. */
    public static function paymentMethod(?string $method): ?string
    {
        $m = strtolower(trim((string) $method));
        if ($m === '') {
            return null;
        }
        if (str_contains($m, 'wallet')) {
            return 'wallet';
        }
        if (str_contains($m, 'paypal')) {
            return 'paypal';
        }
        if (str_contains($m, 'card') || str_contains($m, 'stripe') || str_contains($m, 'debit') || str_contains($m, 'credit')) {
            return 'card';
        }
        return null;
    }

    /** Throws a 422-style message when the plan does not accept the chosen payment method. */
    public static function assertPaymentMethod(array $policy, ?string $method): ?string
    {
        $normalized = self::paymentMethod($method) ?? 'card';
        if (in_array($normalized, $policy['payment_methods'], true)) {
            return null;
        }
        $labels = ['card' => 'Credit / Debit Card', 'paypal' => 'PayPal', 'wallet' => 'Wallet Balance'];
        $allowed = implode(', ', array_map(fn ($m) => $labels[$m] ?? $m, $policy['payment_methods']));

        return "This plan does not accept " . ($labels[$normalized] ?? $normalized) . ". Accepted: {$allowed}.";
    }

    /**
     * Can this customer get the trial?
     *
     * @param array $history the customer's earlier memberships (any store): rows with status / price / metadata
     */
    public static function trialAllowed(array $policy, array $history): bool
    {
        if (! $policy['trial_enabled']) {
            return false;
        }
        $hadPaid = false;
        $hadTrialOnPlan = false;
        foreach ($history as $row) {
            $price = (float) ($row['price'] ?? 0);
            if ($price > 0) {
                $hadPaid = true;
            }
            $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            if (! empty($meta['trial']) && (int) ($row['plan_id'] ?? 0) === (int) $policy['plan_id']) {
                $hadTrialOnPlan = true;
            }
        }
        // Nobody gets the same plan's trial twice.
        if ($hadTrialOnPlan) {
            return false;
        }

        return match ($policy['trial_eligibility']) {
            'all' => true,
            'returning' => $hadPaid,
            default => ! $hadPaid,
        };
    }

    /**
     * Dates, status and amount for a brand-new subscription.
     *
     * @return array{status:string, amount_due_today:float, charge_now:bool, trial_ends_at:?string,
     *               first_charge_date:string, period_start:string, period_end:string, next_billing_date:string,
     *               first_period_prorated:bool, commitment_ends_at:?string, max_ends_at:?string, renewal_price:float}
     */
    public static function schedule(array $policy, bool $withTrial, ?CarbonImmutable $today = null): array
    {
        $today ??= MembershipProrationService::today();
        $price = (float) $policy['plan_price'];
        $months = (int) $policy['cycle_months'];

        $trialEnds = $withTrial ? $today->addDays((int) $policy['trial_days']) : null;

        // Billing starts after the trial, on the fixed date, or today.
        $billingStart = $today;
        if ($withTrial) {
            $billingStart = $trialEnds;
        } elseif ($policy['first_payment_timing'] === 'on_fixed_date' && $policy['fixed_first_payment_date']) {
            $fixed = MembershipProrationService::parseDate($policy['fixed_first_payment_date']);
            if ($fixed && $fixed->greaterThan($today)) {
                $billingStart = $fixed;
            }
        }

        // First paid period runs to the anchor (1st / custom day) or a full cycle.
        $fullEnd = MembershipProrationService::addMonths($billingStart, $months);
        $periodEnd = $fullEnd;
        $anchorDay = match ($policy['billing_anchor']) {
            'first_of_month' => 1,
            'custom_day' => (int) $policy['custom_billing_day'],
            default => null,
        };
        if ($anchorDay === null && $policy['align_billing_dates'] === 'first_of_month') {
            $anchorDay = 1;
        }
        $prorated = false;
        $firstAmount = $price;
        if ($anchorDay !== null && $billingStart->day !== $anchorDay) {
            $candidate = $billingStart->day < $anchorDay
                ? $billingStart->setDay($anchorDay)
                : MembershipProrationService::addMonths($billingStart->setDay(1), 1)->setDay($anchorDay);
            // Short stub up to the anchor, then normal cycles. Without proration the stub is folded into a full cycle.
            if ($policy['prorate_first_cycle']) {
                $periodEnd = $candidate;
                $days = max(1, MembershipProrationService::daysBetween($billingStart, $fullEnd));
                $firstAmount = round($price * MembershipProrationService::daysBetween($billingStart, $candidate) / $days, 2);
                $prorated = true;
            } else {
                // No proration: the days up to the anchor are free, the first full charge is on the anchor.
                $periodEnd = $candidate;
                $firstAmount = 0.0;
            }
        }

        $freeStub = $anchorDay !== null && ! $prorated && $firstAmount <= 0 && $price > 0;
        if ($freeStub && ! $billingStart->greaterThan($today)) {
            $billingStart = $periodEnd;
        }
        $deferred = $billingStart->greaterThan($today);
        $arrears = $policy['billing_timing'] === 'arrears';
        $chargeNow = ! $deferred && ! $arrears && $firstAmount > 0;

        // Access starts today in every case; a fixed-date or in-arrears plan just bills later.
        $status = $withTrial ? 'trialing' : 'active';

        $start = $today;
        $commitment = $policy['min_commitment_months'] > 0 ? MembershipProrationService::addMonths($start, $policy['min_commitment_months']) : null;
        $maxEnd = $policy['max_duration_months'] > 0 ? MembershipProrationService::addMonths($start, $policy['max_duration_months']) : null;

        return [
            'status' => $status,
            'charge_now' => $chargeNow,
            'amount_due_today' => $chargeNow ? $firstAmount : 0.0,
            'first_charge_amount' => $deferred ? $price : $firstAmount,
            'trial_ends_at' => $trialEnds?->toDateString(),
            'first_charge_date' => ($arrears ? ($deferred ? $fullEnd : $periodEnd) : $billingStart)->toDateString(),
            // Trial / fixed first-payment date: free access until billing starts, then Central bills a full cycle.
            'period_start' => $today->toDateString(),
            'period_end' => ($deferred ? $billingStart : $periodEnd)->toDateString(),
            'next_billing_date' => ($deferred ? $billingStart : $periodEnd)->toDateString(),
            'first_period_prorated' => $prorated,
            'commitment_ends_at' => $commitment?->toDateString(),
            'max_ends_at' => $maxEnd?->toDateString(),
            'renewal_price' => self::renewalPrice($policy, $price),
        ];
    }

    /** Price charged at the next renewal. $signupPrice is what the member signed up at. */
    public static function renewalPrice(array $policy, float $signupPrice): float
    {
        return match ($policy['renewal_price_behavior']) {
            'locked' => round($signupPrice, 2),
            'custom' => (float) $policy['custom_renewal_price'],
            default => (float) $policy['plan_price'],
        };
    }

    /** Membership metadata for a new subscription (what Central's billing job reads). */
    public static function membershipMetadata(array $policy, array $schedule, bool $withTrial, array $existing = []): array
    {
        return array_merge($existing, [
            'billing_policy' => $policy,
            'auto_renewal' => $policy['auto_renew'],
            'signup_price' => $policy['plan_price'],
            'renewal_price' => $schedule['renewal_price'],
            'commitment_ends_at' => $schedule['commitment_ends_at'],
            'max_ends_at' => $schedule['max_ends_at'],
            'trial' => $withTrial ? ['started_at' => MembershipProrationService::today()->toDateString(), 'ends_at' => $schedule['trial_ends_at']] : ($existing['trial'] ?? null),
            'first_charge' => [
                'date' => $schedule['first_charge_date'],
                'amount' => $schedule['first_charge_amount'],
                'collected' => $schedule['charge_now'],
                'prorated' => $schedule['first_period_prorated'],
            ],
        ]);
    }

    /**
     * After an admin edits a plan: the parts of an existing member's metadata that must follow the plan.
     * The member's own choices (auto-renew switched off, locked signup price) are kept.
     */
    public static function refreshMembershipMetadata(array $policy, array $metadata, float $memberPrice): array
    {
        $signup = (float) ($metadata['signup_price'] ?? $memberPrice);
        $metadata['billing_policy'] = $policy;
        $metadata['renewal_price'] = self::renewalPrice($policy, $signup);
        if (! $policy['auto_renew']) {
            // The plan no longer renews: stop it for everyone at the end of the current period.
            $metadata['auto_renewal'] = false;
        }

        return $metadata;
    }

    /** Message when a customer may not cancel/downgrade yet, or null. */
    public static function commitmentBlock(array $metadata, string $what = 'cancel'): ?string
    {
        $ends = MembershipProrationService::parseDate($metadata['commitment_ends_at'] ?? null);
        if ($ends && $ends->greaterThan(MembershipProrationService::today())) {
            return "Your plan has a minimum commitment until {$ends->format('M j, Y')}. You can {$what} after that date.";
        }
        return null;
    }

    private static function months(mixed $label): int
    {
        $l = strtolower(trim((string) $label));
        if ($l === '' || str_contains($l, 'no ') || str_contains($l, 'until') || str_contains($l, 'none')) {
            return 0;
        }
        $n = (int) (preg_match('/(\d+)/', $l, $m) ? $m[1] : 0);
        if ($n <= 0) {
            return 0;
        }
        return str_contains($l, 'year') ? $n * 12 : $n;
    }

    private static function pick(mixed $value, array $map, string $default): string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return $default;
        }
        foreach ($map as $key => $needles) {
            if ($v === $key) {
                return $key;
            }
            foreach ($needles as $needle) {
                if (str_contains($v, $needle)) {
                    return $key;
                }
            }
        }
        return $default;
    }

    private static function text(mixed $value): ?string
    {
        $t = trim((string) $value);
        // The old editor saved its placeholder text ("e.g. ...") as the value.
        return ($t === '' || str_starts_with(strtolower($t), 'e.g.')) ? null : $t;
    }
}
