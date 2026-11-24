<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin panel Gift Cards pages: the gift card panel in admin mode. Every buyer of
 * the chosen audience (customer / business), with the admin actions enabled.
 */
class AdminGiftCardPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function headersFor(User $user): array
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $user->role, 'guard_name' => 'web']);
        $user->assignRole($user->role);
        $token = $user->createToken('t')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'X-Central-Auth-Token' => $token];
    }

    private function order(array $attrs): EcommerceGiftCardOrder
    {
        static $n = 0;
        $n++;

        return EcommerceGiftCardOrder::create(array_merge([
            'order_number' => 'GCO-A'.$n, 'buyer_name' => 'Buyer '.$n, 'buyer_email' => "buyer{$n}@example.com",
            'recipient_name' => 'R', 'recipient_email' => 'r@example.com', 'giftcard_amount' => 25, 'payment_status' => 'paid',
            'order_status' => 'Pending Gift Card Issue', 'payment_method' => 'stripe', 'transaction_id' => 'test_stripe_'.$n, 'paid_at' => now(),
        ], $attrs));
    }

    public function test_admin_sees_all_buyers_split_by_audience_and_can_act(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $business = User::factory()->create(['role' => 'customer']);
        $customerOrder = $this->order(['customer_id' => $customer->id, 'buyer_email' => $customer->email]);
        $businessOrder = $this->order(['customer_id' => $business->id, 'buyer_email' => $business->email, 'buyer_business_name' => 'Elite Sportswear LLC']);
        $admin = $this->headersFor(User::factory()->create(['role' => 'super_admin']));

        $customers = $this->withHeaders($admin)->getJson('/api/v1/admin/gift-card-panel?audience=customer')->assertOk()->json('data.rows');
        $this->assertSame(['o'.$customerOrder->id], array_column($customers, 'ref'));
        $this->assertTrue($customers[0]['can']['approve']);
        $this->assertTrue($customers[0]['can']['decline']);

        $businesses = $this->withHeaders($admin)->getJson('/api/v1/admin/gift-card-panel?audience=business')->assertOk()->json('data.rows');
        $this->assertSame(['o'.$businessOrder->id], array_column($businesses, 'ref'));

        // Approve issues the card as admin.
        $this->withHeaders($admin)->postJson("/api/v1/admin/gift-card-panel/o{$customerOrder->id}/approve?audience=customer", ['expiry_mode' => 'standard'])
            ->assertOk();
        $this->assertSame(1, EcommerceGiftCard::where('order_id', $customerOrder->id)->count());

        // Decline (test-mode payment: nothing real to send back) needs a reason and description.
        $this->withHeaders($admin)->postJson("/api/v1/admin/gift-card-panel/o{$businessOrder->id}/decline?audience=business", ['reason' => 'Fraud'])
            ->assertStatus(422);
        $this->withHeaders($admin)->postJson("/api/v1/admin/gift-card-panel/o{$businessOrder->id}/decline?audience=business", [
            'reason' => 'Fraud', 'description' => 'Card reported stolen.',
        ])->assertOk();
        $this->assertSame('refunded', $businessOrder->fresh()->payment_status);
    }

    public function test_transaction_history_splits_audience_and_totals(): void
    {
        $customerCard = EcommerceGiftCard::create([
            'code' => '990000000000001', 'recipient_name' => 'Daniel', 'recipient_email' => 'd@example.com', 'initial_balance' => 100,
            'current_balance' => 50, 'status' => 'partially used', 'currency' => 'USD', 'issue_type' => 'Manual', 'sender_type' => 'customer', 'sender_name' => 'Jessica',
        ]);
        $customerCard->transactions()->create(['transaction_type' => 'Issue', 'amount' => 100, 'notes' => 'issued']);
        $customerCard->transactions()->create(['transaction_type' => 'Redemption', 'amount' => -50, 'notes' => 'used']);
        $businessCard = EcommerceGiftCard::create([
            'code' => '990000000000002', 'recipient_name' => 'Aisha', 'recipient_email' => 'a@example.com', 'initial_balance' => 75,
            'current_balance' => 0, 'status' => 'voided', 'currency' => 'USD', 'issue_type' => 'Manual', 'sender_type' => 'business', 'sender_business_name' => 'Elite Sportswear LLC',
        ]);
        $businessCard->transactions()->create(['transaction_type' => 'Void', 'amount' => -75, 'notes' => 'fraud']);

        $h = $this->headersFor(User::factory()->create(['role' => 'super_admin']));
        $customer = $this->withHeaders($h)->getJson('/api/v1/admin/gift-card-transactions/history?audience=customer')->assertOk()->json('data');
        $this->assertSame(100.0, (float) $customer['stats']['purchased']);
        $this->assertSame(50.0, (float) $customer['stats']['redeemed']);
        $this->assertCount(2, $customer['rows']);
        $this->assertSame('Redeemed', $customer['rows'][0]['type']);
        $this->assertSame('Recipient', $customer['rows'][0]['party']['role']);

        // Totals cards are filters: only redemptions, totals unchanged.
        $redeemed = $this->withHeaders($h)->getJson('/api/v1/admin/gift-card-transactions/history?audience=customer&kind=redeemed')->assertOk()->json('data');
        $this->assertSame(['Redeemed'], array_column($redeemed['rows'], 'type'));
        $this->assertSame(100.0, (float) $redeemed['stats']['purchased']);

        $business = $this->withHeaders($h)->getJson('/api/v1/admin/gift-card-transactions/history?audience=business')->assertOk()->json('data');
        $this->assertSame(75.0, (float) $business['stats']['voided']);
        $this->assertSame('Elite Sportswear LLC', $business['rows'][0]['party']['business_name']);

        $this->withHeaders($h)->postJson("/api/v1/admin/gift-cards/{$businessCard->id}/notes", ['note' => 'Checked.'])->assertCreated();
        $this->withHeaders($h)->getJson("/api/v1/admin/gift-cards/{$businessCard->id}/notes")->assertJsonPath('data.0.note', 'Checked.');
    }

    public function test_internal_notes_are_admin_only_in_the_panel_api(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order(['customer_id' => $customer->id, 'buyer_email' => $customer->email]);
        $admin = $this->headersFor(User::factory()->create(['role' => 'super_admin']));
        $mine = $this->headersFor($customer);

        $this->withHeaders($admin)->postJson("/api/v1/admin/gift-card-panel/o{$order->id}/notes", ['note' => 'Billing name mismatch, watch this one.'])
            ->assertOk()
            ->assertJsonPath('data.notes.0.note', 'Billing name mismatch, watch this one.');

        // The buyer's own panel API never returns notes and cannot add them.
        $this->withHeaders($mine)->getJson("/api/ecommerce/gift-card-panel/o{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.notes', [])
            ->assertJsonPath('data.internal_note', null);
        $this->withHeaders($mine)->postJson("/api/ecommerce/gift-card-panel/o{$order->id}/notes", ['note' => 'hi'])->assertStatus(422);
    }

    public function test_admin_gift_cards_filter_by_purchase_date(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $old = $this->order(['customer_id' => $customer->id, 'buyer_email' => $customer->email, 'paid_at' => now()->subMonths(2)]);
        $new = $this->order(['customer_id' => $customer->id, 'buyer_email' => $customer->email, 'paid_at' => now()]);
        $admin = $this->headersFor(User::factory()->create(['role' => 'super_admin']));

        $from = now()->subDays(7)->toDateString();
        $rows = $this->withHeaders($admin)->getJson("/api/v1/admin/gift-card-panel?audience=customer&from={$from}")->assertOk()->json('data.rows');
        $this->assertSame(['o'.$new->id], array_column($rows, 'ref'));

        $to = now()->subMonth()->toDateString();
        $rows = $this->withHeaders($admin)->getJson("/api/v1/admin/gift-card-panel?audience=customer&to={$to}")->assertOk()->json('data.rows');
        $this->assertSame(['o'.$old->id], array_column($rows, 'ref'));
    }

    public function test_customers_cannot_use_admin_panel_endpoints(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order(['customer_id' => $customer->id, 'buyer_email' => $customer->email]);

        $h = $this->headersFor($customer);
        $this->withHeaders($h)->getJson('/api/v1/admin/gift-card-panel?audience=customer')->assertForbidden();
        $this->withHeaders($h)->postJson("/api/v1/admin/gift-card-panel/o{$order->id}/approve")->assertForbidden();
    }
}
