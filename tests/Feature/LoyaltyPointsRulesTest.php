<?php

namespace Tests\Feature;

use App\Models\EcommerceOrder;
use App\Models\EcommerceLoyaltyTransaction;
use App\Models\CustomerPointsBalance;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\LoyaltyService;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class LoyaltyPointsRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * Rule 1: Award Points After Order Completion
     * Prevents awarding points for unpaid or incomplete orders.
     */
    public function test_points_remain_pending_for_unpaid_or_incomplete_orders(): void
    {
        $user = User::factory()->create([
            'email' => 'loyalty_test_' . uniqid() . '@example.com',
            'loyalty_points' => 0,
        ]);

        $order = EcommerceOrder::create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'order_date' => now(),
            'order_number' => 'ORD-TEST-' . rand(100000, 999999),
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
            'discount_amount' => 0.00,
            'payment_method' => 'card',
        ]);

        // Award pending points for the order
        $pendingTx = LoyaltyService::awardPendingOrderPoints($order);
        $this->assertNotNull($pendingTx);
        $this->assertEquals('pending', $pendingTx->status);
        $this->assertGreaterThan(0, $pendingTx->points);

        $balance = LoyaltyService::getOrCreateBalance($user);
        $this->assertEquals(0, $balance->available_points);
        $this->assertEquals($pendingTx->points, $balance->pending_points);

        // Attempt to release while status is processing and unpaid -> must FAIL
        $released = LoyaltyService::releasePendingPoints($order);
        $this->assertFalse($released);

        // Attempt to release while status is completed but unpaid -> must FAIL
        $order->status = 'completed';
        $order->save();
        $released = LoyaltyService::releasePendingPoints($order);
        $this->assertFalse($released);

        // Balance should still be 0 available
        $balance->refresh();
        $this->assertEquals(0, $balance->available_points);

        // Now mark payment as paid while completed -> automatically releases pending points via model hook
        $order->payment_status = 'paid';
        $order->save();

        $balance->refresh();
        $this->assertEquals($pendingTx->points, $balance->available_points);
        $this->assertEquals(0, $balance->pending_points);
    }

    /**
     * Rule 2: Reverse Points on Refunds, voided or Cancellations
     * Automatically adjusts previously awarded points.
     */
    public function test_reverse_points_on_cancellation_and_void(): void
    {
        $user = User::factory()->create([
            'email' => 'loyalty_rev_' . uniqid() . '@example.com',
            'loyalty_points' => 0,
        ]);

        $order = EcommerceOrder::create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'order_date' => now(),
            'order_number' => 'ORD-TEST-' . rand(100000, 999999),
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
            'payment_method' => 'card',
        ]);

        // Award and release points
        LoyaltyService::awardPendingOrderPoints($order);
        LoyaltyService::releasePendingPoints($order);

        $balance = LoyaltyService::getOrCreateBalance($user);
        $earnedPoints = $balance->available_points;
        $this->assertGreaterThan(0, $earnedPoints);

        // Trigger cancellation / voiding -> reverse points
        $reversed = LoyaltyService::reverseOrderPoints($order, null, 'Order voided by admin');
        $this->assertTrue($reversed);

        $balance->refresh();
        $this->assertEquals(0, $balance->available_points);

        // Verify reversal transaction exists
        $reversalTx = EcommerceLoyaltyTransaction::where('order_id', $order->id)
            ->where('transaction_type', 'reversed')
            ->first();
        $this->assertNotNull($reversalTx);
        $this->assertEquals(-$earnedPoints, $reversalTx->points);
    }

    /**
     * Rule 3: Exclude Gift Card Payments From Points Calculation
     * Avoids awarding purchase points twice when gift cards are bought and then redeemed.
     */
    public function test_exclude_gift_card_payments_from_points_calculation(): void
    {
        $user = User::factory()->create([
            'email' => 'loyalty_gc_' . uniqid() . '@example.com',
            'loyalty_points' => 0,
        ]);

        // Order 1: Subtotal $100, paid $40 with gift card, $60 with credit card
        $orderWithGiftCard = EcommerceOrder::create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'order_date' => now(),
            'order_number' => 'ORD-GC-' . rand(100000, 999999),
            'status' => 'pending',
            'payment_status' => 'paid',
            'subtotal' => 100.00,
            'total_amount' => 60.00,
            'discount_amount' => 0.00,
            'payment_method' => 'stripe',
            'metadata' => [
                'gift_card_applied_amount' => 40.00,
                'gift_card_codes' => ['GC-TEST-1234'],
            ],
        ]);

        // Order 2: Subtotal $100, paid $0 with gift card, $100 with card
        $orderWithoutGiftCard = EcommerceOrder::create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'order_date' => now(),
            'order_number' => 'ORD-NOGC-' . rand(100000, 999999),
            'status' => 'pending',
            'payment_status' => 'paid',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
            'discount_amount' => 0.00,
            'payment_method' => 'stripe',
            'metadata' => [],
        ]);

        $txWithGc = LoyaltyService::awardPendingOrderPoints($orderWithGiftCard);
        $txWithoutGc = LoyaltyService::awardPendingOrderPoints($orderWithoutGiftCard);

        $this->assertNotNull($txWithGc);
        $this->assertNotNull($txWithoutGc);

        // The order with gift card should only earn points on ($100 - $40) = $60
        // The order without gift card earns points on $100
        $this->assertLessThan($txWithoutGc->points, $txWithGc->points);

        $settings = LoyaltyService::getSettings();
        $rate = $settings['points_per_dollar'];
        $firstOrderBonus = ($settings['first_order_bonus'] ?? 0);
        $this->assertEquals((int)round(60.00 * $rate) + $firstOrderBonus, $txWithGc->points);
    }

    /**
     * Rule 4: Maximum Points Redemption Per Order
     * Limits how many points can be used on one transaction, separate from the dollar discount cap.
     */
    public function test_maximum_points_redemption_per_order(): void
    {
        $user = User::factory()->create([
            'email' => 'loyalty_max_' . uniqid() . '@example.com',
            'loyalty_points' => 10000,
        ]);

        $balance = LoyaltyService::getOrCreateBalance($user);
        $balance->available_points = 10000;
        $balance->save();

        // Update settings: Max redemption points per order = 1,000 points
        $siteSetting = SiteSetting::first() ?: new SiteSetting();
        $currSettings = is_array($siteSetting->loyalty_settings) 
            ? $siteSetting->loyalty_settings 
            : (json_decode($siteSetting->loyalty_settings, true) ?: []);
        $currSettings['enabled'] = true;
        $currSettings['max_redeem_points_per_order'] = 1000;
        $currSettings['max_redeem_percent'] = 100.00; // allow up to 100% dollar discount
        $siteSetting->loyalty_settings = json_encode($currSettings);
        $siteSetting->save();

        // Attempt checkout redeeming 2,000 points (exceeds 1,000 max points per order)
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson('/api/ecommerce/checkout', [
            'payment_method' => 'card',
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'points_redeemed' => 2000,
            'items' => [
                [
                    'product_id' => null,
                    'product_name' => 'Custom Embroidery Hoodie',
                    'quantity' => 1,
                    'unit_price' => 200.00,
                    'total_price' => 200.00,
                ]
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Maximum points redemption allowed per order is 1,000 points', $response->json('message'));
    }
}
