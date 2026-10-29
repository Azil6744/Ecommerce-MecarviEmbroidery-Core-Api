<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceAffiliate;
use App\Models\EcommerceAffiliatePayout;
use App\Models\EcommerceReferralCommission;
use App\Services\AffiliateCommissionService;
use App\Services\AffiliatePayoutService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EcommerceAffiliatePayoutController extends Controller
{
    private function requireAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user || !($user->hasAdminAccess() || $user->isSuperAdmin())) {
            abort(response()->json(['success' => false, 'message' => 'Forbidden.'], 403));
        }

        return $user;
    }

    private function myAffiliate(Request $request): EcommerceAffiliate
    {
        $user = $request->user();
        if (!$user) {
            abort(response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401));
        }

        return AffiliateCommissionService::ensureAffiliateFor($user);
    }

    private function fail(\DomainException $e, int $status = 422)
    {
        return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
    }

    // ------------------------------------------------------------------ user

    public function summary(Request $request)
    {
        $affiliate = $this->myAffiliate($request);
        $s = AffiliateCommissionService::settings();
        $payouts = EcommerceAffiliatePayout::where('user_id', $affiliate->user_id);

        return response()->json(['success' => true, 'data' => [
            'affiliate' => $affiliate,
            'available_balance' => AffiliatePayoutService::availableBalance($affiliate),
            'pending_payouts' => (float) (clone $payouts)->whereIn('status', ['pending', 'approved'])->sum('amount'),
            'paid_out' => (float) (clone $payouts)->where('status', 'paid')->sum('amount'),
            'pending_commissions' => (float) EcommerceReferralCommission::where('referrer_id', $affiliate->user_id)->whereIn('status', ['pending', 'approved'])->sum('commission_amount'),
            'enabled_methods' => AffiliatePayoutService::enabledMethods($s),
            'min_payout_amount' => AffiliateCommissionService::flag($s, 'require_min_payout') ? (float) $s['min_payout_amount'] : 0,
            'processing_mode' => $s['payout_processing_mode'],
            'next_payout_date' => AffiliatePayoutService::nextScheduleDate($s)->toDateString(),
            'requirements' => [
                'tax_info_required' => AffiliateCommissionService::flag($s, 'require_tax_info'),
                'tax_info_submitted' => !empty($affiliate->tax_info),
                'payout_method_required' => AffiliateCommissionService::flag($s, 'require_payout_method'),
                'payout_method_saved' => !empty($affiliate->payout_method),
            ],
        ]]);
    }

    public function myPayouts(Request $request)
    {
        $affiliate = $this->myAffiliate($request);

        return response()->json([
            'success' => true,
            'data' => EcommerceAffiliatePayout::where('user_id', $affiliate->user_id)->orderByDesc('created_at')->get(),
        ]);
    }

    public function savePayoutMethod(Request $request)
    {
        $affiliate = $this->myAffiliate($request);
        $v = $request->validate([
            'method' => 'required|string|max:30',
            'details' => 'required|string|max:255',
        ]);

        $method = AffiliatePayoutService::normalizeMethod($v['method']);
        if (!in_array($method, AffiliatePayoutService::enabledMethods(AffiliateCommissionService::settings()), true)) {
            return response()->json(['success' => false, 'message' => 'This payout method is not available.'], 422);
        }

        $affiliate->update(['payout_method' => $method, 'payout_details' => json_encode(['detail' => $v['details']])]);

        return response()->json(['success' => true, 'message' => 'Payout method saved.', 'data' => $affiliate->fresh()]);
    }

    public function saveTaxInfo(Request $request)
    {
        $affiliate = $this->myAffiliate($request);
        $v = $request->validate([
            'legal_name' => 'required|string|max:150',
            'tax_id' => 'required|string|max:60',
            'country' => 'required|string|max:60',
            'address' => 'nullable|string|max:255',
        ]);

        $affiliate->update(['tax_info' => json_encode($v), 'tax_info_submitted_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Tax information saved.']);
    }

    public function requestPayout(Request $request)
    {
        $affiliate = $this->myAffiliate($request);
        $user = $request->user();
        $v = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:30',
        ]);

        $method = AffiliatePayoutService::normalizeMethod($v['method'] ?? $affiliate->payout_method);
        $amount = round((float) $v['amount'], 2);

        try {
            AffiliatePayoutService::assertCanWithdraw($user, $affiliate, $amount, (string) $method);

            $detail = null;
            if ($affiliate->payout_method === $method && $affiliate->payout_details) {
                $detail = json_decode($affiliate->payout_details, true)['detail'] ?? null;
            }

            $payout = AffiliatePayoutService::create($user, $affiliate, $amount, $method, $detail, 'user');
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['success' => true, 'message' => 'Payout request submitted.', 'data' => $payout], 201);
    }

    // ----------------------------------------------------------------- admin

    public function index(Request $request)
    {
        $this->requireAdmin($request);

        $payouts = EcommerceAffiliatePayout::with(['user', 'affiliate'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', AffiliatePayoutService::normalizeMethod($request->get('method'))))
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return response()->json(['success' => true, 'data' => $payouts]);
    }

    /** Admin creates a payout for an affiliate (the "Create Payout" modal). */
    public function store(Request $request)
    {
        $admin = $this->requireAdmin($request);

        $in = [
            'affiliate_id' => $request->input('affiliate_id', $request->input('affiliateId')),
            'method' => $request->input('method'),
            'method_detail' => $request->input('method_detail', $request->input('methodDetail')),
            'schedule_date' => $request->input('schedule_date', $request->input('scheduleDate')),
            'reference_id' => $request->input('reference_id', $request->input('referenceId')),
            'commission_ids' => $request->input('commission_ids', $request->input('selectedCommissionIds')),
            'amount' => $request->input('amount', $request->input('totalAmount')),
        ];
        validator($in, [
            'affiliate_id' => 'required|integer',
            'method' => 'required|string|max:30',
            'schedule_date' => 'nullable|date',
            'commission_ids' => 'nullable|array',
            'commission_ids.*' => 'integer',
            'amount' => 'nullable|numeric|min:0.01',
        ])->validate();

        $affiliate = EcommerceAffiliate::with('user')->findOrFail($in['affiliate_id']);
        $method = AffiliatePayoutService::normalizeMethod($in['method']);
        $ids = $in['commission_ids'] ?: null;

        $amount = $ids
            ? (float) EcommerceReferralCommission::whereIn('id', $ids)->where('referrer_id', $affiliate->user_id)->where('status', 'completed')->sum('commission_amount')
            : (float) $in['amount'];

        try {
            AffiliatePayoutService::assertCanWithdraw($affiliate->user, $affiliate, $amount, (string) $method, true);
            $payout = AffiliatePayoutService::create(
                $affiliate->user, $affiliate, round($amount, 2), $method, $in['method_detail'], 'admin', $ids,
                $in['reference_id'], $admin->id, $in['schedule_date'] ? Carbon::parse($in['schedule_date']) : null
            );
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['success' => true, 'message' => 'Payout created.', 'data' => $payout->load('user')], 201);
    }

    private function act(Request $request, $id, callable $fn, string $message)
    {
        $admin = $this->requireAdmin($request);
        $cleanId = is_numeric($id) ? (int) $id : null;
        $payout = $cleanId ? EcommerceAffiliatePayout::find($cleanId) : null;
        if (!$payout && is_string($id)) {
            $payout = EcommerceAffiliatePayout::where('payout_number', $id)->orWhere('reference_id', $id)->first();
        }
        if (!$payout) {
            return response()->json(['success' => true, 'message' => $message]);
        }
        try {
            $payout = $fn($payout, $admin);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['success' => true, 'message' => $message, 'data' => $payout->load('user')]);
    }

    public function approve(Request $request, $id)
    {
        return $this->act($request, $id, fn ($p, $a) => AffiliatePayoutService::approve($p, $a->id), 'Payout approved.');
    }

    public function markPaid(Request $request, $id)
    {
        return $this->act($request, $id, fn ($p, $a) => AffiliatePayoutService::markPaid($p, $a->id, $request->input('reference_id')), 'Payout marked as paid.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);

        return $this->act($request, $id, fn ($p, $a) => AffiliatePayoutService::reject($p, $a->id, $request->input('reason')), 'Payout rejected and funds returned.');
    }
}
