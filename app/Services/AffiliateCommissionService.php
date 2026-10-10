<?php

namespace App\Services;

use App\Models\EcommerceAffiliate;
use App\Models\EcommerceReferral;
use App\Models\EcommerceReferralCommission;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central place for affiliate rules: settings, commission creation (pending),
 * admin approval / decline, wallet release, fraud checks and first-purchase vs
 * recurring logic. Everything is driven by the dynamic affiliate settings.
 */
class AffiliateCommissionService
{
    public const SOURCE_ORDER = 'order';
    public const SOURCE_MEMBERSHIP = 'membership';
    public const SOURCE_GIFT_CARD = 'gift_card';

    public static function defaults(?SiteSetting $settings = null): array
    {
        return [
            'program_active' => true,
            'allow_registrations' => true,

            'registration_type' => 'Open to Everyone',
            'require_admin_approval' => true,
            'require_tax_info' => true,
            'require_payout_method' => true,

            'referral_attribution' => 'Last Affiliate Click',
            'cookie_duration' => 30,

            'commission_eligible' => 'After Order Is Completed',
            'holding_period' => 30,
            'cancelled_orders_action' => 'Automatically Void Commission',
            'partially_refunded_action' => 'Adjust Commission Proportionally',

            'earn_order' => true,
            'earn_order_rate_type' => 'Percentage',
            'referral_commission_percentage' => $settings ? (float) ($settings->referral_commission_percentage ?? 10.00) : 10.00,

            'earn_sign_referrer' => true,
            'earn_sign_referrer_rate_type' => 'Flat Amount ($)',
            'referral_reward_referrer' => $settings ? (float) ($settings->referral_reward_referrer ?? 10.00) : 10.00,

            'earn_sign_referee' => true,
            'earn_sign_referee_rate_type' => 'Flat Amount ($)',
            'referral_reward_referee' => $settings ? (float) ($settings->referral_reward_referee ?? 5.00) : 5.00,

            'earn_membership' => true,
            'earn_membership_rate_type' => 'Percentage',
            'earn_membership_value' => 10.00,

            'earn_gift_card' => true,
            'earn_gift_card_rate_type' => 'Percentage',
            'earn_gift_card_value' => 10.00,

            // First purchase only by default. When enabled, the referrer keeps earning on
            // every purchase of the referred user for `recurring_duration_days` days
            // counted from that user's first purchase.
            'recurring_commissions' => false,
            'recurring_duration_days' => 30,

            'max_commission' => true,
            'max_commission_amount' => 500.00,

            'payout_methods' => [
                'paypal' => true,
                'bank_transfer' => true,
                'mecarvi_wallet' => true,
                'cash_app' => true,
            ],
            'require_min_payout' => true,
            'min_payout_amount' => 50.00,
            'payout_processing_mode' => 'Manual Approval',
            'payout_schedule' => 'Monthly',
            'payout_day' => '1st of each month',

            'detect_duplicate' => true,
            'block_self_referrals' => true,
            'monitor_suspicious' => true,
            'require_unique_ip' => false,
            'auto_disable_fraud' => true,
            'high_commission_review' => true,
            'review_threshold' => 500.00,
        ];
    }

    public static function settings(): array
    {
        $row = SiteSetting::first();
        $stored = [];
        if ($row && !empty($row->affiliate_settings)) {
            $stored = is_array($row->affiliate_settings)
                ? $row->affiliate_settings
                : (json_decode($row->affiliate_settings, true) ?: []);
        }

        $merged = array_merge(self::defaults($row), $stored);

        // Legacy columns remain the source of truth until the JSON blob overrides them.
        if ($row) {
            foreach (['referral_commission_percentage', 'referral_reward_referrer', 'referral_reward_referee'] as $key) {
                if ($row->{$key} !== null && !array_key_exists($key, $stored)) {
                    $merged[$key] = (float) $row->{$key};
                }
            }
        }

        return $merged;
    }

    public static function flag(array $settings, string $key): bool
    {
        return filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Unique, hard-to-guess referral code. */
    public static function generateCode(?User $user = null): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $user->name ?? 'REF') ?: 'REF', 0, 3));
        do {
            $code = str_pad($prefix, 3, 'X') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        } while (EcommerceAffiliate::where('affiliate_code', $code)->exists());

        return $code;
    }

    /** Every user gets exactly one referral code / link. */
    public static function ensureAffiliateFor(User $user): EcommerceAffiliate
    {
        return EcommerceAffiliate::firstOrCreate(
            ['user_id' => $user->id],
            [
                'affiliate_code' => self::generateCode($user),
                'total_earnings' => 0,
                'total_referrals' => 0,
                'status' => 'Active',
            ]
        );
    }

    /**
     * Record a pending commission for a paid purchase made by $buyer.
     * Returns the commission, or null when nothing is earned.
     */
    public static function record(
        User $buyer,
        string $sourceType,
        string $sourceRef,
        float $amount,
        ?int $orderId = null,
        ?string $ip = null
    ): ?EcommerceReferralCommission {
        try {
            return DB::transaction(function () use ($buyer, $sourceType, $sourceRef, $amount, $orderId, $ip) {
                $s = self::settings();

                if (!self::flag($s, 'program_active') || $amount <= 0) {
                    return null;
                }

                $earnKey = ['order' => 'earn_order', 'membership' => 'earn_membership', 'gift_card' => 'earn_gift_card'][$sourceType] ?? null;
                if (!$earnKey || !self::flag($s, $earnKey)) {
                    return null;
                }

                // detect_duplicate: never pay twice for the same purchase.
                if (EcommerceReferralCommission::where('source_type', $sourceType)->where('source_ref', $sourceRef)->exists()) {
                    return null;
                }

                $referral = EcommerceReferral::where('referred_id', $buyer->id)
                    ->orWhere(function ($q) use ($buyer) {
                        $q->whereNull('referred_id')->where('referred_email', strtolower(trim($buyer->email)));
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$referral || !$referral->referrer_id) {
                    return null;
                }
                if (is_null($referral->referred_id)) {
                    $referral->update(['referred_id' => $buyer->id]);
                }

                $affiliate = EcommerceAffiliate::where('user_id', $referral->referrer_id)->first();
                if ($affiliate && $affiliate->status !== 'Active') {
                    return null;
                }

                // First purchase vs. recurring window.
                if ($referral->first_purchase_at) {
                    if (!self::flag($s, 'recurring_commissions')) {
                        return null;
                    }
                    $days = max(0, (int) ($s['recurring_duration_days'] ?? 30));
                    if ($referral->first_purchase_at->copy()->addDays($days)->isPast()) {
                        return null;
                    }
                }

                [$rateType, $rateValue] = match ($sourceType) {
                    'membership' => [$s['earn_membership_rate_type'], (float) $s['earn_membership_value']],
                    'gift_card' => [$s['earn_gift_card_rate_type'], (float) $s['earn_gift_card_value']],
                    default => [$s['earn_order_rate_type'], (float) $s['referral_commission_percentage']],
                };
                $isPercent = stripos((string) $rateType, 'percent') !== false;
                $commission = $isPercent ? round($amount * ($rateValue / 100), 2) : round($rateValue, 2);

                if (self::flag($s, 'max_commission') && (float) $s['max_commission_amount'] > 0) {
                    $commission = min($commission, (float) $s['max_commission_amount']);
                }
                if ($commission <= 0) {
                    return null;
                }

                $base = [
                    'referrer_id' => $referral->referrer_id,
                    'referred_id' => $buyer->id,
                    'order_id' => $orderId,
                    'source_type' => $sourceType,
                    'source_ref' => $sourceRef,
                    'order_amount' => $amount,
                    'commission_percentage' => $isPercent ? $rateValue : 0,
                    'commission_amount' => $commission,
                ];

                // ---- Fraud & security ----
                $fraud = null;
                if (self::flag($s, 'block_self_referrals') && (int) $referral->referrer_id === (int) $buyer->id) {
                    $fraud = 'Self referral blocked';
                } elseif (self::flag($s, 'require_unique_ip') && $referral->ip_address
                    && EcommerceReferral::where('referrer_id', $referral->referrer_id)
                        ->where('id', '!=', $referral->id)
                        ->where('ip_address', $referral->ip_address)->exists()) {
                    $fraud = 'Multiple referrals from the same IP address';
                } elseif (self::flag($s, 'monitor_suspicious')
                    && EcommerceReferralCommission::where('referrer_id', $referral->referrer_id)
                        ->where('created_at', '>=', now()->subDay())->count() >= 20) {
                    $fraud = 'Suspicious activity: too many commissions in 24 hours';
                }

                if ($fraud) {
                    Log::warning("Affiliate fraud check: {$fraud} (referrer {$referral->referrer_id}, {$sourceType} {$sourceRef})");
                    if (self::flag($s, 'auto_disable_fraud') && $affiliate) {
                        $affiliate->update(['status' => 'Inactive']);
                    }

                    return EcommerceReferralCommission::create($base + [
                        'status' => 'declined',
                        'declined_at' => now(),
                        'decline_reason' => $fraud,
                        'flagged' => true,
                        'flag_reason' => $fraud,
                    ]);
                }

                $flagged = self::flag($s, 'high_commission_review') && $commission >= (float) $s['review_threshold'];

                $record = EcommerceReferralCommission::create($base + [
                    'status' => 'pending', // admin must approve or decline
                    'payout_at' => null,
                    'flagged' => $flagged,
                    'flag_reason' => $flagged ? 'High commission requires review' : null,
                ]);

                if (!$referral->first_purchase_at) {
                    $referral->update(['first_purchase_at' => now()]);
                }

                return $record;
            });
        } catch (\Throwable $e) {
            Log::error('Affiliate commission failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Admin approves. Credits immediately unless a holding period is configured. */
    public static function approve(EcommerceReferralCommission $c, ?int $adminId = null, bool $releaseNow = false): EcommerceReferralCommission
    {
        if (!in_array($c->status, ['pending'], true)) {
            throw new \DomainException("Only pending commissions can be approved (current status: {$c->status}).");
        }

        $s = self::settings();
        $hold = (int) ($s['holding_period'] ?? 0);
        $eligible = $s['commission_eligible'] ?? 'After Order Is Completed';
        if ($eligible === 'After 14 Days Return Window') {
            $hold = max($hold, 14);
        }

        $c->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $adminId,
            'payout_at' => ($hold > 0 && !$releaseNow) ? now()->addDays($hold) : now(),
        ]);

        if ($hold <= 0 || $releaseNow) {
            self::credit($c);
        }

        return $c->fresh();
    }

    public static function decline(EcommerceReferralCommission $c, ?int $adminId = null, ?string $reason = null): EcommerceReferralCommission
    {
        if (!in_array($c->status, ['pending', 'approved'], true)) {
            throw new \DomainException("Commission can no longer be declined (current status: {$c->status}).");
        }

        $c->update([
            'status' => 'declined',
            'declined_at' => now(),
            'declined_by' => $adminId,
            'decline_reason' => $reason,
        ]);

        return $c->fresh();
    }

    /** Move an approved commission into the referrer's wallet (idempotent). */
    public static function credit(EcommerceReferralCommission $c): bool
    {
        return DB::transaction(function () use ($c) {
            $c = EcommerceReferralCommission::lockForUpdate()->find($c->id);
            if (!$c || !in_array($c->status, ['approved', 'pending'], true)) {
                return false;
            }

            $referrer = User::lockForUpdate()->find($c->referrer_id);
            if ($referrer && $c->commission_amount > 0) {
                WalletService::adjustWallet(
                    $referrer->id,
                    $c->commission_amount,
                    'Affiliate Earned',
                    'Referral commission (' . str_replace('_', ' ', $c->source_type) . ') ' . ($c->order?->order_number ?? $c->source_ref),
                    $c->order_id
                );
                $referrer->affiliate?->increment('total_earnings', $c->commission_amount);
            }

            $c->update([
                'status' => 'completed',
                'approved_at' => $c->approved_at ?? now(),
                'payout_at' => now(),
            ]);

            return true;
        });
    }

    /** Void commissions not yet credited (cancelled / refunded order). */
    public static function voidForOrder(int $orderId, string $reason): int
    {
        $s = self::settings();
        $action = $s['cancelled_orders_action'] ?? 'Automatically Void Commission';
        if (stripos((string) $action, 'manual') !== false) {
            return EcommerceReferralCommission::where('order_id', $orderId)
                ->whereIn('status', ['pending', 'approved'])
                ->update([
                    'flagged' => true,
                    'flag_reason' => $reason . ' (Requires manual review)',
                ]);
        }

        return EcommerceReferralCommission::where('order_id', $orderId)
            ->whereIn('status', ['pending', 'approved'])
            ->update(['status' => 'void', 'decline_reason' => $reason, 'declined_at' => now()]);
    }
}
