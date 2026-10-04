<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SiteSetting;
use App\Models\EcommerceOrder;
use App\Models\EcommerceLoyaltyTransaction;
use App\Models\CustomerPointsBalance;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EcommerceLoyaltyController extends Controller
{
    /**
     * Storefront public loyalty config (for checkout, cart, product displays).
     */
    public function getPublicConfig()
    {
        try {
            $settings = LoyaltyService::getSettings();
            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin loyalty settings + aggregated stats.
     */
    public function getAdminConfig()
    {
        try {
            $settings = LoyaltyService::getSettings();

            // Aggregated platform stats
            $totalIssued = (int) EcommerceLoyaltyTransaction::where('points', '>', 0)
                ->whereIn('status', ['available', 'completed'])
                ->sum('points');

            $totalRedeemed = (int) abs(EcommerceLoyaltyTransaction::where('points', '<', 0)
                ->where('status', 'redeemed')
                ->sum('points'));

            $totalPending = (int) CustomerPointsBalance::sum('pending_points');
            $totalExpired = (int) CustomerPointsBalance::sum('expired_points');
            $activeMembersCount = CustomerPointsBalance::where('available_points', '>', 0)
                ->where('is_locked', false)
                ->count();

            return response()->json([
                'success' => true,
                'data' => $settings,
                'stats' => [
                    'total_points_issued' => $totalIssued,
                    'total_points_redeemed' => $totalRedeemed,
                    'total_points_pending' => $totalPending,
                    'total_points_expired' => $totalExpired,
                    'active_members' => $activeMembersCount,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update loyalty settings in database.
     */
    public function updateAdminConfig(Request $request)
    {
        try {
            $siteSetting = SiteSetting::first();
            if (!$siteSetting) {
                $siteSetting = new SiteSetting();
            }

            $currentSettings = is_array($siteSetting->loyalty_settings)
                ? $siteSetting->loyalty_settings
                : (json_decode($siteSetting->loyalty_settings, true) ?: []);

            $payload = $request->all();
            $merged = array_merge($currentSettings, $payload);

            $siteSetting->loyalty_settings = $merged;
            $siteSetting->save();

            return response()->json([
                'success' => true,
                'message' => 'Loyalty program settings updated successfully.',
                'data' => LoyaltyService::getSettings(),
            ]);
        } catch (\Throwable $e) {
            Log::error('EcommerceLoyaltyController::updateAdminConfig error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin view of all loyalty transactions across all customers.
     */
    public function getTransactions(Request $request)
    {
        try {
            $query = EcommerceLoyaltyTransaction::with(['user', 'order'])->latest();

            if ($request->has('status') && $request->status !== 'All' && !empty($request->status)) {
                $query->where('status', strtolower($request->status));
            }

            if ($request->has('type') && $request->type !== 'All' && !empty($request->type)) {
                $query->where('transaction_type', strtolower($request->type));
            }

            if ($request->has('search') && !empty($request->search)) {
                $term = $request->search;
                $query->where(function ($q) use ($term) {
                    $q->where('reason', 'like', "%{$term}%")
                        ->orWhere('reason_details', 'like', "%{$term}%")
                        ->orWhereHas('user', function ($uq) use ($term) {
                            $uq->where('name', 'like', "%{$term}%")
                                ->orWhere('email', 'like', "%{$term}%");
                        });
                });
            }

            $transactions = $query->paginate($request->input('per_page', 25));

            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin view of customer loyalty profiles and balances.
     */
    public function getCustomers(Request $request)
    {
        try {
            $query = User::with(['pointsBalance', 'loyaltyTransactions'])
                ->where('role', '!=', 'admin');

            if ($request->has('search') && !empty($request->search)) {
                $term = $request->search;
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            }

            $customers = $query->paginate($request->input('per_page', 20));

            $formatted = collect($customers->items())->map(function ($user, $index) {
                $balance = LoyaltyService::getOrCreateBalance($user);
                $settings = LoyaltyService::getSettings();
                $tier = 'Stellar Tier';
                foreach ($settings['tiers'] as $t) {
                    if ($balance->available_points >= ($t['min_points'] ?? 0) && $balance->available_points <= ($t['max_points'] ?? PHP_INT_MAX)) {
                        $tier = $t['name'] ?? 'Stellar Tier';
                        break;
                    }
                }

                $recent = EcommerceLoyaltyTransaction::where('user_id', $user->id)
                    ->with('order')
                    ->latest()
                    ->take(5)
                    ->get()
                    ->map(function ($tx) {
                        return [
                            'id' => $tx->id,
                            'date' => $tx->created_at ? $tx->created_at->format('M d, Y') : '',
                            'description' => $tx->reason ?: 'Loyalty activity',
                            'sub_desc' => $tx->order ? "Order #{$tx->order->order_number}" : ($tx->reason_details ?: ''),
                            'points' => (int)$tx->points,
                            'status' => $tx->status,
                            'order_number' => $tx->order?->order_number,
                            'order_total' => $tx->order?->total_amount ? "\${$tx->order->total_amount}" : null,
                        ];
                    });

                return [
                    'id' => $user->id,
                    'rank' => $index + 1,
                    'name' => $user->name ?: 'Customer',
                    'email' => $user->email,
                    'customer_code' => '#C' . str_pad($user->id, 5, '0', STR_PAD_LEFT),
                    'tier' => $tier,
                    'loyalty_points' => (int)$balance->available_points,
                    'pending_points' => (int)$balance->pending_points,
                    'total_earned' => (int)$balance->lifetime_earned,
                    'total_redeemed' => (int)$balance->redeemed_points,
                    'points_expired' => (int)$balance->expired_points,
                    'status' => $balance->is_locked ? 'Suspended' : 'Active',
                    'is_locked' => (bool)$balance->is_locked,
                    'locked_reason' => $balance->locked_reason,
                    'member_since' => $user->created_at ? $user->created_at->format('M d, Y') : '',
                    'recent_transactions' => $recent,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'meta' => [
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'total' => $customers->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin manual point adjustment.
     */
    public function adjustPoints(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'points' => 'required|integer',
            'reason' => 'required|string|max:500',
            'details' => 'nullable|string|max:1000',
        ]);

        try {
            $points = (int)$validated['points'];
            $type = $points > 0 ? 'manual_added' : 'manual_removed';
            $admin = $request->user();

            $success = LoyaltyService::adjustPoints(
                $validated['user_id'],
                abs($points),
                $type,
                $validated['reason'],
                $admin ? $admin->id : null,
                $validated['details'] ?? null
            );

            if ($success) {
                $user = User::find($validated['user_id']);
                $balance = LoyaltyService::getOrCreateBalance($user);
                return response()->json([
                    'success' => true,
                    'message' => 'Points adjusted successfully.',
                    'data' => [
                        'available_points' => $balance->available_points,
                        'pending_points' => $balance->pending_points,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust points. Please check customer account.',
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update customer loyalty status / lock account points.
     */
    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'status' => 'required|string',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $user = User::findOrFail($validated['user_id']);
            $newStatus = strtolower($validated['status']);
            $reason = $validated['reason'] ?: 'Status updated by administrator';

            $balance = LoyaltyService::getOrCreateBalance($user);
            $balance->is_locked = in_array($newStatus, ['suspended', 'inactive', 'deactivated', 'locked']);
            $balance->locked_reason = $balance->is_locked ? $reason : null;
            $balance->save();

            $user->membership_status = $balance->is_locked ? 'Suspended' : 'Active';
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Customer loyalty status updated successfully.',
                'data' => [
                    'is_locked' => $balance->is_locked,
                    'status' => $balance->is_locked ? 'Suspended' : 'Active',
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
