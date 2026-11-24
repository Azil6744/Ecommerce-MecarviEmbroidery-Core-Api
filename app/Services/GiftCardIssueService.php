<?php

namespace App\Services;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardOrder;
use App\Models\User;
use App\Support\GiftCardMailer;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid gift card order into a live gift card. Shared by the admin
 * "issue" action and the panel "Approve Gift Card" action.
 */
class GiftCardIssueService
{
    /**
     * @throws \RuntimeException when the order cannot be issued
     */
    public static function issue(
        EcommerceGiftCardOrder $order,
        ?User $actor,
        ?string $ip = null,
        bool $isAdmin = true,
        ?string $presetCode = null,
        ?\DateTimeInterface $expiresAt = null,
        array $meta = []
    ): EcommerceGiftCard
    {
        if ($order->payment_status !== 'paid') {
            throw new \RuntimeException('Order must be paid before issuing the gift card.');
        }

        if (in_array($order->order_status, ['Gift Card Issued', 'Gift Card Delivered'], true) || $order->giftCards()->exists()) {
            throw new \RuntimeException('Gift card has already been issued for this order.');
        }

        if (in_array($order->order_status, ['Cancelled', 'Refunded', 'Declined'], true)) {
            throw new \RuntimeException('This gift card order was cancelled and cannot be issued.');
        }

        return DB::transaction(function () use ($order, $actor, $ip, $isAdmin, $presetCode, $expiresAt, $meta) {
            // Re-read the order under a row lock so two approvals can never issue twice.
            $order = EcommerceGiftCardOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->giftCards()->exists() || in_array($order->order_status, ['Gift Card Issued', 'Gift Card Delivered', 'Cancelled', 'Refunded', 'Declined'], true)) {
                throw new \RuntimeException('This gift card order has already been processed.');
            }

            if ($presetCode !== null && EcommerceGiftCard::where('code', $presetCode)->exists()) {
                throw new \RuntimeException('That gift card code is already in use. Generate a new code.');
            }

            $code = $presetCode;
            while ($code === null) {
                $candidate = '';
                for ($i = 0; $i < 15; $i++) {
                    $candidate .= random_int(0, 9);
                }
                $code = EcommerceGiftCard::where('code', $candidate)->exists() ? null : $candidate;
            }

            $recipientUser = $order->recipient_email ? User::whereRaw('LOWER(email) = ?', [strtolower($order->recipient_email)])->first() : null;
            $expiresAt = $expiresAt ? \Illuminate\Support\Carbon::instance($expiresAt)->endOfDay() : now()->addYear();
            $isPhysical = strtolower((string) $order->delivery_method) === 'physical';

            $giftCard = EcommerceGiftCard::create([
                'user_id' => $recipientUser?->id,
                'order_id' => $order->id,
                'code' => $code,
                'recipient_name' => $order->recipient_name,
                'recipient_email' => $order->recipient_email,
                'recipient_phone' => $order->recipient_phone,
                'sender_name' => $order->buyer_name,
                'initial_balance' => $order->giftcard_amount,
                'current_balance' => $order->giftcard_amount,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'delivery_type' => $isPhysical ? 'Physical' : 'Email',
                'message' => $order->personal_message,
                'purchased_at' => $order->paid_at ?? $order->created_at ?? now(),
                'buyer_user_id' => $order->customer_id,
                'buyer_name' => $order->buyer_name,
                'buyer_email' => $order->buyer_email,
                'owner_email' => $order->recipient_email,
                'issue_type' => 'Purchased',
                'issued_by_admin_id' => $isAdmin ? $actor?->id : null,
                'flagged_reason' => $order->flagged_reason,
            ]);

            $giftCard->transactions()->create([
                'transaction_type' => 'Issue',
                'amount' => $order->giftcard_amount,
                'notes' => 'Gift card issued for order ' . $order->order_number,
                'created_by' => $actor?->id,
            ]);

            $giftCard->activityLogs()->create([
                'action' => $isAdmin ? 'Issued' : 'Approved',
                'admin_id' => $isAdmin ? $actor?->id : null,
                'user_id' => $isAdmin ? $order->customer_id : $actor?->id,
                'old_value' => null,
                'new_value' => json_encode($giftCard->only(['id', 'code', 'initial_balance', 'recipient_email'])),
                'ip_address' => $ip,
                'meta' => $meta ?: null,
            ]);

            $order->update([
                'order_status' => 'Gift Card Issued',
                'approved_at' => now(),
                'processed_by' => $actor?->id,
                'processed_by_name' => $actor?->name,
            ]);

            if ($isPhysical) {
                // Physical cards are shipped; nothing to email the recipient yet.
                $giftCard->update(['status' => 'active']);
            } else {
                $emailSent = GiftCardMailer::sendIssued($order->recipient_email, [
                    'code' => $code,
                    'balance' => $order->giftcard_amount,
                    'message' => $order->personal_message,
                    'recipient_name' => $order->recipient_name,
                    'sender_name' => $order->buyer_name,
                    'expires_at' => $expiresAt->toDateString(),
                ]);

                if ($emailSent) {
                    $order->update(['order_status' => 'Gift Card Delivered']);
                    $giftCard->update(['status' => 'delivered']);
                } else {
                    $order->update(['order_status' => 'Delivery Failed']);
                    $giftCard->update(['status' => 'Issued — Delivery Failed']);
                }
            }

            GiftCardMailer::sendPurchasedToBuyer($order->buyer_email, [
                'code' => $code,
                'balance' => $order->giftcard_amount,
                'message' => $order->personal_message,
                'recipient_name' => $order->recipient_name,
                'new_owner_email' => $order->recipient_email,
                'sender_name' => $order->buyer_name,
                'expires_at' => $expiresAt->toDateString(),
            ]);

            if ($order->customer_id && $order->order_status !== 'Delivery Failed') {
                // Ledger rows are permanent: release the pending lot instead of deleting it.
                LoyaltyService::releaseReferencePoints('gift_card_order', (string) $order->id);
            }

            return $giftCard->fresh();
        });
    }
}
