<?php

namespace App\Services;

use App\Models\User;
use App\Models\SiteSetting;
use App\Models\EcommerceOrder;
use App\Models\EcommerceLoyaltyTransaction;
use App\Models\CustomerPointsBalance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LoyaltyService
{
    /**
     * Retrieve normalized global loyalty settings.
     */
    public static function getSettings(): array
    {
        $defaults = [
            'enabled' => true,
            'program_name' => 'Mecarvi Gold Rewards',
            'program_description' => 'Earn loyalty points on every custom embroidery order and selected account activities.',
            'points_per_dollar' => 1.0,
            'points_to_dollar_ratio' => 0.01, // 100 points = $1.00
            'minimum_redeem_points' => 500,
            'max_points_per_order' => 2000,
            'max_redeem_percent' => 25.0, // max 25% of subtotal
            'min_order_amount' => 25.00,
            'allow_with_coupons' => true,
            'allow_with_gift_cards' => true,
            'earn_points_on_gift_cards' => false,
            'include_tax_in_calculation' => false,
            'include_shipping_in_calculation' => false,
            'expiry_days' => 365, // 12 months
            'signup_bonus' => 100,
            'first_order_bonus' => 250,
            'review_bonus' => 100,
            'referral_bonus' => 500,
            'birthday_bonus' => 250,
            'membership_bonus' => 1000,
            'tiers' => [
                ['name' => 'Stellar Tier', 'min_points' => 0, 'max_points' => 19999, 'bonus_percent' => 0],
                ['name' => 'Diamond Tier', 'min_points' => 20000, 'max_points' => 34999, 'bonus_percent' => 10],
                ['name' => 'Signature Tier', 'min_points' => 35000, 'max_points' => 99999999, 'bonus_percent' => 20],
            ],
        ];

        try {
            $settings = SiteSetting::first();
            if ($settings && $settings->loyalty_settings) {
                $raw = is_array($settings->loyalty_settings) 
                    ? $settings->loyalty_settings 
                    : json_decode($settings->loyalty_settings, true);
                if (is_array($raw)) {
                    $defaults = array_merge($defaults, $raw);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LoyaltyService::getSettings notice: ' . $e->getMessage());
        }

        // Cast key values
        $defaults['enabled'] = filter_var($defaults['enabled'], FILTER_VALIDATE_BOOLEAN);
        $defaults['points_per_dollar'] = (float)($defaults['points_per_dollar'] ?: 1.0);
        $defaults['points_to_dollar_ratio'] = (float)($defaults['points_to_dollar_ratio'] ?: 0.01);
        $defaults['minimum_redeem_points'] = (int)($defaults['minimum_redeem_points'] ?: 500);
        $defaults['max_points_per_order'] = (int)($defaults['max_points_per_order'] ?: 2000);
        $defaults['max_redeem_percent'] = (float)($defaults['max_redeem_percent'] ?: 25.0);
        $defaults['min_order_amount'] = (float)($defaults['min_order_amount'] ?: 25.0);
        $defaults['allow_with_coupons'] = filter_var($defaults['allow_with_coupons'], FILTER_VALIDATE_BOOLEAN);
        $defaults['allow_with_gift_cards'] = filter_var($defaults['allow_with_gift_cards'], FILTER_VALIDATE_BOOLEAN);
        $defaults['earn_points_on_gift_cards'] = filter_var($defaults['earn_points_on_gift_cards'], FILTER_VALIDATE_BOOLEAN);
        $defaults['include_tax_in_calculation'] = filter_var($defaults['include_tax_in_calculation'], FILTER_VALIDATE_BOOLEAN);
        $defaults['include_shipping_in_calculation'] = filter_var($defaults['include_shipping_in_calculation'], FILTER_VALIDATE_BOOLEAN);
        $defaults['expiry_days'] = (int)($defaults['expiry_days'] ?: 365);

        return $defaults;
    }

    /**
     * Get or create customer points balance record for a user.
     */
    public static function getOrCreateBalance(User|int $user): CustomerPointsBalance
    {
        $userId = $user instanceof User ? $user->id : (int)$user;
        $userModel = $user instanceof User ? $user : User::find($userId);

        $balance = CustomerPointsBalance::firstOrCreate(
            ['user_id' => $userId],
            [
                'available_points' => (int)($userModel?->loyalty_points ?? 0),
                'pending_points' => 0,
                'redeemed_points' => 0,
                'expired_points' => 0,
                'lifetime_earned' => (int)($userModel?->loyalty_points ?? 0),
                'is_locked' => false,
            ]
        );

        return $balance;
    }

    /**
     * Sync points change with Central Auth API in non-blocking fashion.
     */
    public static function syncCentralAuth(User $user, int $points, string $type, string $reason): void
    {
        try {
            $centralUrl = rtrim((string) config('services.central_auth.url'), '/');
            $secret = (string) config('services.internal_notifications.secret');

            if (!empty($centralUrl)) {
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
                    Log::warning("LoyaltyService Central API sync non-fatal status: " . $response->status());
                }
            }
        } catch (\Throwable $centralEx) {
            Log::warning("LoyaltyService Central API sync notice: " . $centralEx->getMessage());
        }
    }

    /**
     * Award pending loyalty points for an embroidery order during checkout.
     * Points remain in 'pending' status until order status becomes 'completed' or 'delivered'.
     */
    public static function awardPendingOrderPoints(EcommerceOrder $order): ?EcommerceLoyaltyTransaction
    {
        try {
            if (!$order->user_id) {
                return null;
            }

            $user = User::find($order->user_id);
            if (!$user) {
                return null;
            }

            $settings = self::getSettings();
            if (!$settings['enabled']) {
                return null;
            }

            // Calculate eligible amount: subtotal minus discounts, excluding tax & shipping unless configured
            $eligibleAmount = (float)$order->subtotal - (float)($order->discount_amount ?? 0);
            if ($settings['include_shipping_in_calculation']) {
                $eligibleAmount += (float)($order->shipping_amount ?? 0);
            }
            if ($settings['include_tax_in_calculation']) {
                $eligibleAmount += (float)($order->tax_amount ?? 0);
            }
            $eligibleAmount = max(0.00, $eligibleAmount);

            if ($eligibleAmount < $settings['min_order_amount'] || $eligibleAmount <= 0) {
                return null;
            }

            // Points based on earning rate
            $pointsPerDollar = $settings['points_per_dollar'];
            $basePoints = $eligibleAmount * $pointsPerDollar;

            // Tier bonus calculation
            $balance = self::getOrCreateBalance($user);
            $tierBonusPercent = 0;
            foreach ($settings['tiers'] as $tier) {
                $minPts = (int)($tier['min_points'] ?? 0);
                $maxPts = (int)($tier['max_points'] ?? PHP_INT_MAX);
                if ($balance->available_points >= $minPts && $balance->available_points <= $maxPts) {
                    $tierBonusPercent = (float)($tier['bonus_percent'] ?? 0);
                    break;
                }
            }

            $calculatedPoints = (int)round($basePoints * (1 + ($tierBonusPercent / 100)));

            // Check if max points per order is enforced
            if ($settings['max_points_per_order'] > 0) {
                $calculatedPoints = min($calculatedPoints, $settings['max_points_per_order']);
            }

            if ($calculatedPoints <= 0) {
                return null;
            }

            // Check first order bonus
            $firstOrderBonus = 0;
            $priorOrdersCount = EcommerceOrder::where('user_id', $user->id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', ['completed', 'delivered', 'confirmed', 'processing', 'in_production'])
                ->count();
            if ($priorOrdersCount === 0 && ($settings['first_order_bonus'] ?? 0) > 0) {
                $firstOrderBonus = (int)$settings['first_order_bonus'];
            }

            $totalPendingPoints = $calculatedPoints + $firstOrderBonus;

            $ratio = $settings['points_to_dollar_ratio'];
            $expiryDays = $settings['expiry_days'];
            $expirationDate = $expiryDays > 0 ? Carbon::now()->addDays($expiryDays) : null;

            // Create pending transaction record
            $transaction = EcommerceLoyaltyTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'transaction_type' => 'earned',
                'points' => $totalPendingPoints,
                'dollar_value' => round($totalPendingPoints * $ratio, 2),
                'status' => 'pending',
                'reason' => "Points pending for Order #{$order->order_number}" . ($firstOrderBonus > 0 ? " (includes +{$firstOrderBonus} first order bonus)" : ""),
                'reason_details' => "Order #{$order->order_number} pending completion/delivery",
                'reference_type' => 'order',
                'reference_id' => (string)$order->id,
                'reference_date' => now()->toDateString(),
                'expiration_date' => $expirationDate,
            ]);

            // Update pending balance
            $balance->pending_points += $totalPendingPoints;
            $balance->save();

            // Store in order record
            $order->loyalty_points_earned = $totalPendingPoints;
            $order->save();

            return $transaction;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::awardPendingOrderPoints error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Release pending points for an order when it transitions to 'completed' or 'delivered'.
     * Updates pending transaction status to 'available' without deleting the record.
     */
    public static function releasePendingPoints(EcommerceOrder $order): bool
    {
        try {
            if (!$order->user_id) {
                return false;
            }

            $user = User::find($order->user_id);
            if (!$user) {
                return false;
            }

            $pendingTransactions = EcommerceLoyaltyTransaction::where('order_id', $order->id)
                ->where('status', 'pending')
                ->get();

            if ($pendingTransactions->isEmpty()) {
                return false;
            }

            $balance = self::getOrCreateBalance($user);
            $totalPointsReleased = 0;

            foreach ($pendingTransactions as $tx) {
                $points = (int)$tx->points;
                if ($points > 0) {
                    $tx->update([
                        'status' => 'available',
                        'reason' => "Points earned on Order #{$order->order_number}",
                        'reason_details' => "Order #{$order->order_number} completed and delivered",
                    ]);

                    $totalPointsReleased += $points;
                }
            }

            if ($totalPointsReleased > 0) {
                $balance->pending_points = max(0, $balance->pending_points - $totalPointsReleased);
                $balance->available_points += $totalPointsReleased;
                $balance->lifetime_earned += $totalPointsReleased;
                $balance->save();

                // Keep users.loyalty_points in sync
                $user->loyalty_points = $balance->available_points;
                $user->save();

                // Sync with Central Auth
                self::syncCentralAuth($user, $totalPointsReleased, 'earned', "Points earned on Order #{$order->order_number}");
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::releasePendingPoints error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reverse earned points when an order is cancelled or refunded.
     * Supports full cancellation or proportional partial refund recalculation.
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

            $balance = self::getOrCreateBalance($user);
            $settings = self::getSettings();
            $ratio = $settings['points_to_dollar_ratio'];

            // 1. Handle pending points reversal
            $pendingTxns = EcommerceLoyaltyTransaction::where('order_id', $order->id)
                ->where('status', 'pending')
                ->get();

            foreach ($pendingTxns as $tx) {
                $pts = (int)$tx->points;
                $tx->update([
                    'status' => 'reversed',
                    'reason' => "Pending points reversed: {$reason}",
                ]);
                $balance->pending_points = max(0, $balance->pending_points - $pts);
            }

            // 2. Handle available points reversal (already completed/delivered orders)
            $availableTxns = EcommerceLoyaltyTransaction::where('order_id', $order->id)
                ->where('transaction_type', 'earned')
                ->where('status', 'available')
                ->get();

            if ($availableTxns->isNotEmpty()) {
                $totalEarnedPoints = (int)$availableTxns->sum('points');
                $orderTotal = (float)($order->subtotal ?: $order->total_amount);

                $isFullReversal = ($refundAmount === null) || ($refundAmount <= 0) || ($refundAmount >= $orderTotal);
                $pointsToReverse = $isFullReversal
                    ? $totalEarnedPoints
                    : (int)round($totalEarnedPoints * ($refundAmount / ($orderTotal ?: 1.00)));

                $pointsToReverse = min($totalEarnedPoints, max(0, $pointsToReverse));

                if ($pointsToReverse > 0) {
                    // Create reversal transaction record
                    EcommerceLoyaltyTransaction::create([
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'transaction_type' => 'reversed',
                        'points' => -$pointsToReverse,
                        'dollar_value' => round($pointsToReverse * $ratio, 2),
                        'status' => 'reversed',
                        'reason' => "Points reversed: {$reason}",
                        'reason_details' => "Reversed {$pointsToReverse} points for Order #{$order->order_number}",
                        'reference_type' => 'order_refund',
                        'reference_id' => (string)$order->id,
                        'reference_date' => now()->toDateString(),
                    ]);

                    // Deduct from available points (allows negative balance tracking if already spent)
                    $balance->available_points -= $pointsToReverse;
                    $balance->lifetime_earned = max(0, $balance->lifetime_earned - $pointsToReverse);

                    // Update existing earned transaction status if fully reversed
                    if ($isFullReversal) {
                        foreach ($availableTxns as $tx) {
                            $tx->update(['status' => 'reversed']);
                        }
                    }

                    $balance->save();

                    $user->loyalty_points = $balance->available_points;
                    $user->save();

                    self::syncCentralAuth($user, -$pointsToReverse, 'reversed', "Points reversed for Order #{$order->order_number}");
                }
            }

            $balance->save();
            return true;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::reverseOrderPoints error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Restore points that were redeemed during checkout if the order is cancelled.
     */
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

            $redeemedTxns = EcommerceLoyaltyTransaction::where('order_id', $order->id)
                ->where('transaction_type', 'redeemed')
                ->where('status', 'redeemed')
                ->get();

            $totalRedeemedPoints = (int)$redeemedTxns->sum(fn($t) => abs($t->points));
            if ($totalRedeemedPoints <= 0 && ($order->loyalty_points_redeemed ?? 0) > 0) {
                $totalRedeemedPoints = (int)$order->loyalty_points_redeemed;
            }

            if ($totalRedeemedPoints <= 0) {
                return false;
            }

            $balance = self::getOrCreateBalance($user);
            $settings = self::getSettings();
            $ratio = $settings['points_to_dollar_ratio'];

            // Mark existing redeemed transactions as reversed
            foreach ($redeemedTxns as $tx) {
                $tx->update(['status' => 'reversed']);
            }

            // Create restoration transaction
            EcommerceLoyaltyTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'transaction_type' => 'manual_added',
                'points' => $totalRedeemedPoints,
                'dollar_value' => round($totalRedeemedPoints * $ratio, 2),
                'status' => 'available',
                'reason' => $reason,
                'reason_details' => "Returned {$totalRedeemedPoints} redeemed points for Order #{$order->order_number}",
                'reference_type' => 'order_cancellation',
                'reference_id' => (string)$order->id,
                'reference_date' => now()->toDateString(),
            ]);

            // Restore balance
            $balance->available_points += $totalRedeemedPoints;
            $balance->redeemed_points = max(0, $balance->redeemed_points - $totalRedeemedPoints);
            $balance->save();

            $user->loyalty_points = $balance->available_points;
            $user->save();

            self::syncCentralAuth($user, $totalRedeemedPoints, 'manual_added', $reason);

            return true;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::restoreRedeemedPoints error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process points redemption during checkout.
     */
    public static function redeemPoints(User $user, int $points, EcommerceOrder $order): bool
    {
        try {
            if ($points <= 0) {
                return false;
            }

            $balance = self::getOrCreateBalance($user);
            $settings = self::getSettings();
            $ratio = $settings['points_to_dollar_ratio'];

            $dollarDiscount = round($points * $ratio, 2);

            // Create redemption transaction
            EcommerceLoyaltyTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'transaction_type' => 'redeemed',
                'points' => -$points,
                'dollar_value' => $dollarDiscount,
                'status' => 'redeemed',
                'reason' => "Redeemed points on Order #{$order->order_number}",
                'reason_details' => "Redeemed {$points} points for \${$dollarDiscount} discount",
                'reference_type' => 'order_redemption',
                'reference_id' => (string)$order->id,
                'reference_date' => now()->toDateString(),
            ]);

            // Deduct available points and add to redeemed points
            $balance->available_points = max(0, $balance->available_points - $points);
            $balance->redeemed_points += $points;
            $balance->save();

            $user->loyalty_points = $balance->available_points;
            $user->save();

            self::syncCentralAuth($user, -$points, 'redeemed', "Redeemed {$points} points on Order #{$order->order_number}");

            return true;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::redeemPoints error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Award bonus loyalty points for account actions (review, signup, referral, birthday, etc.) with duplication prevention.
     */
    public static function awardBonus(int $userId, string $bonusType, int $points, string $reason, ?string $refType = null, ?string $refId = null): bool
    {
        try {
            if ($points <= 0) {
                return false;
            }

            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            // Anti-fraud: Check for duplicate award if reference given
            if (!empty($refType) && !empty($refId)) {
                $alreadyAwarded = EcommerceLoyaltyTransaction::where('user_id', $userId)
                    ->where('reference_type', $refType)
                    ->where('reference_id', (string)$refId)
                    ->whereIn('status', ['available', 'pending'])
                    ->exists();

                if ($alreadyAwarded) {
                    Log::info("LoyaltyService::awardBonus: Duplicate reward prevented for user {$userId}, ref: {$refType}#{$refId}");
                    return false;
                }
            }

            $settings = self::getSettings();
            $ratio = $settings['points_to_dollar_ratio'];
            $expiryDays = $settings['expiry_days'];
            $expirationDate = $expiryDays > 0 ? Carbon::now()->addDays($expiryDays) : null;

            EcommerceLoyaltyTransaction::create([
                'user_id' => $user->id,
                'order_id' => null,
                'transaction_type' => $bonusType,
                'points' => $points,
                'dollar_value' => round($points * $ratio, 2),
                'status' => 'available',
                'reason' => $reason,
                'reference_type' => $refType,
                'reference_id' => $refId ? (string)$refId : null,
                'reference_date' => now()->toDateString(),
                'expiration_date' => $expirationDate,
            ]);

            $balance = self::getOrCreateBalance($user);
            $balance->available_points += $points;
            $balance->lifetime_earned += $points;
            $balance->save();

            $user->loyalty_points = $balance->available_points;
            $user->save();

            self::syncCentralAuth($user, $points, $bonusType, $reason);

            return true;
        } catch (\Throwable $e) {
            Log::error('LoyaltyService::awardBonus error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Admin manual point adjustment with full audit trail and Central Auth sync.
     */
    public static function adjustPoints(int $userId, int $points, string $type, string $reason, ?int $orderId = null, string $status = 'available', ?string $reasonDetails = null, ?string $notes = null, ?int $adminId = null): bool
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                Log::warning("LoyaltyService: User ID {$userId} not found.");
                return false;
            }

            $settings = self::getSettings();
            $ratio = $settings['points_to_dollar_ratio'];

            // Determine signed points
            $signedPoints = abs($points);
            $isDeduction = in_array(strtolower($type), ['manual_removed', 'subtract', 'deduct', 'redeemed', 'expired', 'reversed']);
            if ($isDeduction) {
                $signedPoints = -$signedPoints;
            }

            $balance = self::getOrCreateBalance($user);

            // Update balance
            if ($isDeduction) {
                $balance->available_points += $signedPoints; // signedPoints is negative
            } else {
                $balance->available_points += $signedPoints;
                $balance->lifetime_earned += $signedPoints;
            }
            $balance->save();

            // Update local user points balance
            $user->loyalty_points = $balance->available_points;
            $user->save();

            // Create permanent transaction log
            EcommerceLoyaltyTransaction::create([
                'user_id' => $user->id,
                'order_id' => $orderId,
                'transaction_type' => $signedPoints >= 0 ? ($type ?: 'manual_added') : ($type ?: 'manual_removed'),
                'points' => $signedPoints,
                'dollar_value' => round(abs($points) * $ratio, 2),
                'status' => $status,
                'reason' => $reason,
                'reason_details' => $reasonDetails,
                'notes' => $notes,
                'admin_id' => $adminId,
            ]);

            // Sync with Central Auth
            self::syncCentralAuth($user, $signedPoints, $type, $reason);

            return true;
        } catch (\Throwable $e) {
            Log::error("LoyaltyService: Exception in adjustPoints: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Award loyalty points for a gift card purchase if enabled in settings.
     */
    public static function awardPointsForGiftCard(?int $userId, float $amount, int $orderId, string $orderNumber, string $status = 'pending'): bool
    {
        try {
            if (!$userId) {
                return false;
            }

            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            $settings = self::getSettings();
            if (!$settings['enabled'] || !$settings['earn_points_on_gift_cards']) {
                return false;
            }

            $ptsRate = $settings['points_per_dollar'];
            $pointsEarned = (int)round($amount * $ptsRate);

            if ($pointsEarned <= 0) {
                return false;
            }

            if ($status === 'available') {
                return self::adjustPoints(
                    $userId,
                    $pointsEarned,
                    'earned',
                    "Points earned for Gift Card Order #{$orderNumber}",
                    null,
                    'available'
                );
            } else {
                EcommerceLoyaltyTransaction::create([
                    'user_id' => $userId,
                    'order_id' => null,
                    'transaction_type' => 'earned',
                    'points' => $pointsEarned,
                    'dollar_value' => round($pointsEarned * $settings['points_to_dollar_ratio'], 2),
                    'status' => 'pending',
                    'reason' => "Points pending for Gift Card Order #{$orderNumber}",
                    'reference_type' => 'gift_card_order',
                    'reference_id' => (string)$orderId,
                    'reference_date' => now()->toDateString(),
                ]);

                $balance = self::getOrCreateBalance($user);
                $balance->pending_points += $pointsEarned;
                $balance->save();

                return true;
            }
        } catch (\Throwable $e) {
            Log::error("LoyaltyService: Exception in awardPointsForGiftCard: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Expire old loyalty points past the expiration threshold.
     */
    public static function expireOldPoints(): int
    {
        try {
            $settings = self::getSettings();
            $expiryDays = (int)($settings['expiry_days'] ?? 365);
            if ($expiryDays <= 0) {
                return 0;
            }

            $cutoffDate = Carbon::now()->subDays($expiryDays);

            // Find available earned transactions older than cutoff date or having past expiration date
            $expiringTransactions = EcommerceLoyaltyTransaction::where('status', 'available')
                ->where('points', '>', 0)
                ->where(function ($q) use ($cutoffDate) {
                    $q->where('created_at', '<', $cutoffDate)
                      ->orWhere(function ($eq) {
                          $eq->whereNotNull('expiration_date')
                             ->where('expiration_date', '<', Carbon::now());
                      });
                })
                ->get();

            $totalExpired = 0;

            foreach ($expiringTransactions as $tx) {
                $user = $tx->user;
                if (!$user) continue;

                $balance = self::getOrCreateBalance($user);
                $points = (int)$tx->points;

                // Mark original transaction as expired
                $tx->update(['status' => 'expired']);

                // Create expired audit entry
                EcommerceLoyaltyTransaction::create([
                    'user_id' => $user->id,
                    'transaction_type' => 'expired',
                    'points' => -$points,
                    'dollar_value' => round($points * $settings['points_to_dollar_ratio'], 2),
                    'status' => 'expired',
                    'reason' => "Points expired after {$expiryDays} days of inactivity.",
                    'reference_type' => 'expired_points',
                    'reference_id' => (string)$tx->id,
                ]);

                $balance->available_points = max(0, $balance->available_points - $points);
                $balance->expired_points += $points;
                $balance->save();

                $user->loyalty_points = $balance->available_points;
                $user->save();

                self::syncCentralAuth($user, -$points, 'expired', "Points expired after {$expiryDays} days.");
                $totalExpired += $points;
            }

            return $totalExpired;
        } catch (\Throwable $e) {
            Log::error("LoyaltyService::expireOldPoints error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Return customer loyalty summary formatted for User & Admin panels.
     */
    public static function getSummary(User|int $user): array
    {
        $userId = $user instanceof User ? $user->id : (int)$user;
        $userModel = $user instanceof User ? $user : User::find($userId);

        $balance = self::getOrCreateBalance($userId);
        $settings = self::getSettings();
        $ratio = $settings['points_to_dollar_ratio'];

        $transactions = EcommerceLoyaltyTransaction::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        $available = (int)$balance->available_points;
        $pending = (int)$balance->pending_points;
        $redeemed = (int)$balance->redeemed_points;
        $expired = (int)$balance->expired_points;
        $lifetime = (int)$balance->lifetime_earned;

        // Determine current tier
        $currentTier = 'Stellar Tier';
        $nextTier = 'Diamond Tier';
        $targetPoints = 20000;
        $progressPercent = 0;

        if ($available >= 35000) {
            $currentTier = 'Signature Tier';
            $nextTier = 'Elite Tier';
            $targetPoints = 50000;
            $progressPercent = 100;
        } elseif ($available >= 20000) {
            $currentTier = 'Diamond Tier';
            $nextTier = 'Signature Tier';
            $targetPoints = 35000;
            $progressPercent = round((($available - 20000) / 15000) * 100, 2);
        } elseif ($available > 0) {
            $currentTier = 'Stellar Tier';
            $nextTier = 'Diamond Tier';
            $targetPoints = 20000;
            $progressPercent = round(($available / 20000) * 100, 2);
        }

        $dollarValue = number_format($available * $ratio, 2, '.', '');
        $lifetimeDollarValue = number_format($lifetime * $ratio, 2, '.', '');

        return [
            'available_points' => $available,
            'points' => $available,
            'status_points' => $available,
            'redeemable_points' => max(0, $available),
            'pending_points' => $pending,
            'redeemed_points' => $redeemed,
            'points_redeemed' => $redeemed,
            'expired_points' => $expired,
            'points_expired' => $expired,
            'lifetime_earned' => $lifetime,
            'lifetime_points' => $lifetime,
            'dollar_value' => '$' . $dollarValue,
            'lifetime_dollar_value' => $lifetimeDollarValue,
            'current_tier' => $currentTier,
            'tier' => $currentTier,
            'next_tier' => $nextTier,
            'target_points' => $targetPoints,
            'progress_percent' => $progressPercent,
            'is_locked' => (bool)$balance->is_locked,
            'locked_reason' => $balance->locked_reason,
            'transactions' => $transactions,
            'history' => $transactions,
        ];
    }
}
