<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceGiftCardOrder;
use App\Models\EcommerceGiftCardTransaction;
use App\Models\EcommerceGiftCardTransfer;
use App\Models\EcommerceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GiftCardSystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function centralAuthHeadersFor(User $user): array
    {
        $roleName = $user->role ?: 'customer';
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->assignRole($roleName);

        $token = $user->createToken('feature-test')->plainTextToken;

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Central-Auth-Token' => $token,
        ];
    }

    public function test_gift_card_order_flow(): void
    {
        Mail::fake();

        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'super_admin']);

        // 1. Place order
        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($customer))
            ->postJson('/api/ecommerce/gift-card-orders', [
                'recipient_name' => 'John Doe',
                'recipient_email' => 'john@example.com',
                'personal_message' => 'Happy Birthday!',
                'giftcard_amount' => 100.00,
            ]);

        $response->assertCreated();
        $this->assertSame('Payment Pending', $response->json('data.order_status'));
        $this->assertSame('pending', $response->json('data.payment_status'));

        $orderId = $response->json('data.id');

        // 2. Pay for order
        $payResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($customer))
            ->postJson('/api/ecommerce/payment/gift-card-order', [
                'gift_card_order_id' => $orderId,
                'payment_method' => 'stripe',
                'payment_token' => 'tok_visa',
            ]);

        $payResponse->assertOk();
        $this->assertSame('Pending Gift Card Issue', $payResponse->json('data.order_status'));
        $this->assertSame('paid', $payResponse->json('data.payment_status'));

        // 3. Admin list orders
        $listResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->getJson('/api/v1/admin/gift-card-orders?order_status=Pending+Gift+Card+Issue');

        $listResponse->assertOk();
        $this->assertCount(1, $listResponse->json('data.data'));

        // 4. Admin issue gift card
        $issueResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson("/api/v1/admin/gift-card-orders/{$orderId}/issue");

        $issueResponse->assertOk();
        $this->assertSame('Gift Card Delivered', $issueResponse->json('data.order.order_status'));

        $giftCard = EcommerceGiftCard::where('order_id', $orderId)->first();
        $this->assertNotNull($giftCard);
        $this->assertSame('delivered', $giftCard->status);
        $this->assertSame(100.00, (float) $giftCard->current_balance);
        $this->assertMatchesRegularExpression('/^\d{15}$/', $giftCard->code);

        // Verify ledger transaction
        $this->assertDatabaseHas('ecommerce_gift_card_transactions', [
            'giftcard_id' => $giftCard->id,
            'transaction_type' => 'Issue',
            'amount' => '100.00',
        ]);

        // Verify activity log
        $this->assertDatabaseHas('ecommerce_gift_card_activity_logs', [
            'giftcard_id' => $giftCard->id,
            'action' => 'Issued',
        ]);
    }

    public function test_admin_manual_generation_and_adjust_balance_and_disable_enable(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'super_admin']);

        // 1. Manual issue
        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson('/api/v1/admin/gift-cards', [
                'recipient_name' => 'Alice Smith',
                'recipient_email' => 'alice@example.com',
                'amount' => 150.00,
                'message' => 'Special promotion',
            ]);

        $response->assertCreated();
        $giftCardId = $response->json('data.id');
        $code = $response->json('data.code');

        $this->assertMatchesRegularExpression('/^\d{15}$/', $code);
        $this->assertSame(150.00, (float) $response->json('data.current_balance'));

        // Verify ledger Issue
        $this->assertDatabaseHas('ecommerce_gift_card_transactions', [
            'giftcard_id' => $giftCardId,
            'transaction_type' => 'Issue',
            'amount' => '150.00',
        ]);

        // 2. Adjust Balance
        $adjustResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson("/api/v1/admin/gift-cards/{$giftCardId}/adjust-balance", [
                'amount' => -20.00,
                'notes' => 'Correcting promotional typo',
            ]);

        $adjustResponse->assertOk();
        $this->assertSame(130.00, (float) $adjustResponse->json('data.current_balance'));

        // Verify ledger Adjustment
        $this->assertDatabaseHas('ecommerce_gift_card_transactions', [
            'giftcard_id' => $giftCardId,
            'transaction_type' => 'Manual Adjustment',
            'amount' => '-20.00',
        ]);

        // 3. Disable Gift Card
        $disableResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson("/api/v1/admin/gift-cards/{$giftCardId}/disable", [
                'disabled_reason' => 'Suspected abuse',
            ]);

        $disableResponse->assertOk();
        $this->assertSame('disabled', $disableResponse->json('data.status'));

        // 4. Enable Gift Card
        $enableResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson("/api/v1/admin/gift-cards/{$giftCardId}/enable");

        $enableResponse->assertOk();
        $this->assertSame('active', $enableResponse->json('data.status'));
    }

    public function test_user_gift_card_transfer(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['role' => 'customer', 'email' => 'owner@example.com']);
        $recipient = User::factory()->create(['role' => 'customer', 'email' => 'recipient@example.com']);

        $giftCard = EcommerceGiftCard::create([
            'user_id' => $owner->id,
            'code' => '999999999999999',
            'recipient_name' => 'Owner',
            'recipient_email' => 'owner@example.com',
            'initial_balance' => 50.00,
            'current_balance' => 50.00,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($owner))
            ->postJson("/api/ecommerce/gift-cards/{$giftCard->id}/transfer", [
                'recipient_email' => 'recipient@example.com',
            ]);

        $response->assertOk();
        $this->assertSame($recipient->id, $response->json('data.user_id'));
        $this->assertSame('recipient@example.com', $response->json('data.recipient_email'));

        // Verify transfer table
        $this->assertDatabaseHas('ecommerce_gift_card_transfers', [
            'giftcard_id' => $giftCard->id,
            'old_owner_id' => $owner->id,
            'new_owner_id' => $recipient->id,
            'old_owner_email' => 'owner@example.com',
            'new_owner_email' => 'recipient@example.com',
        ]);

        // Verify ledger
        $this->assertDatabaseHas('ecommerce_gift_card_transactions', [
            'giftcard_id' => $giftCard->id,
            'transaction_type' => 'Transfer',
            'amount' => '50.00',
        ]);
    }

    public function test_checkout_integration_redemption(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'email' => 'buyer@example.com']);

        $category = \App\Models\Category::create([
            'name' => 'Shirts',
            'slug' => 'shirts',
            'is_active' => true,
        ]);

        $product = \App\Models\Product::create([
            'category_id' => $category->id,
            'name' => 'Premium Shirt',
            'sku' => 'PREM-SHIRT',
            'price' => 35.00,
            'is_active' => true,
        ]);

        $giftCard1 = EcommerceGiftCard::create([
            'user_id' => $customer->id,
            'code' => '888888888888888',
            'recipient_name' => 'Buyer',
            'recipient_email' => 'buyer@example.com',
            'initial_balance' => 60.00,
            'current_balance' => 60.00,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $giftCard2 = EcommerceGiftCard::create([
            'user_id' => $customer->id,
            'code' => '777777777777777',
            'recipient_name' => 'Buyer',
            'recipient_email' => 'buyer@example.com',
            'initial_balance' => 40.00,
            'current_balance' => 40.00,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        // Validate code endpoint
        $validateResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($customer))
            ->postJson('/api/ecommerce/gift-cards/validate', [
                'code' => '888888888888888',
            ]);

        $validateResponse->assertOk();
        $this->assertSame(60.00, (float) $validateResponse->json('data.current_balance'));

        // Checkout with cards
        $checkoutResponse = $this
            ->withHeaders($this->centralAuthHeadersFor($customer))
            ->postJson('/api/ecommerce/checkout', [
                'payment_method' => 'gift_card',
                'shipping_amount' => 10.00,
                'tax_amount' => 5.00,
                'total_amount' => 85.00, // Subtotal 70 + shipping 10 + tax 5
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_name' => 'Premium Shirt',
                        'quantity' => 2,
                        'unit_price' => 35.00,
                        'total_price' => 70.00,
                    ]
                ],
                'gift_card_codes' => ['888888888888888', '777777777777777'],
            ]);

        $checkoutResponse->assertCreated();
        $this->assertSame(0.00, (float) $checkoutResponse->json('order.total_amount'));
        $this->assertSame('paid', $checkoutResponse->json('order.payment_status'));

        // Verify giftCard1: fully used (0 balance)
        $giftCard1->refresh();
        $this->assertSame(0.00, (float) $giftCard1->current_balance);
        $this->assertSame('fully used', $giftCard1->status);

        // Verify giftCard2: partially used (40 - 20 = 20 balance remaining)
        $giftCard2->refresh();
        $this->assertSame(20.00, (float) $giftCard2->current_balance);
        $this->assertSame('partially used', $giftCard2->status);

        // Verify ledger transactions
        $this->assertDatabaseHas('ecommerce_gift_card_transactions', [
            'giftcard_id' => $giftCard1->id,
            'transaction_type' => 'Redemption',
            'amount' => '-60.00',
        ]);

        $this->assertDatabaseHas('ecommerce_gift_card_transactions', [
            'giftcard_id' => $giftCard2->id,
            'transaction_type' => 'Redemption',
            'amount' => '-20.00',
        ]);
    }

    public function test_daily_expiration_command(): void
    {
        Mail::fake();

        $giftCard = EcommerceGiftCard::create([
            'code' => '666666666666666',
            'recipient_name' => 'Old Recipient',
            'recipient_email' => 'old@example.com',
            'initial_balance' => 50.00,
            'current_balance' => 50.00,
            'status' => 'delivered',
            'expires_at' => now()->subDays(2),
            'currency' => 'USD',
        ]);

        $this->artisan('ecommerce:check-expired-gift-cards')
            ->assertExitCode(0);

        $giftCard->refresh();
        $this->assertSame('expired', $giftCard->status);
        $this->assertSame(0.00, (float) $giftCard->current_balance);

        // Verify Expiration ledger entry
        $this->assertDatabaseHas('ecommerce_gift_card_transactions', [
            'giftcard_id' => $giftCard->id,
            'transaction_type' => 'Expiration',
            'amount' => '-50.00',
        ]);
    }
}
