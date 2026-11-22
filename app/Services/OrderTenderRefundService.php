<?php

namespace App\Services;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardTransaction;
use App\Models\EcommerceOrder;
use App\Models\EcommerceVoucher;
use App\Models\EcommerceVoucherLog;
use App\Models\EcommerceVoucherSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gift cards and vouchers are separate balances that never mix with the wallet. When an order is
 * cancelled or refunded, the part paid with a gift card goes back to that gift card and the part
 * paid with a voucher comes back as a NEW voucher linked to the one that was used. Only the rest (card / wallet / other) is refunded
 * through the refund method the admin picked.
 *
 * Amounts already returned are tracked per gift card (ledger rows with type "Refund") and per voucher
 * code (order metadata "voucher_refunds"), so repeated / partial refunds never return more than was used.
 */
class OrderTenderRefundService
{
    public const GIFT_CARD_REFUND_TYPE = 'Refund';

    /** Per gift card: amount used on this order minus amount already returned. [giftcard_id => remaining] */
    public static function giftCardRemaining(EcommerceOrder $order): array
    {
        $rows = EcommerceGiftCardTransaction::where('order_id', $order->id)
            ->whereIn('transaction_type', ['Redemption', self::GIFT_CARD_REFUND_TYPE])
            ->get(['giftcard_id', 'transaction_type', 'amount']);

        $remaining = [];
        foreach ($rows as $r) {
            $id = (int) $r->giftcard_id;
            // Redemption rows are negative (balance left the card); Refund rows are positive.
            $remaining[$id] = round(($remaining[$id] ?? 0) - (float) $r->amount, 2);
        }

        return array_filter($remaining, fn ($v) => $v > 0.001);
    }

    /** Per voucher code: amount used on this order minus amount already returned. [code => remaining] */
    public static function voucherRemaining(EcommerceOrder $order): array
    {
        return self::voucherRemainingFromMeta(is_array($order->metadata) ? $order->metadata : []);
    }

    private static function voucherRemainingFromMeta(array $meta): array
    {
        $returned = (array) ($meta['voucher_refunds'] ?? []);
        // Legacy all-at-once restore flag from before partial tracking existed.
        if (! empty($meta['vouchers_restored']) && empty($returned)) {
            return [];
        }

        $remaining = [];
        foreach ((array) ($meta['vouchers_applied'] ?? []) as $item) {
            $code = $item['code'] ?? null;
            if (! $code) {
                continue;
            }
            $remaining[$code] = round(($remaining[$code] ?? 0) + (float) ($item['amount'] ?? 0), 2);
        }
        foreach ($returned as $code => $amt) {
            if (isset($remaining[$code])) {
                $remaining[$code] = round($remaining[$code] - (float) $amt, 2);
            }
        }

        return array_filter($remaining, fn ($v) => $v > 0.001);
    }

    /**
     * Split a refund of $amount for this order into the gift card share, the voucher share and the
     * rest, in proportion to how the order was paid. A full refund returns every gift card / voucher
     * dollar. Each share is capped at what is still returnable.
     *
     * @return array{gift_card: float, voucher: float, other: float}
     */
    public static function split(EcommerceOrder $order, float $amount): array
    {
        $amount = max(0, round($amount, 2));
        $gift = array_sum(self::giftCardRemaining($order));
        $voucher = array_sum(self::voucherRemaining($order));
        $other = max(0, round((float) $order->total_amount, 2)); // total_amount is already net of gift cards / vouchers
        $gross = $gift + $voucher + $other;

        if ($amount <= 0 || $gross <= 0) {
            return ['gift_card' => 0.0, 'voucher' => 0.0, 'other' => $amount];
        }
        if ($amount >= $gross - 0.009) {
            return ['gift_card' => round($gift, 2), 'voucher' => round($voucher, 2), 'other' => round($amount - $gift - $voucher, 2)];
        }

        $giftShare = min($gift, round($amount * $gift / $gross, 2));
        $voucherShare = min($voucher, round($amount * $voucher / $gross, 2));

        return [
            'gift_card' => $giftShare,
            'voucher' => $voucherShare,
            'other' => max(0, round($amount - $giftShare - $voucherShare, 2)),
        ];
    }

    /** Put up to $amount (null = everything still returnable) back on the gift cards used for this order. */
    public static function restoreGiftCards(EcommerceOrder $order, ?float $amount, string $reason, ?int $actorId = null): float
    {
        try {
            return DB::transaction(function () use ($order, $amount, $reason, $actorId) {
                $left = $amount === null ? INF : max(0, round($amount, 2));
                $restored = 0.0;

                foreach (self::giftCardRemaining($order) as $cardId => $remaining) {
                    if ($left <= 0.001) {
                        break;
                    }
                    $card = EcommerceGiftCard::lockForUpdate()->find($cardId);
                    if (! $card) {
                        continue;
                    }
                    $back = round(min($remaining, $left), 2);
                    $old = (float) $card->current_balance;
                    $new = round($old + $back, 2);
                    $status = strtolower((string) $card->status);
                    if (! in_array($status, ['disabled', 'expired', 'cancelled', 'voided'], true)) {
                        $status = $new >= (float) $card->initial_balance - 0.001 ? 'active' : 'partially used';
                    }

                    $card->update(['current_balance' => $new, 'status' => $status]);
                    $card->transactions()->create([
                        'transaction_type' => self::GIFT_CARD_REFUND_TYPE,
                        'amount' => $back,
                        'order_id' => $order->id,
                        'notes' => sprintf('$%s returned to gift card from order %s (%s).', number_format($back, 2), $order->order_number, $reason),
                        'created_by' => $actorId,
                    ]);
                    $card->activityLogs()->create([
                        'action' => 'Balance Restored',
                        'user_id' => $actorId,
                        'old_value' => (string) $old,
                        'new_value' => (string) $new,
                    ]);

                    $restored = round($restored + $back, 2);
                    $left = $left === INF ? INF : round($left - $back, 2);
                }

                return $restored;
            });
        } catch (\Throwable $e) {
            Log::warning("OrderTenderRefundService: gift card restore failed for order {$order->order_number}: " . $e->getMessage());

            return 0.0;
        }
    }

    /**
     * Voucher share of a cancel / refund: a NEW voucher is issued for the refunded amount, linked to the
     * original voucher (refunded_from_voucher_id), held by the same customer and ready to use again.
     * The original voucher is left as it was (its balance was spent on the order).
     *
     * Follows Voucher Settings -> "Automatically restore voucher amount": when that is off nothing is
     * issued automatically (an admin can issue one by hand). The voucher share never goes to the wallet.
     *
     * @return array{amount: float, vouchers: array<int, array{code: string, amount: float, original: string}>}
     */
    public static function issueRefundVouchers(EcommerceOrder $order, ?float $amount, string $reason, ?int $actorId = null, string $actorName = 'Admin'): array
    {
        $none = ['amount' => 0.0, 'vouchers' => []];
        $settings = EcommerceVoucherSetting::current();
        if (empty($settings['restore_on_cancel'])) {
            return $none;
        }

        try {
            return DB::transaction(function () use ($order, $amount, $reason, $actorId, $actorName, $settings) {
                $order = EcommerceOrder::lockForUpdate()->find($order->id);
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $returned = (array) ($meta['voucher_refunds'] ?? []);
                $issuedList = (array) ($meta['voucher_refund_vouchers'] ?? []);
                $left = $amount === null ? INF : max(0, round($amount, 2));
                $total = 0.0;
                $issued = [];
                $today = Carbon::now($settings['timezone'] ?? 'UTC')->startOfDay();

                foreach (self::voucherRemaining($order) as $code => $remaining) {
                    if ($left <= 0.001) {
                        break;
                    }
                    $original = EcommerceVoucher::where('code', $code)->lockForUpdate()->first();
                    if (! $original) {
                        continue;
                    }
                    $back = round(min($remaining, $left), 2);

                    // Keep the original expiry when it is still ahead; otherwise the refund voucher has none.
                    $expires = $original->expires_at && $original->expires_at->gte($today) ? $original->expires_at->format('Y-m-d') : null;

                    $new = EcommerceVoucher::create([
                        'refunded_from_voucher_id' => $original->id,
                        'user_id' => $original->user_id ?: $order->user_id,
                        'holder_type' => $original->holder_type ?: 'customer',
                        'holder_name' => $original->holder_name ?: ($order->customer_name ?: 'Customer'),
                        'holder_email' => $original->holder_email ?: $order->customer_email,
                        'holder_phone' => $original->holder_phone,
                        'contact_person' => $original->contact_person,
                        'amount' => $back,
                        'balance' => $back,
                        'status' => EcommerceVoucher::STATUS_ACTIVE,
                        'usage_type' => $original->usage_type ?: 'single',
                        'issued_at' => Carbon::today()->toDateString(),
                        'expires_at' => $expires,
                        'currency' => $original->currency ?: 'USD',
                        'order_number' => $order->order_number,
                        'issue_category' => 'Order Refund',
                        'issue_summary' => sprintf('Refund of $%s from Order #%s, originally paid with voucher %s (%s).', number_format($back, 2), $order->order_number, $original->code, $reason),
                        'compensation_reason' => 'Order Refund',
                        'delivery_methods' => json_encode(['account']),
                        'case_id' => EcommerceVoucher::nextCaseId(),
                        'issued_by_admin_id' => $actorId,
                        'issued_by_name' => $actorName,
                        'notes' => 'Refund voucher for ' . $original->code,
                    ]);

                    EcommerceVoucherLog::create([
                        'voucher_id' => $new->id,
                        'log_type' => 'activity',
                        'action' => 'Voucher Issued',
                        'details' => sprintf('Refund voucher for $%s from Order #%s (original voucher %s)', number_format($back, 2), $order->order_number, $original->code),
                        'performed_by' => $actorName,
                        'performed_by_id' => $actorId,
                    ]);
                    EcommerceVoucherLog::create([
                        'voucher_id' => $original->id,
                        'log_type' => 'activity',
                        'action' => 'Refund Voucher Issued',
                        'details' => sprintf('$%s from Order #%s refunded as new voucher %s (%s)', number_format($back, 2), $order->order_number, $new->code, $reason),
                        'performed_by' => $actorName,
                        'performed_by_id' => $actorId,
                    ]);

                    $returned[$code] = round((float) ($returned[$code] ?? 0) + $back, 2);
                    $row = ['code' => $new->code, 'amount' => $back, 'original' => $original->code];
                    $issued[] = $row;
                    $issuedList[] = $row + ['issued_at' => now()->toIso8601String()];
                    $total = round($total + $back, 2);
                    $left = $left === INF ? INF : round($left - $back, 2);
                }

                if ($total > 0) {
                    $meta['voucher_refunds'] = $returned;
                    $meta['voucher_refund_vouchers'] = $issuedList;
                    $meta['voucher_restored_amount'] = round((float) ($meta['voucher_restored_amount'] ?? 0) + $total, 2);
                    $meta['vouchers_restored'] = empty(self::voucherRemainingFromMeta($meta));
                    $order->update(['metadata' => $meta]);
                }

                return ['amount' => $total, 'vouchers' => $issued];
            });
        } catch (\Throwable $e) {
            Log::warning("OrderTenderRefundService: refund voucher failed for order {$order->order_number}: " . $e->getMessage());

            return $none;
        }
    }

    /** Amount-only wrapper around issueRefundVouchers(), for callers that only need the total. */
    public static function restoreVouchers(EcommerceOrder $order, ?float $amount, string $reason, ?int $actorId = null, string $actorName = 'Admin'): float
    {
        return self::issueRefundVouchers($order, $amount, $reason, $actorId, $actorName)['amount'];
    }

    /** Cancel / void: return every gift card and voucher dollar used on the order. */
    public static function restoreAll(EcommerceOrder $order, string $reason, ?int $actorId = null, string $actorName = 'Admin'): array
    {
        return [
            'gift_card' => self::restoreGiftCards($order, null, $reason, $actorId),
            'voucher' => self::restoreVouchers($order, null, $reason, $actorId, $actorName),
        ];
    }
}
