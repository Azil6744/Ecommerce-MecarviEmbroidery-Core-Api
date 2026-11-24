<?php

namespace App\Services;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardNote;
use App\Models\EcommerceGiftCardOrder;
use App\Models\EcommerceGiftCardTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only gift card decisions: decline a purchase, void a card, or reverse
 * one ledger transaction. Every action requires a reason and a description,
 * takes an optional internal note, runs under row locks so it can only apply
 * once, and is written to the card's permanent activity log with the admin.
 */
class AdminGiftCardActionService
{
    public const DECLINABLE_STATUSES = ['Payment Pending', 'Pending Gift Card Issue', 'Pending Approval', 'Delivery Failed'];

    /**
     * Decline a purchased gift card order. No card is created and any payment is
     * refunded to where it came from (wallet, Stripe card or PayPal).
     *
     * @return array{order: EcommerceGiftCardOrder, refund: string}
     */
    public static function declineOrder(int $orderId, User $admin, array $input, ?string $ip): array
    {
        return DB::transaction(function () use ($orderId, $admin, $input, $ip) {
            $order = EcommerceGiftCardOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->declined_at || ! in_array($order->order_status, self::DECLINABLE_STATUSES, true)) {
                throw new \RuntimeException('Only pending gift card orders can be declined.');
            }
            if ($order->giftCards()->exists()) {
                throw new \RuntimeException('A gift card was already issued for this order. Void it instead.');
            }

            $order->update([
                'order_status' => 'Declined',
                'declined_at' => now(),
                'declined_reason' => $input['reason'],
                'declined_description' => $input['description'],
                'processed_by' => $admin->id,
                'processed_by_name' => $admin->name,
            ]);

            self::note($admin, $order->id, null, $input['internal_note'] ?? null);

            LoyaltyService::reversePendingReferencePoints('gift_card_order', (string) $order->id, 'Gift card order declined');

            // Last step: send the payment back the way it came. If the processor refuses,
            // this throws and everything above rolls back (the order stays pending).
            $result = GiftCardPaymentRefundService::refund($order, (float) $order->giftcard_amount, 'Order declined: '.$input['reason']);
            $refund = match ($result['status']) {
                'refunded' => $result['method'],
                'manual' => 'manual',
                default => 'none',
            };

            return ['order' => $order->fresh(), 'refund' => $refund];
        });
    }

    /**
     * Void: deactivate the card for good and cancel its remaining balance.
     */
    public static function void(int $cardId, User $admin, array $input, ?string $ip): EcommerceGiftCard
    {
        return DB::transaction(function () use ($cardId, $admin, $input, $ip) {
            $card = EcommerceGiftCard::whereKey($cardId)->lockForUpdate()->firstOrFail();
            $old = strtolower((string) $card->status);
            if (in_array($old, ['voided', 'cancelled'], true)) {
                throw new \RuntimeException('This gift card has already been voided.');
            }

            $remaining = round((float) $card->current_balance, 2);
            $card->update([
                'status' => 'voided',
                'current_balance' => 0,
                'voided_at' => now(),
                'disabled_reason' => $input['reason'],
            ]);

            $card->transactions()->create([
                'transaction_type' => 'Void',
                'amount' => -$remaining,
                'notes' => 'Voided by admin: '.$input['reason'],
                'created_by' => $admin->id,
            ]);

            self::log($card, $admin, 'Voided', $old, 'voided', $ip, $input, 'void');
            self::note($admin, null, $card->id, $input['internal_note'] ?? null);

            return $card->fresh();
        });
    }

    /**
     * Reverse one ledger transaction with the matching balance correction:
     *  - Redemption        → the redeemed amount goes back onto the card
     *  - Manual Adjustment → the adjustment is undone (balance can't go negative)
     *  - Issue             → an unused purchase/issue is cancelled; a paid purchase is refunded
     * The original row is stamped reversed, so the same transaction can never be reversed twice.
     */
    public static function reverse(int $cardId, int $transactionId, User $admin, array $input, ?string $ip): EcommerceGiftCard
    {
        return DB::transaction(function () use ($cardId, $transactionId, $admin, $input, $ip) {
            $card = EcommerceGiftCard::whereKey($cardId)->lockForUpdate()->firstOrFail();
            $tx = EcommerceGiftCardTransaction::whereKey($transactionId)->where('giftcard_id', $card->id)->lockForUpdate()->first();
            if (! $tx) {
                throw new \RuntimeException('Select a transaction on this gift card to reverse.');
            }
            if ($tx->reversed_at || $tx->reversal_of_id) {
                throw new \RuntimeException($tx->reversal_of_id ? 'A reversal cannot itself be reversed.' : 'This transaction has already been reversed.');
            }

            $oldStatus = strtolower((string) $card->status);
            if (in_array($oldStatus, ['voided', 'cancelled'], true)) {
                throw new \RuntimeException('Voided gift cards cannot be changed.');
            }

            $balance = round((float) $card->current_balance, 2);
            $type = (string) $tx->transaction_type;
            $txAmount = round((float) $tx->amount, 2);

            $refundOrder = null;
            if ($type === 'Redemption') {
                $delta = abs($txAmount);
                if ($card->expires_at && $card->expires_at->copy()->endOfDay()->isPast()) {
                    throw new \RuntimeException('This gift card has expired, so the redemption cannot be credited back.');
                }
                $newBalance = round($balance + $delta, 2);
                if ($newBalance - (float) $card->initial_balance > 0.001) {
                    throw new \RuntimeException('Reversing this redemption would put the balance above the original value.');
                }
                $newStatus = abs($newBalance - (float) $card->initial_balance) < 0.001 ? 'active' : 'partially used';
                if (in_array($oldStatus, ['disabled', 'deactivated'], true)) {
                    $newStatus = $oldStatus;
                }
                $ledgerAmount = $delta;
                $note = sprintf('Redemption of $%.2f reversed and credited back.', $delta);
            } elseif ($type === 'Manual Adjustment') {
                $newBalance = round($balance - $txAmount, 2);
                if ($newBalance < 0) {
                    throw new \RuntimeException('The balance has already been used, so this adjustment cannot be reversed.');
                }
                $newStatus = $newBalance <= 0 ? 'fully used' : $card->status;
                $ledgerAmount = -$txAmount;
                $note = sprintf('Manual adjustment of $%.2f reversed.', $txAmount);
            } elseif ($type === 'Issue') {
                if (abs($balance - (float) $card->initial_balance) > 0.001) {
                    throw new \RuntimeException('This gift card has been used, so its issue cannot be reversed. Use Void instead.');
                }
                $order = $card->order_id ? EcommerceGiftCardOrder::whereKey($card->order_id)->lockForUpdate()->first() : null;
                $refundOrder = null;
                if ($order && $card->issue_type === 'Purchased') {
                    if (in_array($order->payment_status, ['refunded', 'refund_pending'], true) || $order->refunded_at) {
                        throw new \RuntimeException('This purchase has already been refunded.');
                    }
                    $order->update(['order_status' => 'Refunded']);
                    $refundOrder = $order;
                }
                $newBalance = 0.0;
                $newStatus = 'voided';
                $ledgerAmount = -$balance;
                $note = 'Issue reversed; the unused card was cancelled'.($order ? ' and the purchase refunded.' : '.');
            } else {
                throw new \RuntimeException("{$type} transactions cannot be reversed.");
            }

            $card->update([
                'current_balance' => $newBalance,
                'status' => $newStatus,
                'voided_at' => $newStatus === 'voided' ? now() : $card->voided_at,
                'redeemed_at' => $newBalance > 0 ? null : $card->redeemed_at,
            ]);

            $tx->update(['reversed_at' => now(), 'reversed_by' => $admin->id]);
            $card->transactions()->create([
                'transaction_type' => 'Reversal',
                'amount' => $ledgerAmount,
                'order_id' => $tx->order_id,
                'notes' => $note.' Reason: '.$input['reason'],
                'created_by' => $admin->id,
                'reversal_of_id' => $tx->id,
            ]);

            self::log($card, $admin, 'Reversed', $oldStatus, $newStatus, $ip, $input + ['transaction_id' => $tx->id, 'transaction_type' => $type], 'reverse');
            self::note($admin, null, $card->id, $input['internal_note'] ?? null);

            // Last step: refund a reversed purchase. If the processor refuses, everything rolls back.
            if (! empty($refundOrder)) {
                GiftCardPaymentRefundService::refund($refundOrder, $balance, 'Gift card reversed: '.$input['reason']);
            }

            return $card->fresh();
        });
    }

    private static function log(EcommerceGiftCard $card, User $admin, string $action, ?string $old, ?string $new, ?string $ip, array $input, string $actionType): void
    {
        $card->activityLogs()->create([
            'action' => $action,
            'admin_id' => $admin->id,
            'old_value' => $old,
            'new_value' => $new,
            'ip_address' => $ip,
            'meta' => array_filter([
                'action_type' => $actionType,
                'reason' => $input['reason'] ?? null,
                'description' => $input['description'] ?? null,
                'transaction_id' => $input['transaction_id'] ?? null,
                'transaction_type' => $input['transaction_type'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);
    }

    private static function note(User $admin, ?int $orderId, ?int $cardId, ?string $note): void
    {
        $note = trim((string) $note);
        if ($note === '') {
            return;
        }

        EcommerceGiftCardNote::create([
            'gift_card_order_id' => $orderId,
            'giftcard_id' => $cardId,
            'user_id' => $admin->id,
            'author_name' => $admin->name,
            'note' => $note,
        ]);
    }
}
