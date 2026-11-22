<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceWalletAccount;
use App\Models\EcommerceWalletActivityLog;
use App\Models\EcommerceWalletNote;
use App\Models\EcommerceWalletSetting;
use App\Models\EcommerceWalletTransaction;
use App\Models\User;
use App\Services\MecarviCashService as Cash;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin backend for the Mecarvi Cash (wallet) section:
 * wallet directory, details, history, notes, add funds, store credit, adjust, disable and settings.
 */
class AdminMecarviCashController extends Controller
{
    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function customers()
    {
        return User::query()->where(fn ($q) => $q->whereNull('role')->orWhereNotIn(DB::raw('LOWER(role)'), Cash::STAFF_ROLES));
    }

    private function fail(string $message, int $status = 422)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = strtoupper(substr($parts[0] ?? '?', 0, 1));
        $b = strtoupper(substr($parts[1] ?? ($parts[0][1] ?? ''), 0, 1));

        return $a . $b;
    }

    private function row(User $u, EcommerceWalletAccount $a): array
    {
        $wallet = round((float) $u->wallet_balance, 2);
        $credit = round((float) $a->store_credit_balance, 2);

        return [
            'id' => $u->id,
            'wallet_id' => $a->wallet_code,
            'wallet_type' => ucfirst($a->wallet_type),
            'name' => $u->business_name ?: $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'initials' => $this->initials($u->business_name ?: ($u->name ?? '?')),
            'wallet_balance' => $wallet,
            'store_credit' => $credit,
            'total_balance' => round($wallet + $credit, 2),
            'status' => $a->status === 'active' ? 'Active' : 'Inactive',
            'disable_effective_at' => optional($a->disable_effective_at)->format('Y-m-d'),
        ];
    }

    private function displayType(EcommerceWalletTransaction $t): string
    {
        return match ($t->category) {
            'topup' => 'Wallet Top-up',
            'payment' => 'Wallet Payment Used',
            'adjustment' => 'Wallet Adjustment',
            'credit_issued' => 'Store Credit Issued',
            'credit_reversed' => 'Store Credit Reversed',
            default => (function () use ($t) {
                $type = strtolower((string) $t->type);
                if (str_contains($type, 'store credit')) {
                    return (float) $t->amount < 0 ? 'Store Credit Reversed' : 'Store Credit Issued';
                }

                return (float) $t->amount < 0 ? 'Wallet Payment Used' : 'Wallet Top-up';
            })(),
        };
    }

    private function txPayload(EcommerceWalletTransaction $t): array
    {
        $t->loadMissing([]);

        return [
            'id' => $t->id,
            'created_at' => optional($t->created_at)->toIso8601String(),
            'type' => $this->displayType($t),
            'balance_type' => $t->balance_type ?: 'wallet',
            'category' => $t->category,
            'description' => $t->description,
            'reason' => $t->reason,
            'amount' => (float) $t->amount,
            'balance_after' => (float) $t->balance_after,
            'status' => $t->status,
            'reference_id' => $t->reference_id,
            'performed_by' => $t->performed_by,
            'expires_at' => $t->expires_at ? Carbon::parse($t->expires_at)->format('Y-m-d') : null,
        ];
    }

    private function guard(Request $request, string $action, string $label)
    {
        $s = Cash::settings();
        if (empty($s['enabled'])) {
            return $this->fail($s['wallet_name'] . ' is currently disabled in Mecarvi Cash Settings.', 403);
        }
        if (! Cash::roleAllowed($request->user(), $action)) {
            return $this->fail("Your role is not permitted to {$label} per Mecarvi Cash Settings.", 403);
        }

        return null;
    }

    private function activeOrFail(EcommerceWalletAccount $a, string $name)
    {
        if ($a->status !== 'active') {
            return $this->fail("{$name}'s wallet is disabled. Enable it before making changes.");
        }

        return null;
    }

    private function resolveUser($id): User
    {
        return $this->customers()->findOrFail($id);
    }

    /* ------------------------------------------------------------------ */
    /* Directory                                                           */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        Cash::sweepIfDue();

        $q = $this->customers();
        if ($request->filled('search')) {
            // Case-insensitive on every driver (Postgres LIKE is case-sensitive).
            $s = '%' . mb_strtolower(trim((string) $request->input('search'))) . '%';
            $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$s])
                ->orWhereRaw('LOWER(email) LIKE ?', [$s])
                ->orWhereRaw('LOWER(business_name) LIKE ?', [$s]));
        }
        // Stat-card filters: all | wallet_balance | store_credit | active | inactive ("status" kept for older callers).
        $filter = strtolower((string) $request->input('filter', $request->input('status', 'all')));
        if (in_array($filter, ['active', 'inactive'], true)) {
            $inactiveIds = EcommerceWalletAccount::where('status', 'inactive')->pluck('user_id');
            $filter === 'inactive' ? $q->whereIn('id', $inactiveIds) : $q->whereNotIn('id', $inactiveIds);
        } elseif ($filter === 'wallet_balance') {
            $q->where('wallet_balance', '>', 0);
        } elseif ($filter === 'store_credit') {
            $q->whereIn('id', EcommerceWalletAccount::where('store_credit_balance', '>', 0)->select('user_id'));
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 12)));
        $page = $q->orderBy('id')->paginate($perPage);

        $accounts = Cash::accountsForUsers($page->items());
        $rows = collect($page->items())->map(fn (User $u) => $this->row($u, $accounts->get($u->id) ?? Cash::account($u)))->values();

        $userAgg = $this->customers()
            ->selectRaw('COUNT(*) as total_customers, COALESCE(SUM(wallet_balance), 0) as total_wallet_balance')
            ->first();
        $accAgg = EcommerceWalletAccount::query()
            ->selectRaw("COALESCE(SUM(store_credit_balance), 0) as store_credit_balance, SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_wallets")
            ->first();

        $totalCustomers = (int) ($userAgg->total_customers ?? 0);
        $inactive = (int) ($accAgg->inactive_wallets ?? 0);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'stats' => [
                'total_wallet_balance' => round((float) ($userAgg->total_wallet_balance ?? 0), 2),
                'store_credit_balance' => round((float) ($accAgg->store_credit_balance ?? 0), 2),
                'active_wallets' => max(0, $totalCustomers - $inactive),
                'inactive_wallets' => $inactive,
            ],
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

    /** Suggested reference id for the modals. */
    public function nextReference(Request $request)
    {
        $kind = in_array($request->input('type'), ['add', 'credit', 'adjust'], true) ? $request->input('type') : 'add';

        return response()->json(['success' => true, 'data' => ['reference_id' => Cash::nextReference($kind)]]);
    }

    /* ------------------------------------------------------------------ */
    /* Wallet details                                                      */
    /* ------------------------------------------------------------------ */

    public function show($id)
    {
        $user = $this->resolveUser($id);
        WalletService::getWalletBalance($user);
        $user->refresh();
        $account = Cash::account($user);

        $agg = EcommerceWalletTransaction::where('user_id', $user->id)
            ->where(fn ($q) => $q->where('balance_type', 'wallet')->orWhereNull('balance_type'))
            ->selectRaw("
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as total_credits,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) as total_debits,
                COALESCE(SUM(CASE WHEN category = 'adjustment' THEN ABS(amount) ELSE 0 END), 0) as total_adjustments,
                COUNT(*) as total_transactions,
                SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as credit_transactions,
                SUM(CASE WHEN amount < 0 THEN 1 ELSE 0 END) as debit_transactions,
                SUM(CASE WHEN category = 'adjustment' THEN 1 ELSE 0 END) as adjustments
            ")
            ->first();

        $row = $this->row($user, $account);

        return response()->json([
            'success' => true,
            'data' => array_merge($row, [
                'overview' => [
                    'total_credits' => round((float) ($agg->total_credits ?? 0), 2),
                    'total_debits' => round((float) ($agg->total_debits ?? 0), 2),
                    'total_adjustments' => round((float) ($agg->total_adjustments ?? 0), 2),
                    'net_balance' => $row['total_balance'],
                    'total_transactions' => (int) ($agg->total_transactions ?? 0),
                    'credit_transactions' => (int) ($agg->credit_transactions ?? 0),
                    'debit_transactions' => (int) ($agg->debit_transactions ?? 0),
                    'adjustments' => (int) ($agg->adjustments ?? 0),
                ],
                'store_credit_overview' => [
                    'total_issued' => round((float) $account->store_credit_issued_total, 2),
                    'total_redeemed' => round((float) $account->store_credit_redeemed_total, 2),
                    'current_balance' => round((float) $account->store_credit_balance, 2),
                ],
                'disable' => [
                    'reason' => $account->disable_reason,
                    'description' => $account->disable_description,
                    'effective_at' => optional($account->disable_effective_at)->format('Y-m-d'),
                    'funds_action' => $account->disable_funds_action,
                    'disabled_at' => optional($account->disabled_at)->toIso8601String(),
                    'disabled_by' => $account->disabled_by,
                ],
            ]),
        ]);
    }

    /**
     * Ledger. scope: all | debits | credits | adjustments | store_credit
     */
    public function transactions(Request $request, $id)
    {
        $user = $this->resolveUser($id);
        $scope = (string) $request->input('scope', 'all');

        $q = EcommerceWalletTransaction::where('user_id', $user->id);
        $walletOnly = fn ($x) => $x->where(fn ($w) => $w->where('balance_type', 'wallet')->orWhereNull('balance_type'));
        match ($scope) {
            'debits' => $walletOnly($q)->where('amount', '<', 0),
            'credits' => $walletOnly($q)->where('amount', '>', 0),
            'adjustments' => $walletOnly($q)->where('category', 'adjustment'),
            'store_credit' => $q->where('balance_type', 'store_credit'),
            default => null,
        };

        $cnt = EcommerceWalletTransaction::where('user_id', $user->id)
            ->selectRaw("
                SUM(CASE WHEN (balance_type = 'wallet' OR balance_type IS NULL) AND amount < 0 THEN 1 ELSE 0 END) as debits,
                SUM(CASE WHEN (balance_type = 'wallet' OR balance_type IS NULL) AND amount > 0 THEN 1 ELSE 0 END) as credits,
                SUM(CASE WHEN (balance_type = 'wallet' OR balance_type IS NULL) AND category = 'adjustment' THEN 1 ELSE 0 END) as adjustments,
                SUM(CASE WHEN balance_type = 'store_credit' THEN 1 ELSE 0 END) as store_credit
            ")
            ->first();

        $counts = [
            'debits' => (int) ($cnt->debits ?? 0),
            'credits' => (int) ($cnt->credits ?? 0),
            'adjustments' => (int) ($cnt->adjustments ?? 0),
            'store_credit' => (int) ($cnt->store_credit ?? 0),
        ];

        $perPage = max(1, min(100, (int) $request->input('per_page', 8)));
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($t) => $this->txPayload($t))->values(),
            'counts' => $counts,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function activity($id)
    {
        $user = $this->resolveUser($id);
        $logs = EcommerceWalletActivityLog::where('user_id', $user->id)->orderByDesc('id')->limit(100)->get();
        $loggedRefs = $logs->pluck('reference_id')->filter()->all();

        $items = $logs->map(fn ($l) => [
            'kind' => $l->kind, 'title' => $l->title, 'description' => $l->description,
            'reference_id' => $l->reference_id, 'performed_by' => $l->performed_by,
            'created_at' => optional($l->created_at)->toIso8601String(),
        ]);

        // Historic ledger rows created outside this admin module (top-ups, checkout payments, ...)
        $legacy = EcommerceWalletTransaction::where('user_id', $user->id)
            ->when($loggedRefs, fn ($q) => $q->whereNotIn('reference_id', $loggedRefs))
            ->orderByDesc('id')->limit(100)->get()
            ->map(function ($t) {
                $type = $this->displayType($t);
                $kind = match ($type) {
                    'Wallet Top-up' => 'topup', 'Wallet Payment Used' => 'payment', 'Wallet Adjustment' => 'adjust',
                    'Store Credit Issued' => 'issued', default => 'reversed',
                };

                return [
                    'kind' => $kind, 'title' => $type, 'description' => $t->description,
                    'reference_id' => $t->reference_id, 'performed_by' => $t->performed_by ?: 'System',
                    'created_at' => optional($t->created_at)->toIso8601String(),
                ];
            });

        $merged = $items->concat($legacy)->sortByDesc('created_at')->values()->take(100);

        return response()->json(['success' => true, 'data' => $merged]);
    }

    /** CSV statement download. */
    public function statement($id)
    {
        $user = $this->resolveUser($id);
        $account = Cash::account($user);
        $rows = EcommerceWalletTransaction::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->streamDownload(function () use ($rows, $user, $account) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Wallet', $account->wallet_code, 'Customer', $user->name, 'Email', $user->email]);
            fputcsv($out, ['Date', 'Type', 'Balance', 'Description', 'Amount', 'Balance After', 'Status', 'Reference ID']);
            foreach ($rows as $t) {
                fputcsv($out, [
                    optional($t->created_at)->format('Y-m-d H:i'),
                    $this->displayType($t),
                    ($t->balance_type ?: 'wallet') === 'store_credit' ? 'Store Credit' : 'Wallet',
                    $t->description,
                    number_format((float) $t->amount, 2, '.', ''),
                    number_format((float) $t->balance_after, 2, '.', ''),
                    $t->status,
                    $t->reference_id,
                ]);
            }
            fclose($out);
        }, "statement-{$account->wallet_code}.csv", ['Content-Type' => 'text/csv']);
    }

    /* ------------------------------------------------------------------ */
    /* Actions                                                             */
    /* ------------------------------------------------------------------ */

    public function addFunds(Request $request, $id)
    {
        if ($denied = $this->guard($request, 'add', 'add funds')) {
            return $denied;
        }
        $user = $this->resolveUser($id);
        $account = Cash::account($user);
        if ($denied = $this->activeOrFail($account, $user->name)) {
            return $denied;
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:1000000',
            'reason' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'internal_note' => 'nullable|string|max:2000',
            'notify_customer' => 'nullable|boolean',
            'reference_id' => 'nullable|string|max:40',
        ]);

        $amount = round((float) $data['amount'], 2);
        $current = round((float) WalletService::getWalletBalance($user), 2);
        $max = (float) Cash::settings()['max_wallet_balance'];
        if ($max > 0 && $current + $amount > $max) {
            return $this->fail('This would exceed the maximum wallet balance of $' . number_format($max, 2) . ' per customer.');
        }

        $ref = $this->freeReference($data['reference_id'] ?? null, 'add');
        $actor = $request->user();
        $desc = trim((string) ($data['description'] ?? '')) ?: "{$data['reason']} – added by admin";

        $ok = WalletService::adjustWallet($user->id, $amount, 'deposit', $desc, $ref, (bool) ($data['notify_customer'] ?? true), [
            'category' => 'topup', 'reason' => $data['reason'], 'internal_note' => $data['internal_note'] ?? null,
            'performed_by' => $actor?->name, 'performed_by_id' => $actor?->id,
        ]);
        if (! $ok) {
            return $this->fail('Failed to add funds to the wallet. Please try again.', 500);
        }

        Cash::log($user, 'topup', 'Wallet Top-up', sprintf('$%s added (%s).', number_format($amount, 2), $data['reason']), $ref, $actor);
        $this->saveInternalNote($user, $actor, $data['internal_note'] ?? null, "Add to wallet {$ref}");

        return $this->done($user, 'Funds added to wallet.', $ref);
    }

    public function issueStoreCredit(Request $request, $id)
    {
        if ($denied = $this->guard($request, 'credit', 'issue store credit')) {
            return $denied;
        }
        if (empty(Cash::settings()['source_store_credit'])) {
            return $this->fail('Store Credit is not an allowed credit source in Mecarvi Cash Settings.', 403);
        }
        $user = $this->resolveUser($id);
        $account = Cash::account($user);
        if ($denied = $this->activeOrFail($account, $user->name)) {
            return $denied;
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:1000000',
            'reason' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'validity' => 'required|in:none,date',
            'expires_at' => 'required_if:validity,date|nullable|date|after:today',
            'internal_note' => 'nullable|string|max:2000',
            'notify_customer' => 'nullable|boolean',
            'reference_id' => 'nullable|string|max:40',
        ]);

        $amount = round((float) $data['amount'], 2);
        $ref = $this->freeReference($data['reference_id'] ?? null, 'credit');
        $actor = $request->user();

        DB::transaction(function () use ($user, $account, $amount, $data, $ref, $actor) {
            $account = EcommerceWalletAccount::where('id', $account->id)->lockForUpdate()->first();
            $new = round((float) $account->store_credit_balance + $amount, 2);
            $account->update([
                'store_credit_balance' => $new,
                'store_credit_issued_total' => round((float) $account->store_credit_issued_total + $amount, 2),
            ]);
            EcommerceWalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'Store Credit Issued',
                'amount' => $amount,
                'balance_after' => $new,
                'description' => trim((string) ($data['description'] ?? '')) ?: sprintf('$%s store credit issued by admin', number_format($amount, 2)),
                'status' => 'Issued',
                'reference_id' => $ref,
                'balance_type' => 'store_credit',
                'category' => 'credit_issued',
                'reason' => $data['reason'],
                'internal_note' => $data['internal_note'] ?? null,
                'expires_at' => $data['validity'] === 'date' ? $data['expires_at'] : null,
                'performed_by' => $actor?->name,
                'performed_by_id' => $actor?->id,
            ]);
        });

        Cash::log($user, 'issued', 'Store Credit Issued', sprintf('$%s store credit issued to customer (%s).', number_format($amount, 2), $data['reason']), $ref, $actor);
        $this->saveInternalNote($user, $actor, $data['internal_note'] ?? null, "Store credit {$ref}");

        if (! empty($data['notify_customer'] ?? true) && $user->email) {
            try {
                app(\App\Services\EmailNotificationService::class)->sendEvent('store_credit_issued', [
                    'customer_name' => $user->name,
                    'customer_email' => $user->email,
                    'amount' => '$' . number_format($amount, 2),
                    'new_balance' => '$' . number_format((float) Cash::account($user)->store_credit_balance, 2),
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $user->email);
            } catch (\Throwable $e) {
                \Log::warning('Store credit email failed: ' . $e->getMessage());
            }
        }

        return $this->done($user, 'Store credit issued.', $ref);
    }

    public function adjustBalance(Request $request, $id)
    {
        if ($denied = $this->guard($request, 'adjust', 'adjust balances')) {
            return $denied;
        }
        $user = $this->resolveUser($id);
        $account = Cash::account($user);
        if ($denied = $this->activeOrFail($account, $user->name)) {
            return $denied;
        }

        $data = $request->validate([
            'amount' => 'required|numeric|not_in:0|min:-1000000|max:1000000',
            'reason' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'internal_note' => 'nullable|string|max:2000',
            'notify_customer' => 'nullable|boolean',
            'reference_id' => 'nullable|string|max:40',
        ]);

        $amount = round((float) $data['amount'], 2);
        $current = round((float) WalletService::getWalletBalance($user), 2);

        if ($amount > 0) {
            if (empty(Cash::settings()['source_adjustments'])) {
                return $this->fail('Adjustments are not an allowed credit source in Mecarvi Cash Settings.', 403);
            }
            $max = (float) Cash::settings()['max_wallet_balance'];
            if ($max > 0 && $current + $amount > $max) {
                return $this->fail('This would exceed the maximum wallet balance of $' . number_format($max, 2) . ' per customer.');
            }
        } elseif ($current + $amount < 0) {
            return $this->fail('Deduction exceeds the available wallet balance ($' . number_format($current, 2) . ').');
        }

        $ref = $this->freeReference($data['reference_id'] ?? null, 'adjust');
        $actor = $request->user();
        $isCredit = $amount > 0;
        $desc = trim((string) ($data['description'] ?? '')) ?: sprintf('Manual adjustment of %s$%s approved', $isCredit ? '+' : '-', number_format(abs($amount), 2));

        $ok = WalletService::adjustWallet($user->id, abs($amount), $isCredit ? 'credit' : 'debit', $desc, $ref, (bool) ($data['notify_customer'] ?? true), [
            'category' => 'adjustment', 'reason' => $data['reason'], 'status' => 'Approved',
            'internal_note' => $data['internal_note'] ?? null,
            'performed_by' => $actor?->name, 'performed_by_id' => $actor?->id,
        ]);
        if (! $ok) {
            return $this->fail('Failed to adjust the wallet balance. Please try again.', 500);
        }

        Cash::log($user, 'adjust', 'Wallet Adjustment', $desc . " ({$data['reason']})", $ref, $actor);
        $this->saveInternalNote($user, $actor, $data['internal_note'] ?? null, "Adjustment {$ref}");

        return $this->done($user, 'Wallet balance adjusted.', $ref);
    }

    public function disable(Request $request, $id)
    {
        if ($denied = $this->guard($request, 'disable', 'disable wallets')) {
            return $denied;
        }
        $user = $this->resolveUser($id);
        $account = Cash::account($user);
        if ($account->status !== 'active') {
            return $this->fail('This wallet is already disabled.');
        }

        $data = $request->validate([
            'reason' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'effective' => 'required|in:immediately,date',
            'effective_date' => 'required_if:effective,date|nullable|date|after_or_equal:today',
            'funds_action' => 'required|in:void,refund',
            'internal_note' => 'nullable|string|max:2000',
        ]);

        $actor = $request->user();

        if ($data['effective'] === 'date') {
            $account->update([
                'disable_reason' => $data['reason'],
                'disable_description' => $data['description'] ?? null,
                'disable_effective_at' => $data['effective_date'],
                'disable_funds_action' => $data['funds_action'],
            ]);
            Cash::log($user, 'disabled', 'Wallet Disable Scheduled', 'Will be disabled on ' . Carbon::parse($data['effective_date'])->format('M j, Y') . " ({$data['reason']}).", null, $actor);
        } else {
            Cash::disableNow($user, $account, $data['reason'], $data['description'] ?? null, $data['funds_action'], $actor);
        }
        $this->saveInternalNote($user, $actor, $data['internal_note'] ?? null, 'Disable wallet');

        return $this->done($user, $data['effective'] === 'date' ? 'Wallet disable scheduled.' : 'Wallet disabled.', null);
    }

    public function enable(Request $request, $id)
    {
        if ($denied = $this->guard($request, 'disable', 'enable wallets')) {
            return $denied;
        }
        $user = $this->resolveUser($id);
        $account = Cash::account($user);

        $account->update([
            'status' => 'active', 'disable_reason' => null, 'disable_description' => null,
            'disable_effective_at' => null, 'disable_funds_action' => null, 'disabled_at' => null, 'disabled_by' => null,
        ]);
        Cash::log($user, 'enabled', 'Wallet Enabled', 'Wallet re-enabled by admin.', null, $request->user());

        return $this->done($user, 'Wallet enabled.', null);
    }

    /* ------------------------------------------------------------------ */
    /* Internal notes (threaded)                                           */
    /* ------------------------------------------------------------------ */

    private function notePayload(EcommerceWalletNote $n): array
    {
        return [
            'id' => $n->id,
            'author' => $n->author_name,
            'author_id' => $n->author_id,
            'initials' => $this->initials($n->author_name),
            'created_at' => optional($n->created_at)->toIso8601String(),
            'updated' => $n->updated_at && $n->created_at && $n->updated_at->gt($n->created_at->copy()->addSecond()),
            'text' => $n->body,
            'replies' => $n->relationLoaded('replies') ? $n->replies->map(fn ($r) => [
                'id' => $r->id, 'author' => $r->author_name, 'author_id' => $r->author_id,
                'initials' => $this->initials($r->author_name),
                'created_at' => optional($r->created_at)->toIso8601String(), 'text' => $r->body,
            ])->values() : [],
        ];
    }

    private function saveInternalNote(User $customer, ?User $actor, ?string $text, string $context): void
    {
        $text = trim((string) $text);
        if ($text === '') {
            return;
        }
        EcommerceWalletNote::create([
            'user_id' => $customer->id, 'author_id' => $actor?->id, 'author_name' => $actor?->name ?: 'Admin',
            'body' => "[{$context}] " . $text,
        ]);
    }

    public function notes($id)
    {
        $user = $this->resolveUser($id);
        $notes = EcommerceWalletNote::with('replies')->where('user_id', $user->id)->whereNull('parent_id')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $notes->map(fn ($n) => $this->notePayload($n))->values()]);
    }

    public function addNote(Request $request, $id)
    {
        $user = $this->resolveUser($id);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $note = EcommerceWalletNote::create([
            'user_id' => $user->id, 'author_id' => $request->user()?->id,
            'author_name' => $request->user()?->name ?: 'Admin', 'body' => trim($data['body']),
        ]);

        return response()->json(['success' => true, 'data' => $this->notePayload($note->load('replies'))], 201);
    }

    public function replyNote(Request $request, $id, $noteId)
    {
        $user = $this->resolveUser($id);
        $parent = EcommerceWalletNote::where('user_id', $user->id)->whereNull('parent_id')->findOrFail($noteId);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        EcommerceWalletNote::create([
            'user_id' => $user->id, 'parent_id' => $parent->id, 'author_id' => $request->user()?->id,
            'author_name' => $request->user()?->name ?: 'Admin', 'body' => trim($data['body']),
        ]);

        return response()->json(['success' => true, 'data' => $this->notePayload($parent->fresh('replies'))], 201);
    }

    private function ownsNote(Request $request, EcommerceWalletNote $note): bool
    {
        $u = $request->user();

        return $u && ($u->id === $note->author_id || (method_exists($u, 'isSuperAdmin') && $u->isSuperAdmin()));
    }

    public function updateNote(Request $request, $id, $noteId)
    {
        $user = $this->resolveUser($id);
        $note = EcommerceWalletNote::where('user_id', $user->id)->findOrFail($noteId);
        if (! $this->ownsNote($request, $note)) {
            return $this->fail('You can only edit your own notes.', 403);
        }
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $note->update(['body' => trim($data['body'])]);

        $root = $note->parent_id ? EcommerceWalletNote::with('replies')->find($note->parent_id) : $note->load('replies');

        return response()->json(['success' => true, 'data' => $this->notePayload($root)]);
    }

    public function deleteNote(Request $request, $id, $noteId)
    {
        $user = $this->resolveUser($id);
        $note = EcommerceWalletNote::where('user_id', $user->id)->findOrFail($noteId);
        if (! $this->ownsNote($request, $note)) {
            return $this->fail('You can only delete your own notes.', 403);
        }
        EcommerceWalletNote::where('parent_id', $note->id)->delete();
        $note->delete();

        return response()->json(['success' => true, 'message' => 'Note deleted.']);
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    public function settings()
    {
        return response()->json(['success' => true, 'data' => EcommerceWalletSetting::current()]);
    }

    public function saveSettings(Request $request)
    {
        $presets = ['admin,manager,super_admin', 'manager,super_admin', 'admin,super_admin', 'super_admin'];

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'wallet_name' => 'required|string|max:60',
            'min_balance_for_use' => 'required|numeric|min:0',
            'allow_at_checkout' => 'required|boolean',
            'allow_partial_payment' => 'required|boolean',
            'allow_customer_add_funds' => 'required|boolean',
            'min_top_up' => 'required|numeric|min:0',
            'max_top_up' => 'required|numeric|gte:min_top_up',
            'payment_methods' => 'present|array',
            'payment_methods.*' => 'in:card,paypal,cashapp',
            'source_affiliate' => 'required|boolean',
            'source_loyalty' => 'required|boolean',
            'source_store_credit' => 'required|boolean',
            'source_adjustments' => 'required|boolean',
            'allow_refunds_to_wallet' => 'required|boolean',
            'auto_restore_wallet_payments' => 'required|boolean',
            'allow_partial_refunds' => 'required|boolean',
            'auto_disable_inactive' => 'required|boolean',
            'inactivity_period' => 'required|integer|min:1|max:600',
            'inactivity_unit' => ['required', Rule::in(['days', 'weeks', 'months', 'years'])],
            'inactivity_action' => ['required', Rule::in(['keep', 'refund'])],
            'perm_add_roles' => ['required', Rule::in($presets)],
            'perm_adjust_roles' => ['required', Rule::in($presets)],
            'perm_credit_roles' => ['required', Rule::in($presets)],
            'perm_disable_roles' => ['required', Rule::in($presets)],
            'max_wallet_balance' => 'required|numeric|min:0',
            'daily_add_limit' => 'required|numeric|min:0',
            'monthly_add_limit' => 'required|numeric|gte:daily_add_limit',
            'decline_attempts_before_restrict' => 'required|integer|min:1|max:20',
        ]);

        $row = EcommerceWalletSetting::query()->first();
        $row ? $row->update(['settings' => json_encode($data)]) : EcommerceWalletSetting::create(['settings' => json_encode($data)]);

        return response()->json(['success' => true, 'message' => 'Settings saved.', 'data' => EcommerceWalletSetting::current()]);
    }

    /* ------------------------------------------------------------------ */

    private function freeReference(?string $requested, string $kind): string
    {
        if ($requested && ! EcommerceWalletTransaction::where('reference_id', $requested)->exists()) {
            return $requested;
        }

        return Cash::nextReference($kind);
    }

    private function done(User $user, string $message, ?string $ref)
    {
        $user->refresh();

        return response()->json([
            'success' => true,
            'message' => $message,
            'reference_id' => $ref,
            'data' => $this->row($user, Cash::account($user)),
        ]);
    }
}
