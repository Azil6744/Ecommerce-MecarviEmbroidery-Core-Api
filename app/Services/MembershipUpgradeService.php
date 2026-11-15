<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Upgrade quote for an admin-initiated membership upgrade.
 *
 * Day-based proration: the customer pays only for the days they use. With a 31-day cycle and
 * 16 days used, the 15 unused days of the old plan are credited and the 15 remaining days are
 * charged at the new plan's rate (billing date unchanged).
 *
 * Mirrors Ecommerce-MecarviTechnologies-Web/components/admin/memberships/upgradeBilling.ts —
 * keep the two in sync. The current billing period is resolved by MembershipProrationService.
 */
class MembershipUpgradeService
{
    /** Monthly + annual price of a subscription plan. */
    public static function planPrices(float $price, ?string $billingCycle, mixed $annualDiscount): array
    {
        $isAnnual = MembershipProrationService::cycleMonths($billingCycle) === 12;
        $discount = min(100, max(0, (float) ($annualDiscount ?? 0)));

        return [
            'monthly' => $isAnnual ? round($price / 12, 2) : $price,
            'annual' => $isAnnual ? $price : round($price * 12 * (1 - $discount / 100), 2),
        ];
    }

    /**
     * @param  array{type: string, value: float, recurring: bool}|null  $discount
     */
    public static function quote(
        CarbonImmutable $today,
        float $currentPrice,
        string $currentCycle, // monthly | annual
        ?array $period,
        float $targetPrice,
        string $targetCycle, // monthly | annual
        string $timing,      // prorated | next_cycle
        int $trialDays,
        ?array $discount
    ): array {
        $newMonths = $targetCycle === 'annual' ? 12 : 1;

        $applyDiscount = function (float $amount) use ($discount): float {
            if (! $discount || $amount <= 0) {
                return 0.0;
            }
            $value = max(0, (float) $discount['value']);
            return round($discount['type'] === 'percentage' ? $amount * min(100, $value) / 100 : min($value, $amount), 2);
        };

        $renewal = round(max(0, $targetPrice - (! empty($discount['recurring']) ? $applyDiscount($targetPrice) : 0)), 2);

        $cycleDays = $period ? max(1, (int) $period['total_days']) : 0;
        $usedDays = $period ? (int) min($cycleDays, max(0, MembershipProrationService::daysBetween($period['start'], $today))) : 0;
        $remainingDays = $cycleDays - $usedDays;

        $base = [
            'today' => $today->toDateString(),
            'period_start' => $period ? $period['start']->toDateString() : null,
            'period_end' => $period ? $period['end']->toDateString() : null,
            'period_days' => $cycleDays,
            'days_used' => $usedDays,
            'days_remaining' => $remainingDays,
            'current_price' => round($currentPrice, 2),
            'target_price' => round($targetPrice, 2),
            'target_cycle' => $targetCycle,
            'timing' => $timing,
            'trial_days' => $trialDays,
            'renewal_amount' => $renewal,
        ];

        $result = function (array $values) use ($base) {
            foreach (['effective_date', 'first_charge_date', 'trial_ends_on', 'next_billing_date', 'new_period_start', 'new_period_end'] as $k) {
                if (isset($values[$k]) && $values[$k] instanceof CarbonImmutable) {
                    $values[$k] = $values[$k]->toDateString();
                }
            }
            return $base + $values;
        };

        // Charge at start of next cycle: nothing today, no proration.
        if ($timing === 'next_cycle') {
            $effective = $period ? $period['end'] : $today;
            $trialEnds = $trialDays > 0 ? $effective->addDays($trialDays) : null;
            $firstCharge = $trialEnds ?? $effective;
            $discountAmount = $applyDiscount($targetPrice);

            return $result([
                'effective_date' => $effective,
                'new_charge' => round($targetPrice, 2),
                'unused_credit' => 0.0,
                'discount_amount' => $discountAmount,
                'total_today' => 0.0,
                'first_charge_date' => $firstCharge,
                'first_charge_amount' => round(max(0, $targetPrice - $discountAmount), 2),
                'trial_ends_on' => $trialEnds,
                'next_billing_date' => $firstCharge,
                'new_period_start' => $firstCharge,
                'new_period_end' => MembershipProrationService::addMonths($firstCharge, $newMonths),
                'carryover_credit' => 0.0,
            ]);
        }

        $unusedCredit = $period ? round(max(0, $currentPrice) * $remainingDays / $cycleDays, 2) : 0.0;

        // Trial: new plan free until the trial ends; the credit is applied to the first invoice.
        if ($trialDays > 0) {
            $trialEnds = $today->addDays($trialDays);
            $discountAmount = $applyDiscount($targetPrice);
            $due = round($targetPrice - $discountAmount - $unusedCredit, 2);

            return $result([
                'effective_date' => $today,
                'new_charge' => round($targetPrice, 2),
                'unused_credit' => $unusedCredit,
                'discount_amount' => $discountAmount,
                'total_today' => 0.0,
                'first_charge_date' => $trialEnds,
                'first_charge_amount' => max(0, $due),
                'trial_ends_on' => $trialEnds,
                'next_billing_date' => $trialEnds,
                'new_period_start' => $today,
                'new_period_end' => $trialEnds,
                'carryover_credit' => max(0, -$due),
            ]);
        }

        // Same billing cycle (and a known period): keep the billing date, charge the remaining days only.
        if ($period && $currentCycle === $targetCycle) {
            $newCharge = round($targetPrice * $remainingDays / $cycleDays, 2);
            $discountAmount = $applyDiscount($newCharge);
            $due = round($newCharge - $discountAmount - $unusedCredit, 2);

            return $result([
                'effective_date' => $today,
                'new_charge' => $newCharge,
                'unused_credit' => $unusedCredit,
                'discount_amount' => $discountAmount,
                'total_today' => max(0, $due),
                'first_charge_date' => $today,
                'first_charge_amount' => max(0, $due),
                'trial_ends_on' => null,
                'next_billing_date' => $period['end'],
                'new_period_start' => $period['start'],
                'new_period_end' => $period['end'],
                'carryover_credit' => max(0, -$due),
            ]);
        }

        // Cycle changes (or no billing dates on file): a fresh full cycle starts today, minus the credit.
        $nextBilling = MembershipProrationService::addMonths($today, $newMonths);
        $discountAmount = $applyDiscount($targetPrice);
        $due = round($targetPrice - $discountAmount - $unusedCredit, 2);

        return $result([
            'effective_date' => $today,
            'new_charge' => round($targetPrice, 2),
            'unused_credit' => $unusedCredit,
            'discount_amount' => $discountAmount,
            'total_today' => max(0, $due),
            'first_charge_date' => $today,
            'first_charge_amount' => max(0, $due),
            'trial_ends_on' => null,
            'next_billing_date' => $nextBilling,
            'new_period_start' => $today,
            'new_period_end' => $nextBilling,
            'carryover_credit' => max(0, -$due),
        ]);
    }
}
