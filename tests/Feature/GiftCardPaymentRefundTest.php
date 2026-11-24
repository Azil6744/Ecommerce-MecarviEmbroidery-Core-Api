<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Gift card purchases charge a real card (Stripe) and save the payment id;
 * admin decline / reverse refunds it through Stripe automatically. Stripe's
 * API is faked: no network calls, no money moves.
 */
class GiftCardPaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function useStripeKeys(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_51GiftCardRefundTestKey000000',
            'services.stripe.key' => 'pk_test_51GiftCardRefundTestKey000000',
        ]);
    }

    private function headersFor(User $user): array
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $user->role, 'guard_name' => 'web']);
        $user->assignRole($user->role);
        $token = $user->createToken('t')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'X-Central-Auth-Token' => $token];
    }

    private function adminHeaders(): array
    {
        return $this->headersFor(User::factory()->create(['role' => 'super_admin']));
    }

    private function order(array $attrs = []): EcommerceGiftCardOrder
    {
        static $n = 0;
        $n++;

        return EcommerceGiftCardOrder::create(array_merge([
            'order_number' => 'GCO-R'.$n, 'buyer_name' => 'B', 'buyer_email' => 'b@example.com', 'recipient_name' => 'R',
            'recipient_email' => 'r@example.com', 'giftcard_amount' => 50, 'payment_status' => 'paid',
            'order_status' => 'Pending Gift Card Issue', 'payment_method' => 'stripe', 'transaction_id' => 'pi_3TestIntent123',
        ], $attrs));
    }

    private function decline(EcommerceGiftCardOrder $order, array $h)
    {
        return $this->withHeaders($h)->postJson("/api/v1/admin/gift-card-orders/{$order->id}/decline", [
            'reason' => 'Suspected fraud', 'description' => 'Billing name does not match.',
        ]);
    }

    // ---------- Paying ----------

    public function test_card_payment_charges_and_saves_payment_reference(): void
    {
        // No Stripe keys in testing = local test mode: only the 4242 card, no money moves.
        $buyer = User::factory()->create(['role' => 'customer']);
        $order = $this->order(['customer_id' => $buyer->id, 'payment_status' => 'pending', 'order_status' => 'Payment Pending', 'payment_method' => null, 'transaction_id' => null]);

        $this->withHeaders($this->headersFor($buyer))->postJson('/api/ecommerce/payment/gift-card-order', [
            'gift_card_order_id' => $order->id, 'payment_method' => 'stripe', 'stripe_payment_method' => 'not_a_real_card',
        ])->assertStatus(422);
        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->withHeaders($this->headersFor($buyer))->postJson('/api/ecommerce/payment/gift-card-order', [
            'gift_card_order_id' => $order->id, 'payment_method' => 'stripe', 'stripe_payment_method' => 'test_card_4242424242424242',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('stripe', $order->payment_method);
        $this->assertStringStartsWith('test_stripe_', $order->transaction_id);
        $this->assertSame('4242', $order->card_last4);
    }

    public function test_paying_someone_elses_order_is_refused(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $order = $this->order(['customer_id' => $owner->id, 'payment_status' => 'pending', 'order_status' => 'Payment Pending', 'transaction_id' => null]);

        $this->withHeaders($this->headersFor($other))->postJson('/api/ecommerce/payment/gift-card-order', [
            'gift_card_order_id' => $order->id, 'payment_method' => 'stripe', 'stripe_payment_method' => 'test_card_4242424242424242',
        ])->assertForbidden();
    }

    // ---------- Refunding ----------

    public function test_decline_refunds_card_payment_through_stripe(): void
    {
        $this->useStripeKeys();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_123', 'status' => 'succeeded'])]);
        $order = $this->order();

        $this->decline($order, $this->adminHeaders())->assertOk()->assertJsonPath('message', 'Gift card order declined and the card payment refunded through Stripe.');

        $order->refresh();
        $this->assertSame('Declined', $order->order_status);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame('re_123', $order->refund_reference);
        $this->assertSame('50.00', (string) $order->refund_amount);
        $this->assertNotNull($order->refunded_at);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/refunds'
            && $r['payment_intent'] === 'pi_3TestIntent123'
            && (int) $r['amount'] === 5000
            && $r->hasHeader('Idempotency-Key', 'gift_card_order_refund_'.$order->id));
    }

    public function test_charge_ids_are_refunded_by_charge(): void
    {
        $this->useStripeKeys();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_ch', 'status' => 'pending'])]);
        $order = $this->order(['transaction_id' => 'ch_3OldCharge']);

        $this->decline($order, $this->adminHeaders())->assertOk();
        Http::assertSent(fn (HttpRequest $r) => ($r['charge'] ?? null) === 'ch_3OldCharge');
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_failed_stripe_refund_changes_nothing(): void
    {
        $this->useStripeKeys();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['error' => ['message' => 'Charge has already been refunded.']], 400)]);
        $order = $this->order();

        $this->decline($order, $this->adminHeaders())->assertStatus(422)->assertJsonFragment(['success' => false]);

        $order->refresh();
        $this->assertSame('Pending Gift Card Issue', $order->order_status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNull($order->declined_at);
        $this->assertNull($order->refund_reference);
    }

    public function test_older_order_without_payment_id_is_flagged_for_manual_refund(): void
    {
        Http::fake();
        $order = $this->order(['transaction_id' => null]);

        $this->decline($order, $this->adminHeaders())->assertOk()->assertJsonFragment(['success' => true]);
        $this->assertSame('refund_pending', $order->fresh()->payment_status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.stripe.com'));
    }

    public function test_unpaid_order_declines_without_refund(): void
    {
        Http::fake();
        $order = $this->order(['payment_status' => 'pending', 'order_status' => 'Payment Pending', 'transaction_id' => null]);

        $this->decline($order, $this->adminHeaders())->assertOk()->assertJsonPath('message', 'Gift card order declined. No payment had been collected.');
        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.stripe.com'));
    }

    public function test_reversing_an_unused_purchased_card_refunds_it_once(): void
    {
        $this->useStripeKeys();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_rev', 'status' => 'succeeded'])]);
        $order = $this->order(['order_status' => 'Gift Card Issued']);
        $card = EcommerceGiftCard::create([
            'code' => '880000000000001', 'order_id' => $order->id, 'recipient_name' => 'R', 'recipient_email' => 'r@example.com',
            'initial_balance' => 50, 'current_balance' => 50, 'status' => 'active', 'currency' => 'USD', 'issue_type' => 'Purchased',
        ]);
        $issue = $card->transactions()->create(['transaction_type' => 'Issue', 'amount' => 50, 'notes' => 'issued']);
        $h = $this->adminHeaders();
        $body = ['action_type' => 'reverse', 'transaction_id' => $issue->id, 'reason' => 'Customer request', 'description' => 'Bought by mistake.'];

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $body)->assertOk();
        $this->assertSame('voided', $card->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('re_rev', $order->fresh()->refund_reference);

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $body)->assertStatus(422);
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.stripe.com/v1/refunds')));
    }

    public function test_failed_refund_on_reverse_keeps_the_card_active(): void
    {
        $this->useStripeKeys();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['error' => ['message' => 'No such payment_intent']], 404)]);
        $order = $this->order(['order_status' => 'Gift Card Issued']);
        $card = EcommerceGiftCard::create([
            'code' => '880000000000002', 'order_id' => $order->id, 'recipient_name' => 'R', 'recipient_email' => 'r@example.com',
            'initial_balance' => 50, 'current_balance' => 50, 'status' => 'active', 'currency' => 'USD', 'issue_type' => 'Purchased',
        ]);
        $issue = $card->transactions()->create(['transaction_type' => 'Issue', 'amount' => 50, 'notes' => 'issued']);

        $this->withHeaders($this->adminHeaders())->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", [
            'action_type' => 'reverse', 'transaction_id' => $issue->id, 'reason' => 'x', 'description' => 'y',
        ])->assertStatus(422);

        $this->assertSame('active', $card->fresh()->status);
        $this->assertSame(50.0, (float) $card->fresh()->current_balance);
        $this->assertNull($issue->fresh()->reversed_at);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }
}
