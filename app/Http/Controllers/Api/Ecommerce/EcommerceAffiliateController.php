<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceAffiliate;
use Illuminate\Support\Facades\Schema;
use App\Services\AffiliateCommissionService;
use App\Services\AffiliatePayoutService;

class EcommerceAffiliateController extends Controller
{
    private function requireAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user->hasAdminAccess() || $user->isSuperAdmin())) {
            abort(response()->json(['success' => false, 'message' => 'Forbidden.'], 403));
        }

        return $user;
    }

    public function getSettings(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => AffiliateCommissionService::settings(),
        ]);
    }

    /** Public, read-only subset of the settings for the storefront / user / business panels. */
    public function publicSettings(Request $request)
    {
        $s = AffiliateCommissionService::settings();
        $public = [
            'program_active', 'allow_registrations', 'registration_type', 'require_admin_approval',
            'cookie_duration', 'holding_period', 'earn_order', 'earn_order_rate_type',
            'referral_commission_percentage', 'earn_sign_referrer', 'earn_sign_referrer_rate_type',
            'referral_reward_referrer', 'earn_sign_referee', 'earn_sign_referee_rate_type',
            'referral_reward_referee', 'earn_membership', 'earn_membership_rate_type', 'earn_membership_value',
            'earn_gift_card', 'earn_gift_card_rate_type', 'earn_gift_card_value',
            'recurring_commissions', 'recurring_duration_days', 'payout_methods',
            'require_min_payout', 'min_payout_amount',
        ];

        return response()->json(['success' => true, 'data' => array_intersect_key($s, array_flip($public))]);
    }

    public function updateSettings(Request $request)
    {
        $this->requireAdmin($request);

        $request->validate([
            'recurring_commissions' => 'sometimes|boolean',
            'recurring_duration_days' => 'sometimes|integer|min:1|max:3650',
            'holding_period' => 'sometimes|integer|min:0|max:365',
            'referral_commission_percentage' => 'sometimes|numeric|min:0|max:100',
            'referral_reward_referrer' => 'sometimes|numeric|min:0',
            'referral_reward_referee' => 'sometimes|numeric|min:0',
            'earn_membership_value' => 'sometimes|numeric|min:0',
            'earn_gift_card_value' => 'sometimes|numeric|min:0',
            'max_commission_amount' => 'sometimes|numeric|min:0',
            'review_threshold' => 'sometimes|numeric|min:0',
            'min_payout_amount' => 'sometimes|numeric|min:0',
        ]);

        $settings = \App\Models\SiteSetting::first() ?: new \App\Models\SiteSetting();

        // Only accept known setting keys.
        $allowed = array_keys(AffiliateCommissionService::defaults());
        $input = array_intersect_key($request->all(), array_flip($allowed));

        // Sync legacy columns if present
        foreach (['referral_commission_percentage', 'referral_reward_referrer', 'referral_reward_referee'] as $col) {
            if (array_key_exists($col, $input)) {
                $settings->{$col} = (float) $input[$col];
            }
        }

        $existing = $settings->affiliate_settings;
        if (is_string($existing)) {
            $existing = json_decode($existing, true);
        }
        $updatedSettings = array_merge(is_array($existing) ? $existing : [], $input);

        $settings->affiliate_settings = $updatedSettings;
        $settings->save();

        return response()->json([
            'success' => true,
            'message' => 'Affiliate settings updated successfully.',
            'data' => AffiliateCommissionService::settings(),
        ]);
    }

    public function referralsList(Request $request)
    {
        $this->requireAdmin($request);
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
        $this->requireAdmin($request);
        $commissions = \App\Models\EcommerceReferralCommission::with(['referrer', 'referred', 'order'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('source_type'), fn ($q) => $q->where('source_type', $request->get('source_type')))
            ->when($request->boolean('flagged'), fn ($q) => $q->where('flagged', true))
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

        $settings = AffiliateCommissionService::settings();
        if (!AffiliateCommissionService::flag($settings, 'program_active') || !AffiliateCommissionService::flag($settings, 'allow_registrations')) {
            return response()->json(['success' => false, 'message' => 'The affiliate program is not accepting registrations.'], 403);
        }

        if (($settings['registration_type'] ?? '') === 'Require Manual Application') {
            return response()->json([
                'success' => false,
                'message' => 'The affiliate program requires manual application approval. Please submit an application.',
                'requires_application' => true,
            ], 422);
        }

        if (($settings['registration_type'] ?? '') === 'Invite Only' && !$request->filled('invite_code')) {
            return response()->json([
                'success' => false,
                'message' => 'Registration is currently by invitation only.',
            ], 403);
        }

        $status = AffiliateCommissionService::flag($settings, 'require_admin_approval') ? 'Pending' : 'Active';

        $data = [
            'user_id' => $user->id,
            'status' => $status,
            'total_earnings' => 0.00,
            'total_referrals' => 0,
        ];

        $requested = $request->input('affiliate_code');
        if (empty($requested)) {
            $data['affiliate_code'] = AffiliateCommissionService::generateCode($user);
        } else {
            $data['affiliate_code'] = strtoupper(preg_replace('/[^A-Za-z0-9-_]/', '', $requested));
            if (EcommerceAffiliate::where('affiliate_code', $data['affiliate_code'])->exists()) {
                return response()->json(['success' => false, 'message' => 'This affiliate code is already taken.'], 422);
            }
        }

        if ($request->filled('payout_method')) {
            $data['payout_method'] = AffiliatePayoutService::normalizeMethod($request->input('payout_method'));
        }
        if ($request->filled('payout_details')) {
            $data['payout_details'] = is_array($request->input('payout_details')) ? json_encode($request->input('payout_details')) : $request->input('payout_details');
        }

        $item = EcommerceAffiliate::create($data);
        return response()->json([
            'success' => true,
            'data' => $item,
            'message' => $status === 'Pending' ? 'Affiliate account created and pending admin approval.' : 'Affiliate registered successfully.',
        ]);
    }

    public function show(Request $request, $id)
    {
        $item = EcommerceAffiliate::findOrFail($id);
        $user = $request->user();
        $isAdmin = $user && ($user->hasAdminAccess() || $user->isSuperAdmin());
        if (!$isAdmin && (!$user || (int) $item->user_id !== (int) $user->id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

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

    private function notifyCommissionPaid($commission): void
    {
        try {
            $referrer = $commission->referrer;
            if ($referrer && $referrer->email) {
                $affiliate = $referrer->affiliate;
                $label = $commission->order ? $commission->order->order_number : ucfirst(str_replace('_', ' ', $commission->source_type));
                $emailService = app(\App\Services\EmailNotificationService::class);
                $payload = [
                    'customer_name' => $referrer->name,
                    'customer_email' => $referrer->email,
                    'payout_id' => 'PAY-' . $commission->id,
                    'amount' => '$' . number_format((float) $commission->commission_amount, 2),
                    'commission_amount' => '$' . number_format((float) $commission->commission_amount, 2),
                    'referral_code' => $affiliate?->affiliate_code ?: 'N/A',
                    'product_name' => 'Referral ' . $label,
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

    /** Admin approves a pending commission (credits wallet immediately unless a holding period applies). */
    public function approveCommission(Request $request, $id)
    {
        $admin = $this->requireAdmin($request);
        $commission = \App\Models\EcommerceReferralCommission::findOrFail($id);

        try {
            $commission = AffiliateCommissionService::approve($commission, $admin->id, $request->boolean('release_now'));
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($commission->status === 'completed') {
            $this->notifyCommissionPaid($commission);
        }

        return response()->json([
            'success' => true,
            'message' => $commission->status === 'completed'
                ? 'Commission approved and credited to the affiliate wallet.'
                : 'Commission approved. It will be released after the holding period.',
            'data' => $commission->load(['referrer', 'referred', 'order']),
        ]);
    }

    public function declineCommission(Request $request, $id)
    {
        $admin = $this->requireAdmin($request);
        $validated = $request->validate(['reason' => 'nullable|string|max:255']);
        $commission = \App\Models\EcommerceReferralCommission::findOrFail($id);

        try {
            $commission = AffiliateCommissionService::decline($commission, $admin->id, $validated['reason'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Commission declined.',
            'data' => $commission->load(['referrer', 'referred', 'order']),
        ]);
    }

    public function bulkCommissionAction(Request $request)
    {
        $admin = $this->requireAdmin($request);
        $validated = $request->validate([
            'action' => 'required|in:approve,decline',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'reason' => 'nullable|string|max:255',
        ]);

        $done = 0;
        $failed = [];
        foreach (\App\Models\EcommerceReferralCommission::whereIn('id', $validated['ids'])->get() as $commission) {
            try {
                if ($validated['action'] === 'approve') {
                    $commission = AffiliateCommissionService::approve($commission, $admin->id);
                    if ($commission->status === 'completed') {
                        $this->notifyCommissionPaid($commission);
                    }
                } else {
                    AffiliateCommissionService::decline($commission, $admin->id, $validated['reason'] ?? null);
                }
                $done++;
            } catch (\DomainException $e) {
                $failed[$commission->id] = $e->getMessage();
            }
        }

        return response()->json(['success' => true, 'processed' => $done, 'failed' => $failed]);
    }

    /** Immediate release of a pending/approved commission to the wallet (skips holding period). */
    public function payout(Request $request, $id)
    {
        $admin = $this->requireAdmin($request);
        $commission = \App\Models\EcommerceReferralCommission::findOrFail($id);

        if ($commission->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'This commission payout has already been processed.'
            ], 400);
        }
        if (!in_array($commission->status, ['pending', 'approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => "A {$commission->status} commission cannot be paid out."
            ], 422);
        }

        if ($commission->status === 'pending') {
            $commission->update(['approved_at' => now(), 'approved_by' => $admin->id]);
        }

        AffiliateCommissionService::credit($commission);
        $commission = $commission->fresh();
        $this->notifyCommissionPaid($commission);

        return response()->json([
            'success' => true,
            'message' => 'Payout processed successfully.',
            'data' => $commission->load(['referrer', 'referred', 'order'])
        ]);
    }

    /** The signed-in user's unique referral code / link (created on first request). */
    public function myLink(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $affiliate = AffiliateCommissionService::ensureAffiliateFor($user);

        return response()->json(['success' => true, 'data' => $affiliate]);
    }

    /** Public: count a click on a referral link. */
    public function trackClick(Request $request, $code)
    {
        $affiliate = EcommerceAffiliate::where('affiliate_code', strtoupper($code))->where('status', 'Active')->first();
        if ($affiliate && AffiliateCommissionService::flag(AffiliateCommissionService::settings(), 'program_active')) {
            $affiliate->increment('total_clicks');
        }

        return response()->json([
            'success' => (bool) $affiliate,
            'cookie_duration' => (int) (AffiliateCommissionService::settings()['cookie_duration'] ?? 30),
        ]);
    }

    public function reversePayout(Request $request, $id)
    {
        $this->requireAdmin($request);
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
            if (!in_array($commission->status, ['completed', 'partially_reversed'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only commissions already paid to the wallet can be reversed. Decline pending commissions instead.',
                ], 422);
            }

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
        $this->requireAdmin($request);
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
        $this->requireAdmin($request);
        $app = \App\Models\EcommerceAffiliateApplication::findOrFail($id);
        if ($app->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Application has already been processed.'], 400);
        }

        $app->update([
            'status' => 'approved',
            'review_note' => $request->input('review_note'),
        ]);

        // Check if affiliate already exists for this user
        $affiliate = EcommerceAffiliate::where('user_id', $app->user_id)->first();
        if (!$affiliate) {
            $user = \App\Models\User::findOrFail($app->user_id);
            $code = AffiliateCommissionService::generateCode($user);

            $affiliate = EcommerceAffiliate::create([
                'user_id' => $app->user_id,
                'affiliate_code' => $code,
                'payout_method' => $app->payout_method,
                'payout_details' => $app->payout_details,
                'tax_info' => $app->tax_info,
                'tax_info_submitted_at' => $app->tax_info ? now() : null,
                'total_earnings' => 0.00,
                'total_referrals' => 0,
                'status' => 'Active',
            ]);
        } else {
            $affiliate->update([
                'status' => 'Active',
                'payout_method' => $app->payout_method ?: $affiliate->payout_method,
                'payout_details' => $app->payout_details ?: $affiliate->payout_details,
                'tax_info' => $app->tax_info ?: $affiliate->tax_info,
                'tax_info_submitted_at' => $app->tax_info ? now() : $affiliate->tax_info_submitted_at,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Affiliate application approved successfully.',
            'data' => $affiliate,
        ]);
    }

    public function rejectApplication(Request $request, $id)
    {
        $this->requireAdmin($request);
        $app = \App\Models\EcommerceAffiliateApplication::findOrFail($id);
        if ($app->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Application has already been processed.'], 400);
        }

        $app->update([
            'status' => 'rejected',
            'review_note' => $request->input('review_note', $request->input('reason')),
        ]);

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

        $settings = AffiliateCommissionService::settings();
        if (!AffiliateCommissionService::flag($settings, 'program_active') || !AffiliateCommissionService::flag($settings, 'allow_registrations')) {
            return response()->json(['success' => false, 'message' => 'The affiliate program is not accepting registrations.'], 403);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
            'payout_method' => 'nullable|string|max:30',
            'payout_details' => 'nullable',
            'tax_info' => 'nullable',
        ]);

        if (AffiliateCommissionService::flag($settings, 'require_payout_method') && empty($validated['payout_method'])) {
            return response()->json(['success' => false, 'message' => 'Payout method is required for affiliate registration.'], 422);
        }
        if (AffiliateCommissionService::flag($settings, 'require_tax_info') && empty($validated['tax_info'])) {
            return response()->json(['success' => false, 'message' => 'Tax information is required for affiliate registration.'], 422);
        }

        $payoutMethod = !empty($validated['payout_method']) ? AffiliatePayoutService::normalizeMethod($validated['payout_method']) : null;
        $payoutDetails = !empty($validated['payout_details']) ? (is_array($validated['payout_details']) ? json_encode($validated['payout_details']) : $validated['payout_details']) : null;
        $taxInfo = !empty($validated['tax_info']) ? (is_array($validated['tax_info']) ? json_encode($validated['tax_info']) : $validated['tax_info']) : null;

        $autoApprove = !AffiliateCommissionService::flag($settings, 'require_admin_approval');

        $app = \App\Models\EcommerceAffiliateApplication::create([
            'user_id' => $user->id,
            'reason' => $validated['reason'] ?? null,
            'payout_method' => $payoutMethod,
            'payout_details' => $payoutDetails,
            'tax_info' => $taxInfo,
            'status' => $autoApprove ? 'approved' : 'pending',
        ]);

        if ($autoApprove) {
            $code = AffiliateCommissionService::generateCode($user);
            $affiliate = EcommerceAffiliate::create([
                'user_id' => $user->id,
                'affiliate_code' => $code,
                'payout_method' => $payoutMethod,
                'payout_details' => $payoutDetails,
                'tax_info' => $taxInfo,
                'tax_info_submitted_at' => $taxInfo ? now() : null,
                'total_earnings' => 0.00,
                'total_referrals' => 0,
                'status' => 'Active',
            ]);

            return response()->json([
                'success' => true,
                'data' => $affiliate,
                'message' => 'Affiliate application approved automatically. You are now an active affiliate.',
            ]);
        }

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
        $this->requireAdmin($request);
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
        $this->requireAdmin($request);
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

