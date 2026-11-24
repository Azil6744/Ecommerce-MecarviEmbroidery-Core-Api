<?php

namespace App\Services;

use App\Models\EcommerceGiftCardOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gives a gift card order's payment back to the buyer when an admin declines the
 * order or reverses the card. The money goes back the way it came:
 *
 *  - wallet   → credited to the buyer's Mecarvi Cash wallet
 *  - stripe   → Stripe refund against the saved payment (pi_… payment intent or ch_… charge)
 *  - paypal   → PayPal refund against the saved sale id
 *  - test     → local test-mode payments moved no money, so nothing to send back
 *
 * Each order is refunded with one idempotency key, so a retry can never refund twice.
 * If the processor refuses, this throws and the caller's transaction rolls back:
 * the order is never shown as refunded unless the money actually went back.
 */
class GiftCardPaymentRefundService
{
    /**
     * @return array{status: string, method: string, reference: ?string}
     *   status "refunded" (money sent back), "manual" (no processor id on file: flagged
     *   refund_pending for a manual refund) or "not_paid" (nothing was collected)
     *
     * @throws \RuntimeException when the refund could not be made
     */
    public static function refund(EcommerceGiftCardOrder $order, float $amount, string $reason): array
    {
        $amount = round($amount, 2);
        $method = strtolower((string) $order->payment_method);
        $reference = trim((string) $order->transaction_id);

        if ($order->payment_status !== 'paid' || $amount <= 0) {
            return ['status' => 'not_paid', 'method' => $method, 'reference' => null];
        }

        if ($method === 'wallet') {
            if (! $order->customer_id) {
                throw new \RuntimeException('This wallet payment has no customer account to refund to.');
            }
            $ok = WalletService::adjustWallet((int) $order->customer_id, $amount, 'credit', "Refund for Gift Card Order #{$order->order_number}: {$reason}", (string) $order->id);
            if (! $ok) {
                throw new \RuntimeException('Could not refund the wallet payment. Nothing was changed; please try again.');
            }

            return self::done($order, $amount, 'wallet', 'WALLET-REFUND-GCO-' . $order->id);
        }

        if ($reference === '') {
            return self::manual($order);
        }

        // Local / testing payments never moved money.
        if (str_starts_with($reference, 'test_') || str_starts_with($reference, 'PAYPAL-TEST-')) {
            return self::done($order, $amount, $method ?: 'test', 'test_refund_' . $order->id);
        }

        if ($method === 'paypal') {
            $ok = app(PayPalMembershipPaymentService::class)->refund($reference, $amount);
            if (! $ok) {
                throw new \RuntimeException('PayPal did not accept the refund. Nothing was changed; please try again or refund it in PayPal.');
            }

            return self::done($order, $amount, 'paypal', 'paypal_refund_' . $reference);
        }

        if (str_starts_with($reference, 'pi_') || str_starts_with($reference, 'ch_')) {
            return self::done($order, $amount, 'stripe', self::stripeRefund($order, $reference, $amount));
        }

        // Older orders (paid before payment ids were saved) carry no processor id.
        return self::manual($order);
    }

    /**
     * No processor payment id on file (orders paid before ids were stored): the money cannot
     * be sent back automatically, so the order is flagged for a manual refund instead.
     */
    private static function manual(EcommerceGiftCardOrder $order): array
    {
        $order->update(['payment_status' => 'refund_pending']);

        return ['status' => 'manual', 'method' => strtolower((string) $order->payment_method), 'reference' => null];
    }

    private static function stripeRefund(EcommerceGiftCardOrder $order, string $reference, float $amount): string
    {
        $secret = StripeKeys::secret();
        if ($secret === '') {
            throw new \RuntimeException('Stripe is not configured, so the card payment cannot be refunded. Nothing was changed.');
        }

        $payload = [
            str_starts_with($reference, 'pi_') ? 'payment_intent' : 'charge' => $reference,
            'amount' => (int) round($amount * 100),
            'reason' => 'requested_by_customer',
            'metadata[purpose]' => 'gift_card_order_refund',
            'metadata[gift_card_order_id]' => (string) $order->id,
        ];

        $response = Http::asForm()
            ->withToken($secret)
            ->withHeaders(['Idempotency-Key' => 'gift_card_order_refund_' . $order->id])
            ->timeout(20)
            ->post('https://api.stripe.com/v1/refunds', $payload);

        $status = $response->json('status');
        if (! $response->successful() || ! in_array($status, ['succeeded', 'pending'], true)) {
            $message = $response->json('error.message') ?: 'Stripe did not accept the refund.';
            Log::warning('Gift card order refund failed', ['order' => $order->id, 'reference' => $reference, 'error' => $message]);
            throw new \RuntimeException("Stripe refund failed: {$message} Nothing was changed.");
        }

        return (string) $response->json('id');
    }

    private static function done(EcommerceGiftCardOrder $order, float $amount, string $method, string $refundReference): array
    {
        $order->update([
            'payment_status' => 'refunded',
            'refund_reference' => $refundReference,
            'refund_amount' => $amount,
            'refunded_at' => now(),
        ]);

        return ['status' => 'refunded', 'method' => $method, 'reference' => $refundReference];
    }
}
