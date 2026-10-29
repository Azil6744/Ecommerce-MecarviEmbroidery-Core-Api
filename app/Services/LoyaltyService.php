<?php

namespace App\Services;

use App\Models\CustomerPointsBalance;
use App\Models\EcommerceCoupon;
use App\Models\EcommerceLoyaltyTransaction as Tx;
use App\Models\EcommerceOrder;
use App\Models\LoyaltyAuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mecarvi Gold Rewards engine.
 *
 * Ledger model
 *  - Every point movement is a row in ecommerce_loyalty_transactions (never deleted).
 *  - Positive rows are "lots": remaining_points tracks what is still spendable, which gives
 *    FIFO redemption and correct expiry (only unspent points expire).
 *  - customer_points_balances is the single source of truth for balances; users.loyalty_points
 *    and Central Auth are mirrors.
 *
 * Statuses: pending | available | redeemed | reversed | expired | manual_added | manual_removed
 */
class LoyaltyService
{
    public const BUSINESS_ROLES = ['business', 'business_owner', 'business_user', 'business-customer', 'company'];

    /** Transaction types that count toward the daily / monthly earning caps. */
    protected const CAPPED_TYPES = ['earned', 'order_bonus', 'first_order_bonus', 'bonus', 'signup_bonus', 'review_reward', 'birthday_bonus', 'referral_bonus', 'membership_bonus', 'photo_bonus', 'review_photo_bonus'];

    /** Last validation / failure message from a bool-returning method. */
    public static ?string $lastError = null;

    protected static ?array $settingsCache = null;

    // =====================================================================
    //  SETTINGS
    // =====================================================================

    public static function defaultTiers(): array
    {
        return [
            ['id' => 'bronze', 'name' => 'Bronze', 'min_points' => 0, 'max_points' => 999, 'bonus_percent' => 5, 'perk_badge' => '', 'color' => 'orange', 'icon' => 'star', 'active' => true],
            ['id' => 'silver', 'name' => 'Silver', 'min_points' => 1000, 'max_points' => 4999, 'bonus_percent' => 10, 'perk_badge' => '', 'color' => 'slate', 'icon' => 'star', 'active' => true],
            ['id' => 'gold', 'name' => 'Gold', 'min_points' => 5000, 'max_points' => 9999, 'bonus_percent' => 15, 'perk_badge' => 'Priority Support', 'color' => 'amber', 'icon' => 'star', 'active' => true],
            ['id' => 'platinum', 'name' => 'Platinum', 'min_points' => 10000, 'max_points' => null, 'bonus_percent' => 20, 'perk_badge' => 'Exclusive Offers', 'color' => 'purple', 'icon' => 'star', 'active' => true],
        ];
    }

    public static function defaultBusinessTiers(): array
    {
        return [
            ['id' => 'bronze', 'name' => 'Bronze', 'min_points' => 0, 'max_points' => 2499, 'bonus_percent' => 8, 'perk_badge' => '', 'color' => 'orange', 'icon' => 'star', 'active' => true],
            ['id' => 'silver', 'name' => 'Silver', 'min_points' => 2500, 'max_points' => 9999, 'bonus_percent' => 12, 'perk_badge' => '', 'color' => 'slate', 'icon' => 'star', 'active' => true],
            ['id' => 'gold', 'name' => 'Gold', 'min_points' => 10000, 'max_points' => 24999, 'bonus_percent' => 18, 'perk_badge' => 'Priority Production', 'color' => 'amber', 'icon' => 'star', 'active' => true],
            ['id' => 'platinum', 'name' => 'Platinum', 'min_points' => 25000, 'max_points' => null, 'bonus_percent' => 25, 'perk_badge' => 'Dedicated Account Rep', 'color' => 'purple', 'icon' => 'star', 'active' => true],
        ];
    }

    public static function activityKeys(): array
    {
        return ['product_purchase', 'gift_card', 'birthday', 'review', 'membership_purchase', 'membership_renewal', 'signup', 'first_order', 'referral', 'project_photos', 'daily_checkin'];
    }

    /** Engine defaults (also what the admin settings page shows until the first save). */
    public static function defaults(): array
    {
        $act = fn (bool $on, int $pts) => ['enabled' => $on, 'points' => (string) $pts];

        return [
            'enabled' => true,
            'program_name' => 'Mecarvi Gold Rewards',
            'program_description' => 'Earn loyalty points on every custom embroidery order and selected account activities.',
            'allow_retail_customers' => true,
            'allow_business_customers' => true,

            // earning
            'points_per_dollar' => '1',
            'min_order_amount' => '1.00',
            'max_points_per_order' => '10000',
            'max_earn_per_day' => '0',
            'max_earn_per_month' => '0',
            'include_tax_in_calculation' => false,
            'include_shipping_in_calculation' => false,
            'earn_on_discounted_orders' => true,
            'exclude_gift_card_payments' => true,
            'earn_points_on_gift_cards' => true,
            'allow_points_reversal' => true,
            'point_availability_trigger' => 'after_completed',
            'excluded_product_ids' => '',
            'excluded_category_ids' => '',
            'earning_activities' => [
                'customer' => [
                    'product_purchase' => $act(true, 200),
                    'gift_card' => $act(true, 200),
                    'birthday' => $act(true, 100),
                    'review' => $act(true, 25),
                    'membership_purchase' => $act(true, 50),
                    'membership_renewal' => $act(true, 50),
                    'signup' => $act(true, 100),
                    'first_order' => $act(true, 250),
                    'referral' => $act(true, 500),
                    'project_photos' => $act(true, 50),
                    'daily_checkin' => $act(true, 25),
                ],
                'business' => [
                    'product_purchase' => $act(true, 500),
                    'gift_card' => $act(true, 400),
                    'birthday' => $act(true, 250),
                    'review' => $act(true, 100),
                    'membership_purchase' => $act(true, 250),
                    'membership_renewal' => $act(true, 150),
                    'signup' => $act(true, 250),
                    'first_order' => $act(true, 500),
                    'referral' => $act(true, 1000),
                    'project_photos' => $act(true, 100),
                    'daily_checkin' => $act(true, 50),
                ],
            ],

            // redemption
            'redemption_enabled' => true,
            'points_to_dollar_ratio' => '0.01', // 100 points = $1.00
            'minimum_redeem_points' => '500',
            'max_redeem_points_per_order' => '2000',
            'max_redeem_percent' => '25',
            'max_redeem_amount' => '0',
            'min_order_for_redemption' => '25.00',
            'max_redeem_per_day' => '0',
            'max_redeem_per_month' => '0',
            'allow_partial_redemption' => true,
            'allow_redemption_with_coupons' => true,
            'allow_with_gift_cards' => true,
            'allow_redemption_on_taxes' => false,
            'allow_redemption_on_shipping' => false,

            // expiry
            'enable_expiration' => true,
            'expiry_days' => '365',
            'expiry_unit' => 'Days',
            'expiration_method' => 'from_earning_date',
            'expiration_reminder_days' => '30_days_before',

            // abuse
            'fraud_protection' => true,

            // notifications
            'notify_members_on_earn' => true,
            'notify_on_redeem' => true,
            'notify_on_expiry' => true,
            'notify_on_expired' => true,
            'notify_on_adjustment' => true,
            'notify_on_tier_upgrade' => true,
            'notify_on_special_offers' => true,

            'terms_and_conditions' => 'By participating in the loyalty program, members agree to earn and redeem points based on the rules and policies set by the store. Points have no cash value and are non-transferable.',
            'terms_status' => 'published',
            'tiers' => self::defaultTiers(),
            'business_tiers' => self::defaultBusinessTiers(),
        ];
    }

    public static function flushSettingsCache(): void
    {
        self::$settingsCache = null;
    }

    /** Raw saved JSON merged over the defaults. Used by the admin settings screen. */
    public static function getConfigForAdmin(): array
    {
        $saved = [];
        try {
            $row = SiteSetting::first();
            if ($row && $row->loyalty_settings) {
                $decoded = is_array($row->loyalty_settings) ? $row->loyalty_settings : json_decode($row->loyalty_settings, true);
                if (is_array($decoded)) {
                    $saved = $decoded;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LoyaltyService::getConfigForAdmin notice: ' . $e->getMessage());
        }

        $merged = array_replace_recursive(self::defaults(), $saved);
        // arrays replaced wholesale (tiers must not merge element by element)
        foreach (['tiers', 'business_tiers'] as $k) {
            if (!empty($saved[$k]) && is_array($saved[$k])) {
                $merged[$k] = $saved[$k];
            }
        }
        // legacy alias
        if (isset($saved['allow_with_coupons']) && !isset($saved['allow_redemption_with_coupons'])) {
            $merged['allow_redemption_with_coupons'] = $saved['allow_with_coupons'];
        }
        if (($merged['point_availability_trigger'] ?? '') === 'on_order_placed') {
            $merged['point_availability_trigger'] = 'after_payment';
        }

        return $merged;
    }

    protected static function num($v, float $default = 0.0): float
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $clean = str_replace([',', '$', ' '], '', (string) $v);

        return is_numeric($clean) ? (float) $clean : $default;
    }

    protected static function bool($v, bool $default = false): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    protected static function idList($v): array
    {
        if (is_array($v)) {
            $items = $v;
        } else {
            $items = preg_split('/[\s,;]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY);
        }

        return array_values(array_unique(array_filter(array_map('intval', $items), fn ($i) => $i > 0)));
    }

    /** Normalised, typed settings used by all engine code. */
    public static function getSettings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $c = self::getConfigForAdmin();

        $expiryRaw = (int) self::num($c['expiry_days'] ?? 365, 365);
        $unit = $c['expiry_unit'] ?? null;
        if (!in_array($unit, ['Days', 'Months'], true)) {
            $unit = ($expiryRaw > 0 && $expiryRaw <= 36) ? 'Months' : 'Days';
        }
        $expiryEnabled = self::bool($c['enable_expiration'] ?? true, true) && $expiryRaw > 0;

        $reminder = (int) preg_replace('/\D+/', '', (string) ($c['expiration_reminder_days'] ?? '30'));
        $trigger = (string) ($c['point_availability_trigger'] ?? 'after_completed');
        if (!in_array($trigger, ['after_payment', 'after_production', 'after_shipped', 'after_completed', 'after_delivered', 'manual'], true)) {
            $trigger = $trigger === 'on_order_placed' ? 'after_payment' : 'after_completed';
        }

        $acts = [];
        foreach (['customer', 'business'] as $segment) {
            foreach (self::activityKeys() as $key) {
                $row = $c['earning_activities'][$segment][$key] ?? [];
                $acts[$segment][$key] = [
                    'enabled' => self::bool($row['enabled'] ?? false, false),
                    'points' => (int) self::num($row['points'] ?? 0, 0),
                ];
            }
        }

        $s = [
            'enabled' => self::bool($c['enabled'] ?? true, true),
            'program_name' => (string) ($c['program_name'] ?? 'Mecarvi Gold Rewards'),
            'program_description' => (string) ($c['program_description'] ?? ''),
            'allow_retail_customers' => self::bool($c['allow_retail_customers'] ?? true, true),
            'allow_business_customers' => self::bool($c['allow_business_customers'] ?? true, true),

            'points_per_dollar' => max(0.0, self::num($c['points_per_dollar'] ?? 1, 1)) ?: 1.0,
            'min_order_amount' => max(0.0, self::num($c['min_order_amount'] ?? 0, 0)),
            'max_points_per_order' => (int) max(0, self::num($c['max_points_per_order'] ?? 0, 0)),
            'max_earn_per_day' => (int) max(0, self::num($c['max_earn_per_day'] ?? 0, 0)),
            'max_earn_per_month' => (int) max(0, self::num($c['max_earn_per_month'] ?? 0, 0)),
            'include_tax_in_calculation' => self::bool($c['include_tax_in_calculation'] ?? false),
            'include_shipping_in_calculation' => self::bool($c['include_shipping_in_calculation'] ?? false),
            'earn_on_discounted_orders' => self::bool($c['earn_on_discounted_orders'] ?? true, true),
            'exclude_gift_card_payments' => self::bool($c['exclude_gift_card_payments'] ?? true, true),
            'earn_points_on_gift_cards' => self::bool($c['earn_points_on_gift_cards'] ?? false) || self::bool($acts['customer']['gift_card']['enabled'] ?? false),
            'allow_points_reversal' => self::bool($c['allow_points_reversal'] ?? true, true),
            'point_availability_trigger' => $trigger,
            'excluded_product_ids' => self::idList($c['excluded_product_ids'] ?? []),
            'excluded_category_ids' => self::idList($c['excluded_category_ids'] ?? []),
            'activities' => $acts,

            'redemption_enabled' => self::bool($c['redemption_enabled'] ?? true, true),
            'points_to_dollar_ratio' => max(0.0001, self::num($c['points_to_dollar_ratio'] ?? 0.01, 0.01)),
            'minimum_redeem_points' => (int) max(0, self::num($c['minimum_redeem_points'] ?? 0, 0)),
            'max_redeem_points_per_order' => (int) max(0, self::num($c['max_redeem_points_per_order'] ?? 0, 0)),
            'max_redeem_percent' => min(100.0, max(0.0, self::num($c['max_redeem_percent'] ?? 100, 100))),
            'max_redeem_amount' => max(0.0, self::num($c['max_redeem_amount'] ?? ($c['max_discount_amount'] ?? 0), 0)),
            'max_discount_amount' => max(0.0, self::num($c['max_discount_amount'] ?? ($c['max_redeem_amount'] ?? 0), 0)),
            'min_order_for_redemption' => max(0.0, self::num($c['min_order_for_redemption'] ?? ($c['min_order_amount'] ?? 0), 0)),
            'max_redeem_per_day' => (int) max(0, self::num($c['max_redeem_per_day'] ?? 0, 0)),
            'max_redeem_per_month' => (int) max(0, self::num($c['max_redeem_per_month'] ?? 0, 0)),
            'allow_partial_redemption' => self::bool($c['allow_partial_redemption'] ?? true, true),
            'allow_with_coupons' => self::bool($c['allow_redemption_with_coupons'] ?? ($c['allow_with_coupons'] ?? true), true),
            'allow_redemption_with_coupons' => self::bool($c['allow_redemption_with_coupons'] ?? ($c['allow_with_coupons'] ?? true), true),
            'allow_with_gift_cards' => self::bool($c['allow_with_gift_cards'] ?? true, true),
            'allow_redemption_on_taxes' => self::bool($c['allow_redemption_on_taxes'] ?? false),
            'allow_redemption_on_shipping' => self::bool($c['allow_redemption_on_shipping'] ?? false),
            'include_rush_in_calculation' => self::bool($c['include_rush_in_calculation'] ?? true, true),

            'enable_expiration' => $expiryEnabled,
            'expiry_value' => $expiryRaw,
            'expiry_unit' => $unit,
            'expiry_days' => $expiryEnabled ? ($unit === 'Months' ? $expiryRaw * 30 : $expiryRaw) : 0,
            'expiration_method' => in_array($c['expiration_method'] ?? '', ['from_earning_date', 'from_last_activity'], true) ? $c['expiration_method'] : 'from_earning_date',
            'expiration_reminder_days' => $reminder > 0 ? $reminder : 30,

            'fraud_protection' => self::bool($c['fraud_protection'] ?? true, true),

            'notify_members_on_earn' => self::bool($c['notify_members_on_earn'] ?? true, true),
            'notify_on_redeem' => self::bool($c['notify_on_redeem'] ?? true, true),
            'notify_on_expiry' => self::bool($c['notify_on_expiry'] ?? true, true),
            'notify_on_expired' => self::bool($c['notify_on_expired'] ?? true, true),
            'notify_on_adjustment' => self::bool($c['notify_on_adjustment'] ?? true, true),
            'notify_on_tier_upgrade' => self::bool($c['notify_on_tier_upgrade'] ?? true, true),

            'terms_and_conditions' => (string) ($c['terms_and_conditions'] ?? ''),
            'terms_status' => (string) ($c['terms_status'] ?? 'published'),
            'tiers' => self::normalizeTiers($c['tiers'] ?? []),
            'business_tiers' => self::normalizeTiers($c['business_tiers'] ?? ($c['tiers'] ?? [])),
        ];

        // Legacy flat keys still honoured when no activity block was saved
        $legacy = ['signup_bonus' => 'signup', 'first_order_bonus' => 'first_order', 'review_bonus' => 'review', 'referral_bonus' => 'referral', 'birthday_bonus' => 'birthday', 'membership_bonus' => 'membership_purchase'];
        if (empty($c['earning_activities']) || !is_array($c['earning_activities'])) {
            foreach ($legacy as $flat => $key) {
                if (isset($c[$flat])) {
                    foreach (['customer', 'business'] as $segment) {
                        $s['activities'][$segment][$key]['points'] = (int) self::num($c[$flat]);
                        $s['activities'][$segment][$key]['enabled'] = self::num($c[$flat]) > 0;
                    }
                }
            }
        }

        // legacy flat accessors used elsewhere in the codebase
        $s['signup_bonus'] = $s['activities']['customer']['signup']['points'];
        $s['first_order_bonus'] = $s['activities']['customer']['first_order']['points'];
        $s['review_bonus'] = $s['activities']['customer']['review']['points'];
        $s['birthday_bonus'] = $s['activities']['customer']['birthday']['points'];
        $s['referral_bonus'] = $s['activities']['customer']['referral']['points'];
        $s['membership_bonus'] = $s['activities']['customer']['membership_purchase']['points'];
        $s['enable_signup_bonus'] = (bool) ($s['activities']['customer']['signup']['enabled'] || $s['activities']['business']['signup']['enabled']);
        $s['enable_first_order_bonus'] = (bool) ($s['activities']['customer']['first_order']['enabled'] || $s['activities']['business']['first_order']['enabled']);
        $s['enable_review_bonus'] = (bool) ($s['activities']['customer']['review']['enabled'] || $s['activities']['business']['review']['enabled']);
        $s['enable_birthday_bonus'] = (bool) ($s['activities']['customer']['birthday']['enabled'] || $s['activities']['business']['birthday']['enabled']);
        $s['enable_referral_bonus'] = (bool) ($s['activities']['customer']['referral']['enabled'] || $s['activities']['business']['referral']['enabled']);
        $s['enable_membership_bonus'] = (bool) ($s['activities']['customer']['membership_purchase']['enabled'] || $s['activities']['business']['membership_purchase']['enabled']);
        $s['enable_order_points'] = self::bool($c['enable_order_points'] ?? true, true);
        $s['allow_with_coupons_legacy'] = $s['allow_with_coupons'];

        return self::$settingsCache = $s;
    }

    protected static function normalizeTiers($tiers): array
    {
        if (!is_array($tiers) || empty($tiers)) {
            $tiers = self::defaultTiers();
        }
        $out = [];
        foreach ($tiers as $t) {
            if (!is_array($t) || empty($t['name'])) {
                continue;
            }
            $out[] = [
                'id' => $t['id'] ?? strtolower((string) $t['name']),
                'name' => (string) $t['name'],
                'min_points' => (int) self::num($t['min_points'] ?? 0),
                'max_points' => isset($t['max_points']) && $t['max_points'] !== '' && $t['max_points'] !== null ? (int) self::num($t['max_points']) : null,
                'bonus_percent' => self::num($t['bonus_percent'] ?? 0),
                'perk_badge' => (string) ($t['perk_badge'] ?? ''),
                'color' => $t['color'] ?? 'slate',
                'active' => ($t['active'] ?? true) !== false,
            ];
        }
        usort($out, fn ($a, $b) => $a['min_points'] <=> $b['min_points']);

        return $out ?: self::normalizeTiers(self::defaultTiers());
    }

    /** Fields safe to expose to the storefront / user panel. */
    public static function publicSettings(): array
    {
        $s = self::getSettings();

        return [
            'enabled' => $s['enabled'],
            'program_name' => $s['program_name'],
            'program_description' => $s['program_description'],
            'points_per_dollar' => $s['points_per_dollar'],
            'points_to_dollar_ratio' => $s['points_to_dollar_ratio'],
            'minimum_redeem_points' => $s['minimum_redeem_points'],
            'max_redeem_points_per_order' => $s['max_redeem_points_per_order'],
            'max_redeem_percent' => $s['max_redeem_percent'],
            'max_redeem_amount' => $s['max_redeem_amount'],
            'min_order_for_redemption' => $s['min_order_for_redemption'],
            'min_order_amount' => $s['min_order_amount'],
            'redemption_enabled' => $s['redemption_enabled'],
            'allow_partial_redemption' => $s['allow_partial_redemption'],
            'allow_redemption_with_coupons' => $s['allow_with_coupons'],
            'allow_with_coupons' => $s['allow_with_coupons'],
            'allow_with_gift_cards' => $s['allow_with_gift_cards'],
            'include_tax_in_calculation' => $s['include_tax_in_calculation'],
            'include_shipping_in_calculation' => $s['include_shipping_in_calculation'],
            'point_availability_trigger' => $s['point_availability_trigger'],
            'enable_expiration' => $s['enable_expiration'],
            'expiry_days' => $s['expiry_days'],
            'tiers' => $s['tiers'],
            'business_tiers' => $s['business_tiers'],
            'terms_and_conditions' => $s['terms_status'] === 'published' ? $s['terms_and_conditions'] : '',
            'activities' => $s['activities'],
        ];
    }

    public static function activity(string $key, ?User $user = null): array
    {
        $s = self::getSettings();
        $segment = ($user && self::isBusinessUser($user)) ? 'business' : 'customer';

        return $s['activities'][$segment][$key] ?? ['enabled' => false, 'points' => 0];
    }

    public static function isBusinessUser(User $user): bool
    {
        return in_array(strtolower((string) $user->role), self::BUSINESS_ROLES, true) || !empty($user->business_name);
    }

    public static function isEligibleUser(User $user): bool
    {
        $s = self::getSettings();
        if (!$s['enabled']) {
            return false;
        }

        return self::isBusinessUser($user) ? $s['allow_business_customers'] : $s['allow_retail_customers'];
    }

    public static function expiryDateFrom(?Carbon $from = null): ?Carbon
    {
        $s = self::getSettings();
        if (!$s['enable_expiration']) {
            return null;
        }
        $from = ($from ?: Carbon::now())->copy();

        return $s['expiry_unit'] === 'Months' ? $from->addMonths($s['expiry_value']) : $from->addDays($s['expiry_value']);
    }

    public static function ratio(): float
    {
        return self::getSettings()['points_to_dollar_ratio'];
    }

    protected static function dollars(int $points): float
    {
        return round(abs($points) * self::ratio(), 2);
    }

    // =====================================================================
    //  TIERS
    // =====================================================================

    public static function tierFor(int $lifetime, bool $business = false): array
    {
        $s = self::getSettings();
        $tiers = array_values(array_filter($business ? $s['business_tiers'] : $s['tiers'], fn ($t) => $t['active']));
        if (empty($tiers)) {
            return ['name' => 'Member', 'bonus_percent' => 0, 'min_points' => 0, 'max_points' => null, 'color' => 'slate', 'perk_badge' => ''];
        }
        $current = $tiers[0];
        foreach ($tiers as $t) {
            if ($lifetime >= $t['min_points']) {
                $current = $t;
            }
        }

        return $current;
    }

    public static function nextTierFor(int $lifetime, bool $business = false): ?array
    {
        $s = self::getSettings();
        $tiers = array_values(array_filter($business ? $s['business_tiers'] : $s['tiers'], fn ($t) => $t['active']));
        foreach ($tiers as $t) {
            if ($t['min_points'] > $lifetime) {
                return $t;
            }
        }

        return null;
    }

    // =====================================================================
    //  BALANCE HELPERS
    // =====================================================================

    /** Get (or lazily create) a balance row. Legacy users.loyalty_points becomes an opening lot. */
    public static function getOrCreateBalance(User|int $user): CustomerPointsBalance
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $existing = CustomerPointsBalance::where('user_id', $userId)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($userId, $user) {
            $model = $user instanceof User ? $user : User::find($userId);
            $pts = max(0, (int) ($model?->loyalty_points ?? 0));

            try {
                $balance = CustomerPointsBalance::create([
                    'user_id' => $userId,
                    'available_points' => $pts,
                    'pending_points' => 0,
                    'redeemed_points' => 0,
                    'expired_points' => 0,
                    'lifetime_earned' => $pts,
                    'is_locked' => false,
                ]);
            } catch (\Throwable $e) {
                return CustomerPointsBalance::where('user_id', $userId)->firstOrFail();
            }

            if ($pts > 0) {
                Tx::create([
                    'user_id' => $userId,
                    'transaction_type' => 'manual_added',
                    'points' => $pts,
                    'remaining_points' => $pts,
                    'dollar_value' => self::dollars($pts),
                    'status' => Tx::STATUS_MANUAL_ADDED,
                    'reason' => 'Opening balance',
                    'reason_details' => 'Balance carried over from the previous loyalty system.',
                    'reference_type' => 'opening_balance',
                    'reference_id' => (string) $userId,
                    'reference_date' => now()->toDateString(),
                    'expiration_date' => self::expiryDateFrom(),
                ]);
            }

            return $balance;
        });
    }

    protected static function locked(int $userId): CustomerPointsBalance
    {
        self::getOrCreateBalance($userId);

        return CustomerPointsBalance::where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    protected static function persist(CustomerPointsBalance $b): void
    {
        if ($b->available_points < 0 && !$b->needs_review) {
            $b->needs_review = true;
            $b->review_reason = 'Negative points balance (points were reversed after being spent).';
        } elseif ($b->available_points >= 0 && $b->needs_review && str_starts_with((string) $b->review_reason, 'Negative points balance')) {
            $b->needs_review = false;
            $b->review_reason = null;
        }
        $b->save();
        User::where('id', $b->user_id)->update(['loyalty_points' => $b->available_points]);
    }

    /** Spendable lots, soonest expiry first, then oldest. */
    protected static function lots(int $userId)
    {
        return Tx::where('user_id', $userId)
            ->where('points', '>', 0)
            ->where('remaining_points', '>', 0)
            ->whereIn('status', [Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])
            ->orderByRaw('expiration_date IS NULL')
            ->orderBy('expiration_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Consume points FIFO from lots. Returns points actually taken from lots. */
    protected static function consume(int $userId, int $points): int
    {
        $left = $points;
        foreach (self::lots($userId) as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (int) $lot->remaining_points);
            $lot->remaining_points -= $take;
            $lot->save();
            $left -= $take;
        }

        return $points - $left;
    }

    protected static function cappedPointsSince(int $userId, Carbon $since): int
    {
        return (int) Tx::where('user_id', $userId)
            ->where('points', '>', 0)
            ->whereIn('transaction_type', self::CAPPED_TYPES)
            ->whereIn('status', [Tx::STATUS_PENDING, Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])
            ->where('created_at', '>=', $since)
            ->sum('points');
    }

    protected static function redeemedPointsSince(int $userId, Carbon $since): int
    {
        return (int) abs(Tx::where('user_id', $userId)
            ->where('transaction_type', 'redeemed')
            ->where('status', Tx::STATUS_REDEEMED)
            ->where('created_at', '>=', $since)
            ->sum('points'));
    }

    /** Apply the daily / monthly earning caps. */
    protected static function capEarning(int $userId, int $points): int
    {
        $s = self::getSettings();
        if ($points <= 0) {
            return 0;
        }
        if ($s['max_earn_per_day'] > 0) {
            $points = min($points, max(0, $s['max_earn_per_day'] - self::cappedPointsSince($userId, Carbon::now()->startOfDay())));
        }
        if ($s['max_earn_per_month'] > 0) {
            $points = min($points, max(0, $s['max_earn_per_month'] - self::cappedPointsSince($userId, Carbon::now()->startOfMonth())));
        }

        return max(0, $points);
    }

    // =====================================================================
    //  NOTIFICATIONS / MIRRORS
    // =====================================================================

    protected static function notify(User $user, string $event, array $data, string $settingKey): void
    {
        $s = self::getSettings();
        if (!($s[$settingKey] ?? true)) {
            return;
        }
        DB::afterCommit(function () use ($user, $event, $data) {
            LoyaltyNotifier::send($user, $event, $data);
        });
    }

    protected static function checkTierUpgrade(User $user, int $lifetimeBefore, int $lifetimeAfter): void
    {
        $business = self::isBusinessUser($user);
        $before = self::tierFor($lifetimeBefore, $business);
        $after = self::tierFor($lifetimeAfter, $business);
        if ($after['min_points'] > $before['min_points']) {
            self::notify($user, 'loyalty_tier_upgraded', ['tier_name' => $after['name'], 'previous_tier' => $before['name']], 'notify_on_tier_upgrade');
        }
    }

    /** Mirror a change to Central Auth after commit (best effort; Core is the source of truth). */
    public static function syncCentralAuth(User $user, int $points, string $type, string $reason): void
    {
        DB::afterCommit(function () use ($user, $points, $type, $reason) {
            try {
                $centralUrl = rtrim((string) config('services.central_auth.url'), '/');
                $secret = (string) config('services.internal_notifications.secret');
                if ($centralUrl === '') {
                    return;
                }
                $response = Http::acceptJson()
                    ->withHeaders(['X-Internal-Notification-Secret' => $secret])
                    ->timeout(3)
                    ->post($centralUrl . '/v1/internal/admin/loyalty/adjust', [
                        'email' => $user->email,
                        'points' => abs($points),
                        'transaction_type' => $type,
                        'reason' => $reason,
                    ]);
                if (!$response->successful()) {
                    Log::warning('LoyaltyService Central mirror non-fatal status: ' . $response->status());
                }
            } catch (\Throwable $e) {
                Log::warning('LoyaltyService Central mirror notice: ' . $e->getMessage());
            }
        });
    }

    // =====================================================================
    //  EARNING
    // =====================================================================

    /** Sum of line totals that are excluded from earning (products / categories). */
    public static function excludedAmount(iterable $lines): float
    {
        $s = self::getSettings();
        if (empty($s['excluded_product_ids']) && empty($s['excluded_category_ids'])) {
            return 0.0;
        }
        $lines = collect($lines);
        $productIds = $lines->pluck('product_id')->filter()->unique()->all();
        $categoryByProduct = [];
        if (!empty($s['excluded_category_ids']) && !empty($productIds)) {
            $categoryByProduct = \App\Models\Product::whereIn('id', $productIds)->pluck('category_id', 'id')->all();
        }
        $total = 0.0;
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $excluded = in_array($pid, $s['excluded_product_ids'], true)
                || (isset($categoryByProduct[$pid]) && in_array((int) $categoryByProduct[$pid], $s['excluded_category_ids'], true));
            if ($excluded) {
                $total += (float) ($line['total_price'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Single earning calculation used by checkout previews and by the order award.
     * $in: subtotal, discount (all discounts), coupon_discount, shipping, tax, gift_card, excluded_amount
     */
    public static function calculateEarning(User $user, array $in, ?int $excludeOrderId = null): array
    {
        $s = self::getSettings();
        $result = ['eligible' => 0.0, 'base_points' => 0, 'tier' => null, 'tier_bonus_percent' => 0.0, 'points' => 0, 'first_order_bonus' => 0, 'flat_bonus' => 0, 'total' => 0, 'reason' => null];

        if (!self::isEligibleUser($user)) {
            $result['reason'] = 'not_eligible';

            return $result;
        }

        $eligible = (float) ($in['subtotal'] ?? 0) - (float) ($in['discount'] ?? 0);
        $eligible -= (float) ($in['excluded_amount'] ?? 0);
        if ($s['include_shipping_in_calculation']) {
            $eligible += (float) ($in['shipping'] ?? 0);
        }
        if ($s['include_tax_in_calculation']) {
            $eligible += (float) ($in['tax'] ?? 0);
        }
        if ($s['exclude_gift_card_payments']) {
            $eligible -= (float) ($in['gift_card'] ?? 0);
        }
        $eligible = max(0.0, round($eligible, 2));
        $result['eligible'] = $eligible;

        if (!$s['earn_on_discounted_orders'] && (float) ($in['coupon_discount'] ?? 0) > 0.009) {
            $result['reason'] = 'discounted_order';
            $eligible = 0.0;
        }

        $tier = self::tierFor((int) self::getOrCreateBalance($user)->lifetime_earned, self::isBusinessUser($user));
        $result['tier'] = $tier['name'];
        $result['tier_bonus_percent'] = (float) $tier['bonus_percent'];

        if ($eligible > 0 && $eligible >= $s['min_order_amount']) {
            $base = $eligible * $s['points_per_dollar'];
            $points = (int) round($base * (1 + ($tier['bonus_percent'] / 100)));
            if ($s['max_points_per_order'] > 0) {
                $points = min($points, $s['max_points_per_order']);
            }
            $result['base_points'] = (int) round($base);
            $result['points'] = max(0, $points);

            $flat = self::activity('product_purchase', $user);
            if ($flat['enabled'] && $flat['points'] > 0) {
                $result['flat_bonus'] = $flat['points'];
            }

            $first = self::activity('first_order', $user);
            if ($first['enabled'] && $first['points'] > 0) {
                $already = Tx::where('user_id', $user->id)->where('reference_type', 'first_order')->where('status', '!=', Tx::STATUS_REVERSED)->exists();
                $prior = EcommerceOrder::where('user_id', $user->id)
                    ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))
                    ->whereNotIn('status', ['cancelled', 'canceled', 'refunded', 'voided', 'void', 'payment_failed', 'failed'])
                    ->count();
                if (!$already && $prior === 0) {
                    $result['first_order_bonus'] = $first['points'];
                }
            }
        } elseif ($result['reason'] === null) {
            $result['reason'] = $eligible <= 0 ? 'nothing_eligible' : 'below_minimum_order';
        }

        $result['total'] = $result['points'] + $result['first_order_bonus'] + $result['flat_bonus'];

        return $result;
    }

    /** Create the PENDING award for a freshly placed order. Idempotent. */
    public static function awardPendingOrderPoints(EcommerceOrder $order): ?Tx
    {
        try {
            if (!$order->user_id) {
                return null;
            }
            $user = User::find($order->user_id);
            if (!$user || !self::isEligibleUser($user)) {
                return null;
            }

            $existing = Tx::where('order_id', $order->id)->where('reference_type', 'order')->whereIn('status', [Tx::STATUS_PENDING, Tx::STATUS_AVAILABLE])->first();
            if ($existing) {
                return $existing;
            }

            $res = DB::transaction(function () use ($order, $user) {
                $balance = self::locked($user->id);
                $s = self::getSettings();

                if ($s['fraud_protection'] && $balance->is_locked) {
                    Log::info("Loyalty: earning skipped for locked account #{$user->id}");

                    return null;
                }

                $metadata = is_array($order->metadata) ? $order->metadata : (json_decode((string) $order->metadata, true) ?: []);
                $giftCard = (float) ($metadata['gift_card_applied_amount'] ?? $metadata['gift_card_amount'] ?? 0);
                $redeemedDollars = (float) Tx::where('order_id', $order->id)->where('transaction_type', 'redeemed')->sum('dollar_value');
                $couponOnly = max(0.0, (float) $order->discount_amount - abs($redeemedDollars));

                $lines = DB::table('ecommerce_order_items')->where('order_id', $order->id)->get(['product_id', 'total_price'])->map(fn ($r) => (array) $r);

                $calc = self::calculateEarning($user, [
                    'subtotal' => (float) $order->subtotal,
                    'discount' => (float) ($order->discount_amount ?? 0),
                    'coupon_discount' => $couponOnly,
                    'shipping' => (float) ($order->shipping_amount ?? 0),
                    'tax' => (float) ($order->tax_amount ?? 0),
                    'gift_card' => $giftCard,
                    'excluded_amount' => self::excludedAmount($lines),
                ], $order->id);

                $points = self::capEarning($user->id, $calc['points']);
                if ($points <= 0 && $calc['first_order_bonus'] <= 0 && $calc['flat_bonus'] <= 0) {
                    return null;
                }

                $main = null;
                if ($points > 0) {
                    $main = Tx::create([
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'transaction_type' => 'earned',
                        'points' => $points,
                        'remaining_points' => 0,
                        'dollar_value' => self::dollars($points),
                        'status' => Tx::STATUS_PENDING,
                        'reason' => "Points pending for Order #{$order->order_number}",
                        'reason_details' => "Order #{$order->order_number} pending " . str_replace('after_', '', $s['point_availability_trigger']),
                        'reference_type' => 'order',
                        'reference_id' => (string) $order->id,
                        'reference_date' => now()->toDateString(),
                    ]);
                }

                $extras = [];
                if ($calc['first_order_bonus'] > 0) {
                    $extras[] = ['first_order_bonus', $calc['first_order_bonus'], 'First order bonus', 'first_order', (string) $user->id];
                }
                if ($calc['flat_bonus'] > 0) {
                    $extras[] = ['order_bonus', $calc['flat_bonus'], 'Order purchase bonus', 'order_bonus', (string) $order->id];
                }
                $extraTotal = 0;
                foreach ($extras as [$type, $pts, $reason, $refType, $refId]) {
                    $pts = self::capEarning($user->id, $pts);
                    if ($pts <= 0) {
                        continue;
                    }
                    $main = $main ?: Tx::create([
                        'user_id' => $user->id, 'order_id' => $order->id, 'transaction_type' => $type, 'points' => $pts, 'remaining_points' => 0,
                        'dollar_value' => self::dollars($pts), 'status' => Tx::STATUS_PENDING, 'reason' => "$reason (Order #{$order->order_number})",
                        'reference_type' => $refType, 'reference_id' => $refId, 'reference_date' => now()->toDateString(),
                    ]);
                    if ($main->transaction_type !== $type) {
                        Tx::create([
                            'user_id' => $user->id, 'order_id' => $order->id, 'transaction_type' => $type, 'points' => $pts, 'remaining_points' => 0,
                            'dollar_value' => self::dollars($pts), 'status' => Tx::STATUS_PENDING, 'reason' => "$reason (Order #{$order->order_number})",
                            'reference_type' => $refType, 'reference_id' => $refId, 'reference_date' => now()->toDateString(),
                        ]);
                    }
                    $extraTotal += $pts;
                }

                $total = $points + $extraTotal;
                $balance->pending_points += $total;
                $balance->save();

                $order->loyalty_points_earned = $total;
                $order->saveQuietly();

                return $main;
            });
            $s = self::getSettings();
            if ($s['point_availability_trigger'] === 'on_order_placed'
                || ($s['point_availability_trigger'] === 'after_payment' && strtolower(trim((string) $order->payment_status)) === 'paid')) {
                self::releasePendingPoints($order, $s['point_availability_trigger'] === 'on_order_placed');
            }

            return $res;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::awardPendingOrderPoints error: ' . $e->getMessage());

            return null;
        }
    }

    /** Statuses at which each availability trigger releases points. */
    protected static function releaseStatuses(string $trigger): ?array
    {
        return match ($trigger) {
            'on_order_placed', 'after_payment' => null, // payment is enough
            'after_production' => ['in_production', 'production', 'shipped', 'delivered', 'completed'],
            'after_shipped' => ['shipped', 'delivered', 'completed'],
            'after_delivered', 'after_completed' => ['delivered', 'completed'],
            'manual' => [],
            default => ['delivered', 'completed'],
        };
    }

    /**
     * Release pending points of an order. $force = admin manual approval (ignores trigger, lock and payment).
     */
    public static function releasePendingPoints(EcommerceOrder $order, bool $force = false): bool
    {
        try {
            if (!$order->user_id) {
                return false;
            }
            $user = User::find($order->user_id);
            if (!$user) {
                return false;
            }

            $hasPending = Tx::where('order_id', $order->id)->where('status', Tx::STATUS_PENDING)->exists();
            if (!$hasPending) {
                return false;
            }

            if (!$force) {
                if (strtolower(trim((string) $order->payment_status)) !== 'paid') {
                    return false;
                }
                $s = self::getSettings();
                $statuses = self::releaseStatuses($s['point_availability_trigger']);
                $status = strtolower(trim((string) ($order->status ?: $order->order_status)));
                if ($statuses !== null && !in_array($status, $statuses, true)) {
                    return false;
                }
            }

            $done = DB::transaction(function () use ($order, $user, $force) {
                $balance = self::locked($user->id);
                if (!$force && $balance->is_locked && self::getSettings()['fraud_protection']) {
                    return false;
                }

                $txs = Tx::where('order_id', $order->id)->where('status', Tx::STATUS_PENDING)->lockForUpdate()->get();
                $released = 0;
                $expiry = self::expiryDateFrom();
                foreach ($txs as $tx) {
                    $pts = (int) $tx->points;
                    if ($pts <= 0) {
                        continue;
                    }
                    $tx->update([
                        'status' => Tx::STATUS_AVAILABLE,
                        'remaining_points' => $pts,
                        'expiration_date' => $expiry,
                        'reason' => $tx->transaction_type === 'earned' ? "Points earned on Order #{$order->order_number}" : $tx->reason,
                        'reason_details' => "Order #{$order->order_number} " . ($force ? 'approved by admin' : 'completed'),
                    ]);
                    $released += $pts;
                }

                if ($released > 0) {
                    $lifetimeBefore = (int) $balance->lifetime_earned;
                    $balance->pending_points = max(0, $balance->pending_points - $released);
                    $balance->available_points += $released;
                    $balance->lifetime_earned += $released;
                    self::persist($balance);

                    self::syncCentralAuth($user, $released, 'earned', "Points earned on Order #{$order->order_number}");
                    self::notify($user, 'loyalty_points_earned', [
                        'points_earned' => $released,
                        'order_number' => $order->order_number,
                        'points_balance' => $balance->available_points,
                    ], 'notify_members_on_earn');
                    self::checkTierUpgrade($user, $lifetimeBefore, (int) $balance->lifetime_earned);
                }

                return $released > 0;
            });

            if ($done) {
                self::awardReferralBonus($user);
            }

            return (bool) $done;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::releasePendingPoints error: ' . $e->getMessage());

            return false;
        }
    }

    /** Reward the referrer once the referred customer's first order is fulfilled (one reward per referral). */
    public static function awardReferralBonus(User $referred): bool
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('ecommerce_referrals')) {
                return false;
            }
            $ref = DB::table('ecommerce_referrals')->where('referred_id', $referred->id)->orderBy('id')->first();
            if (!$ref || (int) $ref->referrer_id === (int) $referred->id) {
                return false; // no referrer or self-referral
            }

            return self::awardActivity(
                (int) $ref->referrer_id,
                'referral',
                'referral_bonus',
                'Referral reward: ' . ($referred->name ?: $referred->email) . ' completed a first order',
                'referral',
                (string) $referred->id
            );
        } catch (\Throwable $e) {
            Log::warning('LoyaltyService::awardReferralBonus notice: ' . $e->getMessage());

            return false;
        }
    }
    /** Release pending points that belong to a non-order reference (gift card orders, etc.). */
    public static function releaseReferencePoints(string $referenceType, string $referenceId, ?int $adminId = null): bool
    {
        try {
            return DB::transaction(function () use ($referenceType, $referenceId, $adminId) {
                $txs = Tx::where('reference_type', $referenceType)->where('reference_id', $referenceId)->where('status', Tx::STATUS_PENDING)->lockForUpdate()->get();
                if ($txs->isEmpty()) {
                    return false;
                }
                $user = User::find($txs->first()->user_id);
                if (!$user) {
                    return false;
                }
                $balance = self::locked($user->id);
                $released = 0;
                $expiry = self::expiryDateFrom();
                foreach ($txs as $tx) {
                    $tx->update(['status' => Tx::STATUS_AVAILABLE, 'remaining_points' => (int) $tx->points, 'expiration_date' => $expiry, 'admin_id' => $adminId ?: $tx->admin_id]);
                    $released += (int) $tx->points;
                }
                $lifetimeBefore = (int) $balance->lifetime_earned;
                $balance->pending_points = max(0, $balance->pending_points - $released);
                $balance->available_points += $released;
                $balance->lifetime_earned += $released;
                self::persist($balance);
                self::syncCentralAuth($user, $released, 'earned', "Points released ({$referenceType} #{$referenceId})");
                self::notify($user, 'loyalty_points_earned', ['points_earned' => $released, 'order_number' => $referenceId, 'points_balance' => $balance->available_points], 'notify_members_on_earn');
                self::checkTierUpgrade($user, $lifetimeBefore, (int) $balance->lifetime_earned);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::releaseReferencePoints error: ' . $e->getMessage());

            return false;
        }
    }

    // =====================================================================
    //  REVERSAL / RESTORE
    // =====================================================================

    /**
     * Cancelled / refunded / voided orders. Pending points are voided; available points are reversed
     * (proportionally for partial refunds). Spent points create a negative balance and flag the account.
     */
    public static function reverseOrderPoints(EcommerceOrder $order, ?float $refundAmount = null, string $reason = 'Order cancelled'): bool
    {
        try {
            if (!$order->user_id) {
                return false;
            }
            $user = User::find($order->user_id);
            if (!$user) {
                return false;
            }

            return DB::transaction(function () use ($order, $user, $refundAmount, $reason) {
                $balance = self::locked($user->id);
                $s = self::getSettings();
                $orderBase = (float) ($order->subtotal ?: $order->total_amount) ?: 1.0;
                $isFull = $refundAmount === null || $refundAmount <= 0 || $refundAmount >= $orderBase;

                // 1. Pending points: void (partial refund voids proportionally)
                $pending = Tx::where('order_id', $order->id)->where('status', Tx::STATUS_PENDING)->lockForUpdate()->get();
                foreach ($pending as $tx) {
                    $pts = (int) $tx->points;
                    if ($isFull) {
                        $tx->update(['status' => Tx::STATUS_REVERSED, 'reason' => "Pending points reversed: {$reason}"]);
                        $balance->pending_points = max(0, $balance->pending_points - $pts);
                    } elseif ($tx->transaction_type === 'earned') {
                        $cut = (int) round($pts * ($refundAmount / $orderBase));
                        $cut = min($pts, max(0, $cut));
                        if ($cut > 0) {
                            $tx->update(['points' => $pts - $cut, 'dollar_value' => self::dollars($pts - $cut), 'reason_details' => "Reduced by {$cut} pts after partial refund"]);
                            $balance->pending_points = max(0, $balance->pending_points - $cut);
                        }
                    }
                }

                // 2. Available points
                if ($s['allow_points_reversal']) {
                    $earned = Tx::where('order_id', $order->id)->where('status', Tx::STATUS_AVAILABLE)->where('points', '>', 0)
                        ->whereIn('transaction_type', ['earned', 'order_bonus', 'first_order_bonus'])->lockForUpdate()->get();

                    if ($earned->isNotEmpty()) {
                        $alreadyReversed = (int) abs(Tx::where('order_id', $order->id)->where('transaction_type', 'reversed')->sum('points'));
                        $earnedBase = (int) $earned->where('transaction_type', 'earned')->sum('points');
                        $totalAwarded = (int) $earned->sum('points');
                        $remainingReversible = max(0, $totalAwarded - $alreadyReversed);

                        if ($isFull) {
                            $toReverse = $remainingReversible;
                        } else {
                            $toReverse = (int) round($earnedBase * ($refundAmount / $orderBase));
                        }
                        $toReverse = min($remainingReversible, max(0, $toReverse));

                        if ($toReverse > 0) {
                            $left = $toReverse;
                            foreach ($earned->sortBy(fn ($t) => $t->transaction_type === 'earned' ? 1 : 0) as $lot) {
                                if ($left <= 0) {
                                    break;
                                }
                                $take = min($left, (int) $lot->remaining_points);
                                $lot->remaining_points -= $take;
                                $left -= $take;
                                if ($isFull) {
                                    $lot->status = Tx::STATUS_REVERSED;
                                    $lot->remaining_points = 0;
                                }
                                $lot->save();
                            }
                            if ($isFull) {
                                foreach ($earned as $lot) {
                                    $lot->update(['status' => Tx::STATUS_REVERSED, 'remaining_points' => 0]);
                                }
                            }

                            Tx::create([
                                'user_id' => $user->id,
                                'order_id' => $order->id,
                                'transaction_type' => 'reversed',
                                'points' => -$toReverse,
                                'remaining_points' => 0,
                                'dollar_value' => self::dollars($toReverse),
                                'status' => Tx::STATUS_REVERSED,
                                'reason' => "Points reversed: {$reason}",
                                'reason_details' => "Reversed {$toReverse} points for Order #{$order->order_number}" . ($left > 0 ? " ({$left} already spent)" : ''),
                                'reference_type' => 'order_refund',
                                'reference_id' => (string) $order->id,
                                'reference_date' => now()->toDateString(),
                            ]);

                            $balance->available_points -= $toReverse; // may go negative: spent points
                            $balance->lifetime_earned = max(0, $balance->lifetime_earned - $toReverse);

                            if ($left > 0) {
                                $balance->needs_review = true;
                                $balance->review_reason = "Negative points balance: {$left} points reversed on Order #{$order->order_number} were already spent.";
                            }

                            self::syncCentralAuth($user, -$toReverse, 'reversed', "Points reversed for Order #{$order->order_number}");
                        }
                    }
                }

                self::persist($balance);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::reverseOrderPoints error: ' . $e->getMessage());

            return false;
        }
    }

    /** Give redeemed points back (cancelled / refunded / failed-payment orders). Idempotent. */
    public static function restoreRedeemedPoints(EcommerceOrder $order, string $reason = 'Restored redeemed points due to order cancellation'): bool
    {
        try {
            if (!$order->user_id) {
                return false;
            }
            $user = User::find($order->user_id);
            if (!$user) {
                return false;
            }

            return DB::transaction(function () use ($order, $user, $reason) {
                $balance = self::locked($user->id);
                $redeemed = Tx::where('order_id', $order->id)->where('transaction_type', 'redeemed')->where('status', Tx::STATUS_REDEEMED)->lockForUpdate()->get();
                $total = (int) $redeemed->sum(fn ($t) => abs($t->points));
                if ($total <= 0) {
                    return false;
                }

                foreach ($redeemed as $tx) {
                    $tx->update(['status' => Tx::STATUS_REVERSED]);
                }

                Tx::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'transaction_type' => 'restored',
                    'points' => $total,
                    'remaining_points' => $total,
                    'dollar_value' => self::dollars($total),
                    'status' => Tx::STATUS_AVAILABLE,
                    'reason' => $reason,
                    'reason_details' => "Returned {$total} redeemed points for Order #{$order->order_number}",
                    'reference_type' => 'order_cancellation',
                    'reference_id' => (string) $order->id,
                    'reference_date' => now()->toDateString(),
                    'expiration_date' => self::expiryDateFrom(),
                ]);

                $balance->available_points += $total;
                $balance->redeemed_points = max(0, $balance->redeemed_points - $total);
                self::persist($balance);

                self::syncCentralAuth($user, $total, 'manual_added', $reason);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::restoreRedeemedPoints error: ' . $e->getMessage());

            return false;
        }
    }

    // =====================================================================
    //  REDEMPTION
    // =====================================================================

    /**
     * Validate a general points redemption.
     * ctx: subtotal, coupon_discount, other_discount, has_coupon, has_gift_card
     * @return array{ok:bool,message:?string,discount:float,points:int,max_points:int,max_discount:float,available:int}
     */
    public static function validateRedemption(User $user, int $points, array $ctx, bool $lockRow = false): array
    {
        $s = self::getSettings();
        $ratio = $s['points_to_dollar_ratio'];
        $subtotal = max(0.0, (float) ($ctx['subtotal'] ?? 0));
        $coupon = max(0.0, (float) ($ctx['coupon_discount'] ?? 0));
        $other = max(0.0, (float) ($ctx['other_discount'] ?? 0));

        $balance = $lockRow ? self::locked($user->id) : self::getOrCreateBalance($user);
        $available = (int) $balance->available_points;

        $maxDiscount = $subtotal * ($s['max_redeem_percent'] / 100);
        if ($s['max_redeem_amount'] > 0) {
            $maxDiscount = min($maxDiscount, $s['max_redeem_amount']);
        }
        $maxDiscount = max(0.0, min($maxDiscount, $subtotal - $coupon - $other));
        $maxByDiscount = (int) floor(round($maxDiscount / $ratio, 4));

        $maxPoints = max(0, min($available, $maxByDiscount));
        if ($s['max_redeem_points_per_order'] > 0) {
            $maxPoints = min($maxPoints, $s['max_redeem_points_per_order']);
        }

        // rolling limits
        if ($s['max_redeem_per_day'] > 0) {
            $maxPoints = min($maxPoints, max(0, $s['max_redeem_per_day'] - self::redeemedPointsSince($user->id, Carbon::now()->startOfDay())));
        }
        if ($s['max_redeem_per_month'] > 0) {
            $maxPoints = min($maxPoints, max(0, $s['max_redeem_per_month'] - self::redeemedPointsSince($user->id, Carbon::now()->startOfMonth())));
        }

        $out = ['ok' => false, 'message' => null, 'discount' => 0.0, 'points' => $points, 'max_points' => $maxPoints, 'max_discount' => round($maxPoints * $ratio, 2), 'available' => $available];

        $fail = function (string $m) use (&$out) {
            $out['message'] = $m;

            return $out;
        };

        if (!$s['enabled']) {
            return $fail('Loyalty rewards program is currently disabled.');
        }
        if (!$s['redemption_enabled']) {
            return $fail('Points redemption is currently disabled.');
        }
        if (!self::isEligibleUser($user)) {
            return $fail('Your account type is not eligible for the rewards program.');
        }
        if ($balance->is_locked) {
            return $fail('Your loyalty points account is currently suspended: ' . ($balance->locked_reason ?: 'Please contact support.'));
        }
        if ($points <= 0) {
            return $fail('Enter the number of points to use.');
        }
        if ($available <= 0 || $points > $available) {
            return $fail("Insufficient loyalty points. You need {$points} points but only have " . max(0, $available) . '.');
        }
        if ($points < $s['minimum_redeem_points']) {
            return $fail('Minimum points required to redeem is ' . number_format($s['minimum_redeem_points']) . ' points.');
        }
        if ($subtotal < $s['min_order_for_redemption']) {
            return $fail('Minimum order subtotal to redeem points is $' . number_format($s['min_order_for_redemption'], 2) . '.');
        }
        if (!empty($ctx['has_coupon']) && !$s['allow_with_coupons']) {
            return $fail('Loyalty points cannot be combined with coupon codes.');
        }
        if (!empty($ctx['has_gift_card']) && !$s['allow_with_gift_cards']) {
            return $fail('Loyalty points cannot be combined with gift cards.');
        }
        if ($s['max_redeem_points_per_order'] > 0 && $points > $s['max_redeem_points_per_order']) {
            return $fail('Maximum points redemption allowed per order is ' . number_format($s['max_redeem_points_per_order']) . ' points.');
        }
        $discount = round($points * $ratio, 2);
        if ($discount > round($maxDiscount, 2) + 0.005) {
            return $fail('Loyalty points discount cannot exceed ' . rtrim(rtrim(number_format($s['max_redeem_percent'], 2), '0'), '.') . '% of the subtotal (max discount of $' . number_format($maxDiscount, 2) . ').');
        }
        if ($points > $maxPoints) {
            return $fail('You can use at most ' . number_format($maxPoints) . ' points on this order (daily / monthly limit or order cap).');
        }
        if (!$s['allow_partial_redemption']) {
            $payable = $subtotal - $coupon - $other;
            if ($payable > 0 && $discount + 0.005 < $payable) {
                return $fail('Points must cover the full order amount (partial point payments are not allowed).');
            }
        }

        $out['ok'] = true;
        $out['discount'] = $discount;

        return $out;
    }

    /**
     * Deduct points for an order (FIFO). Re-validates the balance under a row lock.
     * @throws LoyaltyException
     */
    public static function redeemPoints(User $user, int $points, EcommerceOrder $order): bool
    {
        if ($points <= 0) {
            return false;
        }

        return DB::transaction(function () use ($user, $points, $order) {
            $balance = self::locked($user->id);
            $s = self::getSettings();

            if (!$s['enabled'] || !$s['redemption_enabled']) {
                throw new LoyaltyException('Points redemption is currently disabled.');
            }
            if ($balance->is_locked) {
                throw new LoyaltyException('Your loyalty points account is currently suspended.');
            }
            if ($balance->available_points < $points) {
                throw new LoyaltyException("Insufficient loyalty points. You need {$points} points but only have " . max(0, $balance->available_points) . '.');
            }
            if ($s['max_redeem_per_day'] > 0 && self::redeemedPointsSince($user->id, Carbon::now()->startOfDay()) + $points > $s['max_redeem_per_day']) {
                throw new LoyaltyException('Daily points redemption limit reached.');
            }
            if ($s['max_redeem_per_month'] > 0 && self::redeemedPointsSince($user->id, Carbon::now()->startOfMonth()) + $points > $s['max_redeem_per_month']) {
                throw new LoyaltyException('Monthly points redemption limit reached.');
            }

            $dollar = self::dollars($points);
            Tx::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'transaction_type' => 'redeemed',
                'points' => -$points,
                'remaining_points' => 0,
                'dollar_value' => $dollar,
                'status' => Tx::STATUS_REDEEMED,
                'reason' => "Redeemed points on Order #{$order->order_number}",
                'reason_details' => "Redeemed {$points} points for \${$dollar} discount",
                'reference_type' => 'order_redemption',
                'reference_id' => (string) $order->id,
                'reference_date' => now()->toDateString(),
            ]);

            self::consume($user->id, $points);
            $balance->available_points -= $points;
            $balance->redeemed_points += $points;
            self::persist($balance);

            self::syncCentralAuth($user, -$points, 'redeemed', "Redeemed {$points} points on Order #{$order->order_number}");
            self::notify($user, 'loyalty_point_redemption', [
                'points_redeemed' => $points,
                'reward_description' => "a \${$dollar} discount on Order #{$order->order_number}",
                'points_balance' => $balance->available_points,
            ], 'notify_on_redeem');

            return true;
        });
    }

    // =====================================================================
    //  BONUSES / ADJUSTMENTS
    // =====================================================================

    /**
     * Automatic bonus (signup, review, birthday, referral, membership ...) with permanent
     * one-reward-per-reference protection (any status, including expired / reversed).
     */
    public static function awardBonus(int $userId, string $bonusType, int $points, string $reason, ?string $refType = null, ?string $refId = null): bool
    {
        self::$lastError = null;
        try {
            if ($points <= 0) {
                return false;
            }
            $user = User::find($userId);
            if (!$user || !self::isEligibleUser($user)) {
                return false;
            }

            return DB::transaction(function () use ($user, $bonusType, $points, $reason, $refType, $refId) {
                $balance = self::locked($user->id);
                $s = self::getSettings();

                if ($s['fraud_protection'] && $balance->is_locked) {
                    self::$lastError = 'Account is locked.';

                    return false;
                }

                if (!empty($refType) && !empty($refId)) {
                    $dup = Tx::where('user_id', $user->id)->where('reference_type', $refType)->where('reference_id', (string) $refId)->where('points', '>', 0)->exists();
                    if ($dup) {
                        Log::info("Loyalty: duplicate reward prevented for user {$user->id} ({$refType}#{$refId})");
                        self::$lastError = 'Reward already granted.';

                        return false;
                    }
                }

                $points = self::capEarning($user->id, $points);
                if ($points <= 0) {
                    self::$lastError = 'Earning limit reached.';

                    return false;
                }

                Tx::create([
                    'user_id' => $user->id,
                    'order_id' => null,
                    'transaction_type' => $bonusType,
                    'points' => $points,
                    'remaining_points' => $points,
                    'dollar_value' => self::dollars($points),
                    'status' => Tx::STATUS_AVAILABLE,
                    'reason' => $reason,
                    'reference_type' => $refType,
                    'reference_id' => $refId ? (string) $refId : null,
                    'reference_date' => now()->toDateString(),
                    'expiration_date' => self::expiryDateFrom(),
                ]);

                $lifetimeBefore = (int) $balance->lifetime_earned;
                $balance->available_points += $points;
                $balance->lifetime_earned += $points;
                self::persist($balance);

                self::syncCentralAuth($user, $points, $bonusType, $reason);
                self::notify($user, 'loyalty_points_earned', ['points_earned' => $points, 'order_number' => $reason, 'points_balance' => $balance->available_points], 'notify_members_on_earn');
                self::checkTierUpgrade($user, $lifetimeBefore, (int) $balance->lifetime_earned);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::awardBonus error: ' . $e->getMessage());
            self::$lastError = $e->getMessage();

            return false;
        }
    }

    /** Award a configured activity (review, referral, signup ...) if enabled for the user's segment. */
    public static function awardActivity(int $userId, string $activityKey, string $bonusType, string $reason, ?string $refType = null, ?string $refId = null): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        $act = self::activity($activityKey, $user);
        if (!$act['enabled'] || $act['points'] <= 0) {
            return false;
        }

        return self::awardBonus($userId, $bonusType, (int) $act['points'], $reason, $refType, $refId);
    }

    /**
     * Admin / system manual adjustment with full audit trail.
     * Positive types add a lot; negative types are taken FIFO and cannot exceed the available balance.
     */
    public static function adjustPoints(int $userId, int $points, string $type, string $reason, ?int $orderId = null, string $status = 'available', ?string $reasonDetails = null, ?string $notes = null, ?int $adminId = null): bool
    {
        self::$lastError = null;
        try {
            $user = User::find($userId);
            if (!$user) {
                self::$lastError = 'User not found.';

                return false;
            }
            if (trim($reason) === '' && $adminId) {
                self::$lastError = 'A reason is required.';

                return false;
            }
            $points = abs($points);
            if ($points === 0) {
                self::$lastError = 'Points must be greater than zero.';

                return false;
            }

            $t = strtolower($type);
            $isDeduction = in_array($t, ['manual_removed', 'subtract', 'deduct', 'remove', 'redeemed', 'expired', 'reversed'], true);

            return DB::transaction(function () use ($user, $points, $t, $type, $reason, $orderId, $reasonDetails, $notes, $adminId, $isDeduction) {
                $balance = self::locked($user->id);
                $before = ['available' => (int) $balance->available_points, 'lifetime' => (int) $balance->lifetime_earned];

                if ($isDeduction) {
                    if ($balance->available_points < $points) {
                        self::$lastError = 'Cannot remove more points than the available balance (' . max(0, $balance->available_points) . ').';

                        return false;
                    }
                    self::consume($user->id, $points);
                    $balance->available_points -= $points;
                    Tx::create([
                        'user_id' => $user->id, 'order_id' => $orderId, 'transaction_type' => 'manual_removed', 'points' => -$points, 'remaining_points' => 0,
                        'dollar_value' => self::dollars($points), 'status' => Tx::STATUS_MANUAL_REMOVED, 'reason' => $reason, 'reason_details' => $reasonDetails,
                        'notes' => $notes, 'admin_id' => $adminId, 'reference_date' => now()->toDateString(),
                    ]);
                    $lifetimeBefore = (int) $balance->lifetime_earned;
                } else {
                    $lifetimeBefore = (int) $balance->lifetime_earned;
                    $isManual = in_array($t, ['manual_added', 'add', 'credit', 'manual', 'adjusted'], true);
                    Tx::create([
                        'user_id' => $user->id, 'order_id' => $orderId, 'transaction_type' => $isManual ? 'manual_added' : $type, 'points' => $points, 'remaining_points' => $points,
                        'dollar_value' => self::dollars($points), 'status' => $isManual ? Tx::STATUS_MANUAL_ADDED : Tx::STATUS_AVAILABLE, 'reason' => $reason,
                        'reason_details' => $reasonDetails, 'notes' => $notes, 'admin_id' => $adminId, 'reference_date' => now()->toDateString(),
                        'expiration_date' => self::expiryDateFrom(),
                    ]);
                    $balance->available_points += $points;
                    $balance->lifetime_earned += $points;
                }
                self::persist($balance);

                if ($adminId) {
                    LoyaltyAuditLog::record($isDeduction ? 'points_removed' : 'points_added', $adminId, $user->id, $reason, $before, [
                        'available' => (int) $balance->available_points, 'points' => $isDeduction ? -$points : $points, 'notes' => $notes,
                    ]);
                }

                $signed = $isDeduction ? -$points : $points;
                self::syncCentralAuth($user, $signed, $type, $reason);
                self::notify($user, 'loyalty_points_adjusted', [
                    'points_change' => ($signed > 0 ? '+' : '') . $signed,
                    'reason' => $reason,
                    'points_balance' => $balance->available_points,
                ], 'notify_on_adjustment');
                if (!$isDeduction) {
                    self::checkTierUpgrade($user, $lifetimeBefore, (int) $balance->lifetime_earned);
                }

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::adjustPoints error: ' . $e->getMessage());
            self::$lastError = self::$lastError ?: $e->getMessage();

            return false;
        }
    }

    /** Admin: move points from one account to another (FIFO from source). */
    public static function transferPoints(int $fromUserId, int $toUserId, int $points, string $reason, ?int $adminId = null): bool
    {
        self::$lastError = null;
        if ($fromUserId === $toUserId || $points <= 0 || trim($reason) === '') {
            self::$lastError = 'Invalid transfer request.';

            return false;
        }

        try {
            return DB::transaction(function () use ($fromUserId, $toUserId, $points, $reason, $adminId) {
                $a = User::find($fromUserId);
                $b = User::find($toUserId);
                if (!$a || !$b) {
                    self::$lastError = 'User not found.';

                    return false;
                }
                // lock in a deterministic order to avoid deadlocks
                foreach ([min($fromUserId, $toUserId), max($fromUserId, $toUserId)] as $id) {
                    self::locked($id);
                }
                if (!self::adjustPoints($fromUserId, $points, 'manual_removed', "Transfer to {$b->email}: {$reason}", null, 'manual_removed', null, null, $adminId)) {
                    throw new LoyaltyException(self::$lastError ?: 'Transfer failed.');
                }
                if (!self::adjustPoints($toUserId, $points, 'manual_added', "Transfer from {$a->email}: {$reason}", null, 'manual_added', null, null, $adminId)) {
                    throw new LoyaltyException(self::$lastError ?: 'Transfer failed.');
                }
                LoyaltyAuditLog::record('points_transferred', $adminId, $fromUserId, $reason, null, ['to_user_id' => $toUserId, 'points' => $points]);

                return true;
            });
        } catch (\Throwable $e) {
            self::$lastError = self::$lastError ?: $e->getMessage();

            return false;
        }
    }

    /** Admin: reset available balance to zero (logged). */
    public static function resetBalance(int $userId, string $reason, ?int $adminId = null): bool
    {
        self::$lastError = null;
        if (trim($reason) === '') {
            self::$lastError = 'A reason is required.';

            return false;
        }
        $balance = self::getOrCreateBalance($userId);
        $available = (int) $balance->available_points;
        if ($available > 0) {
            return self::adjustPoints($userId, $available, 'manual_removed', "Balance reset: {$reason}", null, 'manual_removed', null, null, $adminId);
        }
        if ($available < 0) {
            return self::adjustPoints($userId, abs($available), 'manual_added', "Balance reset (clear negative): {$reason}", null, 'manual_added', null, null, $adminId);
        }

        return true;
    }

    public static function setLock(int $userId, bool $locked, ?string $reason = null, ?int $adminId = null): CustomerPointsBalance
    {
        $reason = trim((string) $reason) !== '' ? trim((string) $reason) : ($locked ? 'Account locked by administrator' : 'Account unlocked by administrator');

        return DB::transaction(function () use ($userId, $locked, $reason, $adminId) {
            $b = self::locked($userId);
            $before = ['is_locked' => (bool) $b->is_locked, 'reason' => $b->locked_reason];
            $b->is_locked = $locked;
            $b->locked_reason = $locked ? $reason : null;
            if (!$locked) {
                $b->needs_review = false;
                $b->review_reason = null;
            }
            $b->save();
            LoyaltyAuditLog::record($locked ? 'account_frozen' : 'account_unfrozen', $adminId, $userId, $reason, $before, ['is_locked' => $locked]);

            return $b;
        });
    }

    public static function awardPointsForGiftCard(?int $userId, float $amount, int $orderId, string $orderNumber, string $status = 'pending'): bool
    {
        try {
            if (!$userId) {
                return false;
            }
            $user = User::find($userId);
            if (!$user || !self::isEligibleUser($user)) {
                return false;
            }
            $s = self::getSettings();
            $flat = self::activity('gift_card', $user);
            if (!$s['earn_points_on_gift_cards'] && !$flat['enabled']) {
                return false;
            }
            $points = (int) round($amount * $s['points_per_dollar']);
            if ($flat['enabled'] && $flat['points'] > 0) {
                $points += $flat['points'];
            }
            if ($points <= 0) {
                return false;
            }

            if (Tx::where('reference_type', 'gift_card_order')->where('reference_id', (string) $orderId)->exists()) {
                return false; // idempotent
            }

            if ($status === 'available') {
                return self::awardBonus($userId, 'earned', $points, "Points earned for Gift Card Order #{$orderNumber}", 'gift_card_order', (string) $orderId);
            }

            return (bool) DB::transaction(function () use ($user, $points, $orderId, $orderNumber) {
                $balance = self::locked($user->id);
                $points = self::capEarning($user->id, $points);
                if ($points <= 0) {
                    return false;
                }
                Tx::create([
                    'user_id' => $user->id, 'order_id' => null, 'transaction_type' => 'earned', 'points' => $points, 'remaining_points' => 0,
                    'dollar_value' => self::dollars($points), 'status' => Tx::STATUS_PENDING, 'reason' => "Points pending for Gift Card Order #{$orderNumber}",
                    'reference_type' => 'gift_card_order', 'reference_id' => (string) $orderId, 'reference_date' => now()->toDateString(),
                ]);
                $balance->pending_points += $points;
                $balance->save();

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::awardPointsForGiftCard error: ' . $e->getMessage());

            return false;
        }
    }

    // =====================================================================
    //  EXPIRY
    // =====================================================================

    /** Expire only the *unspent* remainder of lots. Returns total points expired. */
    public static function expireOldPoints(): int
    {
        $s = self::getSettings();
        if (!$s['enable_expiration']) {
            return 0;
        }

        $total = 0;
        try {
            $userIds = [];

            if ($s['expiration_method'] === 'from_last_activity') {
                $cutoff = self::expiryCutoff($s);
                $candidates = Tx::whereIn('status', [Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])->where('points', '>', 0)->where('remaining_points', '>', 0)->distinct()->pluck('user_id');
                foreach ($candidates as $uid) {
                    $last = Tx::where('user_id', $uid)->whereIn('transaction_type', ['earned', 'redeemed', 'order_bonus', 'first_order_bonus', 'restored'])->max('created_at');
                    if ($last && Carbon::parse($last)->lt($cutoff)) {
                        $userIds[] = (int) $uid;
                    }
                }
                foreach ($userIds as $uid) {
                    $total += self::expireUserLots((int) $uid, false);
                }

                return $total;
            }

            $userIds = Tx::whereIn('status', [Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])
                ->where('points', '>', 0)->where('remaining_points', '>', 0)
                ->whereNotNull('expiration_date')->where('expiration_date', '<', Carbon::now())
                ->distinct()->pluck('user_id');

            foreach ($userIds as $uid) {
                $total += self::expireUserLots((int) $uid, true);
            }
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::expireOldPoints error: ' . $e->getMessage());
        }

        return $total;
    }

    protected static function expiryCutoff(array $s): Carbon
    {
        return $s['expiry_unit'] === 'Months' ? Carbon::now()->subMonths($s['expiry_value']) : Carbon::now()->subDays($s['expiry_value']);
    }

    protected static function expireUserLots(int $userId, bool $onlyDue): int
    {
        return (int) DB::transaction(function () use ($userId, $onlyDue) {
            $user = User::find($userId);
            if (!$user) {
                return 0;
            }
            $balance = self::locked($userId);
            $query = Tx::where('user_id', $userId)->whereIn('status', [Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])->where('points', '>', 0)->where('remaining_points', '>', 0);
            if ($onlyDue) {
                $query->whereNotNull('expiration_date')->where('expiration_date', '<', Carbon::now());
            }
            $lots = $query->lockForUpdate()->get();
            $expired = 0;
            foreach ($lots as $lot) {
                $pts = (int) $lot->remaining_points;
                $lot->update(['remaining_points' => 0, 'status' => Tx::STATUS_EXPIRED]);
                Tx::create([
                    'user_id' => $userId, 'transaction_type' => 'expired', 'points' => -$pts, 'remaining_points' => 0, 'dollar_value' => self::dollars($pts),
                    'status' => Tx::STATUS_EXPIRED, 'reason' => 'Points expired', 'reason_details' => "{$pts} unspent points from " . ($lot->reason ?: 'earlier activity') . ' expired.',
                    'reference_type' => 'expired_points', 'reference_id' => (string) $lot->id, 'reference_date' => now()->toDateString(),
                ]);
                $expired += $pts;
            }
            if ($expired > 0) {
                $balance->available_points -= $expired;
                $balance->expired_points += $expired;
                self::persist($balance);
                self::syncCentralAuth($user, -$expired, 'expired', "{$expired} points expired.");
                self::notify($user, 'loyalty_points_expired', ['points_expired' => $expired, 'points_balance' => $balance->available_points], 'notify_on_expired');
            }

            return $expired;
        });
    }

    /** Email members whose points expire within the configured reminder window. Returns number of members notified. */
    public static function sendExpiryReminders(): int
    {
        $s = self::getSettings();
        if (!$s['enable_expiration'] || !$s['notify_on_expiry']) {
            return 0;
        }
        $until = Carbon::now()->addDays($s['expiration_reminder_days']);
        $lots = Tx::whereIn('status', [Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])->where('points', '>', 0)->where('remaining_points', '>', 0)
            ->whereNotNull('expiration_date')->whereBetween('expiration_date', [Carbon::now(), $until])->whereNull('expiry_reminder_sent_at')->get()->groupBy('user_id');

        $sent = 0;
        foreach ($lots as $uid => $group) {
            $user = User::find($uid);
            if (!$user) {
                continue;
            }
            $points = (int) $group->sum('remaining_points');
            $first = $group->min('expiration_date');
            LoyaltyNotifier::send($user, 'loyalty_points_expiring', [
                'points_expiring' => $points,
                'expiry_date' => Carbon::parse($first)->format('M j, Y'),
                'dollar_value' => number_format($points * $s['points_to_dollar_ratio'], 2),
            ]);
            Tx::whereIn('id', $group->pluck('id'))->update(['expiry_reminder_sent_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    // =====================================================================
    //  SUMMARY (customer + admin)
    // =====================================================================

    public static function typeLabel(string $type, ?string $reason = null): string
    {
        return match ($type) {
            'earned' => 'Order purchase',
            'order_bonus' => 'Order bonus',
            'first_order_bonus' => 'First order bonus',
            'redeemed' => 'Points redeemed',
            'reversed' => 'Points reversed',
            'expired' => 'Points expired',
            'restored' => 'Points restored',
            'manual_added' => 'Admin adjustment',
            'manual_removed' => 'Admin adjustment',
            'signup_bonus' => 'Welcome bonus',
            'review_reward' => 'Review reward',
            'birthday_bonus' => 'Birthday reward',
            'referral_bonus', 'referral' => 'Referral reward',
            'membership_bonus' => 'Membership bonus',
            'photo_bonus' => 'Project photos reward',
            default => $reason ?: ucwords(str_replace('_', ' ', $type)),
        };
    }

    public static function transactionPayload(Tx $t): array
    {
        $orderNumber = $t->relationLoaded('order') ? $t->order?->order_number : null;
        $isoDate = optional($t->created_at)->toIso8601String();
        $expIso = optional($t->expiration_date)->toIso8601String();
        $activity = self::typeLabel((string) $t->transaction_type, $t->reason);

        return [
            'id' => $t->id,
            'date' => $isoDate,
            'created_at' => $isoDate,
            'type' => $t->transaction_type,
            'transaction_type' => $t->transaction_type,
            'activity' => $activity,
            'title' => $activity,
            'subtitle' => $t->reason_details ?: ($orderNumber ? "Order #{$orderNumber}" : $t->reason),
            'reason' => $t->reason,
            'reason_details' => $t->reason_details,
            'description' => $t->reason,
            'details' => $t->reason_details,
            'notes' => $t->notes,
            'points' => (int) $t->points,
            'remaining_points' => (int) $t->remaining_points,
            'dollar_value' => (float) $t->dollar_value,
            'status' => $t->status,
            'order_id' => $t->order_id,
            'order_number' => $orderNumber,
            'reference_type' => $t->reference_type,
            'reference_id' => $t->reference_id,
            'expires_at' => $expIso,
            'expiration_date' => $expIso,
        ];
    }

    // =====================================================================
    //  WALLET CONVERSION / DAILY CHECK-IN / LEADERBOARD
    // =====================================================================

    /**
     * Transfer available loyalty points to the user's wallet balance.
     * Deducts points from balance, marks transaction lots as consumed,
     * creates an EcommerceLoyaltyTransaction, and credits the user's wallet.
     *
     * @throws LoyaltyException
     */
    public static function transferToWallet(User $user, ?int $points = null): array
    {
        return DB::transaction(function () use ($user, $points) {
            $balance = self::locked($user->id);
            $s = self::getSettings();

            if (!$s['enabled'] || !$s['redemption_enabled']) {
                throw new LoyaltyException('Points redemption is currently disabled.');
            }
            if (!self::isEligibleUser($user)) {
                throw new LoyaltyException('Your account is not eligible for the rewards program.');
            }
            if ($balance->is_locked && $s['fraud_protection']) {
                throw new LoyaltyException('Your loyalty points account is currently suspended.');
            }

            $avail = (int) $balance->available_points;
            if ($avail <= 0) {
                throw new LoyaltyException('You have no available points to transfer.');
            }

            $spend = $points ? min($avail, max(1, (int)$points)) : $avail;

            $ratio = (float) ($s['points_to_dollar_ratio'] ?? 0.01);
            if ($ratio <= 0) {
                $ratio = 0.01;
            }
            $dollarValue = round($spend * $ratio, 2);

            if ($dollarValue <= 0) {
                throw new LoyaltyException('Points amount is too low to convert into wallet credit.');
            }

            // Consume points from FIFO lots
            self::consume($user->id, $spend);

            // Update balance
            $balance->available_points -= $spend;
            $balance->redeemed_points += $spend;
            self::persist($balance);

            // Create loyalty redemption transaction
            $refId = 'TOPUP-' . strtoupper(uniqid());
            Tx::create([
                'user_id' => $user->id,
                'order_id' => null,
                'transaction_type' => 'redeemed',
                'points' => -$spend,
                'remaining_points' => 0,
                'dollar_value' => $dollarValue,
                'status' => Tx::STATUS_REDEEMED,
                'reason' => 'Transfer to Wallet Balance',
                'reason_details' => "Converted {$spend} points to \${$dollarValue} wallet credit",
                'reference_type' => 'wallet_transfer',
                'reference_id' => $refId,
                'reference_date' => now()->toDateString(),
            ]);

            // Adjust the user's wallet
            WalletService::adjustWallet(
                $user->id,
                $dollarValue,
                'deposit',
                "Loyalty points redemption ({$spend} pts)",
                $refId
            );

            // Keep user loyalty_points column in sync
            $user->loyalty_points = (int) $balance->available_points;
            $user->save();

            self::syncCentralAuth($user, -$spend, 'redeemed', "Converted {$spend} loyalty points to wallet balance");

            return [
                'points_transferred' => $spend,
                'amount_credited' => $dollarValue,
                'remaining_points' => (int) $balance->available_points,
                'wallet_balance' => (float) $user->fresh()->wallet_balance,
                'reference_id' => $refId,
            ];
        });
    }

    /**
     * Redeem a catalog reward item by name and points cost.
     * Deducts points from balance, consumes FIFO lots, logs transaction,
     * generates a private single-use coupon, and returns the voucher code.
     *
     * @throws LoyaltyException
     */
    public static function redeemCatalogReward(User $user, $itemsOrName, int $pointsCost = 0, array $options = []): array
    {
        return DB::transaction(function () use ($user, $itemsOrName, $pointsCost, $options) {
            $balance = self::locked($user->id);
            $s = self::getSettings();

            if (!$s['enabled'] || !$s['redemption_enabled']) {
                throw new LoyaltyException('Points redemption is currently disabled.');
            }
            if (!self::isEligibleUser($user)) {
                throw new LoyaltyException('Your account is not eligible for the rewards program.');
            }
            if ($balance->is_locked && $s['fraud_protection']) {
                throw new LoyaltyException('Your loyalty points account is currently suspended.');
            }

            // Normalize items array
            if (is_array($itemsOrName)) {
                $items = $itemsOrName;
            } else {
                $items = [[
                    'name' => (string) $itemsOrName,
                    'points' => $pointsCost,
                    'quantity' => 1,
                ]];
            }

            // Calculate total points required
            $calculatedPoints = 0;
            foreach ($items as $it) {
                $qty = max(1, (int) ($it['quantity'] ?? 1));
                $pts = (int) ($it['points'] ?? 0);
                $calculatedPoints += ($pts * $qty);
            }

            $totalPoints = $pointsCost > 0 ? $pointsCost : $calculatedPoints;

            if ($totalPoints <= 0) {
                throw new LoyaltyException('Invalid points cost for reward.');
            }

            if ((int)$balance->available_points < $totalPoints) {
                $needed = $totalPoints - max(0, (int)$balance->available_points);
                throw new LoyaltyException("You need {$needed} more points to redeem. Current balance: {$balance->available_points} pts.");
            }

            // Consume points from lots
            self::consume($user->id, $totalPoints);

            // Update balance
            $balance->available_points -= $totalPoints;
            $balance->redeemed_points += $totalPoints;
            self::persist($balance);

            $deliveryMethod = (string) ($options['delivery_method'] ?? 'shipping');
            $isPickup = ($deliveryMethod === 'pickup');
            $shippingFee = $isPickup ? 0.00 : (float) ($options['shipping_fee'] ?? 4.95);
            $totalPaid = $shippingFee;

            $shippingAddress = $options['shipping_address'] ?? null;
            $pickupLocation = $options['pickup_location'] ?? null;
            $pickupLocationId = is_array($pickupLocation)
                ? ($pickupLocation['id'] ?? null)
                : (is_numeric($pickupLocation) ? (int) $pickupLocation : null);

            $shippingAddressFormatted = null;
            if ($shippingAddress) {
                if (is_array($shippingAddress)) {
                    $street = $shippingAddress['address_line1'] ?? ($shippingAddress['address'] ?? ($shippingAddress['street'] ?? ''));
                    $city = $shippingAddress['city'] ?? '';
                    $state = $shippingAddress['state'] ?? '';
                    $zip = $shippingAddress['zip_code'] ?? ($shippingAddress['zip'] ?? ($shippingAddress['postal_code'] ?? ''));
                    $country = $shippingAddress['country'] ?? 'United States';
                    $shippingAddressFormatted = trim("{$street}, {$city}, {$state} {$zip}, {$country}", " ,");
                } else {
                    $shippingAddressFormatted = (string) $shippingAddress;
                }
            }

            // Generate order number in format #RW-YYYYMMDD-XXXX
            $orderNumber = 'RW-' . date('Ymd') . '-' . mt_rand(1000, 9999);

            $paymentMethod = $isPickup ? 'loyalty_points' : (string) ($options['payment_method'] ?? 'card');

            // If user pays shipping with wallet, debit their wallet balance
            if (!$isPickup && $paymentMethod === 'wallet' && $shippingFee > 0) {
                $userWalletBal = (float) ($user->wallet_balance ?? 0.00);
                if ($userWalletBal < $shippingFee) {
                    throw new \RuntimeException("Insufficient wallet balance ($" . number_format($userWalletBal, 2) . ") to cover the shipping fee of $" . number_format($shippingFee, 2));
                }
                \App\Services\WalletService::adjustWallet(
                    (int) $user->id,
                    $shippingFee,
                    'debit',
                    "Shipping fee for reward redemption order #{$orderNumber}",
                    $orderNumber
                );
            }

            $order = \App\Models\EcommerceOrder::create([
                'order_number' => $orderNumber,
                'user_id' => $user->id,
                'customer_name' => $user->name ?? 'Valued Customer',
                'customer_email' => $user->email,
                'status' => 'confirmed',
                'order_date' => now(),
                'subtotal' => 0.00,
                'shipping_amount' => $shippingFee,
                'tax_amount' => 0.00,
                'total_amount' => $totalPaid,
                'payment_method' => $paymentMethod,
                'payment_status' => $isPickup ? 'free' : 'paid',
                'shipping_method' => $isPickup ? 'store_pickup' : 'standard_shipping',
                'shipping_address' => $shippingAddressFormatted ?: ($shippingAddress ? json_encode($shippingAddress) : null),
                'pickup_location_id' => $pickupLocationId,
                'loyalty_points_redeemed' => $totalPoints,
                'metadata' => [
                    'source' => 'loyalty_reward_redemption',
                    'delivery_method' => $deliveryMethod,
                    'pickup_location' => $pickupLocation,
                    'shipping_address' => $shippingAddress,
                    'payment_method' => $paymentMethod,
                    'card_last_four' => $paymentMethod === 'wallet' ? 'wallet' : ($options['card_last_four'] ?? '3456'),
                    'items_count' => count($items),
                ],
            ]);

            // Create order items
            foreach ($items as $it) {
                $productId = $it['product_id'] ?? ($it['id'] ?? null);
                $pName = (string) ($it['name'] ?? 'Reward Product');
                $pQty = max(1, (int) ($it['quantity'] ?? 1));
                $pPrice = (float) ($it['price'] ?? 0);
                $pSku = $it['sku'] ?? ('RWD-' . ($productId ?: mt_rand(100, 999)));

                \App\Models\EcommerceOrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => is_numeric($productId) ? (int) $productId : null,
                    'product_name' => $pName,
                    'product_sku' => $pSku,
                    'quantity' => $pQty,
                    'unit_price' => 0.00,
                    'total_price' => 0.00,
                    'product_options' => [
                        'points_per_unit' => (int) ($it['points'] ?? 0),
                        'total_points' => ((int) ($it['points'] ?? 0)) * $pQty,
                        'original_price' => $pPrice,
                        'imageSrc' => $it['imageSrc'] ?? ($it['image_path'] ?? null),
                    ],
                ]);
            }

            $primaryItem = $items[0] ?? [];
            $primaryName = count($items) === 1
                ? ($primaryItem['name'] ?? 'Reward Product')
                : (count($items) . ' Reward Products (Bundle)');

            $tx = Tx::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'transaction_type' => 'redeemed',
                'points' => -$totalPoints,
                'remaining_points' => (int) $balance->available_points,
                'dollar_value' => round($totalPoints * (float) ($s['points_to_dollar_ratio'] ?? 0.01), 2),
                'status' => Tx::STATUS_REDEEMED,
                'reason' => "Reward Redeemed: {$primaryName}",
                'reason_details' => "Redeemed {$totalPoints} points for order #{$orderNumber}",
                'reference_type' => 'reward_order',
                'reference_id' => (string) $order->id,
                'reference_date' => now()->toDateString(),
            ]);

            $user->loyalty_points = (int) $balance->available_points;
            $user->save();

            self::syncCentralAuth($user, -$totalPoints, 'redeemed', "Redeemed {$totalPoints} points for order #{$orderNumber}");

            $totalQuantity = array_sum(array_map(fn ($it) => max(1, (int) ($it['quantity'] ?? 1)), $items));

            return [
                'order_id' => $order->id,
                'order_number' => '#' . $orderNumber,
                'customer_email' => $user->email,
                'reward_name' => $primaryName,
                'items' => $items,
                'item_image' => $primaryItem['imageSrc'] ?? ($primaryItem['image_path'] ?? null),
                'points_spent' => $totalPoints,
                'total_points' => $totalPoints,
                'total_quantity' => $totalQuantity,
                'quantity_label' => $totalQuantity . ($totalQuantity === 1 ? ' Item' : ' Items'),
                'delivery_method' => $isPickup ? 'Store Pickup' : 'Ship to Address',
                'delivery_method_key' => $deliveryMethod,
                'shipping_fee' => $shippingFee,
                'shipping_fee_formatted' => $shippingFee > 0 ? ('$' . number_format($shippingFee, 2)) : 'Free',
                'total_paid' => $totalPaid,
                'total_paid_formatted' => $totalPaid > 0 ? ('$' . number_format($totalPaid, 2)) : '$0.00',
                'status' => 'Confirmed',
                'status_label' => 'Confirmed',
                'shipping_address' => $shippingAddress,
                'pickup_location' => $pickupLocation,
                'remaining_points' => (int) $balance->available_points,
                'transaction_id' => $tx->id,
            ];
        });
    }

    /** Once-per-day check-in bonus. Amount comes from the admin activity settings, never from the client. */
    public static function claimDailyCheckin(User $user): array
    {
        self::$lastError = null;
        $act = self::activity('daily_checkin', $user);
        if (!self::isEligibleUser($user)) {
            return ['ok' => false, 'message' => 'Your account is not eligible for the rewards program.'];
        }
        if (!$act['enabled'] || $act['points'] <= 0) {
            return ['ok' => false, 'message' => 'Daily check-in is not available right now.'];
        }

        $today = Carbon::now()->toDateString();
        $ok = self::awardBonus($user->id, 'daily_checkin', (int) $act['points'], 'Daily Check-in Bonus', 'daily_checkin', $today);
        if (!$ok) {
            $already = self::$lastError === 'Reward already granted.';

            return ['ok' => false, 'already' => $already, 'message' => $already ? 'You have already checked in today. Come back tomorrow!' : (self::$lastError ?: 'Unable to check in right now.')];
        }

        return ['ok' => true, 'points' => (int) $act['points'], 'message' => "You earned {$act['points']} points!"];
    }

    /** Top members by lifetime points (names are shortened for privacy) plus the caller's own rank. */
    public static function leaderboard(User $user, int $limit = 10): array
    {
        $mine = self::getOrCreateBalance($user);
        $rows = CustomerPointsBalance::query()
            ->where('lifetime_earned', '>', 0)
            ->orderByDesc('lifetime_earned')->orderBy('user_id')
            ->limit($limit)->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        $short = function (?User $u): string {
            if (!$u) {
                return 'Member';
            }
            $name = trim((string) ($u->business_name ?: $u->name));
            $parts = preg_split('/\s+/', $name) ?: [];
            if (count($parts) <= 1) {
                return $name ?: 'Member';
            }

            return $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.';
        };

        $entries = [];
        foreach ($rows as $i => $row) {
            $u = $users->get($row->user_id);
            $entries[] = [
                'rank' => $i + 1,
                'name' => $short($u),
                'points' => (int) $row->lifetime_earned,
                'tier' => self::tierFor((int) $row->lifetime_earned, $u ? self::isBusinessUser($u) : false)['name'],
                'is_you' => (int) $row->user_id === (int) $user->id,
            ];
        }

        $myRank = (int) CustomerPointsBalance::where('lifetime_earned', '>', (int) $mine->lifetime_earned)->count() + 1;

        return [
            'entries' => $entries,
            'my_rank' => (int) $mine->lifetime_earned > 0 ? $myRank : null,
            'my_points' => (int) $mine->lifetime_earned,
            'my_tier' => self::tierFor((int) $mine->lifetime_earned, self::isBusinessUser($user))['name'],
            'my_name' => $short($user),
        ];
    }

    /** Resolve a clean frontend-loadable image URL for a product in the loyalty reward catalog. */
    protected static function resolveProductImageUrl(\App\Models\Product $product): string
    {
        $images = is_array($product->images) ? array_values(array_filter($product->images)) : [];
        $first = isset($images[0]) && is_string($images[0]) ? trim($images[0]) : '';
        if ($first !== '' && !str_starts_with($first, 'blob:')) {
            if (preg_match('/^(https?:|data:|\/)/i', $first)) {
                return $first;
            }
            if (str_starts_with($first, 'products/demo/')) {
                return '/' . ltrim($first, '/');
            }
            $baseAppUrl = request() ? request()->root() : config('app.url');
            if (!$baseAppUrl || $baseAppUrl === 'http://localhost') {
                $baseAppUrl = config('app.url', app()->environment('production') ? 'https://api.mecarviembroidery.com' : 'http://localhost:8000');
            }
            return rtrim((string) $baseAppUrl, '/') . '/storage/' . ltrim($first, '/');
        }

        $nameLower = mb_strtolower((string) $product->name);
        if (str_contains($nameLower, 'cap') || str_contains($nameLower, 'hat') || str_contains($nameLower, 'snapback')) {
            return '/images/loyalty/product_cap.png';
        }
        if (str_contains($nameLower, 'patch')) {
            return '/images/loyalty/product_patch.png';
        }
        if (str_contains($nameLower, 'bag') || str_contains($nameLower, 'tote') || str_contains($nameLower, 'duffel')) {
            return '/images/loyalty/product_duffel.png';
        }
        if (str_contains($nameLower, 'thread') || str_contains($nameLower, 'file') || str_contains($nameLower, 'vector') || str_contains($nameLower, 'design')) {
            return '/images/loyalty/product_thread.png';
        }

        return '/images/loyalty/product_polo.png';
    }

    /** Three-stage tier journey (current / next / goal) for the user panel. */
    protected static function tierJourney(int $lifetime, bool $business): array
    {
        $s = self::getSettings();
        $tiers = array_values(array_filter($business ? $s['business_tiers'] : $s['tiers'], fn ($t) => $t['active']));
        if (empty($tiers)) {
            return [];
        }
        $curIdx = 0;
        foreach ($tiers as $i => $t) {
            if ($lifetime >= $t['min_points']) {
                $curIdx = $i;
            }
        }
        $count = count($tiers);
        $start = $curIdx >= 2 ? max(0, min($curIdx - 1, $count - 3)) : 0;
        $nodes = [];
        foreach (array_slice($tiers, $start, 3, true) as $i => $t) {
            $nodes[] = [
                'id' => $t['id'],
                'name' => $t['name'],
                'min_points' => $t['min_points'],
                'color' => $t['color'] ?? 'slate',
                'perk_badge' => $t['perk_badge'] ?? '',
                'state' => $i < $curIdx ? 'completed' : ($i === $curIdx ? 'current' : ($i === $curIdx + 1 ? 'next' : 'goal')),
            ];
        }

        return $nodes;
    }

    public static function getSummary(User|int $user): array
    {
        $model = $user instanceof User ? $user : User::findOrFail($user);
        $userId = $model->id;

        // Auto-check and award Birthday Bonus if today is the customer's birthday (once per year)
        try {
            $dobRaw = $model->dob ?? ($model->date_of_birth ?? ($model->birthday ?? null));
            if ($dobRaw) {
                $dob = Carbon::parse($dobRaw);
                $now = Carbon::now();
                if ($dob->month === $now->month && $dob->day === $now->day) {
                    $bdayAct = self::activity('birthday', $model);
                    if ($bdayAct['enabled'] && $bdayAct['points'] > 0) {
                        self::awardActivity(
                            $userId,
                            'birthday',
                            'birthday_bonus',
                            "Happy Birthday! Here are {$bdayAct['points']} bonus points from Mecarvi Embroidery.",
                            'birthday',
                            $now->year . '-' . $userId
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore invalid dob format
        }

        $balance = self::getOrCreateBalance($model);
        $s = self::getSettings();
        $ratio = $s['points_to_dollar_ratio'];
        $business = self::isBusinessUser($model);

        $available = max(0, (int) $balance->available_points);
        $pending = (int) $balance->pending_points;
        $lifetime = (int) $balance->lifetime_earned;

        $tier = self::tierFor($available, $business);
        $next = self::nextTierFor($available, $business);
        $progress = 100;
        if ($next) {
            $span = max(1, $next['min_points'] - $tier['min_points']);
            $progress = round(min(100, max(0, (($available - $tier['min_points']) / $span) * 100)), 2);
        }

        $expiringLots = Tx::where('user_id', $userId)->whereIn('status', [Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])->where('points', '>', 0)->where('remaining_points', '>', 0)
            ->whereNotNull('expiration_date')->where('expiration_date', '<=', Carbon::now()->addDays(max(30, $s['expiration_reminder_days'])))->orderBy('expiration_date')->get();
        $expiringPoints = (int) $expiringLots->sum('remaining_points');

        $earliestLot = $expiringLots->first() ?: Tx::where('user_id', $userId)
            ->whereIn('status', [Tx::STATUS_AVAILABLE, Tx::STATUS_MANUAL_ADDED])
            ->where('points', '>', 0)
            ->where('remaining_points', '>', 0)
            ->whereNotNull('expiration_date')
            ->orderBy('expiration_date')
            ->first();

        $todayStr = Carbon::now()->toDateString();
        $checkedInToday = Tx::where('user_id', $userId)
            ->where('reference_type', 'daily_checkin')
            ->where('reference_id', $todayStr)
            ->whereNotIn('status', [Tx::STATUS_REVERSED])
            ->exists();

        $activeTiers = array_values(array_filter($business ? $s['business_tiers'] : $s['tiers'], fn ($t) => $t['active']));

        $txs = Tx::with('order:id,order_number')->where('user_id', $userId)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $payload = $txs->map(fn ($t) => self::transactionPayload($t))->values();

        $allRewardProducts = \App\Models\Product::where('is_active', true)
            ->whereNotNull('loyalty_points_price')
            ->where('loyalty_points_price', '>', 0)
            ->orderByDesc('updated_at')
            ->orderBy('loyalty_points_price', 'asc')
            ->get()
            ->map(function (\App\Models\Product $p) use ($available) {
                $effectivePrice = ($p->sale_price !== null && (float) $p->sale_price > 0 && (float) $p->sale_price < (float) $p->price)
                    ? (float) $p->sale_price
                    : (float) $p->price;

                return [
                    'id' => (string) $p->id,
                    'product_id' => (int) $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'price' => round($effectivePrice, 2),
                    'original_price' => round((float) $p->price, 2),
                    'points' => (int) $p->loyalty_points_price,
                    'imageSrc' => self::resolveProductImageUrl($p),
                    'can_redeem' => $available >= (int) $p->loyalty_points_price,
                ];
            })
            ->values()
            ->all();

        $eligibleRewardCatalog = array_values(array_filter(
            $allRewardProducts,
            fn (array $item) => $item['points'] <= $available
        ));

        return [
            'available_points' => $available,
            'points' => $available,
            'status_points' => $available,
            'redeemable_points' => $available,
            'pending_points' => $pending,
            'redeemed_points' => (int) $balance->redeemed_points,
            'points_redeemed' => (int) $balance->redeemed_points,
            'expired_points' => (int) $balance->expired_points,
            'points_expired' => (int) $balance->expired_points,
            'expiring_points' => $expiringPoints,
            'next_expiry_date' => optional($expiringLots->first()?->expiration_date)->toIso8601String(),
            'next_expiry_formatted' => $expiringLots->first()?->expiration_date
                ? Carbon::parse($expiringLots->first()->expiration_date)->format('M j, Y')
                : ($earliestLot?->expiration_date ? Carbon::parse($earliestLot->expiration_date)->format('M j, Y') : 'Dec 31, ' . Carbon::now()->year),
            'valid_through' => 'Dec 31, ' . Carbon::now()->addYear()->year,
            'checked_in_today' => $checkedInToday,
            'is_business' => $business,
            'active_tiers' => $activeTiers,
            'earning_rates' => [
                'points_per_dollar' => $s['points_per_dollar'],
                'review' => (int) (self::activity('review', $model)['points'] ?? 25),
                'referral' => (int) (self::activity('referral', $model)['points'] ?? 500),
                'daily_checkin' => (int) (self::activity('daily_checkin', $model)['points'] ?? 25),
                'signup' => (int) (self::activity('signup', $model)['points'] ?? 100),
                'first_order' => (int) (self::activity('first_order', $model)['points'] ?? 250),
                'birthday' => (int) (self::activity('birthday', $model)['points'] ?? 100),
                'project_photos' => (int) (self::activity('project_photos', $model)['points'] ?? 50),
            ],
            'reward_catalog' => $eligibleRewardCatalog,
            'all_reward_catalog' => $allRewardProducts,
            'lifetime_earned' => $lifetime,
            'lifetime_points' => $lifetime,
            'dollar_value' => '$' . number_format($available * $ratio, 2, '.', ''),
            'dollar_value_number' => round($available * $ratio, 2),
            'pending_dollar_value' => round($pending * $ratio, 2),
            'lifetime_dollar_value' => '$' . number_format($lifetime * $ratio, 2, '.', ''),
            'current_tier' => $tier['name'],
            'tier' => $tier['name'],
            'tier_color' => $tier['color'] ?? 'slate',
            'tier_bonus_percent' => $tier['bonus_percent'],
            'tier_perk' => $tier['perk_badge'] ?? '',
            'next_tier' => $next['name'] ?? null,
            'target_points' => $next['min_points'] ?? $available,
            'progress_percent' => $progress,
            'tier_journey' => self::tierJourney($available, $business),
            'is_locked' => (bool) $balance->is_locked,
            'locked_reason' => $balance->locked_reason,
            'needs_review' => (bool) $balance->needs_review,
            'program' => self::publicSettings(),
            'transactions' => $payload,
            'history' => $payload,
        ];
    }
}
