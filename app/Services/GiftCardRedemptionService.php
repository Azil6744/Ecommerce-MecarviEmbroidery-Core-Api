<?php

namespace App\Services;

use App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceOrder;

/**
 * Gift card redemption at checkout.
 *
 * plan()   decides how much each card pays, using the cards as read (no lock).
 * redeem() re-reads every card under a row lock inside the order transaction,
 *          re-checks it and deducts exactly the planned amount. If another
 *          checkout spent the balance in between, it throws and the whole
 *          order rolls back, so a card can never be spent twice.
 */
class GiftCardRedemptionService
{
    /** Statuses that can never be redeemed. */
    public const BLOCKED_STATUSES = [
        'disabled', 'deactivated', 'voided', 'cancelled', 'declined', 'expired',
        'fully used', 'fully_used', 'redeemed', 'scheduled', 'pending',
    ];

    public static function settings(): array
    {
        return (new EcommerceGiftCardController())->currentSettings();
    }

    /** Why this card cannot be redeemed right now, or null when it can. */
    public static function blockedReason(EcommerceGiftCard $card): ?string
    {
        $status = strtolower(trim((string) $card->status));
        if (in_array($status, self::BLOCKED_STATUSES, true)) {
            return match ($status) {
                'expired' => "Gift card {$card->code} has expired.",
                'scheduled', 'pending' => "Gift card {$card->code} is not active yet.",
                'fully used', 'fully_used', 'redeemed' => "Gift card {$card->code} has no remaining balance.",
                default => "Gift card {$card->code} is not active.",
            };
        }
        if ($card->expires_at && $card->expires_at->copy()->endOfDay()->isPast()) {
            return "Gift card {$card->code} has expired.";
        }
        if ((float) $card->current_balance <= 0) {
            return "Gift card {$card->code} has no remaining balance.";
        }

        return null;
    }

    /**
     * @param  EcommerceGiftCard[]  $cards
     * @return array{plan: array<int, float>, total: float}
     *
     * @throws \RuntimeException
     */
    public static function plan(array $cards, float $orderTotal, ?array $settings = null): array
    {
        $settings ??= self::settings();
        $cards = array_values($cards);

        $ids = array_map(fn ($c) => $c->id, $cards);
        if (count($ids) !== count(array_unique($ids))) {
            throw new \RuntimeException('The same gift card was entered more than once.');
        }
        if (count($cards) > 1 && empty($settings['allow_multiple_cards'])) {
            throw new \RuntimeException('Only one gift card can be used per order.');
        }

        $minBalance = (float) ($settings['min_redeem_balance'] ?? 0);
        $maxSingle = (float) ($settings['max_single_redemption'] ?? 0);
        $globalPartial = ! array_key_exists('allow_partial_redemption', $settings) || ! empty($settings['allow_partial_redemption']);

        $remaining = round(max(0.0, $orderTotal), 2);
        $plan = [];
        foreach ($cards as $card) {
            if ($reason = self::blockedReason($card)) {
                throw new \RuntimeException($reason);
            }

            $balance = round((float) $card->current_balance, 2);
            if ($minBalance > 0 && $balance < $minBalance) {
                throw new \RuntimeException(sprintf('Gift card %s needs a balance of at least $%.2f to be used.', $card->code, $minBalance));
            }
            if ($remaining <= 0) {
                break;
            }

            $amount = min($remaining, $balance);
            if ($maxSingle > 0) {
                $amount = min($amount, $maxSingle);
            }
            $amount = round($amount, 2);

            $partialAllowed = $globalPartial && ($card->allow_partial_redemption === null || (bool) $card->allow_partial_redemption);
            if (! $partialAllowed && $amount + 0.001 < $balance) {
                throw new \RuntimeException(sprintf(
                    'Gift card %s must be used in full ($%.2f). Add more to your order or remove the card.',
                    $card->code,
                    $balance
                ));
            }

            if ($amount > 0) {
                $plan[$card->id] = $amount;
                $remaining = round($remaining - $amount, 2);
            }
        }

        return ['plan' => $plan, 'total' => round(array_sum($plan), 2)];
    }

    /**
     * Deducts the planned amounts. Must run inside the checkout DB transaction.
     *
     * @param  array<int, float>  $plan  card id => amount
     * @return float total deducted
     *
     * @throws \RuntimeException when a card changed since the plan was made
     */
    public static function redeem(array $plan, EcommerceOrder $order, ?int $userId): float
    {
        $applied = 0.0;
        // Lock in id order so concurrent checkouts using the same cards cannot deadlock.
        ksort($plan);

        foreach ($plan as $cardId => $amount) {
            $card = EcommerceGiftCard::whereKey($cardId)->lockForUpdate()->first();
            if (! $card) {
                throw new \RuntimeException('A gift card on this order no longer exists.');
            }
            if ($reason = self::blockedReason($card)) {
                throw new \RuntimeException($reason);
            }

            $old = round((float) $card->current_balance, 2);
            if ($old + 0.001 < $amount) {
                throw new \RuntimeException("The balance on gift card {$card->code} changed. Please review your order and try again.");
            }

            $new = round($old - $amount, 2);
            $card->update([
                'current_balance' => $new,
                'status' => $new <= 0 ? 'fully used' : 'partially used',
                'last_used_at' => now(),
                'redeemed_at' => $new <= 0 ? now() : $card->redeemed_at,
            ]);

            $card->transactions()->create([
                'transaction_type' => 'Redemption',
                'amount' => -$amount,
                'order_id' => $order->id,
                'notes' => sprintf('Redeemed $%.2f for order %s.', $amount, $order->order_number),
                'created_by' => $userId,
            ]);

            $card->activityLogs()->create([
                'action' => 'Redeemed',
                'user_id' => $userId,
                'old_value' => (string) $old,
                'new_value' => (string) $new,
            ]);

            $applied += $amount;
        }

        return round($applied, 2);
    }
}
