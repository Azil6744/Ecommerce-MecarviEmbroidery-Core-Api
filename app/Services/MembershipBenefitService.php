<?php

namespace App\Services;

class MembershipBenefitService
{
    public function evaluate(array $memberships, float $itemsSubtotal, float $shippingAmount, ?string $siteSlug = null, bool $hasCoupon = false): array
    {
        $best = $this->emptyResult();

        foreach ($memberships as $membership) {
            if (! $this->isActiveMembership($membership) || ! $this->coversSite($membership, $siteSlug)) {
                continue;
            }

            $result = $this->evaluateMembership($membership, $itemsSubtotal, $shippingAmount, $hasCoupon);
            if ($result['membership_discount_amount'] > $best['membership_discount_amount']) {
                $best = $result;
            }
        }

        return $best;
    }

    private function evaluateMembership(array $membership, float $itemsSubtotal, float $shippingAmount, bool $hasCoupon): array
    {
        $result = $this->emptyResult();
        $result['membership_id'] = $membership['id'] ?? $membership['membership_id'] ?? null;
        $result['membership_plan_name'] = $membership['plan_name'] ?? $membership['membership_tier'] ?? null;
        $snapshot = $membership['benefits_snapshot'] ?? $membership['benefits'] ?? null;

        if (empty($snapshot)) {
            $plan = null;
            if (!empty($membership['plan_id'])) {
                $plan = \App\Models\EcommerceSubscriptionPlan::find($membership['plan_id']);
            }
            if (!$plan && !empty($result['membership_plan_name'])) {
                $plan = \App\Models\EcommerceSubscriptionPlan::where('name', $result['membership_plan_name'])
                    ->orWhere('internal_code', $result['membership_plan_name'])
                    ->first();
            }
            if ($plan) {
                $snapshot = $plan->benefit_config ?: $plan->features;
            }
        }

        $result['membership_benefits_snapshot'] = $snapshot;

        $runningDiscount = 0.0;
        foreach ($this->extractBenefits($result['membership_benefits_snapshot']) as $benefit) {
            $type = strtolower((string) ($benefit['benefit_type'] ?? $benefit['type'] ?? ''));
            $restrictions = $this->restrictionBag($benefit);

            if ($type === '' || ! $this->passesRestrictions($restrictions, $itemsSubtotal, $hasCoupon)) {
                continue;
            }

            $value = (float) ($benefit['benefit_value'] ?? $benefit['value'] ?? $benefit['amount'] ?? 0);
            $discount = match ($type) {
                'percentage_discount', 'order_percentage_discount' => $itemsSubtotal * ($value / 100),
                'fixed_discount', 'order_fixed_discount', 'monthly_store_credit' => min($itemsSubtotal, $value),
                'free_delivery', 'free_shipping' => $shippingAmount,
                'discounted_delivery', 'discounted_shipping' => $this->deliveryDiscount($benefit, $shippingAmount),
                default => 0.0,
            };

            $maxAmount = $this->restrictionNumber($restrictions, ['max_discount_amount', 'max_amount', 'maximum_discount_amount']);
            if ($maxAmount !== null) {
                $discount = min($discount, $maxAmount);
            }

            $discount = round(max(0, $discount), 2);
            if ($discount <= 0) {
                continue;
            }

            $remaining = max(0, ($itemsSubtotal + $shippingAmount) - $runningDiscount);
            $discount = min($discount, $remaining);
            $runningDiscount = round($runningDiscount + $discount, 2);

            $result['membership_benefit_usage'][] = [
                'type' => $type,
                'label' => $benefit['title'] ?? $benefit['name'] ?? $this->humanLabel($type),
                'value' => $value,
                'discount_amount' => $discount,
            ];
        }

        $result['membership_discount_amount'] = round($runningDiscount, 2);

        return $result;
    }

    private function extractBenefits(mixed $snapshot): array
    {
        if (is_string($snapshot)) {
            $decoded = json_decode($snapshot, true);
            if (is_array($decoded)) {
                return $this->extractBenefits($decoded);
            }
            $parsed = $this->parseStringBenefit($snapshot);
            return $parsed ? [$parsed] : [];
        }

        if (! is_array($snapshot)) {
            return [];
        }

        if (isset($snapshot['benefits']) && is_array($snapshot['benefits'])) {
            return $this->extractBenefits($snapshot['benefits']);
        }

        if (isset($snapshot['checkout_benefits']) && is_array($snapshot['checkout_benefits'])) {
            return $this->extractBenefits($snapshot['checkout_benefits']);
        }

        if (isset($snapshot['benefit_type']) || isset($snapshot['type'])) {
            return [$snapshot];
        }

        if (array_is_list($snapshot)) {
            $extracted = [];
            foreach ($snapshot as $item) {
                if (is_array($item)) {
                    $extracted = array_merge($extracted, $this->extractBenefits($item));
                } elseif (is_string($item)) {
                    $parsed = $this->parseStringBenefit($item);
                    if ($parsed) {
                        $extracted[] = $parsed;
                    }
                }
            }
            return $extracted;
        }

        // If snapshot is an associative object dictionary (e.g. benefit_config)
        $extracted = [];

        // Check if benefits_table is present from Admin Plan Wizard
        if (!empty($snapshot['benefits_table']) && is_array($snapshot['benefits_table'])) {
            $canCombine = true;
            if (!empty($snapshot['discount_eligibility'])) {
                $canCombine = !str_contains(strtolower((string) $snapshot['discount_eligibility']), 'not allow');
            }

            foreach ($snapshot['benefits_table'] as $b) {
                // If explicitly disabled in wizard, skip
                if (isset($b['status']) && $b['status'] === false) {
                    continue;
                }

                $title = trim((string) ($b['title'] ?? ''));
                $type = strtolower(trim((string) ($b['type'] ?? '')));
                $details = trim((string) ($b['details'] ?? ''));
                $minOrderStr = trim((string) ($b['min_order'] ?? ''));

                $minOrderAmount = 0.0;
                if (preg_match('/[\d\.]+/i', $minOrderStr, $minMatches)) {
                    $minOrderAmount = (float) $minMatches[0];
                }

                $restrictions = array_merge($snapshot, [
                    'minimum_order_amount' => $minOrderAmount,
                    'can_combine_with_coupons' => $canCombine,
                ]);

                // 1. Percentage discount: "15% off" or details with %
                if (str_contains($type, 'discount') || preg_match('/(\d+(?:\.\d+)?)%/i', $details, $pMatch)) {
                    if (preg_match('/(\d+(?:\.\d+)?)%/i', $details, $pMatch)) {
                        $val = (float) $pMatch[1];
                        if ($val > 0) {
                            $extracted[] = [
                                'title' => $title ?: ($val . '% Member Discount'),
                                'benefit_type' => 'percentage_discount',
                                'benefit_value' => $val,
                                'restrictions' => $restrictions,
                            ];
                            continue;
                        }
                    } elseif (preg_match('/\$\s*(\d+(?:\.\d+)?)/i', $details, $fMatch)) {
                        $val = (float) $fMatch[1];
                        if ($val > 0) {
                            $extracted[] = [
                                'title' => $title ?: ('$' . $val . ' Member Discount'),
                                'benefit_type' => 'fixed_discount',
                                'benefit_value' => $val,
                                'restrictions' => $restrictions,
                            ];
                            continue;
                        }
                    }
                }

                // 2. Shipping: "Free shipping", "Free standard shipping"
                if (
                    str_contains($type, 'shipping') ||
                    str_contains($type, 'delivery') ||
                    (str_contains(strtolower($title), 'free') && (str_contains(strtolower($title), 'shipping') || str_contains(strtolower($title), 'delivery'))) ||
                    (str_contains(strtolower($details), 'free') && (str_contains(strtolower($details), 'shipping') || str_contains(strtolower($details), 'delivery')))
                ) {
                    $extracted[] = [
                        'title' => $title ?: 'Free Member Delivery',
                        'benefit_type' => 'free_delivery',
                        'benefit_value' => 100,
                        'restrictions' => $restrictions,
                    ];
                    continue;
                }

                // 3. Store credit: "$10 store credit"
                if (str_contains($type, 'credit') || str_contains(strtolower($title), 'store credit')) {
                    if (preg_match('/[\d\.]+/i', $details, $cMatch)) {
                        $val = (float) $cMatch[0];
                        if ($val > 0) {
                            $extracted[] = [
                                'title' => $title ?: ('$' . $val . ' Monthly Store Credit'),
                                'benefit_type' => 'monthly_store_credit',
                                'benefit_value' => $val,
                                'restrictions' => $restrictions,
                            ];
                            continue;
                        }
                    }
                }
            }
        }

        // Top-level percentage_discount if not already added
        $hasPercentageDiscount = collect($extracted)->contains(fn ($item) => $item['benefit_type'] === 'percentage_discount');
        if (!$hasPercentageDiscount && !empty($snapshot['percentage_discount']) && (float)$snapshot['percentage_discount'] > 0) {
            $extracted[] = [
                'title' => ((float)$snapshot['percentage_discount']) . '% Member Discount',
                'benefit_type' => 'percentage_discount',
                'benefit_value' => (float)$snapshot['percentage_discount'],
                'restrictions' => $snapshot,
            ];
        }

        // Top-level fixed_discount if not already added
        $hasFixedDiscount = collect($extracted)->contains(fn ($item) => $item['benefit_type'] === 'fixed_discount');
        if (!$hasFixedDiscount && !empty($snapshot['fixed_discount']) && (float)$snapshot['fixed_discount'] > 0) {
            $extracted[] = [
                'title' => '$' . ((float)$snapshot['fixed_discount']) . ' Member Discount',
                'benefit_type' => 'fixed_discount',
                'benefit_value' => (float)$snapshot['fixed_discount'],
                'restrictions' => $snapshot,
            ];
        }

        // Top-level free_delivery if not already added
        $hasFreeDelivery = collect($extracted)->contains(fn ($item) => in_array($item['benefit_type'], ['free_delivery', 'free_shipping']));
        if (!$hasFreeDelivery && (!empty($snapshot['free_delivery']) || !empty($snapshot['free_shipping']))) {
            $extracted[] = [
                'title' => 'Free Member Delivery',
                'benefit_type' => 'free_delivery',
                'benefit_value' => 100,
                'restrictions' => $snapshot,
            ];
        }

        return $extracted;
    }

    private function parseStringBenefit(string $text): ?array
    {
        $trim = trim($text);
        if ($trim === '') {
            return null;
        }

        // Match percentage discount e.g. "10% Site-wide Discount" or "20% off" or "10% discount on digitizing"
        if (preg_match('/(\d+(?:\.\d+)?)%\s*(?:site-wide|off|discount|on|all)?/i', $trim, $matches)) {
            $val = (float) $matches[1];
            if ($val > 0) {
                return [
                    'title' => $trim,
                    'benefit_type' => 'percentage_discount',
                    'benefit_value' => $val,
                    'restrictions' => [
                        'can_combine_with_coupons' => true,
                    ],
                ];
            }
        }

        // Match fixed dollar discount e.g. "$50 Store Credit" or "$10 Off"
        if (preg_match('/\$\s*(\d+(?:\.\d+)?)/i', $trim, $matches)) {
            $val = (float) $matches[1];
            if ($val > 0) {
                return [
                    'title' => $trim,
                    'benefit_type' => 'fixed_discount',
                    'benefit_value' => $val,
                    'restrictions' => [
                        'can_combine_with_coupons' => true,
                    ],
                ];
            }
        }

        // Match free/priority shipping e.g. "Free Shipping", "Priority Shipping", "Faster delivery on all orders"
        if (preg_match('/free\s+(?:priority\s+)?(?:shipping|delivery)/i', $trim) || preg_match('/priority\s+shipping/i', $trim) || preg_match('/faster\s+delivery/i', $trim)) {
            return [
                'title' => $trim,
                'benefit_type' => 'free_shipping',
                'benefit_value' => 100,
                'restrictions' => [
                    'can_combine_with_coupons' => true,
                ],
            ];
        }

        return null;
    }

    private function isActiveMembership(array $membership): bool
    {
        $status = strtolower((string) ($membership['status'] ?? 'active'));
        return in_array($status, [
            'active',
            'trialing',
            'pending_cancellation',
            'pending_upgrade',
            'pending_downgrade',
            'past_due',
            'grace_period'
        ], true);
    }

    private function coversSite(array $membership, ?string $siteSlug): bool
    {
        if (! $siteSlug) {
            return true;
        }

        $coverageType = strtolower((string) ($membership['coverage_type'] ?? ''));
        if (in_array($coverageType, ['universal', 'global', 'all', 'all-sites', 'all_sites'], true)) {
            return true;
        }

        $target = strtolower($siteSlug);

        $coveredSites = $membership['covered_sites'] ?? [];
        if (! is_array($coveredSites) || $coveredSites === []) {
            $applicableSite = strtolower((string) ($membership['applicable_site'] ?? ''));
            if ($applicableSite === '' || in_array($applicableSite, ['universal', 'global', 'all', 'all-sites', 'all_sites'], true)) {
                return true;
            }
            return $applicableSite === $target || str_contains($applicableSite, $target) || str_contains($target, $applicableSite);
        }

        foreach ($coveredSites as $site) {
            $siteLower = strtolower((string) $site);
            if (in_array($siteLower, ['universal', 'global', 'all', 'all-sites', 'all_sites'], true)) {
                return true;
            }
            if ($siteLower === $target || str_contains($siteLower, $target) || str_contains($target, $siteLower)) {
                return true;
            }
        }

        return false;
    }

    private function restrictionBag(array $benefit): array
    {
        $restrictions = $benefit['restrictions'] ?? [];
        return is_array($restrictions) ? array_merge($benefit, $restrictions) : $benefit;
    }

    private function passesRestrictions(array $restrictions, float $itemsSubtotal, bool $hasCoupon): bool
    {
        $minimum = $this->restrictionNumber($restrictions, ['minimum_order_amount', 'min_order_amount', 'min_subtotal']);
        if ($minimum !== null && $itemsSubtotal < $minimum) {
            return false;
        }

        $canCombine = $restrictions['can_combine_with_coupons']
            ?? $restrictions['combine_with_coupons']
            ?? $restrictions['stackable_with_coupons']
            ?? (isset($restrictions['discount_eligibility'])
                ? !str_contains(strtolower((string) $restrictions['discount_eligibility']), 'not allow')
                : true);

        if ($hasCoupon && ! $canCombine) {
            return false;
        }

        return true;
    }

    private function restrictionNumber(array $restrictions, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $restrictions) && $restrictions[$key] !== null && $restrictions[$key] !== '') {
                return (float) $restrictions[$key];
            }
        }

        return null;
    }

    private function deliveryDiscount(array $benefit, float $shippingAmount): float
    {
        $value = (float) ($benefit['benefit_value'] ?? $benefit['value'] ?? $benefit['amount'] ?? 0);
        $mode = strtolower((string) ($benefit['discount_mode'] ?? $benefit['mode'] ?? 'percentage'));

        return $mode === 'fixed'
            ? min($shippingAmount, $value)
            : $shippingAmount * ($value / 100);
    }

    private function humanLabel(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }

    private function emptyResult(): array
    {
        return [
            'membership_id' => null,
            'membership_plan_name' => null,
            'membership_benefits_snapshot' => null,
            'membership_discount_amount' => 0.0,
            'membership_benefit_usage' => [],
        ];
    }
}
