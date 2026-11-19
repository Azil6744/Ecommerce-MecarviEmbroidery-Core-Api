<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceVoucher;
use App\Models\EcommerceVoucherLog;
use App\Models\EcommerceVoucherSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EcommerceVoucherController extends Controller
{
    /**
     * Storefront / POS: validate voucher codes against active Voucher Settings.
     */
    public function validateCodes(Request $request)
    {
        $data = $request->validate([
            'codes' => 'required|array|min:1',
            'codes.*' => 'string',
            'order_total' => 'required|numeric|min:0',
            'channel' => 'nullable|in:online,pos',
        ]);

        $res = \App\Services\VoucherService::validateForOrder(
            $data['codes'],
            (float) $data['order_total'],
            $data['channel'] ?? 'online'
        );

        return response()->json([
            'success' => $res['valid'],
            'message' => $res['message'],
            'data' => [
                'total_applied' => $res['total_applied'],
                'vouchers' => collect($res['vouchers'])->map(fn ($entry) => [
                    'code' => $entry['voucher']->code,
                    'deduct' => $entry['deduct'],
                    'balance' => (float) $entry['voucher']->balance,
                ])->values(),
            ],
        ], $res['valid'] ? 200 : 422);
    }

    /**
     * Customer / business panel: the signed-in user's own vouchers.
     */
    public function myVouchers(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $this->syncExpired();

        $vouchers = EcommerceVoucher::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if (! empty($user->email)) {
                    $q->orWhereRaw('LOWER(holder_email) = ?', [strtolower($user->email)]);
                }
            })
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $vouchers->map(fn (EcommerceVoucher $v) => $this->payload($v))->values(),
            'stats' => $this->stats(EcommerceVoucher::query()->whereIn('id', $vouchers->pluck('id'))),
        ]);
    }

    /**
     * Admin: paginated list with stats. Filters: holder_type, status, search, per_page, page.
     */
    public function index(Request $request)
    {
        $this->syncExpired();

        $holderType = $request->input('holder_type');
        $base = EcommerceVoucher::query();
        if (in_array($holderType, ['customer', 'business'], true)) {
            $base->where('holder_type', $holderType);
        }

        $stats = $this->stats(clone $base);

        $query = clone $base;
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', strtolower((string) $request->input('status')));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('holder_name', 'like', "%{$search}%")
                    ->orWhere('holder_email', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 12)));
        $page = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (EcommerceVoucher $v) => $this->payload($v))->values(),
            'stats' => $stats,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem() ?? 0,
                'to' => $page->lastItem() ?? 0,
            ],
        ]);
    }

    /** Suggested voucher code + case id for the Issue Voucher form. */
    public function nextCode()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'code' => EcommerceVoucher::generateCode(),
                'case_id' => EcommerceVoucher::nextCaseId(),
                'format' => EcommerceVoucherSetting::current()['code_prefix'] . '-' . EcommerceVoucherSetting::current()['code_format'],
            ],
        ]);
    }

    public function show($id)
    {
        $this->syncExpired();
        $voucher = EcommerceVoucher::with('logs')->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->payload($voucher, true)]);
    }

    public function update(Request $request, $id)
    {
        $voucher = EcommerceVoucher::findOrFail($id);

        $data = $request->validate([
            'usage_type' => 'nullable|in:single,multi',
            'expires_at' => 'nullable|date',
            'order_number' => 'nullable|string|max:60',
            'issue_category' => 'nullable|string|max:80',
            'compensation_reason' => 'nullable|string|max:120',
            'issue_summary' => 'nullable|string|max:2000',
        ]);

        $changes = [];
        if (array_key_exists('expires_at', $data) && $data['expires_at'] !== optional($voucher->expires_at)->format('Y-m-d')) {
            $changes[] = 'Expiry date set to ' . ($data['expires_at'] ? Carbon::parse($data['expires_at'])->format('M j, Y') : 'No Expiry');
        }
        if (array_key_exists('usage_type', $data) && $data['usage_type'] !== $voucher->usage_type) {
            $changes[] = 'Usage type changed to ' . ($data['usage_type'] === 'multi' ? 'Multi Use' : 'Single Use');
        }
        if (array_key_exists('order_number', $data) && $data['order_number'] !== $voucher->order_number) {
            $changes[] = 'Order # updated to ' . ($data['order_number'] ?: 'None');
        }

        $voucher->update(array_filter($data, fn ($v) => $v !== null));

        $actor = $request->user()?->name ?: 'Admin';
        EcommerceVoucherLog::create([
            'voucher_id' => $voucher->id,
            'log_type' => 'activity',
            'action' => 'Voucher Updated',
            'details' => $changes ? implode('; ', $changes) : 'Voucher details updated',
            'performed_by' => $actor,
            'performed_by_id' => $request->user()?->id,
        ]);

        return response()->json(['success' => true, 'data' => $this->payload($voucher->fresh('logs'), true)]);
    }

    public function destroy(Request $request, $id)
    {
        if ($denied = $this->authorizeAction($request, 'delete')) {
            return $denied;
        }

        $voucher = EcommerceVoucher::findOrFail($id);
        EcommerceVoucherLog::where('voucher_id', $voucher->id)->delete();
        $voucher->delete();

        return response()->json(['success' => true, 'message' => 'Voucher deleted.']);
    }

    public function addNote(Request $request, $id)
    {
        $voucher = EcommerceVoucher::findOrFail($id);
        $data = $request->validate([
            'note' => 'required|string|max:2000',
        ]);

        $actor = $request->user()?->name ?: 'Admin';
        EcommerceVoucherLog::create([
            'voucher_id' => $voucher->id,
            'log_type' => 'note',
            'action' => 'Note',
            'details' => trim($data['note']),
            'performed_by' => $actor,
            'performed_by_id' => $request->user()?->id,
        ]);

        return response()->json(['success' => true, 'data' => $this->payload($voucher->fresh('logs'), true)], 201);
    }

    public function store(Request $request)
    {
        if ($denied = $this->authorizeAction($request, 'issue')) {
            return $denied;
        }

        $data = $request->validate([
            'holder_type' => 'required|in:customer,business',
            'holder_name' => 'required|string|max:255',
            'holder_email' => 'nullable|email|max:255',
            'holder_phone' => 'nullable|string|max:40',
            'contact_person' => 'nullable|string|max:255',
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/', 'unique:ecommerce_vouchers,code'],
            'issued_at' => 'nullable|date',
            'order_number' => 'nullable|string|max:60',
            'issue_category' => 'required|string|max:80',
            'issue_summary' => 'required|string|max:2000',
            'compensation_reason' => 'required|string|max:120',
            'delivery_methods' => 'nullable|array',
            'delivery_methods.*' => 'string|in:email,account,print',
            'case_id' => 'nullable|string|max:40',
            'amount' => 'required|numeric|min:0.01|max:1000000',
            'usage_type' => 'required|in:single,multi',
            'expires_at' => 'nullable|date|after_or_equal:today',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:2000',
        ]);

        $holder = null;
        if (! empty($data['holder_email'])) {
            $holder = User::whereRaw('LOWER(email) = ?', [strtolower($data['holder_email'])])->first();
        }

        $voucher = EcommerceVoucher::create([
            'user_id' => $holder?->id,
            'holder_type' => $data['holder_type'],
            'holder_name' => $data['holder_name'],
            'holder_email' => $data['holder_email'] ?? null,
            'amount' => $data['amount'],
            'balance' => $data['amount'],
            'status' => EcommerceVoucher::STATUS_ACTIVE,
            'usage_type' => $data['usage_type'],
            'issued_at' => $data['issued_at'] ?? Carbon::today(),
            'expires_at' => $data['expires_at'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'notes' => $data['notes'] ?? null,
            'issued_by_admin_id' => $request->user()?->id,
            'issued_by_name' => $request->user()?->name ?: 'Admin',
            'code' => ! empty($data['code']) ? strtoupper($data['code']) : null,
            'holder_phone' => $data['holder_phone'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'order_number' => $data['order_number'] ?? null,
            'issue_category' => $data['issue_category'],
            'issue_summary' => $data['issue_summary'],
            'compensation_reason' => $data['compensation_reason'],
            'delivery_methods' => json_encode($data['delivery_methods'] ?? []),
            'case_id' => $data['case_id'] ?? EcommerceVoucher::nextCaseId(),
        ]);

        $actor = $request->user()?->name ?: 'Admin';
        EcommerceVoucherLog::create([
            'voucher_id' => $voucher->id,
            'log_type' => 'activity',
            'action' => 'Voucher Issued',
            'details' => sprintf('Voucher %s created for $%s', $voucher->code, number_format((float) $voucher->amount, 2)),
            'performed_by' => $actor,
            'performed_by_id' => $request->user()?->id,
        ]);

        EcommerceVoucherLog::create([
            'voucher_id' => $voucher->id,
            'log_type' => 'note',
            'action' => 'Case Created',
            'details' => $data['issue_summary'],
            'performed_by' => $actor,
            'performed_by_id' => $request->user()?->id,
        ]);

        return response()->json(['success' => true, 'data' => $this->payload($voucher->fresh('logs'), true)], 201);
    }

    public function void(Request $request, $id)
    {
        if ($denied = $this->authorizeAction($request, 'void')) {
            return $denied;
        }

        $voucher = EcommerceVoucher::findOrFail($id);

        if ($voucher->status !== EcommerceVoucher::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Only active vouchers can be voided.',
            ], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'void_when' => 'nullable|in:immediate,scheduled',
            'scheduled_void_at' => 'nullable|date|after_or_equal:today',
            'notes' => 'nullable|string|max:500',
            'notify_customer' => 'nullable|boolean',
        ]);

        $when = $data['void_when'] ?? 'immediate';
        $actor = $request->user()?->name ?: 'Admin';

        if ($when === 'scheduled') {
            if (empty($data['scheduled_void_at'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select a scheduled void date.',
                ], 422);
            }

            $voucher->update([
                'scheduled_void_at' => $data['scheduled_void_at'],
                'void_reason' => $data['reason'],
                'void_notes' => $data['notes'] ?? null,
            ]);

            EcommerceVoucherLog::create([
                'voucher_id' => $voucher->id,
                'log_type' => 'activity',
                'action' => 'Void Scheduled',
                'details' => 'Scheduled to void on ' . Carbon::parse($data['scheduled_void_at'])->format('M j, Y') . ' (' . $data['reason'] . ')',
                'performed_by' => $actor,
                'performed_by_id' => $request->user()?->id,
            ]);
        } else {
            $cancelRemaining = ! empty(EcommerceVoucherSetting::current()['cancel_remaining_on_void']);
            $voucher->update([
                'status' => EcommerceVoucher::STATUS_VOIDED,
                'balance' => $cancelRemaining ? 0 : $voucher->balance,
                'void_reason' => $data['reason'],
                'void_notes' => $data['notes'] ?? null,
                'scheduled_void_at' => null,
            ]);

            EcommerceVoucherLog::create([
                'voucher_id' => $voucher->id,
                'log_type' => 'activity',
                'action' => 'Voucher Voided',
                'details' => 'Voided: ' . $data['reason'],
                'performed_by' => $actor,
                'performed_by_id' => $request->user()?->id,
            ]);
        }

        if (! empty($data['notes'])) {
            EcommerceVoucherLog::create([
                'voucher_id' => $voucher->id,
                'log_type' => 'note',
                'action' => 'Note',
                'details' => trim($data['notes']),
                'performed_by' => $actor,
                'performed_by_id' => $request->user()?->id,
            ]);
        }

        return response()->json(['success' => true, 'data' => $this->payload($voucher->fresh('logs'), true)]);
    }

    private function authorizeAction(Request $request, string $action)
    {
        $settings = EcommerceVoucherSetting::current();
        if (empty($settings["perm_{$action}_enabled"])) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($action) . ' voucher is currently disabled in Voucher Settings.',
            ], 403);
        }

        $user = $request->user();
        if (! $user) {
            return null;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) ($settings["perm_{$action}_roles"] ?? 'super_admin'))));
        $role = strtolower((string) ($user->role ?? ''));
        $hasRole = in_array($role, $allowed, true) || ($user->isSuperAdmin() && in_array('super_admin', $allowed, true));
        if (! $hasRole && method_exists($user, 'hasAnyRole')) {
            try {
                $hasRole = $user->hasAnyRole($allowed);
            } catch (\Throwable $e) {
                $hasRole = false;
            }
        }

        if (! $hasRole) {
            return response()->json([
                'success' => false,
                'message' => 'Your role is not permitted to ' . $action . ' vouchers per Voucher Settings.',
            ], 403);
        }

        return null;
    }

    private function syncExpired(): void
    {
        $settings = EcommerceVoucherSetting::current();
        $today = Carbon::now($settings['timezone'] ?? 'UTC')->toDateString();

        // Process scheduled voids whose date has arrived
        $scheduled = EcommerceVoucher::query()
            ->where('status', EcommerceVoucher::STATUS_ACTIVE)
            ->whereNotNull('scheduled_void_at')
            ->whereDate('scheduled_void_at', '<=', $today)
            ->get();

        foreach ($scheduled as $v) {
            $cancelRemaining = ! empty($settings['cancel_remaining_on_void']);
            $v->update([
                'status' => EcommerceVoucher::STATUS_VOIDED,
                'balance' => $cancelRemaining ? 0 : $v->balance,
            ]);
            EcommerceVoucherLog::create([
                'voucher_id' => $v->id,
                'log_type' => 'activity',
                'action' => 'Voucher Voided',
                'details' => 'Scheduled void executed' . ($v->void_reason ? ': ' . $v->void_reason : ''),
                'performed_by' => 'System',
            ]);
        }

        if (empty($settings['auto_expire'])) {
            return;
        }

        EcommerceVoucher::query()
            ->where('status', EcommerceVoucher::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today)
            ->update(['status' => EcommerceVoucher::STATUS_EXPIRED]);
    }

    private function stats($query): array
    {
        $counts = (clone $query)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[EcommerceVoucher::STATUS_ACTIVE] ?? 0),
            'redeemed' => (int) ($counts[EcommerceVoucher::STATUS_REDEEMED] ?? 0),
            'expired' => (int) ($counts[EcommerceVoucher::STATUS_EXPIRED] ?? 0),
            'voided' => (int) ($counts[EcommerceVoucher::STATUS_VOIDED] ?? 0),
        ];
    }

    private function payload(EcommerceVoucher $v, bool $withLogs = false): array
    {
        $amount = (float) $v->amount;
        $balance = (float) $v->balance;

        $label = match ($v->status) {
            EcommerceVoucher::STATUS_ACTIVE => $balance < $amount ? 'Active Partially Used' : 'Active Unused',
            EcommerceVoucher::STATUS_REDEEMED => 'Redeemed',
            EcommerceVoucher::STATUS_EXPIRED => 'Expired',
            EcommerceVoucher::STATUS_VOIDED => 'Voided',
            default => ucfirst((string) $v->status),
        };

        $data = [
            'id' => $v->id,
            'code' => $v->code,
            'holder_type' => $v->holder_type,
            'holder_name' => $v->holder_name,
            'holder_email' => $v->holder_email,
            'amount' => $amount,
            'balance' => $balance,
            'currency' => $v->currency,
            'status' => $v->status,
            'status_label' => $label,
            'usage_type' => $v->usage_type,
            'issued_at' => optional($v->issued_at)->format('Y-m-d'),
            'expires_at' => optional($v->expires_at)->format('Y-m-d'),
            'scheduled_void_at' => optional($v->scheduled_void_at)->format('Y-m-d'),
            'notes' => $v->notes,
            'void_reason' => $v->void_reason,
            'void_notes' => $v->void_notes,
            'order_number' => $v->order_number,
            'issue_category' => $v->issue_category,
            'issue_summary' => $v->issue_summary,
            'compensation_reason' => $v->compensation_reason,
            'delivery_methods' => json_decode((string) $v->delivery_methods, true) ?: [],
            'case_id' => $v->case_id,
            'issued_by_name' => $v->issued_by_name ?: 'Admin',
        ];

        if ($withLogs) {
            $logs = $v->relationLoaded('logs') ? $v->logs : $v->logs()->get();
            $activities = $logs->where('log_type', 'activity')->values()->map(fn (EcommerceVoucherLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'details' => $l->details,
                'performed_by' => $l->performed_by ?: 'System',
                'created_at' => optional($l->created_at)->toIso8601String(),
            ])->all();

            if (count($activities) === 0) {
                $activities[] = [
                    'id' => 0,
                    'action' => 'Voucher Issued',
                    'details' => sprintf('Voucher %s created for $%s', $v->code, number_format($amount, 2)),
                    'performed_by' => $v->issued_by_name ?: 'Admin',
                    'created_at' => optional($v->created_at)->toIso8601String(),
                ];
            }

            $notes = $logs->where('log_type', 'note')->values()->map(fn (EcommerceVoucherLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'details' => $l->details,
                'performed_by' => $l->performed_by ?: 'Admin',
                'created_at' => optional($l->created_at)->toIso8601String(),
            ])->all();

            if (count($notes) === 0 && ! empty($v->issue_summary)) {
                $notes[] = [
                    'id' => 0,
                    'action' => 'Case Created',
                    'details' => $v->issue_summary,
                    'performed_by' => $v->issued_by_name ?: 'Admin',
                    'created_at' => optional($v->created_at)->toIso8601String(),
                ];
            }

            $data['activity_history'] = $activities;
            $data['internal_notes'] = $notes;
        }

        return $data;
    }
}
