<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardOrder;
use App\Models\EcommerceOrder;
use App\Models\User;
use App\Services\GiftCardRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Checklist coverage: admin decline / void / reverse (reason + description,
 * once only, admin only) and checkout redemption rules (locking, status,
 * partial redemption, settings limits).
 */
class AdminGiftCardActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function headersFor(string $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        $token = $user->createToken('t')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'X-Central-Auth-Token' => $token];
    }

    private function card(array $attrs = []): EcommerceGiftCard
    {
        static $n = 700000000000000;
        $n++;
        $card = EcommerceGiftCard::create(array_merge([
            'code' => (string) $n,
            'recipient_name' => 'Emma',
            'recipient_email' => 'emma@example.com',
            'initial_balance' => 100,
            'current_balance' => 100,
            'status' => 'active',
            'currency' => 'USD',
            'issue_type' => 'Manual',
        ], $attrs));
        $card->transactions()->create(['transaction_type' => 'Issue', 'amount' => (float) $card->initial_balance, 'notes' => 'issued']);

        return $card;
    }

    private function shopOrder(): EcommerceOrder
    {
        static $n = 0;
        $n++;

        return EcommerceOrder::create([
            'order_number' => 'ORD-GC-'.$n, 'subtotal' => 100, 'total_amount' => 100, 'payment_status' => 'pending',
            'status' => 'pending', 'customer_name' => 'x', 'customer_email' => 'x@example.com', 'customer_phone' => '1', 'order_date' => now(),
        ]);
    }

    private function action(array $extra = []): array
    {
        return array_merge(['reason' => 'Fraud suspected', 'description' => 'Card reported stolen by buyer.', 'internal_note' => 'Checked with support.'], $extra);
    }

    // ---------- Decline ----------

    public function test_admin_declines_paid_order_with_reason_and_description(): void
    {
        $order = EcommerceGiftCardOrder::create([
            'order_number' => 'GCO-1', 'buyer_name' => 'B', 'buyer_email' => 'b@example.com', 'recipient_name' => 'R',
            'recipient_email' => 'r@example.com', 'giftcard_amount' => 50, 'payment_status' => 'paid',
            'order_status' => 'Pending Gift Card Issue', 'payment_method' => 'card',
        ]);
        $h = $this->headersFor('super_admin');

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-card-orders/{$order->id}/decline", ['reason' => 'Fraud'])
            ->assertStatus(422)->assertJsonValidationErrors(['description']);

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-card-orders/{$order->id}/decline", $this->action())->assertOk();

        $order->refresh();
        $this->assertSame('Declined', $order->order_status);
        $this->assertSame('refund_pending', $order->payment_status);
        $this->assertSame('Card reported stolen by buyer.', $order->declined_description);
        $this->assertSame(0, EcommerceGiftCard::where('order_id', $order->id)->count());

        // Declined orders can't be declined again or issued.
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-card-orders/{$order->id}/decline", $this->action())->assertStatus(422);
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-card-orders/{$order->id}/issue")->assertStatus(400);
    }

    public function test_customer_cannot_use_admin_actions(): void
    {
        $card = $this->card();
        $this->withHeaders($this->headersFor('customer'))
            ->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'void']))
            ->assertForbidden();
        $this->assertSame('active', $card->fresh()->status);
    }

    public function test_panel_buyer_cannot_decline_void_or_reverse_their_own_purchase(): void
    {
        $buyer = User::factory()->create(['role' => 'customer', 'email' => 'buyer@example.com']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $buyer->assignRole('customer');
        $token = $buyer->createToken('t')->plainTextToken;
        $h = ['Authorization' => 'Bearer '.$token, 'X-Central-Auth-Token' => $token];

        $pending = EcommerceGiftCardOrder::create([
            'order_number' => 'GCO-P1', 'customer_id' => $buyer->id, 'buyer_name' => 'Buyer', 'buyer_email' => 'buyer@example.com',
            'recipient_name' => 'R', 'recipient_email' => 'r@example.com', 'giftcard_amount' => 40, 'payment_status' => 'paid',
            'order_status' => 'Pending Gift Card Issue', 'payment_method' => 'card',
        ]);
        $issuedOrder = EcommerceGiftCardOrder::create([
            'order_number' => 'GCO-P2', 'customer_id' => $buyer->id, 'buyer_name' => 'Buyer', 'buyer_email' => 'buyer@example.com',
            'recipient_name' => 'R', 'recipient_email' => 'r@example.com', 'giftcard_amount' => 40, 'payment_status' => 'paid',
            'order_status' => 'Gift Card Issued', 'payment_method' => 'card',
        ]);
        $card = $this->card(['order_id' => $issuedOrder->id, 'issue_type' => 'Purchased', 'initial_balance' => 40, 'current_balance' => 40, 'buyer_user_id' => $buyer->id, 'buyer_email' => 'buyer@example.com']);

        $this->withHeaders($h)->postJson("/api/ecommerce/gift-card-panel/o{$pending->id}/decline", $this->action())->assertStatus(422);
        $this->withHeaders($h)->postJson("/api/ecommerce/gift-card-panel/o{$issuedOrder->id}/void", $this->action(['action_type' => 'void']))->assertStatus(422);
        $this->withHeaders($h)->postJson("/api/ecommerce/gift-card-panel/o{$issuedOrder->id}/void", $this->action(['action_type' => 'reverse']))->assertStatus(422);

        $this->assertSame('Pending Gift Card Issue', $pending->fresh()->order_status);
        $this->assertSame('active', $card->fresh()->status);
        $this->assertSame('paid', $issuedOrder->fresh()->payment_status);
    }

    // ---------- Void ----------

    public function test_void_cancels_balance_once_and_card_cannot_be_reactivated_or_redeemed(): void
    {
        $card = $this->card(['current_balance' => 60]);
        $h = $this->headersFor('super_admin');

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", ['action_type' => 'void', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['description']);

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'void']))->assertOk();

        $card->refresh();
        $this->assertSame('voided', $card->status);
        $this->assertSame(0.0, (float) $card->current_balance);
        $this->assertDatabaseHas('ecommerce_gift_card_transactions', ['giftcard_id' => $card->id, 'transaction_type' => 'Void', 'amount' => '-60.00']);
        $this->assertDatabaseHas('ecommerce_gift_card_notes', ['giftcard_id' => $card->id, 'note' => 'Checked with support.']);
        $log = $card->activityLogs()->where('action', 'Voided')->first();
        $this->assertNotNull($log->admin_id);
        $this->assertSame('Card reported stolen by buyer.', $log->meta['description']);

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'void']))->assertStatus(422);
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/enable")->assertStatus(422);
        $this->assertNotNull(GiftCardRedemptionService::blockedReason($card));
    }

    // ---------- Reverse ----------

    public function test_reverse_redemption_credits_back_exactly_once(): void
    {
        $card = $this->card();
        $order = $this->shopOrder();
        GiftCardRedemptionService::redeem([$card->id => 40.0], $order, null);
        $tx = $card->transactions()->where('transaction_type', 'Redemption')->firstOrFail();
        $h = $this->headersFor('super_admin');

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'reverse']))
            ->assertStatus(422)->assertJsonValidationErrors(['transaction_id']);

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'reverse', 'transaction_id' => $tx->id]))
            ->assertOk()
            ->assertJsonPath('data.current_balance', 100);

        $this->assertSame('active', $card->fresh()->status);
        $this->assertNotNull($tx->fresh()->reversed_at);

        // Second reversal of the same transaction is refused: no duplicate credit.
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'reverse', 'transaction_id' => $tx->id]))
            ->assertStatus(422);
        $this->assertSame(100.0, (float) $card->fresh()->current_balance);

        // The reversal row itself cannot be reversed either.
        $reversal = $card->transactions()->where('transaction_type', 'Reversal')->firstOrFail();
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'reverse', 'transaction_id' => $reversal->id]))
            ->assertStatus(422);
    }

    public function test_reverse_issue_only_for_unused_card(): void
    {
        $h = $this->headersFor('super_admin');
        $used = $this->card(['current_balance' => 70]);
        $issue = $used->transactions()->where('transaction_type', 'Issue')->first();
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$used->id}/void-reverse", $this->action(['action_type' => 'reverse', 'transaction_id' => $issue->id]))
            ->assertStatus(422);

        $unused = $this->card();
        $issue = $unused->transactions()->where('transaction_type', 'Issue')->first();
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$unused->id}/void-reverse", $this->action(['action_type' => 'reverse', 'transaction_id' => $issue->id]))
            ->assertOk();
        $this->assertSame('voided', $unused->fresh()->status);
    }

    public function test_history_lists_ledger_audit_and_notes(): void
    {
        $card = $this->card();
        $h = $this->headersFor('super_admin');
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$card->id}/void-reverse", $this->action(['action_type' => 'void']))->assertOk();

        $this->withHeaders($h)->getJson("/api/v1/admin/gift-cards/{$card->id}/history")
            ->assertOk()
            ->assertJsonPath('data.transactions.0.transaction_type', 'Void')
            ->assertJsonPath('data.activity.0.action', 'Voided')
            ->assertJsonPath('data.activity.0.meta.reason', 'Fraud suspected')
            ->assertJsonPath('data.notes.0.note', 'Checked with support.');
    }

    // ---------- Redemption rules ----------

    public function test_redeem_refuses_when_balance_changed_after_planning(): void
    {
        $card = $this->card();
        $planned = GiftCardRedemptionService::plan([$card], 80.0);
        $this->assertSame([$card->id => 80.0], $planned['plan']);

        // Another checkout spends the card first.
        GiftCardRedemptionService::redeem([$card->id => 50.0], $this->shopOrder(), null);

        $this->expectException(\RuntimeException::class);
        GiftCardRedemptionService::redeem($planned['plan'], $this->shopOrder(), null);
    }

    public function test_balance_never_negative_and_status_updates(): void
    {
        $card = $this->card();
        $planned = GiftCardRedemptionService::plan([$card], 250.0);
        $this->assertSame(100.0, $planned['total']);
        GiftCardRedemptionService::redeem($planned['plan'], $this->shopOrder(), null);
        $card->refresh();
        $this->assertSame(0.0, (float) $card->current_balance);
        $this->assertSame('fully used', $card->status);
        $this->assertNotNull(GiftCardRedemptionService::blockedReason($card));
    }

    public function test_blocked_statuses_and_expiry(): void
    {
        foreach (['scheduled', 'pending', 'voided', 'disabled', 'expired', 'cancelled'] as $status) {
            $this->assertNotNull(GiftCardRedemptionService::blockedReason($this->card(['status' => $status])), $status);
        }
        $this->assertNotNull(GiftCardRedemptionService::blockedReason($this->card(['expires_at' => now()->subDay()])));
        $this->assertNull(GiftCardRedemptionService::blockedReason($this->card(['status' => 'partially used', 'current_balance' => 20])));
    }

    public function test_card_without_partial_redemption_must_be_used_in_full(): void
    {
        $card = $this->card(['allow_partial_redemption' => false]);
        try {
            GiftCardRedemptionService::plan([$card], 30.0);
            $this->fail('Expected a full-use error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must be used in full', $e->getMessage());
        }
        $this->assertSame(100.0, GiftCardRedemptionService::plan([$card], 120.0)['total']);
    }

    public function test_settings_limits_apply(): void
    {
        $settings = GiftCardRedemptionService::settings();
        $a = $this->card();
        $b = $this->card();

        $one = array_merge($settings, ['allow_multiple_cards' => false]);
        $this->expectExceptionMessage('Only one gift card');
        try {
            GiftCardRedemptionService::plan([$a, $b], 300.0, $one);
        } finally {
            $capped = GiftCardRedemptionService::plan([$a], 300.0, array_merge($settings, ['max_single_redemption' => 25]));
            $this->assertSame(25.0, $capped['total']);
            $low = $this->card(['current_balance' => 0.5, 'status' => 'partially used']);
            try {
                GiftCardRedemptionService::plan([$low], 10.0, array_merge($settings, ['min_redeem_balance' => 1]));
                $this->fail('Expected minimum-balance error');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('at least', $e->getMessage());
            }
        }
    }
}
