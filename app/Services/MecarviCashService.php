<?php

namespace App\Services;

use App\Models\EcommerceWalletAccount;
use App\Models\EcommerceWalletActivityLog;
use App\Models\EcommerceWalletSetting;
use App\Models\EcommerceWalletTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Mecarvi Cash (wallet) business rules: accounts, references, permissions, limits and sweeps.
 */
class MecarviCashService
{
    public const STAFF_ROLES = ['super_admin', 'admin', 'editor', 'manager', 'staff'];

    /** disable_reason used when a wallet is restricted for too many declined funding attempts. */
    public const DECLINE_REASON = 'Declined funding attempts';

    /**
     * Record a declined top-up charge. After "decline_attempts_before_restrict" declines in a row
     * (since the last successful top-up or admin re-enable) the wallet is restricted (set inactive).
     * Returns true when this decline restricted the wallet.
     */
    public static function recordFundingDecline(User $user, string $method, ?string $message = null): bool
    {
        $s = self::settings();
        $account = self::account($user);
        self::log($user, 'declined', 'Funding Declined', 'Top-up via ' . $method . ' was declined' . ($message ? ": {$message}" : '.'), null, null);

        $limit = (int) ($s['decline_attempts_before_restrict'] ?? 0);
        if ($limit <= 0 || $account->status !== 'active') {
            return false;
        }

        // Row ids, not timestamps: a top-up and a decline can land in the same second.
        $sinceId = (int) EcommerceWalletActivityLog::where('user_id', $user->id)
            ->whereIn('kind', ['topup', 'enabled'])
            ->max('id');
        $declines = EcommerceWalletActivityLog::where('user_id', $user->id)
            ->where('kind', 'declined')
            ->where('id', '>', $sinceId)
            ->count();
        if ($declines < $limit) {
            return false;
        }

        $account->update([
            'status' => 'inactive',
            'disable_reason' => self::DECLINE_REASON,
            'disable_description' => "Restricted after {$declines} declined funding attempts.",
            'disable_funds_action' => null,
            'disabled_at' => now(),
            'disabled_by' => 'System',
        ]);
        self::log($user, 'disabled', 'Wallet Restricted', "Restricted after {$declines} declined funding attempts. Funds kept in wallet.", null, null);

        return true;
    }

    public static function settings(): array
    {
        return EcommerceWalletSetting::current();
    }

    /** Get (or lazily create) the wallet account row for a user. */
    public static function account(User $user): EcommerceWalletAccount
    {
        $account = EcommerceWalletAccount::where('user_id', $user->id)->first();
        if ($account) {
            return $account;
        }

        return EcommerceWalletAccount::create([
            'user_id' => $user->id,
            'wallet_code' => 'WLT-' . str_pad((string) $user->id, 7, '0', STR_PAD_LEFT),
            'wallet_type' => strtolower((string) $user->role) === 'business' ? 'business' : 'customer',
            'status' => 'active',
        ]);
    }

    /** Batch-load (and lazily create) wallet accounts for a collection/array of users in 1-2 queries. */
    public static function accountsForUsers(iterable $users): \Illuminate\Support\Collection
    {
        $collection = collect($users);
        if ($collection->isEmpty()) {
            return collect();
        }
        $ids = $collection->pluck('id')->all();
        $existing = EcommerceWalletAccount::whereIn('user_id', $ids)->get()->keyBy('user_id');
        $missing = [];
        $now = now();

        foreach ($collection as $u) {
            if (! $existing->has($u->id)) {
                $missing[] = [
                    'user_id' => $u->id,
                    'wallet_code' => 'WLT-' . str_pad((string) $u->id, 7, '0', STR_PAD_LEFT),
                    'wallet_type' => strtolower((string) $u->role) === 'business' ? 'business' : 'customer',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($missing) {
            EcommerceWalletAccount::insertOrIgnore($missing);
            $existing = EcommerceWalletAccount::whereIn('user_id', $ids)->get()->keyBy('user_id');
        }

        return $existing;
    }

    /** Run sweep at most once every 5 minutes on web requests. */
    public static function sweepIfDue(): void
    {
        if (Cache::add('mecarvi_cash_sweep_lock', 1, 300)) {
            self::sweep();
        }
    }

    /** Next free reference id like WL-ADD-000149 / SC-ISS-000149 / WAD-0000149. */
    public static function nextReference(string $kind): string
    {
        [$prefix, $pad] = match ($kind) {
            'add' => ['WL-ADD-', 6],
            'credit' => ['SC-ISS-', 6],
            'adjust' => ['WAD-', 7],
            'reverse' => ['SC-REV-', 6],
            default => ['REF-', 6],
        };

        $n = EcommerceWalletTransaction::where('reference_id', 'like', $prefix . '%')->count() + 1;
        do {
            $ref = $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
            $exists = EcommerceWalletTransaction::where('reference_id', $ref)->exists();
            $n++;
        } while ($exists);

        return $ref;
    }

    /** Roles allowed to run an action ("add", "adjust", "credit", "disable"). */
    public static function roleAllowed(?User $user, string $action): bool
    {
        if (! $user) {
            return false;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) (self::settings()["perm_{$action}_roles"] ?? 'super_admin'))));
        $role = strtolower((string) ($user->role ?? ''));
        if (in_array($role, $allowed, true)) {
            return true;
        }
        try {
            return $user->hasAnyRole($allowed);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function log(User $customer, string $kind, string $title, ?string $description, ?string $reference, ?User $actor): void
    {
        EcommerceWalletActivityLog::create([
            'user_id' => $customer->id,
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'reference_id' => $reference,
            'performed_by' => $actor?->name ?: 'System',
            'performed_by_id' => $actor?->id,
        ]);
        EcommerceWalletAccount::where('user_id', $customer->id)->update(['last_activity_at' => now()]);
    }

    /**
     * Validate a customer-initiated top-up against Mecarvi Cash settings.
     * Returns an error message, or null when allowed.
     */
    public static function validateCustomerTopUp(User $user, float $amount, string $method): ?string
    {
        $s = self::settings();
        if (empty($s['enabled'])) {
            return $s['wallet_name'] . ' is currently disabled.';
        }
        if (empty($s['allow_customer_add_funds'])) {
            return 'Adding funds to the wallet is currently disabled.';
        }
        $account = self::account($user);
        if ($account->status !== 'active') {
            return $account->disable_reason === self::DECLINE_REASON
                ? 'Your wallet is restricted after repeated declined payments. Please contact support.'
                : 'Your wallet is disabled. Please contact support.';
        }
        if ($amount < (float) $s['min_top_up']) {
            return 'Minimum top-up amount is $' . number_format((float) $s['min_top_up'], 2) . '.';
        }
        if ($amount > (float) $s['max_top_up']) {
            return 'Maximum top-up amount is $' . number_format((float) $s['max_top_up'], 2) . '.';
        }
        $methodKey = match (strtolower($method)) {
            'card', 'credit_card', 'debit_card' => 'card',
            'paypal' => 'paypal',
            'cashapp', 'cash_app', 'cash_app_pay' => 'cashapp',
            default => strtolower($method),
        };
        if (! in_array($methodKey, (array) $s['payment_methods'], true)) {
            return 'This payment method is not allowed for wallet top-ups.';
        }
        $max = (float) $s['max_wallet_balance'];
        if ($max > 0 && ((float) $user->wallet_balance + $amount) > $max) {
            return 'This top-up would exceed the maximum wallet balance of $' . number_format($max, 2) . '.';
        }
        $topups = EcommerceWalletTransaction::where('user_id', $user->id)
            ->where('balance_type', 'wallet')->where('amount', '>', 0)
            ->whereIn('category', ['topup']);
        $daily = (float) $s['daily_add_limit'];
        if ($daily > 0) {
            $today = (clone $topups)->where('created_at', '>=', now()->startOfDay())->sum('amount');
            if ($today + $amount > $daily) {
                return 'Daily add-funds limit of $' . number_format($daily, 2) . ' reached.';
            }
        }
        $monthly = (float) $s['monthly_add_limit'];
        if ($monthly > 0) {
            $month = (clone $topups)->where('created_at', '>=', now()->startOfMonth())->sum('amount');
            if ($month + $amount > $monthly) {
                return 'Monthly add-funds limit of $' . number_format($monthly, 2) . ' reached.';
            }
        }

        return null;
    }

    /**
     * Validate paying with the wallet (orders, memberships, gift cards) against Mecarvi Cash settings
     * and the customer's wallet status. Returns an error message, or null when allowed.
     * Pass $balance when the caller already fetched the live balance.
     */
    public static function validateWalletSpend(User $user, float $amount, ?float $balance = null, bool $partial = false): ?string
    {
        $s = self::settings();
        $name = $s['wallet_name'] ?: 'Mecarvi Cash';
        if (empty($s['enabled'])) {
            return "{$name} is currently disabled. Please choose another payment method.";
        }
        if (empty($s['allow_at_checkout'])) {
            return "{$name} can't be used for payments right now. Please choose another payment method.";
        }
        if ($partial && empty($s['allow_partial_payment'])) {
            return "Paying part of an order with {$name} isn't allowed. Use {$name} for the full amount or choose another payment method.";
        }
        $account = self::account($user);
        if ($account->status !== 'active' && $account->disable_reason === self::DECLINE_REASON) {
            return "Your {$name} wallet is restricted after repeated declined payments. Please contact support.";
        }
        if ($account->status !== 'active') {
            return "Your {$name} wallet is disabled. Please contact support or choose another payment method.";
        }
        $min = (float) ($s['min_balance_for_use'] ?? 0);
        if ($min > 0) {
            $balance ??= WalletService::getWalletBalance($user);
            if ($balance < $min) {
                return "You need a {$name} balance of at least $" . number_format($min, 2) . ' to pay with it.';
            }
        }

        return null;
    }

    /** Whether a credit source ("affiliate", "loyalty", "store_credit", "adjustments") may fund the wallet. */
    public static function sourceAllowed(string $source): bool
    {
        $s = self::settings();

        return ! empty($s['enabled']) && ! empty($s["source_{$source}"]);
    }

    /**
     * "Automatically Restore Wallet Payments": when an order is cancelled/voided/refunded, put back the
     * part that was paid from the wallet. Uses the local ledger, so it's idempotent and never restores
     * more than was debited (wallet refunds already issued through returns are subtracted).
     * Returns the amount restored.
     */
    public static function restoreOrderWalletPayment(\App\Models\EcommerceOrder $order, string $reason): float
    {
        try {
            $s = self::settings();
            if (empty($s['enabled']) || empty($s['auto_restore_wallet_payments']) || ! $order->user_id || ! $order->order_number) {
                return 0.0;
            }
            $restoreRef = $order->order_number . '-WREST';
            $ledger = EcommerceWalletTransaction::where('user_id', $order->user_id)->where('balance_type', '!=', 'store_credit');
            $paid = -(float) (clone $ledger)->where('reference_id', $order->order_number)->where('amount', '<', 0)->sum('amount');
            if ($paid <= 0) {
                return 0.0;
            }
            $alreadyBack = (float) (clone $ledger)->whereIn('reference_id', [$restoreRef, (string) $order->id])->where('amount', '>', 0)->sum('amount');
            $amount = round($paid - $alreadyBack, 2);
            if ($amount <= 0) {
                return 0.0;
            }

            $ok = WalletService::adjustWallet((int) $order->user_id, $amount, 'refund', "Wallet payment restored for order #{$order->order_number} ({$reason})", $restoreRef, true, [
                'category' => 'refund',
                'reason' => $reason,
            ]);
            if (! $ok) {
                Log::warning("MecarviCashService: wallet restore failed for order {$order->order_number}");

                return 0.0;
            }
            if ($user = User::find($order->user_id)) {
                self::log($user, 'refund', 'Wallet Payment Restored', sprintf('$%s returned to wallet for order #%s.', number_format($amount, 2), $order->order_number), $restoreRef, null);
            }

            return $amount;
        } catch (\Throwable $e) {
            Log::warning('MecarviCashService::restoreOrderWalletPayment failed: ' . $e->getMessage());

            return 0.0;
        }
    }

    /** Settings that are safe to expose to customers / storefront. */
    public static function publicSettings(): array
    {
        $s = self::settings();

        return [
            'enabled' => (bool) $s['enabled'],
            'wallet_name' => (string) $s['wallet_name'],
            'allow_at_checkout' => (bool) $s['enabled'] && (bool) $s['allow_at_checkout'],
            'allow_partial_payment' => (bool) $s['allow_partial_payment'],
            'min_balance_for_use' => (float) $s['min_balance_for_use'],
            'allow_customer_add_funds' => (bool) $s['enabled'] && (bool) $s['allow_customer_add_funds'],
            'min_top_up' => (float) $s['min_top_up'],
            'max_top_up' => (float) $s['max_top_up'],
            'payment_methods' => array_values((array) $s['payment_methods']),
            'allow_refunds_to_wallet' => (bool) $s['enabled'] && (bool) $s['allow_refunds_to_wallet'],
            'source_loyalty' => self::sourceAllowed('loyalty'),
        ];
    }

    /** Disable a wallet now: apply the remaining-funds policy and write the ledger/audit rows. */
    public static function disableNow(User $customer, EcommerceWalletAccount $account, string $reason, ?string $description, string $fundsAction, ?User $actor): void
    {
        $balance = round((float) WalletService::getWalletBalance($customer), 2);
        $credit = round((float) $account->store_credit_balance, 2);
        $ref = 'WDS-' . str_pad((string) ($account->id), 6, '0', STR_PAD_LEFT) . '-' . now()->format('ymdHis');

        if ($balance > 0) {
            $label = $fundsAction === 'refund' ? 'Refund to original payment method requested' : 'Remaining funds voided';
            WalletService::adjustWallet($customer->id, $balance, 'debit', "Wallet disabled – {$label}", $ref, false, [
                'category' => 'adjustment',
                'reason' => $reason,
                'status' => $fundsAction === 'refund' ? 'Pending Refund' : 'Completed',
                'performed_by' => $actor?->name,
                'performed_by_id' => $actor?->id,
            ]);
        }
        if ($credit > 0) {
            EcommerceWalletTransaction::create([
                'user_id' => $customer->id,
                'type' => 'Store Credit Reversed',
                'amount' => -$credit,
                'balance_after' => 0,
                'description' => 'Store credit voided – wallet disabled',
                'status' => 'Reversed',
                'reference_id' => $ref . '-SC',
                'balance_type' => 'store_credit',
                'category' => 'credit_reversed',
                'reason' => $reason,
                'performed_by' => $actor?->name,
                'performed_by_id' => $actor?->id,
            ]);
        }

        $account->update([
            'status' => 'inactive',
            'store_credit_balance' => 0,
            'disable_reason' => $reason,
            'disable_description' => $description,
            'disable_effective_at' => null,
            'disable_funds_action' => $fundsAction,
            'disabled_at' => now(),
            'disabled_by' => $actor?->name ?: 'System',
        ]);

        self::log($customer, 'disabled', 'Wallet Disabled', "{$reason}. " . ($fundsAction === 'refund' ? 'Funds queued for refund.' : 'Funds voided.'), $ref, $actor);
    }

    /**
     * Apply scheduled disables and inactivity auto-disable. Safe & cheap to call repeatedly.
     */
    public static function sweep(): int
    {
        $count = 0;
        try {
            // 1) scheduled disables that reached their date
            EcommerceWalletAccount::where('status', 'active')
                ->whereNotNull('disable_effective_at')
                ->whereDate('disable_effective_at', '<=', Carbon::today())
                ->get()
                ->each(function (EcommerceWalletAccount $a) use (&$count) {
                    $user = User::find($a->user_id);
                    if ($user) {
                        self::disableNow($user, $a, $a->disable_reason ?: 'Scheduled disable', $a->disable_description, $a->disable_funds_action ?: 'void', null);
                        $count++;
                    }
                });

            // 2) inactivity auto-disable
            $s = self::settings();
            if (! empty($s['auto_disable_inactive']) && (int) $s['inactivity_period'] > 0) {
                $n = (int) $s['inactivity_period'];
                $cutoff = match ($s['inactivity_unit']) {
                    'days' => now()->subDays($n),
                    'weeks' => now()->subWeeks($n),
                    'years' => now()->subYears($n),
                    default => now()->subMonths($n),
                };
                EcommerceWalletAccount::where('status', 'active')
                    ->where('created_at', '<', $cutoff)
                    ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $cutoff))
                    ->get()
                    ->each(function (EcommerceWalletAccount $a) use ($cutoff, $s, &$count) {
                        $user = User::find($a->user_id);
                        if (! $user) {
                            return;
                        }
                        $recent = EcommerceWalletTransaction::where('user_id', $user->id)->where('created_at', '>=', $cutoff)->exists();
                        if ($recent) {
                            return;
                        }
                        $action = ($s['inactivity_action'] ?? 'keep') === 'refund' ? 'refund' : 'keep';
                        if ($action === 'keep') {
                            $a->update([
                                'status' => 'inactive', 'disable_reason' => 'Inactivity',
                                'disable_description' => 'Auto-disabled after period of inactivity.',
                                'disable_funds_action' => null, 'disabled_at' => now(), 'disabled_by' => 'System',
                            ]);
                            self::log($user, 'disabled', 'Wallet Auto-Disabled', 'Disabled due to inactivity. Funds kept in wallet.', null, null);
                        } else {
                            self::disableNow($user, $a, 'Inactivity', 'Auto-disabled after period of inactivity.', 'refund', null);
                        }
                        $count++;
                    });
            }
        } catch (\Throwable $e) {
            Log::warning('MecarviCashService::sweep failed: ' . $e->getMessage());
        }

        return $count;
    }
}
