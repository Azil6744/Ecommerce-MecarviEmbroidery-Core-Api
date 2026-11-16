<?php

namespace App\Services;

class MembershipBenefitService
{
    public function evaluate(array $memberships, float $itemsSubtotal, float $shippingAmount, ?string $siteSlug = null, bool $hasCoupon = false, ?int $userId = null): array
    {
        $best = $this->emptyResult();

        foreach ($memberships as $membership) {
            if (! $this->isActiveMembership($membership) || ! $this->coversSite($membership, $siteSlug)) {
                continue;
            }

            $result = $this->evaluateMembership($membership, $itemsSubtotal, $shippingAmount, $hasCoupon, $userId);
            if ($result['membership_discount_amount'] > $best['membership_discount_amount']) {
                $best = $result;
            }
        }

        return $best;
    }

    private function evaluateMembership(array $membership, float $itemsSubtotal, float $shippingAmount, bool $hasCoupon, ?int $userId = null): array
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

            // Usage Limit / Reset Frequency from the plan editor's benefits table.
            if (! $this->withinUsageLimit($benefit, $type, $userId)) {
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
                    'usage_limit' => $b['usage_limit'] ?? 'Unlimited',
                    'usage_reset' => $b['reset'] ?? 'Never',
                ]);
                unset($restrictions['benefits_table']);

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

        // When the plan editor's benefits table exists it is the source of truth: a benefit
        // switched off there must not come back through the derived top-level numbers.
        if (!empty($snapshot['benefits_table']) && is_array($snapshot['benefits_table'])) {
            return $extracted;
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

    /**
     * A benefit with a numeric Usage Limit can be used that many times per Reset period
     * ("Never" = for the life of the membership). Uses are counted from the member's
     * earlier orders, which record each applied benefit in membership_benefit_usage.
     */
    private function withinUsageLimit(array $benefit, string $type, ?int $userId): bool
    {
        $restrictions = $this->restrictionBag($benefit);
        $limitRaw = trim((string) ($restrictions['usage_limit'] ?? ''));
        if ($limitRaw === '' || ! preg_match('/^\d+/', $limitRaw, $m)) {
            // A store credit is once per period by nature: "Monthly Store Credit" left on
            // "Unlimited" must not take $10 off every order.
            if (in_array($type, ['monthly_store_credit', 'annual_store_credit'], true)) {
                $limitRaw = '1';
                $restrictions['usage_reset'] = $type === 'annual_store_credit' ? 'yearly' : 'monthly';
                $benefit['restrictions'] = array_merge($benefit['restrictions'] ?? [], ['usage_limit' => '1', 'usage_reset' => $restrictions['usage_reset']]);
                preg_match('/^\d+/', $limitRaw, $m);
            } else {
                return true; // "Unlimited" or blank
            }
        }
        $limit = (int) $m[0];
        if ($limit <= 0) {
            return false;
        }
        if (! $userId) {
            return true; // guest preview: nothing to count against
        }

        $reset = strtolower((string) ($restrictions['usage_reset'] ?? 'never'));
        $since = match (true) {
            str_contains($reset, 'month') => now()->startOfMonth(),
            str_contains($reset, 'quarter') => now()->firstOfQuarter()->startOfDay(),
            str_contains($reset, 'year') || str_contains($reset, 'annual') => now()->startOfYear(),
            str_contains($reset, 'week') => now()->startOfWeek(),
            default => null,
        };

        $label = strtolower(trim((string) ($benefit['title'] ?? '')));

        try {
            $query = \Illuminate\Support\Facades\DB::table('ecommerce_orders')
                ->where('user_id', $userId)
                ->whereNotNull('membership_benefit_usage')
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereRaw('LOWER(status) NOT IN (?, ?, ?, ?)', ['cancelled', 'canceled', 'failed', 'refunded']);
                });
            if ($since) {
                $query->where('created_at', '>=', $since);
            }

            $used = 0;
            foreach ($query->pluck('membership_benefit_usage') as $raw) {
                $rows = is_string($raw) ? json_decode($raw, true) : $raw;
                foreach ((array) $rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $rowLabel = strtolower(trim((string) ($row['label'] ?? '')));
                    $rowType = strtolower((string) ($row['type'] ?? ''));
                    if (($label !== '' && $rowLabel === $label) || ($label === '' && $rowType === $type)) {
                        $used++;
                    }
                }
            }

            return $used < $limit;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Membership benefit usage count failed: ' . $e->getMessage());
            return true;
        }
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

    /** Membership statuses that still give the member their benefits. */
    public const LIVE_STATUSES = ['active', 'trialing', 'pending_cancellation', 'pending_upgrade', 'pending_downgrade', 'past_due', 'grace_period'];

    /**
     * The non-price perks of the customer's live membership, read from the plan editor
     * (benefits table rows, else the plan's top-level settings). Used for loyalty points,
     * order priority, support-ticket priority and team seats.
     *
     * Core keeps a local copy of every membership (Central purchases included), so this works
     * without a Central call.
     *
     * @return array{plan: ?string, loyalty_multiplier: float, priority_processing: bool, priority_support: bool, early_access: bool, free_digitizing_per_month: ?int, team_seats: ?int}
     */
    public static function perksFor(?\App\Models\User $user): array
    {
        $none = ['plan' => null, 'loyalty_multiplier' => 1.0, 'priority_processing' => false, 'priority_support' => false, 'early_access' => false, 'free_digitizing_per_month' => null, 'team_seats' => null];
        if (! $user) {
            return $none;
        }

        try {
            $membership = \App\Models\EcommerceMembership::where('user_id', $user->id)
                ->whereRaw('LOWER(REPLACE(status, \' \', \'_\')) IN (' . implode(',', array_fill(0, count(self::LIVE_STATUSES), '?')) . ')', self::LIVE_STATUSES)
                ->latest('updated_at')
                ->first();
            if (! $membership) {
                return $none;
            }
            $plan = ($membership->plan_id ? \App\Models\EcommerceSubscriptionPlan::find($membership->plan_id) : null)
                ?: \App\Models\EcommerceSubscriptionPlan::where('name', $membership->plan_name)
                    ->orWhere('internal_code', $membership->plan_name)
                    ->first();

            return $plan ? ['plan' => $plan->name] + self::planPerks($plan) : $none;
        } catch (\Throwable) {
            return $none;
        }
    }

    /**
     * Perks of a plan. Benefits table rows win (what the admin sees in the plan editor); rows
     * switched off there do not count. Matching is by the row's type, title and details.
     *
     * @return array{loyalty_multiplier: float, priority_processing: bool, priority_support: bool, early_access: bool, free_digitizing_per_month: ?int, team_seats: ?int}
     */
    public static function planPerks(\App\Models\EcommerceSubscriptionPlan $plan): array
    {
        $cfg = $plan->benefit_config;
        if (is_string($cfg)) {
            $cfg = json_decode($cfg, true) ?: [];
        }
        $cfg = is_array($cfg) ? $cfg : [];

        $perks = [
            'loyalty_multiplier' => 1.0,
            'priority_processing' => false,
            'priority_support' => false,
            'early_access' => false,
            'free_digitizing_per_month' => null,
            'team_seats' => null,
        ];

        $rows = is_array($cfg['benefits_table'] ?? null) ? $cfg['benefits_table'] : [];
        foreach ($rows as $row) {
            if (! is_array($row) || (isset($row['status']) && $row['status'] === false)) {
                continue;
            }
            $text = strtolower(trim(($row['type'] ?? '') . ' ' . ($row['title'] ?? '') . ' ' . ($row['details'] ?? '')));

            if (str_contains($text, 'multiplier') || str_contains($text, 'reward point') || str_contains($text, 'loyalty point')) {
                if (preg_match('/(\d+(?:\.\d+)?)\s*x\b/', $text, $m) && (float) $m[1] > 1) {
                    $perks['loyalty_multiplier'] = max($perks['loyalty_multiplier'], (float) $m[1]);
                }
            }
            if (str_contains($text, 'priority') && preg_match('/process|production|queue|turnaround|order/', $text)) {
                $perks['priority_processing'] = true;
            }
            if ((str_contains($text, 'priority') || str_contains($text, 'dedicated')) && preg_match('/support|account manager/', $text)) {
                $perks['priority_support'] = true;
            }
            if (str_contains($text, 'early access')) {
                $perks['early_access'] = true;
            }
            if (str_contains($text, 'digitiz') && preg_match('/(\d+)/', (string) ($row['details'] ?? $row['title'] ?? ''), $m)) {
                $perks['free_digitizing_per_month'] = (int) $m[1];
            }
            if (preg_match('/seat|user/', $text) && preg_match('/(\d+)/', (string) (($row['details'] ?? '') . ' ' . ($row['title'] ?? '')), $m)) {
                $perks['team_seats'] = max((int) $perks['team_seats'], (int) $m[1]);
            }
        }

        if (empty($rows)) {
            // No benefits table: the plan's top-level settings.
            if (is_numeric($cfg['loyalty_multiplier'] ?? null) && (float) $cfg['loyalty_multiplier'] > 1) {
                $perks['loyalty_multiplier'] = (float) $cfg['loyalty_multiplier'];
            }
            $perks['priority_processing'] = ! empty($cfg['priority_processing']);
            $perks['priority_support'] = ! empty($cfg['priority_support']);
            $perks['early_access'] = ! empty($cfg['early_access']);
        }
        if (! empty($cfg['team_seats'])) {
            $perks['team_seats'] = max((int) $perks['team_seats'], (int) $cfg['team_seats']);
        }

        return $perks;
    }
}
