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
            'converted_to_wallet' => (float) $affiliate->converted_to_wallet,
            'can_convert_to_wallet' => \App\Services\MecarviCashService::sourceAllowed('affiliate'),
            'pending_payouts' => (float) (clone $payouts)->whereIn('status', ['pending', 'approved'])->sum('amount'),
            'paid_out' => (float) (clone $payouts)->where('status', 'paid')->sum('amount') - (float) (clone $payouts)->where('status', 'paid')->sum('reversed_amount'),
            'pending_commissions' => (float) EcommerceReferralCommission::where('referrer_id', $affiliate->user_id)->where('status', 'pending')->sum(\Illuminate\Support\Facades\DB::raw('commission_amount - COALESCE(refunded_amount, 0)')),
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

    /** Move affiliate earnings into the wallet (the alternative to withdrawing them to the bank). */
    public function convertToWallet(Request $request)
    {
        $affiliate = $this->myAffiliate($request);
        $v = $request->validate(['amount' => 'required|numeric|min:0.01|max:1000000']);

        try {
            $available = AffiliatePayoutService::convertToWallet($request->user(), $affiliate, (float) $v['amount']);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'success' => true,
            'message' => '$' . number_format((float) $v['amount'], 2) . ' moved into your wallet.',
            'data' => ['available_balance' => $available, 'converted_to_wallet' => (float) $affiliate->fresh()->converted_to_wallet],
        ]);
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
            'details' => 'required',
        ]);

        $method = AffiliatePayoutService::normalizeMethod($v['method']);
        if (!in_array($method, AffiliatePayoutService::enabledMethods(AffiliateCommissionService::settings()), true)) {
            return response()->json(['success' => false, 'message' => 'This payout method is not available.'], 422);
        }

        $rawDetails = $v['details'];
        if (is_array($rawDetails)) {
            $payload = $rawDetails;
        } elseif (is_string($rawDetails)) {
            $decoded = json_decode($rawDetails, true);
            $payload = is_array($decoded) ? $decoded : ['detail' => $rawDetails];
        } else {
            $payload = ['detail' => (string) $rawDetails];
        }

        $affiliate->update(['payout_method' => $method, 'payout_details' => json_encode($payload)]);

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
            'payout_method' => 'nullable|string|max:30',
            'method_detail' => 'nullable|string|max:255',
            'account' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $rawMethod = $v['method'] ?? $v['payout_method'] ?? $affiliate->payout_method;
        $method = AffiliatePayoutService::normalizeMethod($rawMethod);
        $amount = round((float) $v['amount'], 2);

        try {
            AffiliatePayoutService::assertCanWithdraw($user, $affiliate, $amount, (string) $method);

            $detail = $v['method_detail'] ?? $v['account'] ?? null;
            if (!$detail && $affiliate->payout_method === $method && $affiliate->payout_details) {
                $detail = json_decode($affiliate->payout_details, true)['detail'] ?? null;
            }

            $notes = $v['notes'] ?? null;

            $payout = AffiliatePayoutService::create($user, $affiliate, $amount, (string) $method, $detail, 'user', null, null, null, null, $notes);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['success' => true, 'message' => 'Payout request submitted.', 'data' => $payout], 201);
    }

    // ----------------------------------------------------------------- admin

    public function index(Request $request)
    {
        $this->requireAdmin($request);

        AffiliateCommissionService::syncSignupCommissions();

        $payouts = EcommerceAffiliatePayout::with(['user', 'affiliate.user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', AffiliatePayoutService::normalizeMethod($request->get('method'))))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->get('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->get('to'))->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        $items = $payouts->getCollection();
        $userIds = $items->pluck('user_id')->filter()->unique();
        $allComms = EcommerceReferralCommission::with(['referred', 'order'])
            ->whereIn('referrer_id', $userIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('referrer_id');

        $items->transform(function ($payout) use ($allComms) {
            $userComms = $allComms->get($payout->user_id, collect());
            $cIds = is_array($payout->commission_ids) ? $payout->commission_ids : (json_decode((string) $payout->commission_ids, true) ?: []);
            if (!empty($cIds)) {
                $matched = $userComms->whereIn('id', $cIds)->values();
            } else {
                $matched = $userComms->filter(fn ($c) => in_array(strtolower($c->status ?? ''), ['approved', 'completed', 'partially_reversed', 'reversed'], true))->values();
            }

            $payout->commissions_breakdown = $matched->map(function ($c) {
                $net = max(0, round((float) $c->commission_amount - (float) ($c->refunded_amount ?? 0), 2));
                $referredName = $c->referred?->name ?: ($c->referred?->email ?: 'Customer');
                $orderNum = $c->order?->order_number ?: $c->source_ref;
                return [
                    'id' => $c->id,
                    'source_type' => $c->source_type ?: 'order',
                    'order_id' => $c->order_id,
                    'order_number' => $orderNum,
                    'order_amount' => (float) ($c->order?->total_amount ?: ($c->order_amount ?? 0)),
                    'referred_name' => $referredName,
                    'referred_email' => $c->referred?->email ?: '',
                    'commission_percentage' => (float) ($c->commission_percentage ?? 0),
                    'commission_amount' => $net > 0 ? $net : (float) $c->commission_amount,
                    'created_at' => $c->created_at ? $c->created_at->toIso8601String() : null,
                    'date' => $c->created_at ? $c->created_at->format('M d, Y') : '',
                ];
            })->values();

            return $payout;
        });

        return response()->json(['success' => true, 'data' => $payouts]);
    }

    /** Admin payouts page aggregates: status cards, per-method totals and upcoming scheduled payouts. */
    public function stats(Request $request)
    {
        $this->requireAdmin($request);

        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->endOfDay() : null;

        $inRange = function (?Carbon $f, ?Carbon $t) {
            return EcommerceAffiliatePayout::query()
                ->when($f, fn ($q) => $q->where('created_at', '>=', $f))
                ->when($t, fn ($q) => $q->where('created_at', '<=', $t));
        };
        $base = $inRange($from, $to);

        $agg = fn ($q, string $col = 'amount') => [
            'amount' => round((float) (clone $q)->sum($col), 2),
            'count' => (clone $q)->count(),
        ];

        // Rejected payouts never left the platform, so they don't count toward totals.
        $live = (clone $base)->where('status', '!=', 'rejected');
        $total = $agg($live);
        $paid = (clone $base)->where('status', 'paid');
        $completed = [
            'amount' => round((float) (clone $paid)->sum('amount') - (float) (clone $paid)->sum('reversed_amount'), 2),
            'count' => (clone $paid)->count(),
        ];
        $pending = $agg((clone $base)->whereIn('status', ['pending', 'approved']));
        // Partial reversals keep status 'paid'; reversed_amount is the source of truth.
        $reversed = $agg((clone $base)->where('reversed_amount', '>', 0), 'reversed_amount');

        // Growth vs. the previous period of equal length (only when a bounded range is given).
        $growth = null;
        if ($from && $to) {
            $days = $from->diffInDays($to) + 1;
            $prevTotal = (float) $inRange($from->copy()->subDays($days), $from->copy()->subSecond())
                ->where('status', '!=', 'rejected')->sum('amount');
            $growth = $prevTotal > 0 ? round((($total['amount'] - $prevTotal) / $prevTotal) * 100, 1) : null;
        }

        $methods = ['paypal' => ['amount' => 0.0, 'count' => 0], 'bank' => ['amount' => 0.0, 'count' => 0], 'wallet' => ['amount' => 0.0, 'count' => 0], 'cashapp' => ['amount' => 0.0, 'count' => 0]];
        $rows = (clone $live)->selectRaw('method, SUM(amount) as total, COUNT(*) as cnt')->groupBy('method')->get();
        foreach ($rows as $r) {
            $m = strtolower((string) $r->method);
            $key = str_contains($m, 'paypal') ? 'paypal' : (str_contains($m, 'bank') ? 'bank' : (str_contains($m, 'cash') ? 'cashapp' : 'wallet'));
            $methods[$key]['amount'] = round($methods[$key]['amount'] + (float) $r->total, 2);
            $methods[$key]['count'] += (int) $r->cnt;
        }

        // Upcoming = not yet paid and scheduled from today on, regardless of the selected range.
        $upcoming = EcommerceAffiliatePayout::whereIn('status', ['pending', 'approved'])
            ->whereNotNull('scheduled_for')
            ->whereDate('scheduled_for', '>=', now()->toDateString())
            ->selectRaw('scheduled_for, SUM(amount) as total, COUNT(DISTINCT affiliate_id) as affiliates, COUNT(*) as cnt')
            ->groupBy('scheduled_for')
            ->orderBy('scheduled_for')
            ->limit((int) $request->get('upcoming_limit', 4))
            ->get()
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->scheduled_for)->toDateString(),
                'amount' => round((float) $r->total, 2),
                'affiliates' => (int) $r->affiliates,
                'count' => (int) $r->cnt,
            ]);

        return response()->json(['success' => true, 'data' => [
            'total' => $total + ['growth_percent' => $growth],
            'completed' => $completed,
            'pending' => $pending,
            'reversed' => $reversed,
            'methods' => $methods,
            'upcoming' => $upcoming,
        ]]);
    }

    /** Admin creates a payout for an affiliate (the "Create Payout" modal). */
    public function store(Request $request)
    {
        $admin = $this->requireAdmin($request);

        $methodDetails = $request->input('payout_method_details', []);
        if (is_string($methodDetails)) {
            $methodDetails = json_decode($methodDetails, true) ?: [];
        }

        $in = [
            'affiliate_id' => $request->input('affiliate_id', $request->input('affiliateId')),
            'method' => $request->input('method', $request->input('payout_method')),
            'method_detail' => $request->input('method_detail', $request->input('methodDetail', $methodDetails['detail'] ?? null)),
            'schedule_date' => $request->input('schedule_date', $request->input('scheduleDate', $methodDetails['schedule_date'] ?? null)),
            'reference_id' => $request->input('reference_id', $request->input('referenceId', $methodDetails['reference_id'] ?? null)),
            'commission_ids' => $request->input('commission_ids', $request->input('selectedCommissionIds')),
            'amount' => $request->input('amount', $request->input('totalAmount')),
            'notes' => $request->input('notes'),
        ];
        validator($in, [
            'affiliate_id' => 'required',
            'method' => 'required|string|max:30',
            'schedule_date' => 'nullable|date',
            'commission_ids' => 'nullable|array',
            'commission_ids.*' => 'integer',
            'amount' => 'nullable|numeric|min:0.01',
        ])->validate();

        $rawAffiliateId = $in['affiliate_id'];
        $affiliate = is_numeric($rawAffiliateId)
            ? EcommerceAffiliate::with('user')->find((int) $rawAffiliateId)
            : null;
        if (!$affiliate) {
            $affiliate = EcommerceAffiliate::with('user')->where('affiliate_code', (string) $rawAffiliateId)->firstOrFail();
        }

        AffiliateCommissionService::syncSignupCommissions((int) $affiliate->user_id);

        // Ensure any approved commissions are credited/released to wallet before payout
        $approvedComms = EcommerceReferralCommission::where('referrer_id', $affiliate->user_id)
            ->where('status', 'approved')
            ->get();
        foreach ($approvedComms as $ac) {
            try {
                AffiliateCommissionService::credit($ac);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Auto-credit approved commission {$ac->id} on payout: " . $e->getMessage());
            }
        }

        // Sync affiliate total_earnings from live records
        $allValidComms = EcommerceReferralCommission::where('referrer_id', $affiliate->user_id)
            ->whereIn('status', ['approved', 'completed', 'partially_reversed'])
            ->get();
        $nonSignupSum = (float) $allValidComms->where('source_type', '!=', AffiliateCommissionService::SOURCE_SIGNUP)
            ->sum(fn ($c) => max(0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));
        $signupCommSum = (float) $allValidComms->where('source_type', AffiliateCommissionService::SOURCE_SIGNUP)
            ->sum(fn ($c) => max(0, (float) $c->commission_amount - (float) ($c->refunded_amount ?? 0)));
        $refSignupSum = (float) \App\Models\EcommerceReferral::where('referrer_id', $affiliate->user_id)->sum('reward_amount_referrer');
        $liveEarnings = round($nonSignupSum + max($signupCommSum, $refSignupSum), 2);
        if ($liveEarnings > (float) $affiliate->total_earnings) {
            $affiliate->update(['total_earnings' => $liveEarnings]);
            $affiliate->refresh();
        }

        $method = AffiliatePayoutService::normalizeMethod($in['method']);
        $ids = !empty($in['commission_ids']) ? array_map('intval', $in['commission_ids']) : null;

        $calcAmount = 0.0;
        if ($ids) {
            $calcAmount = (float) EcommerceReferralCommission::whereIn('id', $ids)
                ->where('referrer_id', $affiliate->user_id)
                ->whereIn('status', ['approved', 'completed', 'partially_reversed'])
                ->sum(\Illuminate\Support\Facades\DB::raw('commission_amount - COALESCE(refunded_amount, 0)'));
        }
        $amount = $calcAmount > 0 ? $calcAmount : (float) ($in['amount'] ?? 0);

        try {
            AffiliatePayoutService::assertCanWithdraw($affiliate->user, $affiliate, $amount, (string) $method, true);
            $payout = AffiliatePayoutService::create(
                $affiliate->user, $affiliate, round($amount, 2), $method, $in['method_detail'], 'admin', $ids,
                $in['reference_id'], $admin->id, $in['schedule_date'] ? Carbon::parse($in['schedule_date']) : null,
                $in['notes']
            );
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['success' => true, 'message' => 'Payout created.', 'data' => $payout->load(['user', 'affiliate'])], 201);
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

        return response()->json(['success' => true, 'message' => $message, 'data' => $payout->load(['user', 'affiliate'])]);
    }

    public function approve(Request $request, $id)
    {
        return $this->act($request, $id, fn ($p, $a) => AffiliatePayoutService::approve($p, $a->id, $request->input('notes')), 'Payout approved.');
    }

    public function markPaid(Request $request, $id)
    {
        $ref = $request->input('reference_id', $request->input('transaction_id'));
        return $this->act($request, $id, fn ($p, $a) => AffiliatePayoutService::markPaid($p, $a->id, $ref, $request->input('notes')), 'Payout marked as paid.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);

        return $this->act($request, $id, fn ($p, $a) => AffiliatePayoutService::reject($p, $a->id, $request->input('reason')), 'Payout rejected. The amount is back in the affiliate earnings balance.');
    }

    public function notifyAddPayoutMethod(Request $request, $id)
    {
        $this->requireAdmin($request);

        $affiliate = is_numeric($id)
            ? EcommerceAffiliate::with('user')->find((int) $id)
            : null;
        if (!$affiliate) {
            $affiliate = EcommerceAffiliate::with('user')->where('affiliate_code', (string) $id)->first();
        }
        if (!$affiliate || !$affiliate->user || !$affiliate->user->email) {
            return response()->json(['success' => false, 'message' => 'Affiliate email address not found.'], 404);
        }

        $rawMethod = (string) $request->input('method', 'bank');
        $methodLabel = match (strtolower($rawMethod)) {
            'paypal' => 'PayPal',
            'cashapp', 'cash_app' => 'Cash App',
            default => 'Bank Transfer',
        };

        $recipientEmail = $affiliate->user->email;
        $customerName = $affiliate->user->name ?: 'Affiliate Partner';
        $amountStr = $request->filled('amount')
            ? ('$' . number_format((float) $request->input('amount'), 2))
            : ('$' . number_format(AffiliatePayoutService::availableBalance($affiliate), 2));

        $subject = "Action Required: Add Your {$methodLabel} Payout Details";

        // Dynamic user panel URL: priority from request -> config/env USER_PANEL_URL -> local port vs production server
        $rawUserPanel = (string) (
            $request->input('user_panel_url')
            ?: config('services.user_panel.url')
            ?: env('USER_PANEL_URL')
            ?: env('NEXT_PUBLIC_USER_PANEL_BASE_URL')
            ?: (in_array(env('APP_ENV', 'production'), ['local', 'testing']) ? 'http://localhost:3001' : 'https://user.mecarvi.com')
        );

        $dashboardUrl = rtrim($rawUserPanel, '/') . '/affiliate-information';

        $html = '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:32px 16px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">'
            . '<tr><td style="padding:24px 32px;background:linear-gradient(90deg,#0056F6 0%,#0076FF 100%);color:#ffffff;font-size:20px;font-weight:800;">Mecarvi Embroidery — Affiliate Program</td></tr>'
            . '<tr><td style="padding:32px;">'
            . '<h1 style="margin:0 0 16px;font-size:22px;color:#0B1536;">Add Your ' . e($methodLabel) . ' Payout Details</h1>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Hi <strong>' . e($customerName) . '</strong>,</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have <strong>' . e($amountStr) . '</strong> in approved affiliate commissions ready for payout (Affiliate Code: <strong>' . e($affiliate->affiliate_code) . '</strong>).</p>'
            . '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">To receive your payout via <strong>' . e($methodLabel) . '</strong>, please log in to your Affiliate Dashboard and save your ' . e($methodLabel) . ' account details.</p>'
            . '<p style="margin:0 0 24px;"><a href="' . e($dashboardUrl) . '" style="display:inline-block;background:#0066FF;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:700;font-size:14px;">Add Payout Method</a></p>'
            . '<p style="margin:0;font-size:13px;color:#6b7280;">Once your payout method is saved, our team will process your payout right away.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:20px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">Mecarvi Embroidery • Affiliate Program</td></tr>'
            . '</table></td></tr></table></body></html>';

        try {
            $emailService = app(\App\Services\EmailNotificationService::class);
            $setting = $emailService->setting();

            $fromName = ($setting->from_name && strtolower($setting->from_name) !== 'laravel')
                ? $setting->from_name
                : 'Mecarvi Embroidery';
            $fromEmail = $setting->from_email ?: config('mail.from.address', 'noreply@mecarvi.com');

            if ($setting->smtp_host) {
                \Illuminate\Support\Facades\Config::set('mail.default', 'smtp');
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.host', $setting->smtp_host);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.port', $setting->smtp_port ?: 587);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.encryption', $setting->smtp_encryption);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.username', $setting->smtp_username);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.password', $setting->getDecryptedSmtpPassword());
            }

            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($recipientEmail, $subject, $fromEmail, $fromName, $html) {
                $message->to($recipientEmail)
                    ->from($fromEmail, $fromName)
                    ->subject($subject)
                    ->html($html);
            });

            \App\Models\EmailNotificationLog::create([
                'event_key' => 'affiliate_add_payout_method',
                'recipient_email' => $recipientEmail,
                'recipient_type' => 'customer',
                'subject' => $subject,
                'status' => 'sent',
                'sent_at' => now(),
                'payload' => [
                    'affiliate_id' => $affiliate->id,
                    'affiliate_code' => $affiliate->affiliate_code,
                    'method' => $methodLabel,
                    'amount' => $amountStr,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Affiliate notifyAddPayoutMethod mail warning: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Notification email sent to {$recipientEmail} requesting {$methodLabel} details.",
        ]);
    }

    public function sendReceipt(Request $request, $id)
    {
        $this->requireAdmin($request);

        $payout = EcommerceAffiliatePayout::with(['user', 'affiliate.user'])->find((int) $id);
        if (!$payout) {
            $payout = EcommerceAffiliatePayout::with(['user', 'affiliate.user'])
                ->where('payout_number', (string) $id)
                ->first();
        }

        if (!$payout) {
            return response()->json(['success' => false, 'message' => 'Payout record not found.'], 404);
        }

        $user = $payout->user ?: $payout->affiliate?->user;
        $recipientEmail = $request->input('email') ?: ($user?->email ?: null);

        if (!$recipientEmail) {
            return response()->json(['success' => false, 'message' => 'No valid email address found for this user.'], 422);
        }

        $customerName = $user?->name ?: ($payout->affiliate?->affiliate_code ?: 'Affiliate Partner');
        $amountStr = '$' . number_format((float) $payout->amount, 2);
        $payoutNumber = $payout->payout_number ?: "PO-{$payout->id}";
        $referenceId = $payout->reference_id ?: "REF-{$payout->id}";
        $rawMethod = strtolower((string) $payout->method);
        $methodLabel = match (true) {
            str_contains($rawMethod, 'paypal') => 'PayPal',
            str_contains($rawMethod, 'cash') => 'Cash App',
            str_contains($rawMethod, 'bank') => 'Bank Transfer (ACH)',
            default => 'Mecarvi Wallet',
        };
        $paidDate = $payout->paid_at ? $payout->paid_at->format('M d, Y h:i A') : now()->format('M d, Y h:i A');

        $subject = "Payment Receipt: Affiliate Payout {$payoutNumber} ({$amountStr})";

        $html = '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:32px 16px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">'
            . '<tr><td style="padding:24px 32px;background:linear-gradient(90deg,#0056F6 0%,#0076FF 100%);color:#ffffff;font-size:20px;font-weight:800;">Mecarvi Embroidery — Payout Receipt</td></tr>'
            . '<tr><td style="padding:32px;">'
            . '<h1 style="margin:0 0 8px;font-size:22px;color:#0B1536;">Payout Receipt</h1>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#374151;">Hi <strong>' . e($customerName) . '</strong>,</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#374151;">Your affiliate payout has been processed. Here are the details of your payout transaction:</p>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin:0 0 24px;padding:16px 20px;">'
            . '<tr><td style="padding:6px 0;font-size:13px;color:#64748b;">Payout Number:</td><td align="right" style="padding:6px 0;font-size:13px;font-weight:700;color:#0f172a;">' . e($payoutNumber) . '</td></tr>'
            . '<tr><td style="padding:6px 0;font-size:13px;color:#64748b;">Reference ID:</td><td align="right" style="padding:6px 0;font-size:13px;font-weight:700;color:#0f172a;">' . e($referenceId) . '</td></tr>'
            . '<tr><td style="padding:6px 0;font-size:13px;color:#64748b;">Payment Method:</td><td align="right" style="padding:6px 0;font-size:13px;font-weight:700;color:#0f172a;">' . e($methodLabel) . '</td></tr>'
            . '<tr><td style="padding:6px 0;font-size:13px;color:#64748b;">Date:</td><td align="right" style="padding:6px 0;font-size:13px;font-weight:700;color:#0f172a;">' . e($paidDate) . '</td></tr>'
            . '<tr style="border-top:1px solid #cbd5e1;"><td style="padding:12px 0 6px;font-size:15px;font-weight:800;color:#0f172a;">Total Payout Amount:</td><td align="right" style="padding:12px 0 6px;font-size:18px;font-weight:900;color:#0066FF;">' . e($amountStr) . '</td></tr>'
            . '</table>'
            . '<p style="margin:0;font-size:13px;color:#6b7280;">Thank you for partnering with Mecarvi Embroidery. If you have questions regarding this payout, please contact our support team.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:20px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">Mecarvi Embroidery • Affiliate Program</td></tr>'
            . '</table></td></tr></table></body></html>';

        try {
            $emailService = app(\App\Services\EmailNotificationService::class);
            $setting = $emailService->setting();

            $fromName = ($setting->from_name && strtolower($setting->from_name) !== 'laravel')
                ? $setting->from_name
                : 'Mecarvi Embroidery';
            $fromEmail = $setting->from_email ?: config('mail.from.address', 'noreply@mecarvi.com');

            if ($setting->smtp_host) {
                \Illuminate\Support\Facades\Config::set('mail.default', 'smtp');
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.host', $setting->smtp_host);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.port', $setting->smtp_port ?: 587);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.encryption', $setting->smtp_encryption);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.username', $setting->smtp_username);
                \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.password', $setting->getDecryptedSmtpPassword());
            }

            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($recipientEmail, $subject, $fromEmail, $fromName, $html) {
                $message->to($recipientEmail)
                    ->from($fromEmail, $fromName)
                    ->subject($subject)
                    ->html($html);
            });

            \App\Models\EmailNotificationLog::create([
                'event_key' => 'affiliate_payout_receipt',
                'recipient_email' => $recipientEmail,
                'recipient_type' => 'customer',
                'subject' => $subject,
                'status' => 'sent',
                'sent_at' => now(),
                'payload' => [
                    'payout_id' => $payout->id,
                    'payout_number' => $payoutNumber,
                    'amount' => $amountStr,
                    'recipient' => $customerName,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Affiliate sendReceipt mail warning: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Receipt sent successfully to {$recipientEmail}",
        ]);
    }
}
