<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Charity;
use App\Models\Category;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\EcommerceOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CharitySystemIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private function createTestProduct()
    {
        $category = Category::create([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_active' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Premium Shirt',
            'sku' => 'PREM-SHIRT',
            'price' => 35.00,
            'stock_quantity' => 100,
            'is_active' => true,
        ]);
    }

    public function test_checkout_validation_for_charity_donation(): void
    {
        // 1. Seed site settings with charity donation disabled
        SiteSetting::create([
            'site_name' => 'Mecarvi',
            'charity_donation_enabled' => false,
            'charity_name' => 'Mock Charity',
            'charity_default_amount' => 5.00,
        ]);

        // 2. Seed active charity
        Charity::create([
            'name' => 'Mock Charity',
            'tagline' => 'Test tagline',
            'description' => 'Test description',
            'contact_person' => 'Jane Doe',
            'address' => '123 Charity Lane',
            'phone' => '1234567890',
            'email' => 'contact@charity.org',
            'web' => 'charity.org',
            'status' => 'Active',
            'category' => 'Community',
            'logo_svg_type' => 'feeding_america',
        ]);

        $product = $this->createTestProduct();

        // 3. Post checkout with a donation when disabled -> should return 400
        $response = $this->postJson('/api/ecommerce/checkout', [
            'customer_name' => 'Guest User',
            'customer_email' => 'guest@example.com',
            'guest_shipping_address' => [
                'first_name' => 'Guest',
                'last_name' => 'User',
                'address' => '123 Guest St',
                'city' => 'Atlanta',
                'state' => 'GA',
                'zip_code' => '30303',
                'country' => 'United States',
                'phone' => '1234567890',
            ],
            'guest_billing_address' => [
                'first_name' => 'Guest',
                'last_name' => 'User',
                'address' => '123 Guest St',
                'city' => 'Atlanta',
                'state' => 'GA',
                'zip_code' => '30303',
                'country' => 'United States',
                'phone' => '1234567890',
            ],
            'payment_method' => 'stripe',
            'shipping_method' => 'express',
            'shipping_amount' => 10.00,
            'packaging_amount' => 5.00,
            'donation_amount' => 5.00,
            'selected_charity' => 'Mock Charity',
            'total_amount' => 100.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => 'Premium Shirt',
                    'quantity' => 2,
                    'unit_price' => 35.00,
                    'total_price' => 70.00,
                ]
            ],
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('Charity donations are currently disabled', $response->json('message'));
    }

    public function test_guest_checkout_and_donation_ledger_creation(): void
    {
        // 1. Seed site settings with charity donation enabled
        SiteSetting::create([
            'site_name' => 'Mecarvi',
            'charity_donation_enabled' => true,
            'charity_name' => 'Mock Charity',
            'charity_default_amount' => 5.00,
        ]);

        // 2. Seed active charity
        Charity::create([
            'name' => 'Mock Charity',
            'tagline' => 'Test tagline',
            'description' => 'Test description',
            'contact_person' => 'Jane Doe',
            'address' => '123 Charity Lane',
            'phone' => '1234567890',
            'email' => 'contact@charity.org',
            'web' => 'charity.org',
            'status' => 'Active',
            'category' => 'Community',
            'logo_svg_type' => 'feeding_america',
        ]);

        $product = $this->createTestProduct();

        // 3. Post checkout successfully as a guest
        $response = $this->postJson('/api/ecommerce/checkout', [
            'customer_name' => 'Guest User',
            'customer_email' => 'guest@example.com',
            'guest_shipping_address' => [
                'first_name' => 'Guest',
                'last_name' => 'User',
                'address' => '123 Guest St',
                'city' => 'Atlanta',
                'state' => 'GA',
                'zip_code' => '30303',
                'country' => 'United States',
                'phone' => '1234567890',
            ],
            'guest_billing_address' => [
                'first_name' => 'Guest',
                'last_name' => 'User',
                'address' => '123 Guest St',
                'city' => 'Atlanta',
                'state' => 'GA',
                'zip_code' => '30303',
                'country' => 'United States',
                'phone' => '1234567890',
            ],
            'payment_method' => 'stripe',
            'shipping_method' => 'express',
            'shipping_amount' => 10.00,
            'packaging_amount' => 5.00,
            'donation_amount' => 5.00,
            'selected_charity' => 'Mock Charity',
            'total_amount' => 100.00,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => 'Premium Shirt',
                    'quantity' => 2,
                    'unit_price' => 35.00,
                    'total_price' => 70.00,
                ]
            ],
        ]);

        $response->assertCreated();
        
        $order = EcommerceOrder::orderBy('id', 'desc')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->user_id);
        $this->assertSame('Guest User', $order->customer_name);
        $this->assertSame('guest@example.com', $order->customer_email);

        // Verify donation record is in the database ledger log
        $this->assertDatabaseHas('donations', [
            'order_id' => $order->order_number,
            'amount' => '5.00',
            'payment_method_brand' => 'Stripe',
            'status' => 'Completed',
        ]);
    }

    public function test_admin_donations_list_and_reversal(): void
    {
        $this->withoutMiddleware();

        $charity = Charity::create([
            'name' => 'Save The Oceans',
            'tagline' => 'Protect Marine Life',
            'contact_person' => 'Jack Sparrow',
            'address' => 'Ocean Pier 9',
            'phone' => '9998887777',
            'email' => 'contact@oceans.org',
            'status' => 'Active',
            'category' => 'Environment',
        ]);

        $donation = \App\Models\Donation::create([
            'order_id' => 'ORD-TEST-999',
            'txn_id' => 'TXN-TEST-999',
            'donor_name' => 'John Doe',
            'donor_email' => 'john@example.com',
            'charity_name' => 'Save The Oceans',
            'charity_category' => 'Environment',
            'amount' => 50.00,
            'payment_method_brand' => 'Visa',
            'payment_method_details' => '•••• 4242',
            'status' => 'Completed',
        ]);

        // 1. Test Admin Donations Index
        $response = $this->getJson('/api/ecommerce/admin/donations');
        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data',
            'stats' => ['total_donations', 'total_amount', 'pending_payouts', 'completed_payouts', 'charities_supported'],
        ]);

        // 2. Test Donation Reversal (Full refund)
        $reverseResponse = $this->postJson("/api/ecommerce/admin/donations/{$donation->id}/reverse", [
            'reason' => 'Donor dispute',
            'notes' => 'Customer requested a full refund of donation',
        ]);
        $reverseResponse->assertOk();
        $this->assertDatabaseHas('donations', [
            'id' => $donation->id,
            'status' => 'Refunded',
        ]);

        // 3. Test Donation Receipt Endpoint
        $receiptResponse = $this->postJson("/api/ecommerce/admin/donations/{$donation->id}/send-receipt", [
            'email' => 'john@example.com',
        ]);
        $receiptResponse->assertOk();
        $this->assertTrue($receiptResponse->json('success'));
    }

    public function test_charity_payouts_lifecycle(): void
    {
        $this->withoutMiddleware();

        $charity = Charity::create([
            'name' => 'Global Wildlife Fund',
            'tagline' => 'Protecting Wildlife',
            'contact_person' => 'Jane Goodall',
            'address' => '77 Forest Rd',
            'phone' => '1112223333',
            'email' => 'wildlife@example.org',
            'status' => 'Active',
            'category' => 'Animals',
        ]);

        $donation = \App\Models\Donation::create([
            'order_id' => 'ORD-TEST-888',
            'txn_id' => 'TXN-TEST-888',
            'donor_name' => 'Jane Smith',
            'donor_email' => 'jane@example.com',
            'charity_name' => 'Global Wildlife Fund',
            'charity_category' => 'Animals',
            'amount' => 100.00,
            'payment_method_brand' => 'Mastercard',
            'payment_method_details' => '•••• 5555',
            'status' => 'Completed',
        ]);

        // 1. Create a payout
        $createPayoutResponse = $this->postJson('/api/ecommerce/admin/donation-payouts', [
            'charity_name' => 'Global Wildlife Fund',
            'amount' => 100.00,
            'payment_method' => 'Bank Transfer',
            'reference_or_check' => 'ACH-12345678',
            'scheduled_date' => 'Nov 01, 2026',
            'notes_to_charity' => 'Monthly payout',
            'selected_donation_ids' => [(string) $donation->id],
        ]);
        $createPayoutResponse->assertOk();
        $payoutId = $createPayoutResponse->json('data.id');
        $this->assertNotNull($payoutId);

        // 2. Fetch payouts list
        $listResponse = $this->getJson('/api/ecommerce/admin/donation-payouts');
        $listResponse->assertOk();
        $this->assertGreaterThanOrEqual(1, count($listResponse->json('data')));

        // 3. Complete the payout
        $completeResponse = $this->postJson("/api/ecommerce/admin/donation-payouts/{$payoutId}/complete", [
            'date_paid' => 'Nov 02, 2026',
        ]);
        $completeResponse->assertOk();
        $this->assertDatabaseHas('charity_payouts', [
            'id' => $payoutId,
            'status' => 'Paid',
        ]);

        // 4. Test cancel on a new payout
        $secondPayout = $this->postJson('/api/ecommerce/admin/donation-payouts', [
            'charity_name' => 'Global Wildlife Fund',
            'amount' => 25.00,
            'payment_method' => 'Check',
            'reference_or_check' => 'CHK-999',
        ]);
        $secondPayoutId = $secondPayout->json('data.id');

        $cancelResponse = $this->postJson("/api/ecommerce/admin/donation-payouts/{$secondPayoutId}/cancel", [
            'reason' => 'Administrative error',
            'notes' => 'Cancelling invalid check',
        ]);
        $cancelResponse->assertOk();
        $this->assertDatabaseHas('charity_payouts', [
            'id' => $secondPayoutId,
            'status' => 'Canceled',
        ]);
    }

    public function test_multicharity_checkout_and_user_profile_donations(): void
    {
        $this->withoutMiddleware();

        SiteSetting::create([
            'site_name' => 'Mecarvi',
            'charity_donation_enabled' => true,
        ]);

        $charity1 = Charity::create([
            'name' => 'Charity Alpha',
            'contact_person' => 'Alice',
            'address' => '123 Alpha St',
            'phone' => '1111111111',
            'email' => 'alpha@test.com',
            'category' => 'Health',
            'status' => 'Active',
        ]);

        $charity2 = Charity::create([
            'name' => 'Charity Beta',
            'contact_person' => 'Bob',
            'address' => '456 Beta St',
            'phone' => '2222222222',
            'email' => 'beta@test.com',
            'category' => 'Education',
            'status' => 'Active',
        ]);

        $user = User::create([
            'name' => 'Test Donor User',
            'email' => 'donor@example.com',
            'password' => bcrypt('password123'),
        ]);

        $product = $this->createTestProduct();

        $checkoutResponse = $this->actingAs($user)->postJson('/api/ecommerce/checkout', [
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'payment_method' => 'stripe',
            'shipping_method' => 'standard',
            'shipping_amount' => 5.00,
            'packaging_amount' => 2.00,
            'donation_amount' => 35.00,
            'selected_charity' => 'Charity Alpha, Charity Beta',
            'charity_donations' => [
                [
                    'charity_id' => $charity1->id,
                    'charity_name' => 'Charity Alpha',
                    'amount' => 15.00,
                    'note' => 'Keep up the good work!',
                ],
                [
                    'charity_id' => $charity2->id,
                    'charity_name' => 'Charity Beta',
                    'amount' => 20.00,
                    'note' => 'For the students',
                ],
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 35.00,
                    'total_price' => 35.00,
                ]
            ],
        ]);

        $checkoutResponse->assertCreated();

        $order = EcommerceOrder::where('customer_email', $user->email)->orderBy('id', 'desc')->first();
        $this->assertNotNull($order);
        $this->assertEquals(35.00, (float) $order->donation_amount);

        // Verify both donation records were created in database
        $this->assertDatabaseHas('donations', [
            'order_id' => $order->order_number,
            'charity_name' => 'Charity Alpha',
            'amount' => '15.00',
            'note' => 'Keep up the good work!',
            'status' => 'Completed',
        ]);

        $this->assertDatabaseHas('donations', [
            'order_id' => $order->order_number,
            'charity_name' => 'Charity Beta',
            'amount' => '20.00',
            'note' => 'For the students',
            'status' => 'Completed',
        ]);

        // Verify User Profile Donations API
        $userDonationsResponse = $this->getJson("/api/v1/admin/users/{$user->id}/donations");
        $userDonationsResponse->assertOk();
        $this->assertCount(2, $userDonationsResponse->json('data'));
        $this->assertEquals(35.00, (float) $userDonationsResponse->json('stats.total_amount'));

        // Verify User Profile Financial Transactions API
        $userFinTxResponse = $this->getJson("/api/v1/admin/users/{$user->id}/financial-transactions");
        $userFinTxResponse->assertOk();
        $this->assertTrue($userFinTxResponse->json('success'));
    }
}
