<?php

namespace App\Services;

use App\Models\EcommerceAffiliate;
use App\Models\EcommerceAffiliatePayout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Affiliate earnings balance: kept separate from the wallet. The affiliate either converts it into
 * the wallet (convertToWallet) or withdraws it to the bank (create). Wallet money - top-ups, store
 * credit, refunds, converted loyalty/affiliate earnings - can never be withdrawn.
 */
class AffiliatePayoutService
{
    /** Withdrawal methods that send affiliate earnings out (settings key => stored method). */
    public const METHODS = ['paypal', 'bank_transfer', 'cash_app'];

    public static function enabledMethods(array $settings): array
    {
        $enabled = $settings['payout_methods'] ?? [];

        return array_values(array_filter(self::METHODS, fn ($m) => filter_var($enabled[$m] ?? false, FILTER_VALIDATE_BOOLEAN)));
    }

    public static function normalizeMethod(?string $method): ?string
    {
        $m = strtolower(str_replace([' ', '-'], '_', trim((string) $method)));

        return ['cashapp' => 'cash_app', 'bank' => 'bank_transfer'][$m] ?? ($m ?: null);
    }

    /** Earnings not yet committed to a payout or converted into the wallet. */
    public static function availableBalance(EcommerceAffiliate $affiliate): float
    {
        return self::availableFrom($affiliate, (float) $affiliate->total_earnings, self::committedPayouts($affiliate->user_id));
    }

    /** Net amount tied up in pending / approved / paid payouts. */
    public static function committedPayouts(int $userId): float
    {
        // Partially reversed paid payouts keep status 'paid'; their reversed part already reduced total_earnings.
        $q = EcommerceAffiliatePayout::where('user_id', $userId)->whereIn('status', ['pending', 'approved', 'paid']);

        return round((float) $q->sum('amount') - (float) $q->sum('reversed_amount'), 2);
    }

    /** Shared formula, for callers that compute earnings / payouts themselves. */
    public static function availableFrom(EcommerceAffiliate $affiliate, float $totalEarnings, float $committed): float
    {
        return max(0, round($totalEarnings - $committed - (float) $affiliate->converted_to_wallet, 2));
    }

    /**
     * Move affiliate earnings into the wallet (Mecarvi Cash). Once converted they are wallet money
     * and can only be spent, never withdrawn. Returns the new available affiliate balance.
     */
    public static function convertToWallet(User $user, EcommerceAffiliate $affiliate, float $amount): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \DomainException('Enter a valid amount.');
        }
        if ($affiliate->status !== 'Active') {
            throw new \DomainException('Your affiliate account is not active.');
        }
        if (!MecarviCashService::sourceAllowed('affiliate')) {
            throw new \DomainException('Converting affiliate earnings to the wallet is currently turned off.');
        }
        $cash = MecarviCashService::settings();
        if (MecarviCashService::account($user)->status !== 'active') {
            throw new \DomainException('Your ' . ($cash['wallet_name'] ?: 'wallet') . ' is disabled. Please contact support.');
        }
        $max = (float) ($cash['max_wallet_balance'] ?? 0);
        if ($max > 0 && WalletService::getWalletBalance($user) + $amount > $max + 0.001) {
            throw new \DomainException('This would exceed the maximum wallet balance of $' . number_format($max, 2) . '.');
        }

        return DB::transaction(function () use ($user, $affiliate, $amount) {
            $affiliate = EcommerceAffiliate::lockForUpdate()->find($affiliate->id);
            $available = self::availableBalance($affiliate);
            if ($amount > $available + 0.001) {
                throw new \DomainException('Amount exceeds your available affiliate earnings ($' . number_format($available, 2) . ').');
            }

            $ref = 'AFC-' . $affiliate->id . '-' . now()->format('ymdHis') . strtoupper(bin2hex(random_bytes(2))); // fits reference columns (40)
            $ok = WalletService::adjustWallet($user->id, $amount, 'Affiliate Earned', 'Affiliate earnings converted to wallet', $ref, true, [
                'category' => 'topup',
                'reason' => 'Affiliate earnings conversion',
            ]);
            if (!$ok) {
                throw new \DomainException('Could not add the funds to your wallet. Nothing was changed, please try again.');
            }
            $affiliate->update(['converted_to_wallet' => round((float) $affiliate->converted_to_wallet + $amount, 2)]);
            MecarviCashService::log($user, 'topup', 'Affiliate Earnings Converted', sprintf('$%s affiliate earnings moved into the wallet.', number_format($amount, 2)), $ref, $user);

            return self::availableBalance($affiliate->fresh());
        });
    }

    public static function nextScheduleDate(array $settings): Carbon
    {
        $now = now()->startOfDay();
        $schedule = strtolower((string) ($settings['payout_schedule'] ?? 'Monthly'));
        $day = strtolower((string) ($settings['payout_day'] ?? '1st of each month'));

        if ($schedule === 'weekly') {
            return $now->copy()->next(Carbon::MONDAY);
        }
        if (str_contains($schedule, 'bi')) {
            $d = $now->copy()->next(Carbon::MONDAY);
            return $d->weekOfYear % 2 === 0 ? $d : $d->addWeek();
        }

        $target = fn (Carbon $m) => str_contains($day, 'last') ? $m->copy()->endOfMonth()->startOfDay()
            : $m->copy()->day(str_contains($day, '15') ? 15 : 1)->startOfDay();

        $candidate = $target($now);
        return $candidate->gt($now) ? $candidate : $target($now->copy()->addMonthNoOverflow()->startOfMonth());
    }

    /** Throws DomainException with a user-facing message when a withdrawal is not allowed. */
    public static function assertCanWithdraw(User $user, EcommerceAffiliate $affiliate, float $amount, string $method, bool $isAdmin = false): void
    {
        $s = AffiliateCommissionService::settings();

        if (!AffiliateCommissionService::flag($s, 'program_active')) {
            throw new \DomainException('The affiliate program is currently not active.');
        }
        if ($affiliate->status !== 'Active') {
            throw new \DomainException('Your affiliate account is not active.');
        }
        if (!$isAdmin && !in_array($method, self::enabledMethods($s), true)) {
            throw new \DomainException('This payout method is not available.');
        }
        if ($amount <= 0) {
            throw new \DomainException('Enter a valid amount.');
        }
        if (!$isAdmin && AffiliateCommissionService::flag($s, 'require_min_payout') && $amount < (float) $s['min_payout_amount']) {
            throw new \DomainException('The minimum payout amount is $' . number_format((float) $s['min_payout_amount'], 2) . '.');
        }
        if (!$isAdmin && AffiliateCommissionService::flag($s, 'require_tax_info') && empty($affiliate->tax_info)) {
            throw new \DomainException('Please submit your tax information before requesting a payout.');
        }
        if (!$isAdmin && AffiliateCommissionService::flag($s, 'require_payout_method') && empty($affiliate->payout_method)) {
            throw new \DomainException('Please save a payout method before requesting a payout.');
        }
        // Only affiliate earnings can be withdrawn; the wallet balance is never touched here.
        $available = self::availableBalance($affiliate);
        if ($amount > $available + 0.001) {
            throw new \DomainException('Amount exceeds your available affiliate earnings ($' . number_format($available, 2) . ').');
        }
    }

    public static function create(
        User $user,
        EcommerceAffiliate $affiliate,
        float $amount,
        string $method,
        ?string $detail,
        string $source = 'user',
        ?array $commissionIds = null,
        ?string $reference = null,
        ?int $adminId = null,
        ?Carbon $scheduleDate = null,
        ?string $notes = null
    ): EcommerceAffiliatePayout {
        $s = AffiliateCommissionService::settings();
        $auto = stripos((string) ($s['payout_processing_mode'] ?? ''), 'auto') !== false;

        return DB::transaction(function () use ($user, $affiliate, $amount, $method, $detail, $source, $commissionIds, $reference, $adminId, $scheduleDate, $notes, $auto, $s) {
            $payoutNumber = 'PO-' . strtoupper(bin2hex(random_bytes(4)));

            // The payout itself is the hold on the affiliate balance (pending/approved/paid payouts are
            // subtracted in availableBalance); a rejection releases it. Re-check under lock.
            $locked = EcommerceAffiliate::lockForUpdate()->find($affiliate->id);
            if ($amount > self::availableBalance($locked) + 0.001) {
                throw new \DomainException('Amount exceeds your available affiliate earnings.');
            }

            $payout = EcommerceAffiliatePayout::create([
                'payout_number' => $payoutNumber,
                'user_id' => $user->id,
                'affiliate_id' => $affiliate->id,
                'amount' => $amount,
                'method' => $method,
                'method_detail' => $detail,
                'status' => ($auto || $source === 'admin') ? 'approved' : 'pending',
                'source' => $source,
                'auto_process' => $auto && $source === 'user',
                'commission_ids' => $commissionIds,
                'scheduled_for' => ($scheduleDate ?? ($auto || $source === 'admin' ? self::nextScheduleDate($s) : null))?->toDateString(),
                'reference_id' => $reference,
                'notes' => $notes,
                'processed_by' => $adminId,
                'approved_at' => ($auto || $source === 'admin') ? now() : null,
            ]);

            return $payout;
        });
    }

    public static function approve(EcommerceAffiliatePayout $p, ?int $adminId, ?string $notes = null): EcommerceAffiliatePayout
    {
        if ($p->status !== 'pending') {
            throw new \DomainException("Only pending payouts can be approved (current status: {$p->status}).");
        }
        $p->update([
            'status' => 'approved',
            'approved_at' => now(),
            'processed_by' => $adminId,
            'scheduled_for' => $p->scheduled_for ?? now()->toDateString(),
            'notes' => $notes ?: $p->notes,
        ]);

        return $p->fresh();
    }

    public static function markPaid(EcommerceAffiliatePayout $p, ?int $adminId, ?string $reference = null, ?string $notes = null): EcommerceAffiliatePayout
    {
        if (!in_array($p->status, ['pending', 'approved'], true)) {
            throw new \DomainException("This payout cannot be marked paid (current status: {$p->status}).");
        }
        $p->update([
            'status' => 'paid',
            'paid_at' => now(),
            'approved_at' => $p->approved_at ?? now(),
            'processed_by' => $adminId ?? $p->processed_by,
            'reference_id' => $reference ?: $p->reference_id,
            'notes' => $notes ?: $p->notes,
        ]);

        return $p->fresh();
    }

    public static function reject(EcommerceAffiliatePayout $p, ?int $adminId, ?string $reason = null): EcommerceAffiliatePayout
    {
        if (!in_array($p->status, ['pending', 'approved'], true)) {
            throw new \DomainException("This payout cannot be rejected (current status: {$p->status}).");
        }

        // Rejecting releases the amount back into the affiliate earnings balance (no wallet movement).
        return DB::transaction(function () use ($p, $adminId, $reason) {
            $p->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'reject_reason' => $reason,
                'processed_by' => $adminId,
            ]);

            return $p->fresh();
        });
    }

    public static function processScheduledPayouts(): int
    {
        $s = AffiliateCommissionService::settings();
        $mode = $s['payout_processing_mode'] ?? 'Manual Approval';
        $autoAllowed = stripos((string) $mode, 'auto') !== false;

        // In Manual Approval mode, scheduled payouts stay approved until an admin manually pays and marks them paid
        if (!$autoAllowed) {
            return 0;
        }

        $today = now()->toDateString();
        $due = EcommerceAffiliatePayout::where('status', 'approved')
            ->where('auto_process', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('scheduled_for')
                  ->orWhere('scheduled_for', '<=', $today);
            })
            ->get();

        $processed = 0;
        foreach ($due as $payout) {
            try {
                self::markPaid($payout, null, 'AUTO-' . now()->format('Ymd'));
                $processed++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Auto-process payout {$payout->id} failed: " . $e->getMessage());
            }
        }

        return $processed;
    }
}
