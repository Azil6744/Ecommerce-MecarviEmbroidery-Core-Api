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

    private function formatPayableCommission($c): array
    {
        $source = strtolower((string) ($c->source_type ?: 'order'));
        $referredName = $c->referred?->name ?: ($c->order?->customer_name ?: ($c->referred?->email ?: 'Customer'));
        $referredEmail = $c->referred?->email ?: ($c->order?->customer_email ?: '');
        $pct = (float) ($c->commission_percentage ?? 0);
        $netAmount = max(0.0, round((float) $c->commission_amount - (float) ($c->refunded_amount ?? 0), 2));
        $rateStr = $pct > 0
            ? (rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%')
            : ('$' . rtrim(rtrim(number_format($netAmount, 2), '0'), '.') . ' (flat)');

        if ($source === 'signup') {
            $type = 'New Sign Up';
            $iconType = 'user';
            $detailsTitle = 'Referred: ' . $referredName;
            $detailsSubtitle = null;
        } elseif ($source === 'membership') {
            $type = 'Membership Referral';
            $iconType = 'crown';
            $detailsTitle = 'Referred: ' . $referredName;
            $detailsSubtitle = $c->source_ref ? ('Plan / Ref: ' . $c->source_ref) : 'Membership Plan';
        } elseif ($source === 'gift_card') {
            $type = 'Gift Card Referral';
            $iconType = 'gift';
            $refNum = $c->source_ref ?: ('GC-' . $c->id);
            $detailsTitle = 'Order #' . ltrim((string) $refNum, '#');
            $detailsSubtitle = 'Customer: ' . $referredName;
        } else {
            $type = 'Order Purchase';
            $iconType = 'cart';
            $orderNum = $c->order?->order_number ?: ($c->source_ref ?: ('ORD-' . ($c->order_id ?: $c->id)));
            $detailsTitle = 'Order #' . ltrim((string) $orderNum, '#');
            $detailsSubtitle = 'Customer: ' . $referredName;
        }

        return [
            'id' => (int) $c->id,
            'date' => $c->created_at ? $c->created_at->format('M d, Y') : '',
            'type' => $type,
            'iconType' => $iconType,
            'detailsTitle' => $detailsTitle,
            'detailsSubtitle' => $detailsSubtitle,
            'rate' => $rateStr,
            'amount' => $netAmount,
            'source_type' => $source,
            'status' => $c->status,
        ];
    }

    public function referralCommissionsList(Request $request)
    {
        $this->requireAdmin($request);
        AffiliateCommissionService::syncSignupCommissions();

        $commissions = \App\Models\EcommerceReferralCommission::with(['referrer.affiliate', 'referred', 'order.items'])
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

    public function getCommissionDetails(Request $request, $id)
    {
        $this->requireAdmin($request);
        AffiliateCommissionService::syncSignupCommissions();

        $cleanId = null;
        if (is_numeric($id)) {
            $cleanId = (int) $id;
        } elseif (is_string($id) && preg_match('/^(?:COMMISSION|AFF|TRX|ORD|REF)[-_]?(\d+)$/i', $id, $matches)) {
            $cleanId = (int) $matches[1];
        }

        $commission = null;
        if ($cleanId !== null) {
            $commission = \App\Models\EcommerceReferralCommission::with([
                'referrer.affiliate',
                'referred',
                'order.items'
            ])->find($cleanId);
        }

        if (!$commission) {
            return response()->json(['success' => false, 'message' => 'Commission not found.'], 404);
        }

        $referral = null;
        if ($commission->referrer_id && $commission->referred_id) {
            $referral = \App\Models\EcommerceReferral::where('referrer_id', $commission->referrer_id)
                ->where('referred_id', $commission->referred_id)
                ->first();
        }

        // Determine commission type: signup vs purchase
        $requestedType = $request->get('type');
        $rawSource = $commission->source_type ?? 'order';
        $isSignup = $requestedType === 'signup' || ($requestedType !== 'purchase' && in_array(strtolower($rawSource), ['signup', 'new sign up', 'user_signup', 'registration']));

        // Referrer details
        $referrer = $commission->referrer;
        $referrerAffiliate = $referrer?->affiliate ?: EcommerceAffiliate::where('user_id', $commission->referrer_id)->first();
        $referrerName = $referrer?->name ?: ($referrer?->email ?: 'Affiliate');
        $nameParts = explode(' ', trim($referrerName));
        $referrerInitials = strtoupper(
            (isset($nameParts[0][0]) ? $nameParts[0][0] : '') .
            (isset($nameParts[1][0]) ? $nameParts[1][0] : (isset($nameParts[0][1]) ? $nameParts[0][1] : 'A'))
        ) ?: 'AF';
        $referrerCode = $referrerAffiliate?->affiliate_code ?: ($referrer?->customer_account_number ?: ('AFF-' . $commission->referrer_id));
        $referrerEmail = $referrer?->email ?: '—';
        $referrerPhone = $referrer?->phone ?: '—';
        $referrerLocation = $referrer?->country ?: ($referrer?->address ?: 'United States');
        $referrerStatus = $referrerAffiliate?->status ?: ($referrer?->status ?: 'Active');

        // Referred customer details
        $referred = $commission->referred;
        $referredName = $referred?->name ?: ($referral?->referred_email ?: ($commission->order?->customer_name ?: 'Customer'));
        $refNameParts = explode(' ', trim($referredName));
        $referredInitials = strtoupper(
            (isset($refNameParts[0][0]) ? $refNameParts[0][0] : '') .
            (isset($refNameParts[1][0]) ? $refNameParts[1][0] : (isset($refNameParts[0][1]) ? $refNameParts[0][1] : 'C'))
        ) ?: 'CU';
        $referredCode = $referred?->customer_account_number ?: ($referred?->id ? ('CUS-' . str_pad((string) $referred->id, 4, '0', STR_PAD_LEFT)) : '—');
        $referredEmail = $referred?->email ?: ($referral?->referred_email ?: ($commission->order?->customer_email ?: '—'));
        $referredPhone = $referred?->phone ?: ($commission->order?->customer_phone ?: '—');
        $referredLocation = $referred?->country ?: ($referred?->address ?: 'United States');
        $referredStatus = $referred?->status ?: 'Active';

        // Commission amounts
        $referrerCommissionAmount = (float) ($commission->commission_amount ?? ($referral?->reward_amount_referrer ?? 0.00));
        $refereeCommissionAmount = (float) ($referral?->reward_amount_referee ?? 0.00);
        $commissionRate = (float) ($commission->commission_percentage ?? 0.00);

        // Order details (for signup commissions, also check if referred customer placed an order)
        $order = $commission->order;
        if (!$order && $commission->referred_id) {
            $order = \App\Models\EcommerceOrder::with('items')
                ->where('user_id', $commission->referred_id)
                ->orderBy('created_at', 'asc')
                ->first();
        }
        $orderNumRaw = $order?->order_number ?: ($commission->source_ref && !$isSignup ? $commission->source_ref : null);
        $orderId = $orderNumRaw ? ('#' . ltrim((string) $orderNumRaw, '#')) : '—';
        $orderTotal = (float) ($order?->total_amount ?: ($commission->order_amount ?: 0.00));
        $orderDate = $order?->created_at ? $order->created_at->format('M d, Y') : ($commission->created_at ? $commission->created_at->format('M d, Y') : '—');

        // Real Order Items
        $orderItems = [];
        if ($order && $order->items && $order->items->count() > 0) {
            foreach ($order->items as $idx => $item) {
                $opts = is_array($item->product_options)
                    ? $item->product_options
                    : (is_string($item->product_options) ? (json_decode($item->product_options, true) ?: []) : []);
                $color = $opts['color'] ?? $opts['Color'] ?? null;
                $size = $opts['size'] ?? $opts['Size'] ?? null;
                $style = $opts['style'] ?? $opts['Style'] ?? null;

                $descParts = [];
                if ($color) $descParts[] = "Color: {$color}";
                if ($size) $descParts[] = "Size: {$size}";
                if ($style) $descParts[] = "Style: {$style}";

                $orderItems[] = [
                    'name' => $item->product_name ?: 'Order Item #' . ($idx + 1),
                    'variant' => count($descParts) ? implode(' | ', $descParts) : 'Standard',
                    'sku' => $item->product_sku ? ('SKU: ' . $item->product_sku) : '—',
                    'quantity' => (int) ($item->quantity ?: 1),
                    'price' => (float) ($item->unit_price ?: ($item->total_price ?: 0.00)),
                    'image' => $item->product_image ?: '/assets/images/affiliate-commission/hoodie.png',
                ];
            }
        }

        $joinTimeStr = $referred?->created_at
            ? $referred->created_at->format('M d, Y, h:i A')
            : ($referral?->created_at ? $referral->created_at->format('M d, Y, h:i A') : ($commission->created_at ? $commission->created_at->format('M d, Y, h:i A') : '—'));
        $orderTimeStr = $order?->created_at
            ? $order->created_at->format('M d, Y, h:i A')
            : ($commission->created_at ? $commission->created_at->format('M d, Y, h:i A') : '—');
        $approvedTimeStr = $commission->approved_at
            ? $commission->approved_at->format('M d, Y, h:i A')
            : (in_array(strtolower($commission->status ?? ''), ['approved', 'completed'], true) && $commission->created_at ? $commission->created_at->format('M d, Y, h:i A') : 'Pending');

        $approverName = 'Admin';
        if ($commission->approved_by) {
            $approverUser = \App\Models\User::find($commission->approved_by);
            if ($approverUser) {
                $approverName = $approverUser->name;
            }
        } elseif ($isSignup) {
            $approverName = 'System (Auto-Approved)';
        }

        $isApprovedOrCompleted = in_array(strtolower($commission->status ?? ''), ['approved', 'completed'], true);

        // Dynamic completion timeline for Referral Sign Up
        $timelineSignup = [
            [
                'step' => 'Account Created',
                'time' => $joinTimeStr,
                'note' => "{$referredName} created an account using referral code {$referrerCode}.",
                'status' => 'completed',
            ],
            [
                'step' => 'Referral Verified',
                'time' => $joinTimeStr,
                'note' => "Referral link attribution verified for {$referredEmail}.",
                'status' => 'completed',
            ],
            ...(
                $order
                    ? [[
                        'step' => 'First Purchase Placed',
                        'time' => $orderTimeStr,
                        'note' => "Referred customer placed order {$orderId} for $" . number_format($orderTotal, 2) . ".",
                        'status' => 'completed',
                    ]]
                    : []
            ),
            [
                'step' => $isApprovedOrCompleted ? 'Commission Approved' : 'Commission Status: ' . ucfirst((string) $commission->status),
                'time' => $approvedTimeStr,
                'note' => $isApprovedOrCompleted
                    ? "Sign-up commission of $" . number_format($referrerCommissionAmount, 2) . " approved by {$approverName}."
                    : "Commission is currently {$commission->status}.",
                'status' => $isApprovedOrCompleted ? 'completed' : 'pending',
            ],
        ];

        // Dynamic completion timeline for Referral Purchase
        $timelinePurchase = [
            [
                'step' => 'Referral Attributed',
                'time' => $joinTimeStr,
                'note' => "{$referredName} linked to affiliate {$referrerName} ({$referrerCode}).",
                'status' => 'completed',
            ],
            [
                'step' => 'Order Placed',
                'time' => $orderTimeStr,
                'note' => $order
                    ? "Referred customer placed order {$orderId} for $" . number_format($orderTotal, 2) . "."
                    : "Commission recorded for $" . number_format($orderTotal, 2) . ".",
                'status' => 'completed',
            ],
            [
                'step' => 'Commission Calculated',
                'time' => $commission->created_at ? $commission->created_at->format('M d, Y, h:i A') : $orderTimeStr,
                'note' => "Calculated $" . number_format($referrerCommissionAmount, 2) . ($commissionRate > 0 ? " ({$commissionRate}%)" : "") . " commission.",
                'status' => 'completed',
            ],
            [
                'step' => $isApprovedOrCompleted ? 'Commission Approved' : 'Commission Status: ' . ucfirst((string) $commission->status),
                'time' => $approvedTimeStr,
                'note' => $isApprovedOrCompleted
                    ? "Commission approved by {$approverName}."
                    : ($commission->decline_reason ?: "Commission is currently {$commission->status}."),
                'status' => $isApprovedOrCompleted ? 'completed' : 'pending',
            ],
        ];

        $activeTimeline = $isSignup ? $timelineSignup : $timelinePurchase;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $commission->id,
                'program' => 'Affiliate Program',
                'commission_type' => $isSignup
                    ? 'New Sign-Up (Referral)'
                    : ($rawSource === 'membership' ? 'Membership Referral' : ($rawSource === 'gift_card' ? 'Gift Card Referral' : 'Referral Purchase')),
                'type_category' => $isSignup ? 'signup' : 'purchase',
                'attribution' => 'Referral Link',
                'referee_join_date' => $referred?->created_at ? $referred->created_at->format('M d, Y') : ($referral?->created_at ? $referral->created_at->format('M d, Y') : '—'),
                'order_id' => $orderId,
                'order_date' => $orderDate,
                'payout_method' => $referrerAffiliate?->payout_method ? ucwords(str_replace('_', ' ', $referrerAffiliate->payout_method)) : 'Wallet / Bank Transfer',
                'payout_date' => $commission->payout_at ? $commission->payout_at->format('M d, Y') : '—',
                'transaction_id' => 'TRX-' . ($commission->created_at ? $commission->created_at->format('Ymd') : date('Ymd')) . '-' . str_pad((string) $commission->id, 5, '0', STR_PAD_LEFT),
                'approval_date' => $approvedTimeStr,
                'approved_by' => $isApprovedOrCompleted ? $approverName : '—',
                'current_status' => ucfirst(strtolower($commission->status ?: 'pending')),
                'commission_rate' => $commissionRate,
                'referrer' => [
                    'name' => $referrerName,
                    'initials' => $referrerInitials,
                    'code' => $referrerCode,
                    'email' => $referrerEmail,
                    'phone' => $referrerPhone,
                    'location' => $referrerLocation,
                    'status' => $referrerStatus,
                ],
                'referred_customer' => [
                    'name' => $referredName,
                    'initials' => $referredInitials,
                    'code' => $referredCode,
                    'email' => $referredEmail,
                    'phone' => $referredPhone,
                    'location' => $referredLocation,
                    'status' => $referredStatus,
                ],
                'referrer_commission_amount' => $referrerCommissionAmount,
                'referred_customer_commission_amount' => $refereeCommissionAmount,
                'order' => [
                    'order_id' => $orderId,
                    'order_total' => $orderTotal,
                    'items' => $orderItems,
                ],
                'timeline' => $activeTimeline,
                'timelines' => [
                    'signup' => $timelineSignup,
                    'purchase' => $timelinePurchase,
                ],
                'active_timeline' => $activeTimeline,
            ]
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $request->is('*admin/*') || ($user && ($user->hasAdminAccess() || $user->isSuperAdmin()));

        AffiliateCommissionService::syncSignupCommissions();

        $query = EcommerceAffiliate::with('user');
        if (!$isAdmin) {
            if (!$user) {
                return response()->json(['success' => true, 'data' => []]);
            }
            if (Schema::hasColumn((new EcommerceAffiliate)->getTable(), 'user_id')) {
                $query->where('user_id', $user->id);
            }
        }

        $affiliates = $query->orderBy('created_at', 'desc')->get();

        // Calculate dynamic live earnings, referrals, and balance for each affiliate
        $affiliateUserIds = $affiliates->pluck('user_id')->filter()->unique();
        $affiliateIds = $affiliates->pluck('id')->filter()->unique();

        $allCommissions = \App\Models\EcommerceReferralCommission::with(['referred', 'order'])
            ->whereIn('referrer_id', $affiliateUserIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('referrer_id');

        $allPayouts = \App\Models\EcommerceAffiliatePayout::whereIn('affiliate_id', $affiliateIds)
            ->orWhereIn('user_id', $affiliateUserIds)
            ->orderBy('created_at', 'desc')
            ->get();

        $allPayoutsByAffiliate = $allPayouts->groupBy('affiliate_id');
        $allPayoutsByUser = $allPayouts->groupBy('user_id');

        $allReferralsRewards = \App\Models\EcommerceReferral::whereIn('referrer_id', $affiliateUserIds)
            ->selectRaw('referrer_id, sum(reward_amount_referrer) as total_reward, count(*) as count')
            ->groupBy('referrer_id')
            ->get()
            ->keyBy('referrer_id');

        foreach ($affiliates as $item) {
            $userCommissions = $allCommissions->get($item->user_id, collect());
            $approvedComms = $userCommissions->filter(function ($c) {
                $st = strtolower($c->status ?? '');
                return in_array($st, ['approved', 'completed', 'partially_reversed'], true);
            });

            $nonSignupTotal = (float) $approvedComms
                ->filter(fn ($c) => strtolower((string) $c->source_type) !== AffiliateCommissionService::SOURCE_SIGNUP)
                ->sum(fn ($c) => max(0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

            $signupCommTotal = (float) $approvedComms
                ->filter(fn ($c) => strtolower((string) $c->source_type) === AffiliateCommissionService::SOURCE_SIGNUP)
                ->sum(fn ($c) => max(0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

            // Referral sign-up rewards (avoid double-counting when signup commission rows exist)
            $refData = $allReferralsRewards->get($item->user_id);
            $signupRewardsTotal = max($signupCommTotal, (float) ($refData ? $refData->total_reward : 0));

            $totalEarnings = round($nonSignupTotal + $signupRewardsTotal, 2);

            // Compute payouts (pending, approved, paid reduce available balance)
            $payoutsForAffiliate = $allPayoutsByAffiliate->get($item->id, collect())
                ->merge($allPayoutsByUser->get($item->user_id, collect()))
                ->unique('id');

            $activePayouts = $payoutsForAffiliate->filter(function ($p) {
                $st = strtolower($p->status ?? '');
                return in_array($st, ['pending', 'approved', 'paid', 'completed'], true);
            });
            $committedAmount = (float) $activePayouts->sum(fn ($p) => max(0, (float) $p->amount - (float) ($p->reversed_amount ?? 0)));
            $availableBalance = \App\Services\AffiliatePayoutService::availableFrom($item, $totalEarnings, $committedAmount);

            // Keep database total_earnings in sync
            if (abs((float) $item->total_earnings - $totalEarnings) > 0.009) {
                $item->update(['total_earnings' => $totalEarnings]);
            }

            // Collect commission IDs already tied to an active payout
            $paidCommissionIds = [];
            foreach ($activePayouts as $p) {
                if ((float) ($p->reversed_amount ?? 0) >= (float) $p->amount - 0.001) {
                    continue;
                }
                $cIds = is_array($p->commission_ids) ? $p->commission_ids : (json_decode((string) $p->commission_ids, true) ?: []);
                foreach ($cIds as $cid) {
                    $paidCommissionIds[] = (int) $cid;
                }
            }

            $payableComms = $availableBalance > 0.001
                ? $approvedComms
                    ->reject(fn ($c) => in_array((int) $c->id, $paidCommissionIds, true))
                    ->map(fn ($c) => $this->formatPayableCommission($c))
                    ->filter(fn ($c) => $c['amount'] > 0)
                    ->values()
                    ->all()
                : [];

            $decodedDetails = is_string($item->payout_details)
                ? (json_decode($item->payout_details, true) ?: [])
                : (is_array($item->payout_details) ? $item->payout_details : []);
            if (isset($decodedDetails['detail']) && is_string($decodedDetails['detail']) && str_starts_with(trim($decodedDetails['detail']), '{')) {
                $inner = json_decode($decodedDetails['detail'], true);
                if (is_array($inner)) {
                    $decodedDetails = array_merge($decodedDetails, $inner);
                }
            }

            $item->total_earnings = $totalEarnings;
            $item->available_balance = $availableBalance;
            $item->total_referrals = $refData ? (int) $refData->count : ($item->total_referrals ?? 0);
            $item->setAttribute('payout_details_parsed', $decodedDetails);
            $item->setAttribute('payable_commissions', $payableComms);
            $item->setAttribute('payouts_list', $payoutsForAffiliate->values());
        }

        return response()->json(['success' => true, 'data' => $affiliates]);
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
        $cleanId = is_numeric($id) ? (int) $id : null;
        $item = $cleanId ? EcommerceAffiliate::with('user')->find($cleanId) : null;
        if (!$item && is_string($id)) {
            $item = EcommerceAffiliate::with('user')->where('affiliate_code', $id)->first();
        }
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Affiliate not found.'], 404);
        }
        $user = $request->user();
        $isAdmin = $user && ($user->hasAdminAccess() || $user->isSuperAdmin());
        if (!$isAdmin && (!$user || (int) $item->user_id !== (int) $user->id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $referrerId = $item->user_id;
        AffiliateCommissionService::syncSignupCommissions((int) $referrerId);

        $referrals = \App\Models\EcommerceReferral::with('referred')
            ->where('referrer_id', $referrerId)
            ->orderBy('created_at', 'desc')
            ->get();

        $commissions = \App\Models\EcommerceReferralCommission::with(['referred', 'order'])
            ->where('referrer_id', $referrerId)
            ->orderBy('created_at', 'desc')
            ->get();

        $payouts = \App\Models\EcommerceAffiliatePayout::where('affiliate_id', $item->id)
            ->orWhere('user_id', $referrerId)
            ->orderBy('created_at', 'desc')
            ->get();

        $referredUserIds = $referrals->pluck('referred_id')->filter()->unique();
        $orders = \App\Models\EcommerceOrder::where(function ($q) use ($referredUserIds, $item) {
                if ($referredUserIds->isNotEmpty()) {
                    $q->whereIn('user_id', $referredUserIds);
                }
                if (Schema::hasColumn('ecommerce_orders', 'affiliate_id')) {
                    $q->orWhere('affiliate_id', $item->id);
                }
            })
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        $commissionsByOrderId = $commissions->whereNotNull('order_id')->keyBy('order_id');
        $orders->each(function ($o) use ($commissionsByOrderId) {
            $comm = $commissionsByOrderId->get($o->id);
            $o->commission_amount = $comm ? $comm->commission_amount : 0.00;
        });

        // Attach actual order totals and first purchase date to each referral record
        $ordersByUserId = $orders->groupBy('user_id');
        $referrals->each(function ($ref) use ($ordersByUserId) {
            $userOrders = $ref->referred_id ? $ordersByUserId->get($ref->referred_id, collect()) : collect();
            $ref->setAttribute('order_amount', round((float) $userOrders->sum('total_amount'), 2));
            $ref->setAttribute('orders_count', $userOrders->count());
            if (!$ref->first_purchase_at && $userOrders->isNotEmpty()) {
                $earliest = $userOrders->sortBy('created_at')->first();
                if ($earliest && $earliest->created_at) {
                    $ref->first_purchase_at = $earliest->created_at;
                }
            }
        });

        $totalReferralsCount = $referrals->count();
        $totalOrdersCount = $orders->count();

        $approvedCommissions = $commissions->filter(function ($c) {
            $st = strtolower($c->status ?? '');
            return in_array($st, ['approved', 'completed', 'partially_reversed'], true);
        });

        $nonSignupTotal = (float) $approvedCommissions
            ->filter(fn ($c) => strtolower((string) $c->source_type) !== AffiliateCommissionService::SOURCE_SIGNUP)
            ->sum(fn ($c) => max(0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

        $signupCommTotal = (float) $approvedCommissions
            ->filter(fn ($c) => strtolower((string) $c->source_type) === AffiliateCommissionService::SOURCE_SIGNUP)
            ->sum(fn ($c) => max(0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));

        $signupRewardsTotal = max($signupCommTotal, (float) $referrals->sum('reward_amount_referrer'));
        $totalEarnings = round($nonSignupTotal + $signupRewardsTotal, 2);

        $activePayouts = $payouts->filter(function ($p) {
            $st = strtolower($p->status ?? '');
            return in_array($st, ['pending', 'approved', 'paid', 'completed'], true);
        });
        $committedAmount = (float) $activePayouts->sum(fn ($p) => max(0, (float) $p->amount - (float) ($p->reversed_amount ?? 0)));

        $paidOnlyPayouts = $payouts->filter(function ($p) {
            $st = strtolower($p->status ?? '');
            return in_array($st, ['completed', 'paid'], true);
        });
        $totalPaid = round((float) $paidOnlyPayouts->sum(fn ($p) => max(0, (float) $p->amount - (float) ($p->reversed_amount ?? 0))), 2);
        $availableBalance = \App\Services\AffiliatePayoutService::availableFrom($item, $totalEarnings, $committedAmount);
        $totalClicks = (int) ($item->total_clicks ?? 0);

        if (abs((float) $item->total_earnings - $totalEarnings) > 0.009) {
            $item->update(['total_earnings' => $totalEarnings]);
        }

        // Collect commission IDs already tied to an active payout
        $paidCommissionIds = [];
        foreach ($activePayouts as $p) {
            if ((float) ($p->reversed_amount ?? 0) >= (float) $p->amount - 0.001) {
                continue;
            }
            $cIds = is_array($p->commission_ids) ? $p->commission_ids : (json_decode((string) $p->commission_ids, true) ?: []);
            foreach ($cIds as $cid) {
                $paidCommissionIds[] = (int) $cid;
            }
        }

        $payableComms = $availableBalance > 0.001
            ? $approvedCommissions
                ->reject(fn ($c) => in_array((int) $c->id, $paidCommissionIds, true))
                ->map(fn ($c) => $this->formatPayableCommission($c))
                ->filter(fn ($c) => $c['amount'] > 0)
                ->values()
                ->all()
            : [];

        $decodedDetails = is_string($item->payout_details)
            ? (json_decode($item->payout_details, true) ?: [])
            : (is_array($item->payout_details) ? $item->payout_details : []);
        if (isset($decodedDetails['detail']) && is_string($decodedDetails['detail']) && str_starts_with(trim($decodedDetails['detail']), '{')) {
            $inner = json_decode($decodedDetails['detail'], true);
            if (is_array($inner)) {
                $decodedDetails = array_merge($decodedDetails, $inner);
            }
        }

        $payload = $item->toArray();
        $payload['total_earnings'] = $totalEarnings;
        $payload['available_balance'] = $availableBalance;
        $payload['payout_details_parsed'] = $decodedDetails;
        $payload['stats'] = [
            'total_referrals' => $totalReferralsCount,
            'total_orders' => $totalOrdersCount,
            'total_clicks' => $totalClicks,
            'total_commission' => $totalEarnings,
            'total_paid' => $totalPaid,
            'available_balance' => $availableBalance,
        ];
        $payload['referrals_list'] = $referrals;
        $payload['orders_list'] = $orders;
        $payload['commissions_list'] = $commissions;
        $payload['payable_commissions'] = $payableComms;
        $payload['payouts_list'] = $payouts;

        return response()->json(['success' => true, 'data' => $payload]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !$user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $cleanId = is_numeric($id) ? (int) $id : null;
        $item = $cleanId ? EcommerceAffiliate::find($cleanId) : null;
        if (!$item && is_string($id)) {
            $item = EcommerceAffiliate::where('affiliate_code', $id)->first();
        }
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Affiliate not found.'], 404);
        }
        $item->update($request->all());
        return response()->json(['success' => true, 'data' => $item]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !$user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $cleanId = is_numeric($id) ? (int) $id : null;
        $item = $cleanId ? EcommerceAffiliate::find($cleanId) : null;
        if (!$item && is_string($id)) {
            $item = EcommerceAffiliate::where('affiliate_code', $id)->first();
        }
        if (!$item) {
            return response()->json(['success' => true, 'message' => 'Deleted successfully']);
        }
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
        $cleanId = is_numeric($id) ? (int) $id : null;
        $commission = $cleanId ? \App\Models\EcommerceReferralCommission::find($cleanId) : null;
        if (!$commission) {
            return response()->json(['success' => false, 'message' => 'Commission not found.'], 404);
        }

        try {
            // When admin approves manually, release immediately to the wallet (no 30-day wait)
            $commission = AffiliateCommissionService::approve($commission, $admin->id, true);
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
        $cleanId = is_numeric($id) ? (int) $id : null;
        $commission = $cleanId ? \App\Models\EcommerceReferralCommission::find($cleanId) : null;
        if (!$commission) {
            return response()->json(['success' => false, 'message' => 'Commission not found.'], 404);
        }

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
                    $commission = AffiliateCommissionService::approve($commission, $admin->id, true);
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

    /** Immediate release of a pending/approved commission to affiliate earnings (skips holding period). */
    public function payout(Request $request, $id)
    {
        $admin = $this->requireAdmin($request);
        $cleanId = is_numeric($id) ? (int) $id : null;
        $commission = $cleanId ? \App\Models\EcommerceReferralCommission::find($cleanId) : null;
        if (!$commission) {
            return response()->json(['success' => false, 'message' => 'Commission not found.'], 404);
        }

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
            // Admin release-now of a pending commission: approve it first (credit() only pays approved ones).
            $commission->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $admin->id]);
        }

        try {
            if (!AffiliateCommissionService::credit($commission)) {
                return response()->json(['success' => false, 'message' => 'Commission could not be paid out.'], 422);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Commission payout failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Releasing the commission failed. It stays approved and can be retried.'], 502);
        }
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

    /** Admin: claw back (part of) a commission that was already paid to the referrer's wallet. */
    public function reverseCommission(Request $request, $id)
    {
        $this->requireAdmin($request);
        $validated = $request->validate([
            'reversal_amount' => 'required|numeric|min:0.01',
            'reversal_type' => 'nullable|string|in:full,partial',
            'reason' => 'required|string|max:255',
            'detailed_reason' => 'required|string|max:500',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $commission = is_numeric($id) ? \App\Models\EcommerceReferralCommission::find((int) $id) : null;
        if (!$commission) {
            return response()->json(['success' => false, 'message' => 'Commission not found.'], 404);
        }

        try {
            $commission = AffiliateCommissionService::reverse(
                $commission,
                (float) $validated['reversal_amount'],
                "Commission reversal ({$validated['reason']}): {$validated['detailed_reason']}"
            );
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Commission reversal failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Reversal failed. Nothing was changed.'], 500);
        }

        if (in_array($commission->status, ['reversed'], true)) {
            AffiliateCommissionService::resetFirstPurchaseIfNone((int) $commission->referrer_id, (int) $commission->referred_id);
        }

        $referrer = $commission->referrer;
        try {
            if ($referrer && $referrer->email) {
                app(\App\Services\EmailNotificationService::class)->sendEvent('affiliate_payout_reversed', [
                    'customer_name' => $referrer->name,
                    'customer_email' => $referrer->email,
                    'payout_id' => 'COMMISSION-' . $commission->id,
                    'amount' => '$' . number_format((float) $validated['reversal_amount'], 2),
                    'reason' => $validated['reason'],
                    'detailed_reason' => $validated['detailed_reason'],
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $referrer->email);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Commission reversal email failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Commission reversed. Funds were debited from the affiliate wallet.',
            'data' => [
                'commission_id' => $commission->id,
                'reversal_amount' => (float) $validated['reversal_amount'],
                'refunded_amount' => (float) $commission->refunded_amount,
                'status' => $commission->status,
                'reason' => $validated['reason'],
                'detailed_reason' => $validated['detailed_reason'],
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reversed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Admin: reverse (part of) a payout that was already PAID out. Payouts come out of affiliate
     * earnings, never the wallet, so no wallet movement happens; the earnings counter is reduced so
     * the reversed amount does not become withdrawable again. Pending/approved payouts must be rejected instead.
     */
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

        $amount = round((float) $validated['reversal_amount'], 2);

        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($id, $amount, $validated) {
                $payout = \App\Models\EcommerceAffiliatePayout::lockForUpdate()->where(function ($q) use ($id) {
                    if (is_numeric($id)) {
                        $q->where('id', (int) $id);
                    } else {
                        $q->where('payout_number', $id);
                    }
                })->first();

                if (!$payout) {
                    throw new \DomainException('Payout not found.');
                }
                if ($payout->status !== 'paid') {
                    throw new \DomainException("Only paid payouts can be reversed (current status: {$payout->status}). Reject pending or approved payouts instead.");
                }

                $remaining = round((float) $payout->amount - (float) $payout->reversed_amount, 2);
                if ($amount > $remaining + 0.001) {
                    throw new \DomainException('Reversal amount ($' . number_format($amount, 2) . ') exceeds the remaining reversible amount ($' . number_format($remaining, 2) . ').');
                }
                $amount = min($amount, $remaining);
                $newReversed = round((float) $payout->reversed_amount + $amount, 2);

                $payout->update([
                    'reversed_amount' => $newReversed,
                    'status' => $newReversed >= (float) $payout->amount - 0.001 ? 'reversed' : 'paid',
                    'notes' => trim(($payout->notes ? $payout->notes . ' | ' : '') . "Reversed \${$amount}: {$validated['reason']} - {$validated['detailed_reason']}"),
                    'reject_reason' => $validated['reason'],
                ]);

                $affiliate = EcommerceAffiliate::where('id', $payout->affiliate_id)->lockForUpdate()->first();
                if ($affiliate) {
                    $affiliate->update(['total_earnings' => max(0, round((float) $affiliate->total_earnings - $amount, 2))]);
                }

                return $payout->fresh();
            });
        } catch (\DomainException $e) {
            $status = str_contains($e->getMessage(), 'not found') ? 404 : 422;
            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Reverse payout exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Reversal failed. Nothing was changed.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout reversal recorded.',
            'data' => [
                'payout_id' => $result->id,
                'payout_number' => $result->payout_number,
                'reversal_amount' => $amount,
                'reversed_amount' => (float) $result->reversed_amount,
                'status' => $result->status,
                'reason' => $validated['reason'],
                'detailed_reason' => $validated['detailed_reason'],
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reversed_at' => now()->toIso8601String(),
            ],
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
        $cleanId = is_numeric($id) ? (int) $id : null;
        $app = $cleanId ? \App\Models\EcommerceAffiliateApplication::find($cleanId) : null;
        if (!$app) {
            return response()->json(['success' => true, 'message' => 'Affiliate application approved.']);
        }
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
        $cleanId = is_numeric($id) ? (int) $id : null;
        $app = $cleanId ? \App\Models\EcommerceAffiliateApplication::find($cleanId) : null;
        if (!$app) {
            return response()->json(['success' => true, 'message' => 'Affiliate application rejected.']);
        }
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

        $cleanId = is_numeric($id) ? (int) $id : null;
        $item = $cleanId ? EcommerceAffiliate::find($cleanId) : null;
        if (!$item && is_string($id)) {
            $item = EcommerceAffiliate::where('affiliate_code', $id)->first();
        }
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Affiliate not found.'], 404);
        }
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
        $active   = EcommerceAffiliate::whereRaw('LOWER(status) = ?', ['active'])->count();
        $inactive = EcommerceAffiliate::whereRaw('LOWER(status) = ?', ['inactive'])->count();
        $banned   = EcommerceAffiliate::whereRaw('LOWER(status) = ?', ['banned'])->count();

        return response()->json([
            'success' => true,
            'data'    => compact('total', 'active', 'inactive', 'banned'),
        ]);
    }
}

