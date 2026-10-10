<?php

namespace App\Services;

use App\Models\EcommerceAffiliate;
use App\Models\EcommerceAffiliatePayout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Affiliate withdrawals: validation against dynamic settings, wallet hold,
 * approval / rejection (with refund) and scheduled processing.
 */
class AffiliatePayoutService
{
    /** Withdrawal methods that move money out of the wallet (settings key => stored method). */
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

    /** Earnings not yet committed to a payout. */
    public static function availableBalance(EcommerceAffiliate $affiliate): float
    {
        $committed = (float) EcommerceAffiliatePayout::where('user_id', $affiliate->user_id)
            ->whereIn('status', ['pending', 'approved', 'paid'])->sum('amount');

        return max(0, round((float) $affiliate->total_earnings - $committed, 2));
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
        if (!in_array($method, self::enabledMethods($s), true)) {
            throw new \DomainException('This payout method is not available.');
        }
        if ($amount <= 0) {
            throw new \DomainException('Enter a valid amount.');
        }
        if (AffiliateCommissionService::flag($s, 'require_min_payout') && $amount < (float) $s['min_payout_amount']) {
            throw new \DomainException('The minimum payout amount is $' . number_format((float) $s['min_payout_amount'], 2) . '.');
        }
        if (!$isAdmin && AffiliateCommissionService::flag($s, 'require_tax_info') && empty($affiliate->tax_info)) {
            throw new \DomainException('Please submit your tax information before requesting a payout.');
        }
        if (!$isAdmin && AffiliateCommissionService::flag($s, 'require_payout_method') && empty($affiliate->payout_method)) {
            throw new \DomainException('Please save a payout method before requesting a payout.');
        }
        if ($amount > self::availableBalance($affiliate) + 0.001) {
            throw new \DomainException('Amount exceeds your available affiliate balance.');
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
            // Hold the funds: debit the wallet now; a rejection refunds it.
            $held = WalletService::adjustWallet(
                $user->id,
                $amount,
                'debit',
                'Affiliate payout request (' . str_replace('_', ' ', $method) . ')',
                null
            );
            if (!$held) {
                throw new \DomainException('Insufficient wallet balance for this payout.');
            }

            $payout = EcommerceAffiliatePayout::create([
                'payout_number' => 'PO-' . strtoupper(bin2hex(random_bytes(4))),
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

    public static function approve(EcommerceAffiliatePayout $p, ?int $adminId): EcommerceAffiliatePayout
    {
        if ($p->status !== 'pending') {
            throw new \DomainException("Only pending payouts can be approved (current status: {$p->status}).");
        }
        $p->update([
            'status' => 'approved',
            'approved_at' => now(),
            'processed_by' => $adminId,
            'scheduled_for' => $p->scheduled_for ?? now()->toDateString(),
        ]);

        return $p->fresh();
    }

    public static function markPaid(EcommerceAffiliatePayout $p, ?int $adminId, ?string $reference = null): EcommerceAffiliatePayout
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
        ]);

        return $p->fresh();
    }

    public static function reject(EcommerceAffiliatePayout $p, ?int $adminId, ?string $reason = null): EcommerceAffiliatePayout
    {
        if (!in_array($p->status, ['pending', 'approved'], true)) {
            throw new \DomainException("This payout cannot be rejected (current status: {$p->status}).");
        }

        return DB::transaction(function () use ($p, $adminId, $reason) {
            WalletService::adjustWallet(
                $p->user_id,
                (float) $p->amount,
                'credit',
                'Affiliate payout ' . $p->payout_number . ' rejected - funds returned',
                null
            );
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
        $today = now()->toDateString();
        $due = EcommerceAffiliatePayout::where('status', 'approved')
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
