<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;

class EcommerceConfigController extends Controller
{
    /**
     * Get Loyalty settings + real-time ledger statistics.
     */
    public function getLoyalty()
    {
        try {
            $merged = \App\Services\LoyaltyService::getConfigForAdmin();

            $stats = [
                'active_members' => 0,
                'total_points_issued' => 0,
                'total_points_redeemed' => 0,
                'total_points_pending' => 0,
                'total_points_expired' => 0,
                'total_points_reversed' => 0,
                'flagged_accounts' => 0,
            ];

            if (\Illuminate\Support\Facades\Schema::hasTable('customer_points_balances')) {
                $stats['active_members'] = (int) \App\Models\CustomerPointsBalance::where(function ($q) {
                    $q->where('available_points', '>', 0)
                      ->orWhere('lifetime_earned', '>', 0)
                      ->orWhere('pending_points', '>', 0);
                })->count();
                $stats['total_points_issued'] = (int) \App\Models\CustomerPointsBalance::sum('lifetime_earned');
                $stats['total_points_redeemed'] = (int) \App\Models\CustomerPointsBalance::sum('redeemed_points');
                $stats['total_points_pending'] = (int) \App\Models\CustomerPointsBalance::sum('pending_points');
                $stats['total_points_expired'] = (int) \App\Models\CustomerPointsBalance::sum('expired_points');
                $stats['flagged_accounts'] = (int) \App\Models\CustomerPointsBalance::where('is_locked', true)
                    ->orWhere('needs_review', true)
                    ->count();
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('ecommerce_loyalty_transactions')) {
                $stats['total_points_reversed'] = (int) abs(\App\Models\EcommerceLoyaltyTransaction::where('status', 'reversed')->sum('points'));
            }

            $merged['stats'] = $stats;

            return response()->json([
                'success' => true,
                'data' => $merged,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loyalty config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Loyalty settings.
     */
    public function saveLoyalty(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $oldConfig = $settings->loyalty_settings ? json_decode($settings->loyalty_settings, true) : [];

            $payload = $request->all();

            $validated = $request->validate([
                'enabled' => 'required|boolean',
                'program_name' => 'nullable|string|max:255',
                'program_description' => 'nullable|string|max:2000',
                'points_per_dollar' => 'nullable',
                'points_to_dollar_ratio' => 'nullable',
                'minimum_redeem_points' => 'nullable',
                'max_redeem_points_per_order' => 'nullable',
                'max_redeem_percent' => 'nullable',
                'max_discount_amount' => 'nullable',
                'expiry_days' => 'nullable',
                'min_order_amount' => 'nullable',
                'max_earn_per_day' => 'nullable',
                'max_earn_per_month' => 'nullable',
                'max_redeem_per_day' => 'nullable',
                'max_redeem_per_month' => 'nullable',
                'signup_bonus' => 'nullable',
                'birthday_bonus' => 'nullable',
                'review_bonus' => 'nullable',
                'photo_upload_bonus' => 'nullable',
                'first_order_bonus' => 'nullable',
                'membership_bonus' => 'nullable',
                'referral_bonus' => 'nullable',
                'other_bonus' => 'nullable',
                'enable_order_points' => 'nullable|boolean',
                'enable_signup_bonus' => 'nullable|boolean',
                'enable_first_order_bonus' => 'nullable|boolean',
                'enable_review_bonus' => 'nullable|boolean',
                'enable_photo_upload_bonus' => 'nullable|boolean',
                'enable_referral_bonus' => 'nullable|boolean',
                'enable_birthday_bonus' => 'nullable|boolean',
                'enable_membership_bonus' => 'nullable|boolean',
                'allow_partial_redemption' => 'nullable|boolean',
                'allow_redemption_on_shipping' => 'nullable|boolean',
                'allow_redemption_on_taxes' => 'nullable|boolean',
                'allow_redemption_on_discounts' => 'nullable|boolean',
                'allow_with_coupons' => 'nullable|boolean',
                'allow_with_gift_cards' => 'nullable|boolean',
                'enable_expiration' => 'nullable|boolean',
                'expiration_method' => 'nullable|string',
                'expiration_reminder_days' => 'nullable|string',
                'show_balance_on_store' => 'nullable|boolean',
                'show_earning_on_product' => 'nullable|boolean',
                'allow_points_transfer' => 'nullable|boolean',
                'include_tax_in_calculation' => 'nullable|boolean',
                'include_shipping_in_calculation' => 'nullable|boolean',
                'include_digitizing_in_calculation' => 'nullable|boolean',
                'include_custom_embroidery_in_calculation' => 'nullable|boolean',
                'include_setup_in_calculation' => 'nullable|boolean',
                'include_addons_in_calculation' => 'nullable|boolean',
                'include_rush_in_calculation' => 'nullable|boolean',
                'notify_members_on_earn' => 'nullable|boolean',
                'earn_points_on_gift_cards' => 'nullable|boolean',
                'earn_points_on_manual_orders' => 'nullable|boolean',
                'exclude_gift_card_payments' => 'nullable|boolean',
                'point_availability_trigger' => 'nullable|string',
                'remove_points_on_cancel' => 'nullable|boolean',
                'reverse_points_on_refund' => 'nullable|boolean',
                'recalculate_on_partial_refund' => 'nullable|boolean',
                'excluded_product_ids' => 'nullable',
                'excluded_category_ids' => 'nullable',
                'terms_and_conditions' => 'nullable|string',
                'tiers' => 'nullable|array',
                'business_tiers' => 'nullable|array',
                'earning_activities' => 'nullable|array',
            ]);

            unset($payload['stats']);
            $finalConfig = array_merge(is_array($oldConfig) ? $oldConfig : [], $payload, $validated);

            // Sync legacy columns in site_settings for checkout code compatibility
            $ptsRate = (float) str_replace([',', ' '], '', (string) ($finalConfig['points_per_dollar'] ?? 1));
            if ($ptsRate <= 0) {
                $ptsRate = 1.0;
            }
            $settings->loyalty_points_earned_per_unit_price = 1.0;
            $settings->loyalty_points_earned_points = $ptsRate >= 1 ? (int) round($ptsRate) : 1;

            $settings->loyalty_settings = json_encode($finalConfig);
            $settings->save();

            \App\Services\LoyaltyService::flushSettingsCache();

            \App\Models\LoyaltyAuditLog::record(
                'settings_updated',
                $request->user()?->id,
                null,
                'Updated Mecarvi Gold Rewards configuration',
                is_array($oldConfig) ? $oldConfig : null,
                $finalConfig
            );

            return response()->json([
                'success' => true,
                'data' => \App\Services\LoyaltyService::getConfigForAdmin(),
                'message' => 'Loyalty configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save loyalty config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform manual customer points adjustment.
     */
    public function adjustPoints(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'points' => 'required|integer|min:1',
                'transaction_type' => 'required|string',
                'reason' => 'required|string|max:1000',
                'reason_details' => 'nullable|string|max:1000',
                'notes' => 'nullable|string|max:1000',
                'order_id' => 'nullable|integer|exists:ecommerce_orders,id',
                'reference_type' => 'nullable|string|max:100',
                'reference_id' => 'nullable|string|max:100',
                'reference_date' => 'nullable|string|max:100',
                'expiration_date' => 'nullable|string|max:100',
            ]);

            $user = \App\Models\User::find($validated['user_id']);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer user not found.'
                ], 404);
            }

            $admin = $request->user();
            $beforePoints = (int) $user->loyalty_points;
            $isRemove = in_array(strtolower($validated['transaction_type']), [
                'manual_removed', 'subtract', 'deduct', 'remove', 'redeemed', 'expired', 'reversed'
            ], true);
            $status = $isRemove ? 'manual_removed' : 'manual_added';

            $success = \App\Services\LoyaltyService::adjustPoints(
                (int) $user->id,
                (int) $validated['points'],
                (string) $validated['transaction_type'],
                (string) $validated['reason'],
                $validated['order_id'] ?? null,
                $status,
                $validated['reason_details'] ?? null,
                $validated['notes'] ?? null,
                $admin?->id,
                $validated['reference_type'] ?? null,
                $validated['reference_id'] ?? null,
                $validated['reference_date'] ?? null,
                $validated['expiration_date'] ?? null
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => \App\Services\LoyaltyService::$lastError ?: 'Failed to adjust loyalty points.',
                ], 422);
            }

            $user->refresh();
            $balance = \App\Models\CustomerPointsBalance::where('user_id', $user->id)->first();

            // Record user admin change log
            try {
                \App\Models\UserAdminChange::create([
                    'user_id' => $user->id,
                    'admin_id' => $admin?->id,
                    'actor_name' => $admin ? $admin->name : 'Administrator',
                    'actor_role' => $admin && $admin->role ? ucfirst($admin->role) : 'Administrator',
                    'title' => 'Loyalty Points Adjusted',
                    'description' => "Admin adjusted points: {$validated['points']} pts ({$validated['transaction_type']}). Reason: {$validated['reason']}",
                    'changed_fields' => 'Loyalty Points',
                    'before_value' => (string) $beforePoints,
                    'after_value' => (string) $user->loyalty_points,
                ]);
            } catch (\Throwable $auditEx) {
                \Illuminate\Support\Facades\Log::warning('Audit change log notice: ' . $auditEx->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer loyalty points adjusted successfully!',
                'data' => [
                    'loyalty_points' => (int) ($balance?->available_points ?? $user->loyalty_points),
                    'available_points' => (int) ($balance?->available_points ?? $user->loyalty_points),
                    'pending_points' => (int) ($balance?->pending_points ?? 0),
                    'redeemed_points' => (int) ($balance?->redeemed_points ?? 0),
                    'expired_points' => (int) ($balance?->expired_points ?? 0),
                    'lifetime_earned' => (int) ($balance?->lifetime_earned ?? 0),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust points: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get loyalty point transactions history (Core API ledger is primary source of truth).
     */
    public function getTransactions(Request $request)
    {
        try {
            $query = \App\Models\EcommerceLoyaltyTransaction::with(['user', 'order.items.product', 'admin'])->latest();

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->query('user_id'));
            }

            if ($request->filled('order_id')) {
                $query->where('order_id', (int) $request->query('order_id'));
            }

            if ($request->filled('status') && strtolower((string) $request->query('status')) !== 'all') {
                $query->where('status', strtolower((string) $request->query('status')));
            }

            $typeFilter = $request->query('transaction_type') ?: $request->query('type');
            if ($typeFilter && strtolower((string) $typeFilter) !== 'all') {
                $query->where('transaction_type', strtolower((string) $typeFilter));
            }

            if ($request->filled('search')) {
                $s = trim((string) $request->query('search'));
                $query->where(function ($q) use ($s) {
                    $q->where('reason', 'ilike', "%{$s}%")
                      ->orWhere('notes', 'ilike', "%{$s}%")
                      ->orWhere('reference_id', 'ilike', "%{$s}%")
                      ->orWhereHas('user', function ($uq) use ($s) {
                          $uq->where('name', 'ilike', "%{$s}%")
                             ->orWhere('email', 'ilike', "%{$s}%")
                             ->orWhere('business_name', 'ilike', "%{$s}%");
                      })
                      ->orWhereHas('order', function ($oq) use ($s) {
                          $oq->where('order_number', 'ilike', "%{$s}%");
                      });
                });
            }

            $limit = (int) $request->query('limit', 500);
            $localTx = $query->limit(max(1, min($limit, 2000)))->get();

            $transactions = $localTx->map(function ($t) {
                return [
                    'id' => $t->id,
                    'user_id' => $t->user_id,
                    'order_id' => $t->order_id,
                    'transaction_type' => $t->transaction_type,
                    'points' => (int) $t->points,
                    'remaining_points' => $t->remaining_points !== null ? (int) $t->remaining_points : null,
                    'dollar_value' => (string) $t->dollar_value,
                    'status' => $t->status,
                    'reason' => $t->reason,
                    'reason_details' => $t->reason_details,
                    'notes' => $t->notes,
                    'reference_type' => $t->reference_type,
                    'reference_id' => $t->reference_id,
                    'reference_date' => optional($t->reference_date)->toIso8601String(),
                    'expiration_date' => optional($t->expiration_date)->toIso8601String(),
                    'balance_after' => $t->balance_after !== null ? (int) $t->balance_after : null,
                    'admin_id' => $t->admin_id,
                    'created_at' => optional($t->created_at)->toIso8601String() ?? now()->toIso8601String(),
                    'user' => $t->user ? [
                        'id' => $t->user->id,
                        'name' => $t->user->name,
                        'email' => $t->user->email,
                        'role' => $t->user->role,
                        'business_name' => $t->user->business_name,
                        'loyalty_points' => (int) $t->user->loyalty_points,
                    ] : null,
                    'order' => $t->order ? [
                        'id' => $t->order->id,
                        'order_number' => $t->order->order_number,
                        'total_amount' => (string) $t->order->total_amount,
                        'status' => $t->order->status,
                        'payment_status' => $t->order->payment_status,
                        'items' => $t->order->items ? $t->order->items->map(function ($it) {
                            $img = null;
                            if ($it->product && !empty($it->product->images)) {
                                $img = is_array($it->product->images) ? ($it->product->images[0] ?? null) : $it->product->images;
                            }
                            return [
                                'id' => $it->id,
                                'product_id' => $it->product_id,
                                'product_name' => $it->product_name ?? optional($it->product)->name ?? 'Item',
                                'quantity' => (int) $it->quantity,
                                'unit_price' => (float) $it->unit_price,
                                'total_price' => (float) $it->total_price,
                                'image' => $img,
                            ];
                        })->values() : [],
                    ] : null,
                    'admin' => $t->admin ? [
                        'id' => $t->admin->id,
                        'name' => $t->admin->name,
                        'email' => $t->admin->email,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transactions->values()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transactions list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lock or unlock a customer's loyalty account (fraud/abuse control).
     */
    public function lockUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'locked' => 'required|boolean',
                'reason' => 'nullable|string|max:500',
            ]);

            $balance = \App\Services\LoyaltyService::setLock(
                (int) $validated['user_id'],
                (bool) $validated['locked'],
                $validated['reason'] ?? null,
                $request->user()?->id
            );

            if (!$validated['locked'] && $balance->needs_review) {
                $balance->needs_review = false;
                $balance->review_reason = null;
                $balance->save();
            }

            return response()->json([
                'success' => true,
                'message' => $validated['locked']
                    ? 'Customer loyalty account locked.'
                    : 'Customer loyalty account unlocked.',
                'data' => $balance,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update loyalty lock status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Transfer loyalty points between two users.
     */
    public function transferPoints(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_user_id' => 'required|integer|exists:users,id',
                'to_user_id' => 'required|integer|exists:users,id|different:from_user_id',
                'points' => 'required|integer|min:1',
                'reason' => 'required|string|max:500',
            ]);

            $ok = \App\Services\LoyaltyService::transferPoints(
                (int) $validated['from_user_id'],
                (int) $validated['to_user_id'],
                (int) $validated['points'],
                (string) $validated['reason'],
                $request->user()?->id
            );

            if (!$ok) {
                return response()->json([
                    'success' => false,
                    'message' => \App\Services\LoyaltyService::$lastError ?: 'Failed to transfer points.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Loyalty points transferred successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to transfer points: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset a customer's available loyalty balance to zero.
     */
    public function resetBalance(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'reason' => 'required|string|max:500',
            ]);

            $ok = \App\Services\LoyaltyService::resetBalance(
                (int) $validated['user_id'],
                (string) $validated['reason'],
                $request->user()?->id
            );

            if (!$ok) {
                return response()->json([
                    'success' => false,
                    'message' => \App\Services\LoyaltyService::$lastError ?: 'Failed to reset loyalty balance.',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer loyalty balance reset successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset balance: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually approve/release pending points for an order.
     */
    public function releaseOrderPoints(Request $request, $orderId)
    {
        try {
            $order = \App\Models\EcommerceOrder::findOrFail($orderId);
            \App\Services\LoyaltyService::releasePendingPoints($order, true);

            \App\Models\LoyaltyAuditLog::record(
                'manual_order_points_release',
                $request->user()?->id,
                (int) $order->user_id,
                "Admin manually released pending points for Order #{$order->order_number}",
                null,
                ['order_id' => $order->id, 'order_number' => $order->order_number]
            );

            return response()->json([
                'success' => true,
                'message' => "Pending loyalty points released for Order #{$order->order_number}.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to release order points: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Award project photo upload bonus to a customer.
     */
    public function awardPhotoBonus(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'order_id' => 'nullable|integer|exists:ecommerce_orders,id',
                'notes' => 'nullable|string|max:500',
            ]);

            $refId = $validated['order_id'] ? "order-photo-{$validated['order_id']}" : 'photo-' . now()->timestamp;

            $ok = \App\Services\LoyaltyService::awardActivity(
                (int) $validated['user_id'],
                'photo_upload',
                'photo_bonus',
                $validated['notes'] ?? 'Project Photo Upload Bonus',
                'photo_upload',
                $refId
            );

            if (!$ok) {
                return response()->json([
                    'success' => false,
                    'message' => \App\Services\LoyaltyService::$lastError ?: 'Photo upload bonus could not be awarded (already awarded, disabled, or cap reached).',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Project photo upload bonus awarded!',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to award photo bonus: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get loyalty audit logs for admin review.
     */
    public function getLoyaltyAuditLogs(Request $request)
    {
        try {
            $query = \App\Models\LoyaltyAuditLog::with(['admin', 'targetUser'])->latest('created_at');
            if ($request->filled('user_id') || $request->filled('target_user_id')) {
                $uid = (int) ($request->query('user_id') ?? $request->query('target_user_id'));
                $query->where('user_id', $uid);
            }
            $logs = $query->limit(250)->get();

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loyalty audit logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Customer endpoint: Get authenticated user's Mecarvi Gold Rewards summary & ledger.
     */
    public function myLoyaltySummary(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $summary = \App\Services\LoyaltyService::getSummary($user);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loyalty summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Customer endpoint: Transfer available loyalty points to wallet balance.
     */
    public function transferToWallet(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $points = $request->input('points') !== null ? (int) $request->input('points') : null;
            $res = \App\Services\LoyaltyService::transferToWallet($user, $points);

            return response()->json([
                'success' => true,
                'message' => 'Points successfully transferred to your wallet.',
                'data' => $res,
            ]);
        } catch (\App\Services\LoyaltyException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to transfer points to wallet.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Customer endpoint: Claim once-per-day check-in bonus.
     */
    public function claimDailyCheckin(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $res = \App\Services\LoyaltyService::claimDailyCheckin($user);

            return response()->json([
                'success' => (bool) ($res['ok'] ?? false),
                'message' => $res['message'] ?? 'Check-in processed.',
                'data' => $res,
            ], ($res['ok'] ?? false) ? 200 : 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to claim daily check-in.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Customer endpoint: Get program leaderboard.
     */
    public function getLeaderboard(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $limit = min(50, max(5, (int) $request->input('limit', 10)));
            $res = \App\Services\LoyaltyService::leaderboard($user, $limit);

            return response()->json([
                'success' => true,
                'data' => $res,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch leaderboard.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Customer endpoint: Redeem catalog reward item for points.
     */
    public function redeemReward(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            $items = $request->input('items');
            if (!is_array($items) || empty($items)) {
                $itemName = (string) $request->input('item_name', 'Reward Item');
                $pointsCost = (int) $request->input('points', 0);
                $quantity = max(1, (int) $request->input('quantity', 1));
                $productId = $request->input('product_id');
                $items = [[
                    'id' => $productId,
                    'product_id' => $productId,
                    'name' => $itemName,
                    'points' => $pointsCost,
                    'quantity' => $quantity,
                    'price' => (float) $request->input('price', 0),
                    'imageSrc' => $request->input('imageSrc') ?: $request->input('image_path'),
                ]];
                $totalPoints = $pointsCost * $quantity;
            } else {
                $totalPoints = 0;
                foreach ($items as &$it) {
                    $qty = max(1, (int) ($it['quantity'] ?? 1));
                    $it['quantity'] = $qty;
                    $pts = (int) ($it['points'] ?? 0);
                    $totalPoints += ($pts * $qty);
                }
                unset($it);
            }

            if ($totalPoints <= 0) {
                return response()->json(['success' => false, 'message' => 'Invalid points cost.'], 422);
            }

            $options = [
                'delivery_method' => (string) $request->input('delivery_method', 'shipping'),
                'shipping_address' => $request->input('shipping_address'),
                'pickup_location' => $request->input('pickup_location'),
                'shipping_fee' => (float) $request->input('shipping_fee', 4.95),
                'payment_method' => (string) $request->input('payment_method', 'card'),
                'card_last_four' => (string) $request->input('card_last_four', '3456'),
            ];

            $res = \App\Services\LoyaltyService::redeemCatalogReward($user, $items, $totalPoints, $options);

            return response()->json([
                'success' => true,
                'message' => "Successfully redeemed rewards!",
                'data' => $res,
            ]);
        } catch (\App\Services\LoyaltyException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to redeem reward.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Customer endpoint: Preview points redemption & earning at checkout.
     */
    public function redeemPreview(Request $request)
    {
        try {
            $user = $request->user();
            $settings = \App\Services\LoyaltyService::getSettings();

            $subtotal = (float) $request->input('subtotal', 0);
            $shipping = (float) $request->input('shipping_cost', 0);
            $tax = (float) $request->input('tax', 0);
            $couponDiscount = (float) $request->input('coupon_discount', 0);
            $giftCardAmount = (float) $request->input('gift_card_amount', 0);
            $pointsRequested = (int) $request->input('points_to_redeem', 0);
            $hasCoupon = !empty($request->input('coupon_code')) || $couponDiscount > 0;
            $hasGiftCard = !empty($request->input('gift_card_code')) || $giftCardAmount > 0;

            $balance = $user ? \App\Services\LoyaltyService::getOrCreateBalance($user->id) : null;
            $availablePoints = $balance ? (int) $balance->available_points : (int) ($user->loyalty_points ?? 0);
            $ratio = (float) ($settings['points_to_dollar_ratio'] ?? 0.01);

            // Calculate max points usable on this order
            $eligibleBasis = max(0, $subtotal - $couponDiscount);
            if (!empty($settings['allow_redemption_on_shipping'])) {
                $eligibleBasis += max(0, $shipping);
            }
            if (!empty($settings['allow_redemption_on_taxes'])) {
                $eligibleBasis += max(0, $tax);
            }

            $maxDollarByPercent = $eligibleBasis * (min(100, max(0, (float) ($settings['max_redeem_percent'] ?? 100))) / 100.0);
            $maxDollarCap = (float) ($settings['max_discount_amount'] ?? 0);
            $maxDollarAllowed = $maxDollarCap > 0 ? min($maxDollarByPercent, $maxDollarCap) : $maxDollarByPercent;

            $maxPointsByDollar = $ratio > 0 ? (int) floor($maxDollarAllowed / $ratio) : 0;
            $maxPointsPerOrder = (int) ($settings['max_redeem_points_per_order'] ?? 0);
            $maxPointsAllowed = $availablePoints;
            if ($maxPointsPerOrder > 0) {
                $maxPointsAllowed = min($maxPointsAllowed, $maxPointsPerOrder);
            }
            $maxPointsAllowed = min($maxPointsAllowed, $maxPointsByDollar);

            $validationValid = true;
            $validationMessage = null;
            $appliedPoints = 0;
            $discountDollar = 0.0;

            if ($pointsRequested > 0) {
                if (!$user) {
                    $validationValid = false;
                    $validationMessage = 'Please sign in to redeem loyalty points.';
                } else {
                    $res = \App\Services\LoyaltyService::validateRedemption($user, $pointsRequested, [
                        'subtotal' => $subtotal,
                        'shipping_cost' => $shipping,
                        'tax' => $tax,
                        'coupon_discount' => $couponDiscount,
                        'has_coupon' => $hasCoupon,
                        'has_gift_card' => $hasGiftCard,
                    ]);
                    $validationValid = (bool) $res['ok'];
                    $validationMessage = $res['message'] ?? null;
                    if ($validationValid) {
                        $appliedPoints = (int) $res['points'];
                        $discountDollar = (float) $res['discount'];
                    }
                    $maxPointsAllowed = (int) ($res['max_points'] ?? $maxPointsAllowed);
                }
            } elseif ($user) {
                $res = \App\Services\LoyaltyService::validateRedemption($user, 0, [
                    'subtotal' => $subtotal,
                    'shipping_cost' => $shipping,
                    'tax' => $tax,
                    'coupon_discount' => $couponDiscount,
                    'has_coupon' => $hasCoupon,
                    'has_gift_card' => $hasGiftCard,
                ]);
                $maxPointsAllowed = (int) ($res['max_points'] ?? $maxPointsAllowed);
            }

            $earningPreview = $user ? \App\Services\LoyaltyService::calculateEarning($user, [
                'subtotal' => $subtotal,
                'discount' => $couponDiscount + $discountDollar,
                'coupon_discount' => $couponDiscount,
                'gift_card' => $giftCardAmount,
                'shipping' => $shipping,
                'tax' => $tax,
            ]) : ['total' => (int) round(max(0, $subtotal - $couponDiscount - $discountDollar) * (float) ($settings['points_per_dollar'] ?? 1))];

            return response()->json([
                'success' => true,
                'data' => [
                    'enabled' => (bool) ($settings['enabled'] ?? false),
                    'available_points' => $availablePoints,
                    'available_dollar_value' => round($availablePoints * $ratio, 2),
                    'points_to_dollar_ratio' => $ratio,
                    'minimum_redeem_points' => (int) ($settings['minimum_redeem_points'] ?? 100),
                    'min_order_amount' => (float) ($settings['min_order_amount'] ?? 0),
                    'max_points_usable' => max(0, $maxPointsAllowed),
                    'max_discount_dollar' => round(max(0, $maxPointsAllowed * $ratio), 2),
                    'valid' => $validationValid,
                    'message' => $validationMessage,
                    'points_applied' => $appliedPoints,
                    'discount_amount' => $discountDollar,
                    'points_to_earn' => (int) ($earningPreview['total'] ?? 0),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to preview loyalty redemption: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Charity settings.
     */
    public function getCharity()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $charity = $settings->charity_settings ? json_decode($settings->charity_settings, true) : null;

            $defaultCategories = [
                [
                    'id' => 'education',
                    'name' => 'Education',
                    'description' => 'Support education and learning initiatives.',
                    'status' => 'Active',
                    'icon' => 'education'
                ],
                [
                    'id' => 'disaster-relief',
                    'name' => 'Disaster Relief',
                    'description' => 'Provide aid during natural disasters.',
                    'status' => 'Active',
                    'icon' => 'disaster-relief'
                ],
                [
                    'id' => 'environment',
                    'name' => 'Environment',
                    'description' => 'Support environmental conservation.',
                    'status' => 'Active',
                    'icon' => 'environment'
                ],
                [
                    'id' => 'health',
                    'name' => 'Health',
                    'description' => 'Improve health and wellness in communities.',
                    'status' => 'Active',
                    'icon' => 'health'
                ],
                [
                    'id' => 'animals',
                    'name' => 'Animals',
                    'description' => 'Support animal welfare and rescue efforts.',
                    'status' => 'Active',
                    'icon' => 'animals'
                ]
            ];

            $defaultAssistanceOptions = [
                [
                    'id' => 'rental-assistance',
                    'name' => 'Rental Assistance',
                    'description' => 'Help with rental and housing costs.',
                    'status' => 'Active',
                    'icon' => 'rental'
                ],
                [
                    'id' => 'shelter-housing',
                    'name' => 'Shelter / Housing',
                    'description' => 'Provide temporary shelter and housing support.',
                    'status' => 'Active',
                    'icon' => 'shelter'
                ],
                [
                    'id' => 'utility-assistance',
                    'name' => 'Utility Assistance',
                    'description' => 'Help with electricity, water and utility bills.',
                    'status' => 'Active',
                    'icon' => 'utility'
                ],
                [
                    'id' => 'clothing-assistance',
                    'name' => 'Clothing Assistance',
                    'description' => 'Provide clothing and essential items.',
                    'status' => 'Active',
                    'icon' => 'clothing'
                ],
                [
                    'id' => 'food-support',
                    'name' => 'Food Support',
                    'description' => 'Support with food and nutrition needs.',
                    'status' => 'Active',
                    'icon' => 'food'
                ],
                [
                    'id' => 'job-training',
                    'name' => 'Job Training',
                    'description' => 'Support job training and employment opportunities.',
                    'status' => 'Active',
                    'icon' => 'job'
                ]
            ];

            if (!$charity) {
                $charity = [
                    'enabled' => true,
                    'charity_name' => 'Mecarvi Foundation',
                    'charity_description' => 'Mecarvi Foundation provides direct financial assistance to individuals and communities through education, health support and community development initiatives.',
                    'suggested_amounts' => '1,5,10,25,50',
                    'allow_custom_amount' => true,
                    'minimum_donation_amount' => 1.00,
                    'maximum_donation_amount' => 500.00,
                    'categories' => $defaultCategories,
                    'assistance_options' => $defaultAssistanceOptions
                ];
            } else {
                if (!isset($charity['minimum_donation_amount'])) {
                    $charity['minimum_donation_amount'] = 1.00;
                }
                if (!isset($charity['maximum_donation_amount'])) {
                    $charity['maximum_donation_amount'] = 500.00;
                }
                if (!isset($charity['suggested_amounts'])) {
                    $charity['suggested_amounts'] = '1,5,10,25,50';
                }
                if (!isset($charity['categories']) || empty($charity['categories'])) {
                    $charity['categories'] = $defaultCategories;
                } else {
                    // Normalize existing string categories if any
                    $charity['categories'] = array_map(function ($cat, $idx) {
                        if (is_array($cat)) return $cat;
                        return [
                            'id' => 'cat_' . ($idx + 1),
                            'name' => (string) $cat,
                            'description' => 'Support ' . strtolower((string) $cat) . ' initiatives.',
                            'status' => 'Active',
                            'icon' => 'education'
                        ];
                    }, $charity['categories'], array_keys($charity['categories']));
                }
                if (!isset($charity['assistance_options']) || empty($charity['assistance_options'])) {
                    $charity['assistance_options'] = $defaultAssistanceOptions;
                } else {
                    // Normalize existing string options if any
                    $charity['assistance_options'] = array_map(function ($opt, $idx) {
                        if (is_array($opt)) return $opt;
                        return [
                            'id' => 'assist_' . ($idx + 1),
                            'name' => (string) $opt,
                            'description' => 'Provide ' . strtolower((string) $opt) . ' support.',
                            'status' => 'Active',
                            'icon' => 'rental'
                        ];
                    }, $charity['assistance_options'], array_keys($charity['assistance_options']));
                }
            }

            return response()->json([
                'success' => true,
                'data' => $charity
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch charity config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Charity settings.
     */
    public function saveCharity(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $currentConfig = $settings->charity_settings ? json_decode($settings->charity_settings, true) : [];
            
            $validated = $request->validate([
                'enabled' => 'required|boolean',
                'charity_name' => 'nullable|string|max:255',
                'charity_description' => 'nullable|string',
                'suggested_amounts' => 'required|string',
                'allow_custom_amount' => 'required|boolean',
                'minimum_donation_amount' => 'nullable|numeric',
                'maximum_donation_amount' => 'nullable|numeric',
                'categories' => 'nullable|array',
                'assistance_options' => 'nullable|array'
            ]);

            if (empty($validated['charity_name'])) {
                $validated['charity_name'] = $currentConfig['charity_name'] ?? $settings->charity_name ?? 'Mecarvi Foundation';
            }
            if (empty($validated['charity_description'])) {
                $validated['charity_description'] = $currentConfig['charity_description'] ?? 'Mecarvi Foundation provides direct financial assistance to individuals and communities.';
            }

            // Sync legacy columns in site_settings for checkout code compatibility
            $settings->charity_name = $validated['charity_name'];
            $settings->charity_donation_enabled = $validated['enabled'];
            $suggested = explode(',', $validated['suggested_amounts']);
            $settings->charity_default_amount = (float)($suggested[0] ?? 1.00);

            $settings->charity_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Charity configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save charity config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Tips settings.
     */
    public function getTips()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $tips = $settings->tips_settings ? json_decode($settings->tips_settings, true) : null;

            if (!$tips) {
                $tips = [
                    'enabled' => false,
                    'suggested_percentages' => '10,15,20',
                    'allow_custom' => true,
                    'max_custom_percent' => '30'
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $tips
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tips config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Tips settings.
     */
    public function saveTips(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            
            $validated = $request->validate([
                'enabled' => 'required|boolean',
                'suggested_percentages' => 'required|string',
                'allow_custom' => 'required|boolean',
                'max_custom_percent' => 'required|string'
            ]);

            $settings->tips_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Tips configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save tips config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Tax Configuration Settings.
     */
    public function getTaxSettings()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $taxSettings = $settings->tax_settings ? json_decode($settings->tax_settings, true) : null;

            if (!$taxSettings) {
                $taxSettings = [
                    'tax_calculation_source' => 'manual',
                    'enable_taxes' => (bool)($settings->tax_enabled ?? true),
                    'tax_calculation_based_on' => 'shipping_address',
                    'display_prices' => 'excluding_tax',
                    'calculate_tax_after_discounts' => true,
                    'tax_label_at_checkout' => 'Sales Tax',
                    'allow_tax_exemption' => true,
                    'auto_apply_non_tax_exempt' => true,
                    'tax_on_shipping' => true,
                    'apply_tax_to_gift_cards' => false,
                    'opensalestax_connected' => false,
                    'opensalestax_url' => '',
                    'opensalestax_api_key' => '',
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $taxSettings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tax settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Public Tax Configuration and Active Rates for Checkout.
     */
    public function getPublicTaxConfig()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $taxSettings = $settings->tax_settings ? json_decode($settings->tax_settings, true) : null;

            if (!$taxSettings) {
                $taxSettings = [
                    'tax_calculation_source' => 'manual',
                    'enable_taxes' => (bool)($settings->tax_enabled ?? true),
                    'tax_calculation_based_on' => 'shipping_address',
                    'display_prices' => 'excluding_tax',
                    'calculate_tax_after_discounts' => true,
                    'tax_label_at_checkout' => 'Sales Tax',
                    'allow_tax_exemption' => true,
                    'auto_apply_non_tax_exempt' => true,
                    'tax_on_shipping' => true,
                    'apply_tax_to_gift_cards' => false,
                    'opensalestax_connected' => false,
                ];
            }

            $rates = \App\Models\TaxRate::where('is_active', true)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'settings' => $taxSettings,
                    'default_tax_rate' => (float)($settings->tax_rate ?? 0),
                    'rates' => $rates,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch public tax configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Tax Configuration Settings.
     */
    public function saveTaxSettings(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);

            $validated = $request->validate([
                'tax_calculation_source' => 'nullable|string',
                'enable_taxes' => 'required|boolean',
                'tax_calculation_based_on' => 'nullable|string',
                'display_prices' => 'nullable|string',
                'calculate_tax_after_discounts' => 'nullable|boolean',
                'tax_label_at_checkout' => 'nullable|string|max:255',
                'allow_tax_exemption' => 'nullable|boolean',
                'auto_apply_non_tax_exempt' => 'nullable|boolean',
                'tax_on_shipping' => 'nullable|boolean',
                'apply_tax_to_gift_cards' => 'nullable|boolean',
                'opensalestax_connected' => 'nullable|boolean',
                'opensalestax_url' => 'nullable|string',
                'opensalestax_api_key' => 'nullable|string',
            ]);

            // Sync legacy boolean tax_enabled on site_settings
            $settings->tax_enabled = $validated['enable_taxes'];
            $settings->tax_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Tax configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save tax settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPackaging()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $packaging = $settings->packaging_settings ? json_decode($settings->packaging_settings, true) : null;

            // Handle old format or empty value
            if (!$packaging || !isset($packaging['styles']) || empty($packaging['styles'])) {
                $packaging = [
                    'styles' => [
                        [
                            'id' => 1,
                            'name' => 'Standard Packaging',
                            'description' => 'Your order is carefully packed in our standard packaging to keep items secure and protected during transit.',
                            'price' => '0.00',
                            'includedInPrice' => true,
                            'displayOrder' => '1',
                            'status' => true,
                            'isRecommended' => false,
                            'image' => '/images/packaging/standard.png',
                        ],
                        [
                            'id' => 2,
                            'name' => 'Premium Packaging',
                            'description' => 'Premium box with tissue to upgrade your order with an enhanced presentation that adds a polished finishing touch while keeping your items neatly packaged and ready for delivery.',
                            'price' => '4.99',
                            'includedInPrice' => false,
                            'displayOrder' => '2',
                            'status' => true,
                            'isPopular' => true,
                            'isRecommended' => true,
                            'image' => '/images/packaging/premium.png',
                        ],
                        [
                            'id' => 3,
                            'name' => 'Luxury Packaging',
                            'description' => 'Give your order an elegant presentation designed to make every unboxing feel memorable and special. Perfect for gifts, special occasions, celebrations, or simply adding a distinguished finishing touch to your order.',
                            'price' => '9.99',
                            'includedInPrice' => false,
                            'displayOrder' => '3',
                            'status' => true,
                            'image' => '/images/packaging/luxury.png',
                        ],
                    ],
                    'additional_options' => [
                        [
                            'id' => 1,
                            'name' => 'Custom Greetings Card',
                            'description' => 'Add a personalized greeting card to your order with your own message to make your package more thoughtful and personal.',
                            'price' => '$2.99',
                            'displayOrder' => '1',
                            'status' => true,
                            'image' => '/images/packaging/greeting_card.png',
                        ],
                        [
                            'id' => 2,
                            'name' => 'Extra Protection',
                            'description' => 'Add an extra layer of protective packaging to help keep your items secure and protected during transit.',
                            'price' => '$0.99',
                            'displayOrder' => '2',
                            'status' => true,
                            'image' => '/images/packaging/bubble_wrap.png',
                        ],
                        [
                            'id' => 3,
                            'name' => 'Package Insurance',
                            'description' => 'Add coverage to help protect your order against loss, theft or damage while in transit.',
                            'price' => '$5.99',
                            'displayOrder' => '3',
                            'status' => true,
                            'image' => '/images/packaging/insurance.png',
                        ],
                    ],
                ];
            }

            return response()->json(['success' => true, 'data' => $packaging]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch packaging config', 'error' => $e->getMessage()], 500);
        }
    }

    public function savePackaging(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            
            $input = $request->all();
            if (isset($input['styles']) && is_array($input['styles'])) {
                foreach ($input['styles'] as $k => $s) {
                    if (isset($s['price'])) {
                        $cleanPrice = preg_replace('/[^0-9.]/', '', (string) $s['price']);
                        $input['styles'][$k]['price'] = $cleanPrice !== '' ? (float) $cleanPrice : 0.00;
                    }
                    if (isset($s['includedInPrice'])) {
                        $input['styles'][$k]['includedInPrice'] = (bool) $s['includedInPrice'];
                    }
                    if (isset($s['status'])) {
                        $input['styles'][$k]['status'] = (bool) $s['status'];
                    }
                }
            }
            if (isset($input['additional_options']) && is_array($input['additional_options'])) {
                foreach ($input['additional_options'] as $k => $opt) {
                    if (isset($opt['price'])) {
                        $cleanPrice = preg_replace('/[^0-9.]/', '', (string) $opt['price']);
                        $input['additional_options'][$k]['price'] = '$' . number_format((float) ($cleanPrice !== '' ? $cleanPrice : 0), 2, '.', '');
                    }
                    if (isset($opt['status'])) {
                        $input['additional_options'][$k]['status'] = (bool) $opt['status'];
                    }
                }
            }
            $request->merge($input);

            $validated = $request->validate([
                'styles' => 'required|array',
                'styles.*.id' => 'required',
                'styles.*.name' => 'required|string|max:255',
                'styles.*.description' => 'required|string|max:500',
                'styles.*.price' => 'required|numeric|min:0',
                'styles.*.includedInPrice' => 'required|boolean',
                'styles.*.displayOrder' => 'required|string|max:50',
                'styles.*.status' => 'required|boolean',
                'styles.*.isRecommended' => 'nullable|boolean',
                'styles.*.image' => 'nullable|string',

                'additional_options' => 'required|array',
                'additional_options.*.id' => 'required',
                'additional_options.*.name' => 'required|string|max:255',
                'additional_options.*.description' => 'required|string|max:500',
                'additional_options.*.price' => 'required|string|max:50',
                'additional_options.*.displayOrder' => 'required|string|max:50',
                'additional_options.*.status' => 'required|boolean',
                'additional_options.*.image' => 'nullable|string',
            ]);

            // Save Base64 Images as files for styles
            foreach ($validated['styles'] as $key => $style) {
                if (isset($style['image']) && str_starts_with($style['image'], 'data:image/')) {
                    $validated['styles'][$key]['image'] = $this->saveBase64Image($style['image'], $style['name']);
                }
            }

            // Save Base64 Images as files for additional options
            foreach ($validated['additional_options'] as $key => $opt) {
                if (isset($opt['image']) && str_starts_with($opt['image'], 'data:image/')) {
                    $validated['additional_options'][$key]['image'] = $this->saveBase64Image($opt['image'], $opt['name']);
                }
            }

            $settings->packaging_settings = json_encode($validated);
            $settings->save();

            return response()->json(['success' => true, 'data' => $validated, 'message' => 'Packaging configuration saved successfully']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to save packaging config', 'error' => $e->getMessage()], 500);
        }
    }

    public function getTurnaround()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $turnaround = $settings->turnaround_settings ? json_decode($settings->turnaround_settings, true) : null;

            if (!$turnaround || !is_array($turnaround) || empty($turnaround)) {
                $turnaround = [
                    [
                        'id' => 1,
                        'name' => 'Standard Production',
                        'description' => 'Standard production follows our regular turnaround schedule for store pickup, shipping or delivery.',
                        'production_time_value' => '3-5',
                        'production_time_unit' => 'Business Days',
                        'estimated_days' => '3-5 business days',
                        'additional_fee' => 0.00,
                        'availability' => 'Weekdays Only',
                        'cutoff_time' => '5:00 PM',
                        'status' => true,
                        'is_default' => true,
                        'color_code' => '#2563eb',
                        'icon_type' => 'calendar',
                        'priority' => 1,
                    ],
                    [
                        'id' => 2,
                        'name' => 'Express Production',
                        'description' => 'Expedited production completed by 3:00 PM the next business day for store pickup, shipping or delivery.',
                        'production_time_value' => '1',
                        'production_time_unit' => 'Business Days',
                        'estimated_days' => '1 business days',
                        'additional_fee' => 25.00,
                        'availability' => 'Weekdays Only',
                        'cutoff_time' => '12:00 PM',
                        'status' => true,
                        'is_default' => false,
                        'color_code' => '#f97316',
                        'icon_type' => 'clock',
                        'priority' => 2,
                    ],
                    [
                        'id' => 3,
                        'name' => 'Rush Production',
                        'description' => 'Urgent same-day production completed by the end of the business day for store pickup, shipping or delivery.',
                        'production_time_value' => '6',
                        'production_time_unit' => 'Hours',
                        'estimated_days' => '6 hours',
                        'additional_fee' => 45.00,
                        'availability' => 'Weekdays Only',
                        'cutoff_time' => '12:00 PM',
                        'status' => true,
                        'is_default' => false,
                        'color_code' => '#ec4899',
                        'icon_type' => 'zap',
                        'priority' => 3,
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $turnaround
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch turnaround configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function saveTurnaround(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);

            $items = $request->input('turnaround_times', $request->all());
            if (isset($items['turnaround_times'])) {
                $items = $items['turnaround_times'];
            }

            if (!is_array($items)) {
                $items = [];
            }

            $settings->turnaround_settings = json_encode($items);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $items,
                'message' => 'Turnaround times configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save turnaround configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDeliverySettings()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $delivery = $settings->delivery_settings ? json_decode($settings->delivery_settings, true) : null;

            if (!$delivery || !is_array($delivery)) {
                $delivery = [
                    'max_delivery_radius' => 25,
                    'radius_unit' => 'miles',
                    'allow_custom_upcharges' => true,
                    'mileage_tiers' => [
                        ['id' => 'tier_1', 'min_miles' => 0, 'max_miles' => 5, 'price' => 15.00, 'label' => '0 to 5 miles'],
                        ['id' => 'tier_2', 'min_miles' => 6, 'max_miles' => 10, 'price' => 20.00, 'label' => '6 to 10 miles'],
                        ['id' => 'tier_3', 'min_miles' => 11, 'max_miles' => 15, 'price' => 25.00, 'label' => '11 to 15 miles'],
                        ['id' => 'tier_4', 'min_miles' => 16, 'max_miles' => 20, 'price' => 30.00, 'label' => '16 to 20 miles'],
                        ['id' => 'tier_5', 'min_miles' => 21, 'max_miles' => 25, 'price' => 35.00, 'label' => '21 to 25 miles'],
                    ]
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $delivery
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivery settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function saveDeliverySettings(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $validated = $request->validate([
                'max_delivery_radius' => 'required|numeric|min:1',
                'radius_unit' => 'nullable|string',
                'mileage_tiers' => 'required|array|min:1',
                'mileage_tiers.*.min_miles' => 'required|numeric|min:0',
                'mileage_tiers.*.max_miles' => 'required|numeric|min:0',
                'mileage_tiers.*.price' => 'required|numeric|min:0',
                'mileage_tiers.*.label' => 'nullable|string',
                'allow_custom_upcharges' => 'nullable|boolean',
            ]);

            $settings->delivery_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Delivery settings saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save delivery settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function saveBase64Image($base64Data, $name)
    {
        if (empty($base64Data)) {
            return '';
        }

        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $data = substr($base64Data, strpos($base64Data, ',') + 1);
            $type = strtolower($type[1]); // png, jpg, jpeg, gif

            if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) {
                return '';
            }

            $data = base64_decode($data);

            if ($data === false) {
                return '';
            }

            $fileName = uniqid() . '_' . \Illuminate\Support\Str::slug($name) . '.' . $type;
            $dirPath = storage_path('app/public/packaging');

            if (!\Illuminate\Support\Facades\File::exists($dirPath)) {
                \Illuminate\Support\Facades\File::makeDirectory($dirPath, 0755, true);
            }

            \Illuminate\Support\Facades\File::put($dirPath . '/' . $fileName, $data);

            return '/storage/packaging/' . $fileName;
        }

        return $base64Data; // Return as-is if it's already a URL/path
    }
}

