<?php

namespace App\Services;

use App\Models\EcommerceOrder;
use App\Models\EcommerceVoucher;
use App\Models\EcommerceVoucherLog;
use App\Models\EcommerceVoucherSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class VoucherService
{
    /**
     * Validate a list of voucher codes for an order total and channel ('online' | 'pos').
     * Returns ['valid' => bool, 'message' => ?string, 'vouchers' => array, 'total_applied' => float].
     */
    public static function validateForOrder(array $codes, float $orderTotal, string $channel = 'online'): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes))));
        if (empty($codes)) {
            return ['valid' => true, 'message' => null, 'vouchers' => [], 'total_applied' => 0.0];
        }

        $settings = EcommerceVoucherSetting::current();

        if ($channel === 'pos' && empty($settings['channel_in_store_pos'])) {
            return ['valid' => false, 'message' => 'In-store POS voucher redemption is disabled.', 'vouchers' => [], 'total_applied' => 0.0];
        }
        if ($channel !== 'pos' && empty($settings['channel_online_checkout'])) {
            return ['valid' => false, 'message' => 'Online checkout voucher redemption is disabled.', 'vouchers' => [], 'total_applied' => 0.0];
        }

        $maxPerOrder = (int) ($settings['max_per_order'] ?? 0);
        if ($maxPerOrder > 0 && count($codes) > $maxPerOrder) {
            return [
                'valid' => false,
                'message' => "You can apply at most {$maxPerOrder} voucher(s) per order.",
                'vouchers' => [],
                'total_applied' => 0.0,
            ];
        }

        $tz = $settings['timezone'] ?? 'UTC';
        $today = Carbon::now($tz)->toDateString();
        $autoExpire = ! empty($settings['auto_expire']);
        $allowMultipleOrders = ! empty($settings['allow_multiple_orders']);
        $allowPartial = ! empty($settings['allow_partial_redemption']);
        $allowSplit = ! empty($settings['allow_split_payment']);

        $vouchers = [];
        $remaining = max(0.0, $orderTotal);
        $totalApplied = 0.0;

        foreach ($codes as $code) {
            $voucher = EcommerceVoucher::whereRaw('UPPER(code) = ?', [$code])->first();
            if (! $voucher) {
                return ['valid' => false, 'message' => "Invalid voucher code: {$code}.", 'vouchers' => [], 'total_applied' => 0.0];
            }

            if ($autoExpire && $voucher->status === EcommerceVoucher::STATUS_ACTIVE && $voucher->expires_at && $voucher->expires_at->format('Y-m-d') < $today) {
                $voucher->update(['status' => EcommerceVoucher::STATUS_EXPIRED]);
            }

            if ($voucher->status !== EcommerceVoucher::STATUS_ACTIVE) {
                return ['valid' => false, 'message' => "Voucher {$code} is {$voucher->status}.", 'vouchers' => [], 'total_applied' => 0.0];
            }

            if ((float) $voucher->balance <= 0) {
                return ['valid' => false, 'message' => "Voucher {$code} has no remaining balance.", 'vouchers' => [], 'total_applied' => 0.0];
            }

            if (! $allowMultipleOrders && ($voucher->last_used_at !== null || (float) $voucher->balance < (float) $voucher->amount)) {
                return ['valid' => false, 'message' => "Voucher {$code} has already been used and cannot be redeemed on multiple orders.", 'vouchers' => [], 'total_applied' => 0.0];
            }

            if ($voucher->usage_type === 'single' && ($voucher->last_used_at !== null || (float) $voucher->balance < (float) $voucher->amount)) {
                return ['valid' => false, 'message' => "Single-use voucher {$code} has already been redeemed.", 'vouchers' => [], 'total_applied' => 0.0];
            }

            $balance = (float) $voucher->balance;
            if (! $allowPartial && $remaining < $balance) {
                return [
                    'valid' => false,
                    'message' => "Partial voucher redemption is disabled; order total must be at least $" . number_format($balance, 2) . " to use {$code}.",
                    'vouchers' => [],
                    'total_applied' => 0.0,
                ];
            }

            $deduct = min($remaining, $balance);
            $remaining = round($remaining - $deduct, 2);
            $totalApplied = round($totalApplied + $deduct, 2);
            $vouchers[] = ['voucher' => $voucher, 'deduct' => $deduct];
        }

        if (! $allowSplit && $totalApplied < round($orderTotal, 2)) {
            return [
                'valid' => false,
                'message' => 'Split payment is disabled; voucher balance must cover the full order total.',
                'vouchers' => [],
                'total_applied' => 0.0,
            ];
        }

        return [
            'valid' => true,
            'message' => null,
            'vouchers' => $vouchers,
            'total_applied' => $totalApplied,
        ];
    }

    /**
     * Deduct validated vouchers for a placed order and record audit logs + metadata.
     */
    public static function redeemForOrder(EcommerceOrder $order, array $validatedVouchers, ?int $userId = null): float
    {
        if (empty($validatedVouchers)) {
            return 0.0;
        }

        $appliedMeta = [];
        $totalApplied = 0.0;

        foreach ($validatedVouchers as $entry) {
            /** @var EcommerceVoucher $voucher */
            $voucher = $entry['voucher'];
            $deduct = (float) $entry['deduct'];
            if ($deduct <= 0) {
                continue;
            }

            $oldBalance = (float) $voucher->balance;
            $newBalance = max(0.0, round($oldBalance - $deduct, 2));
            $newStatus = $newBalance <= 0 ? EcommerceVoucher::STATUS_REDEEMED : EcommerceVoucher::STATUS_ACTIVE;

            $voucher->update([
                'balance' => $newBalance,
                'status' => $newStatus,
                'last_used_at' => now(),
            ]);

            EcommerceVoucherLog::create([
                'voucher_id' => $voucher->id,
                'log_type' => 'activity',
                'action' => 'Redeemed',
                'details' => sprintf('$%s applied to Order #%s', number_format($deduct, 2), $order->order_number),
                'performed_by' => 'System',
                'performed_by_id' => $userId,
            ]);

            $appliedMeta[] = ['code' => $voucher->code, 'amount' => $deduct];
            $totalApplied = round($totalApplied + $deduct, 2);
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['vouchers_applied'] = $appliedMeta;
        $meta['voucher_deduction'] = $totalApplied;
        $order->update(['metadata' => $meta]);

        return $totalApplied;
    }

    /**
     * Restore redeemed voucher amounts when an order is cancelled/refunded/voided (if enabled in settings).
     */
    public static function restoreForOrder(EcommerceOrder $order, ?int $actorId = null, string $actorName = 'Admin'): float
    {
        $settings = EcommerceVoucherSetting::current();
        if (empty($settings['restore_on_cancel'])) {
            return 0.0;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $applied = $meta['vouchers_applied'] ?? [];
        if (empty($applied) || ! empty($meta['vouchers_restored'])) {
            return 0.0;
        }

        $restoredTotal = 0.0;
        $today = Carbon::now($settings['timezone'] ?? 'UTC')->toDateString();

        foreach ($applied as $item) {
            $code = $item['code'] ?? null;
            $amount = (float) ($item['amount'] ?? 0);
            if (! $code || $amount <= 0) {
                continue;
            }

            $voucher = EcommerceVoucher::where('code', $code)->first();
            if (! $voucher || $voucher->status === EcommerceVoucher::STATUS_VOIDED) {
                continue;
            }

            $newBalance = min((float) $voucher->amount, round((float) $voucher->balance + $amount, 2));
            $isPast = ! empty($settings['auto_expire']) && $voucher->expires_at && $voucher->expires_at->format('Y-m-d') < $today;
            $newStatus = $isPast ? EcommerceVoucher::STATUS_EXPIRED : EcommerceVoucher::STATUS_ACTIVE;

            $voucher->update([
                'balance' => $newBalance,
                'status' => $newStatus,
            ]);

            EcommerceVoucherLog::create([
                'voucher_id' => $voucher->id,
                'log_type' => 'activity',
                'action' => 'Balance Restored',
                'details' => sprintf('$%s restored from cancelled Order #%s', number_format($amount, 2), $order->order_number),
                'performed_by' => $actorName,
                'performed_by_id' => $actorId,
            ]);

            $restoredTotal = round($restoredTotal + $amount, 2);
        }

        $meta['vouchers_restored'] = true;
        $meta['voucher_restored_amount'] = $restoredTotal;
        $order->update(['metadata' => $meta]);

        return $restoredTotal;
    }

    /**
     * Send expiration reminder emails for vouchers expiring in `notify_lead_days`.
     */
    public static function sendExpiryReminders(): int
    {
        $settings = EcommerceVoucherSetting::current();
        if (empty($settings['notify_before_expiry'])) {
            return 0;
        }

        $leadDays = max(1, (int) ($settings['notify_lead_days'] ?? 7));
        $tz = $settings['timezone'] ?? 'UTC';
        $targetDate = Carbon::now($tz)->addDays($leadDays)->toDateString();

        $vouchers = EcommerceVoucher::query()
            ->where('status', EcommerceVoucher::STATUS_ACTIVE)
            ->whereNotNull('holder_email')
            ->whereDate('expires_at', $targetDate)
            ->get();

        $sent = 0;
        foreach ($vouchers as $voucher) {
            $alreadySent = EcommerceVoucherLog::query()
                ->where('voucher_id', $voucher->id)
                ->where('action', 'Expiry Reminder Sent')
                ->exists();

            if ($alreadySent) {
                continue;
            }

            try {
                Mail::raw(
                    sprintf(
                        "Hello %s,\n\nYour Mecarvi voucher %s (remaining balance: $%s) will expire on %s.\n\nThank you!",
                        $voucher->holder_name,
                        $voucher->code,
                        number_format((float) $voucher->balance, 2),
                        optional($voucher->expires_at)->format('M j, Y')
                    ),
                    function ($msg) use ($voucher) {
                        $msg->to($voucher->holder_email)->subject("Your Voucher {$voucher->code} Expires Soon");
                    }
                );
            } catch (\Throwable $e) {
                // Mail transport may not be configured in local dev; still record attempt or skip
            }

            EcommerceVoucherLog::create([
                'voucher_id' => $voucher->id,
                'log_type' => 'activity',
                'action' => 'Expiry Reminder Sent',
                'details' => "Expiration reminder ({$leadDays} day lead time) queued for {$voucher->holder_email}",
                'performed_by' => 'System',
            ]);
            $sent++;
        }

        return $sent;
    }
}
