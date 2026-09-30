<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceAffiliate;
use Illuminate\Support\Facades\Schema;

class EcommerceAffiliateController extends Controller
{
    public function getSettings(Request $request)
    {
        $settings = \App\Models\SiteSetting::first();
        return response()->json([
            'success' => true,
            'data' => [
                'referral_reward_referrer' => $settings ? (float)$settings->referral_reward_referrer : 0.00,
                'referral_reward_referee' => $settings ? (float)$settings->referral_reward_referee : 0.00,
                'referral_commission_percentage' => $settings ? (float)$settings->referral_commission_percentage : 0.00,
            ]
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'referral_reward_referrer' => 'required|numeric|min:0',
            'referral_reward_referee' => 'required|numeric|min:0',
            'referral_commission_percentage' => 'required|numeric|between:0,100',
        ]);

        $settings = \App\Models\SiteSetting::first();
        if (!$settings) {
            $settings = new \App\Models\SiteSetting();
        }

        $settings->referral_reward_referrer = $validated['referral_reward_referrer'];
        $settings->referral_reward_referee = $validated['referral_reward_referee'];
        $settings->referral_commission_percentage = $validated['referral_commission_percentage'];
        $settings->save();

        return response()->json([
            'success' => true,
            'message' => 'Affiliate settings updated successfully.',
            'data' => [
                'referral_reward_referrer' => (float)$settings->referral_reward_referrer,
                'referral_reward_referee' => (float)$settings->referral_reward_referee,
                'referral_commission_percentage' => (float)$settings->referral_commission_percentage,
            ]
        ]);
    }

    public function referralsList(Request $request)
    {
        $referrals = \App\Models\EcommerceReferral::with(['referrer', 'referred'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $referrals
        ]);
    }

    public function referralCommissionsList(Request $request)
    {
        $commissions = \App\Models\EcommerceReferralCommission::with(['referrer', 'referred', 'order'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $commissions
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        
        // Check if admin or admin route to return all affiliates
        if ($request->is('*admin/*') || ($user && ($user->hasAdminAccess() || $user->isSuperAdmin()))) {
            return response()->json(['success' => true, 'data' => EcommerceAffiliate::with('user')->orderBy('created_at', 'desc')->get()]);
        }
        
        if (!$user) {
            return response()->json(['success' => true, 'data' => []]);
        }
        
        // Get by user_id if column exists, otherwise all
        if (Schema::hasColumn((new EcommerceAffiliate)->getTable(), 'user_id')) {
            $query = EcommerceAffiliate::where('user_id', $user->id)->with('user');
            return response()->json(['success' => true, 'data' => $query->get()]);
        }

        return response()->json(['success' => true, 'data' => EcommerceAffiliate::with('user')->get()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Check if already an affiliate
        $existing = EcommerceAffiliate::where('user_id', $user->id)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'data' => $existing,
                'message' => 'You are already registered as an affiliate.'
            ]);
        }

        $data = $request->all();
        $data['user_id'] = $user->id;
        $data['status'] = 'Active';
        $data['total_earnings'] = 0.00;
        $data['total_referrals'] = 0;

        // Auto-generate affiliate code if not provided
        if (empty($data['affiliate_code'])) {
            $code = strtoupper(substr($user->name ?? 'PARTNER', 0, 3) . rand(1000, 9999));
            while (EcommerceAffiliate::where('affiliate_code', $code)->exists()) {
                $code = strtoupper(substr($user->name ?? 'PARTNER', 0, 3) . rand(1000, 9999));
            }
            $data['affiliate_code'] = $code;
        } else {
            // Normalize provided code
            $data['affiliate_code'] = strtoupper(preg_replace('/[^A-Za-z0-9-_]/', '', $data['affiliate_code']));
            if (EcommerceAffiliate::where('affiliate_code', $data['affiliate_code'])->exists()) {
                return response()->json(['success' => false, 'message' => 'This affiliate code is already taken.'], 422);
            }
        }

        $item = EcommerceAffiliate::create($data);
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function show(Request $request, $id)
    {
        $item = EcommerceAffiliate::findOrFail($id);
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !$user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $item = EcommerceAffiliate::findOrFail($id);
        $item->update($request->all());
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !$user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $item = EcommerceAffiliate::findOrFail($id);
        $item->delete();
        return response()->json(['success' => true, 'message' => 'Deleted successfully']);
    }

    public function payout(Request $request, $id)
    {
        $commission = \App\Models\EcommerceReferralCommission::findOrFail($id);
        
        if ($commission->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'This commission payout has already been processed.'
            ], 400);
        }

        $commission->update([
            'status' => 'completed',
            'payout_at' => now(),
        ]);

        // Add to referrer's wallet
        $referrer = $commission->referrer;
        if ($referrer) {
            \App\Services\WalletService::adjustWallet(
                $referrer->id,
                $commission->commission_amount,
                'Affiliate Credit',
                'Referral commission payout for order #' . ($commission->order ? $commission->order->order_number : 'N/A'),
                $commission->order_id
            );

            // Increment affiliate earnings
            $affiliate = $referrer->affiliate;
            if ($affiliate) {
                $affiliate->increment('total_earnings', $commission->commission_amount);
            }

            try {
                if ($referrer->email) {
                    $emailService = app(\App\Services\EmailNotificationService::class);
                    $payload = [
                        'customer_name' => $referrer->name,
                        'customer_email' => $referrer->email,
                        'payout_id' => 'PAY-' . $commission->id,
                        'amount' => '$' . number_format((float) $commission->commission_amount, 2),
                        'commission_amount' => '$' . number_format((float) $commission->commission_amount, 2),
                        'referral_code' => $affiliate?->affiliate_code ?: 'N/A',
                        'product_name' => 'Referral Order #' . ($commission->order ? $commission->order->order_number : 'N/A'),
                        'site_name' => config('app.name', 'Mecarvi Embroidery'),
                    ];

                    $emailService->sendEvent('customer_pay_out', $payload, $referrer->email);
                    $emailService->sendEvent('customer_referral_commission', $payload, $referrer->email);
                    $emailService->sendEvent('referral_product_commission', $payload, $referrer->email);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Affiliate payout email notification failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout processed successfully.',
            'data' => $commission->load(['referrer', 'referred', 'order'])
        ]);
    }

    public function reversePayout(Request $request, $id)
    {
        $validated = $request->validate([
            'reversal_amount' => 'required|numeric|min:0.01',
            'reversal_type' => 'nullable|string|in:full,partial',
            'reason' => 'required|string|max:255',
            'detailed_reason' => 'required|string|max:500',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $commission = \App\Models\EcommerceReferralCommission::find($id);
        
        $reversalAmount = (float) $validated['reversal_amount'];
        $reason = $validated['reason'];
        $detailedReason = $validated['detailed_reason'];
        $adminNotes = $validated['admin_notes'] ?? null;

        if ($commission) {
            $maxReversible = (float) $commission->commission_amount;
            if ($reversalAmount > $maxReversible) {
                return response()->json([
                    'success' => false,
                    'message' => "Reversal amount (\${$reversalAmount}) cannot exceed the payout amount (\${$maxReversible})."
                ], 422);
            }

            $commission->update([
                'status' => $reversalAmount >= $maxReversible ? 'reversed' : 'partially_reversed',
            ]);

            $referrer = $commission->referrer;
            if ($referrer) {
                // Debit funds from customer's affiliate wallet balance
                \App\Services\WalletService::adjustWallet(
                    $referrer->id,
                    -$reversalAmount,
                    'Affiliate Reversal',
                    "Payout reversal ({$reason}): {$detailedReason}",
                    $commission->order_id
                );

                // Adjust affiliate earnings
                $affiliate = $referrer->affiliate;
                if ($affiliate) {
                    $newEarnings = max(0, (float)$affiliate->total_earnings - $reversalAmount);
                    $affiliate->update(['total_earnings' => $newEarnings]);
                }

                try {
                    if ($referrer->email) {
                        $emailService = app(\App\Services\EmailNotificationService::class);
                        $payload = [
                            'customer_name' => $referrer->name,
                            'customer_email' => $referrer->email,
                            'payout_id' => 'PAYOUT-' . $commission->id,
                            'amount' => '$' . number_format($reversalAmount, 2),
                            'reason' => $reason,
                            'detailed_reason' => $detailedReason,
                            'site_name' => config('app.name', 'Mecarvi Embroidery'),
                        ];
                        $emailService->sendEvent('affiliate_payout_reversed', $payload, $referrer->email);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Affiliate payout reversal email notification failed: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payout reversed successfully. Funds have been debited from the customer affiliate balance.',
                'data' => [
                    'payout_id' => 'PAYOUT-' . $commission->id,
                    'reversal_amount' => $reversalAmount,
                    'status' => $commission->status,
                    'reason' => $reason,
                    'detailed_reason' => $detailedReason,
                    'admin_notes' => $adminNotes,
                    'reversed_at' => now()->toIso8601String(),
                ]
            ]);
        }

        // If reversing a standalone affiliate record or generic payout reference
        $affiliate = \App\Models\EcommerceAffiliate::find($id);
        if ($affiliate && $affiliate->user) {
            \App\Services\WalletService::adjustWallet(
                $affiliate->user->id,
                -$reversalAmount,
                'Affiliate Reversal',
                "Payout reversal ({$reason}): {$detailedReason}"
            );
            $newEarnings = max(0, (float)$affiliate->total_earnings - $reversalAmount);
            $affiliate->update(['total_earnings' => $newEarnings]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout reversed successfully. Funds have been debited from the customer affiliate balance.',
            'data' => [
                'payout_id' => is_numeric($id) ? 'PAYOUT-' . $id : $id,
                'reversal_amount' => $reversalAmount,
                'status' => 'reversed',
                'reason' => $reason,
                'detailed_reason' => $detailedReason,
                'admin_notes' => $adminNotes,
                'reversed_at' => now()->toIso8601String(),
            ]
        ]);
    }

    public function myReferrals(Request $request)
    {
        $user = $request->user();
        $referrals = \App\Models\EcommerceReferral::where('referrer_id', $user->id)
            ->with(['referred'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $referrals
        ]);
    }

    public function applicationsList(Request $request)
    {
        $applications = \App\Models\EcommerceAffiliateApplication::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $applications
        ]);
    }

    public function approveApplication(Request $request, $id)
    {
        $app = \App\Models\EcommerceAffiliateApplication::findOrFail($id);
        if ($app->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Application has already been processed.'], 400);
        }

        $app->update(['status' => 'approved']);

        // Check if affiliate already exists for this user
        $exists = EcommerceAffiliate::where('user_id', $app->user_id)->exists();
        if (!$exists) {
            $user = \App\Models\User::findOrFail($app->user_id);
            // Generate a unique affiliate code
            $code = strtoupper(substr($user->name ?? 'PARTNER', 0, 3) . rand(1000, 9999));
            while (EcommerceAffiliate::where('affiliate_code', $code)->exists()) {
                $code = strtoupper(substr($user->name ?? 'PARTNER', 0, 3) . rand(1000, 9999));
            }

            EcommerceAffiliate::create([
                'user_id' => $app->user_id,
                'affiliate_code' => $code,
                'total_earnings' => 0.00,
                'total_referrals' => 0,
                'status' => 'Active',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Affiliate application approved successfully.',
        ]);
    }

    public function rejectApplication(Request $request, $id)
    {
        $app = \App\Models\EcommerceAffiliateApplication::findOrFail($id);
        if ($app->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Application has already been processed.'], 400);
        }

        $app->update(['status' => 'rejected']);

        return response()->json([
            'success' => true,
            'message' => 'Affiliate application rejected successfully.',
        ]);
    }

    public function apply(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Check if already an affiliate
        if (EcommerceAffiliate::where('user_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'You are already an affiliate.'], 400);
        }

        // Check if pending application exists
        $pending = \App\Models\EcommerceAffiliateApplication::where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();
        if ($pending) {
            return response()->json(['success' => false, 'message' => 'You already have a pending application.'], 400);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $app = \App\Models\EcommerceAffiliateApplication::create([
            'user_id' => $user->id,
            'reason' => $validated['reason'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data' => $app,
            'message' => 'Affiliate application submitted successfully.',
        ]);
    }

    public function myCommissions(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $commissions = \App\Models\EcommerceReferralCommission::where('referrer_id', $user->id)
            ->with(['referred', 'order'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $commissions
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:Active,Inactive,Banned',
            'reason' => 'nullable|string|max:255',
            'notes'  => 'nullable|string|max:500',
        ]);

        $item = EcommerceAffiliate::findOrFail($id);
        $item->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Affiliate status updated to ' . $validated['status'] . '.',
            'data'    => $item->load('user'),
        ]);
    }

    public function getStats(Request $request)
    {
        $total    = EcommerceAffiliate::count();
        $active   = EcommerceAffiliate::where('status', 'Active')->count();
        $inactive = EcommerceAffiliate::where('status', 'Inactive')->count();
        $banned   = EcommerceAffiliate::where('status', 'Banned')->count();

        return response()->json([
            'success' => true,
            'data'    => compact('total', 'active', 'inactive', 'banned'),
        ]);
    }
}

