<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\EcommerceOrder;
use App\Models\EcommerceReferral;
use App\Models\EcommerceAffiliate;
use App\Models\EcommerceReferralCommission;
use App\Models\EcommerceReturn;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateSystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function centralAuthHeadersFor(User $user): array
    {
        $token = $user->createToken('feature-test')->plainTextToken;
        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Central-Auth-Token' => $token,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Setup base settings
        $settings = SiteSetting::first();
        if (!$settings) {
            $settings = new SiteSetting();
        }
        $settings->referral_reward_referrer = 15.00;
        $settings->referral_reward_referee = 10.00;
        $settings->referral_commission_percentage = 15.00;
        $settings->save();
    }

    public function test_referral_registration_credits_wallets(): void
    {
        $referrer = User::factory()->create([
            'email' => 'referrer@example.com',
            'wallet_balance' => 0.00,
        ]);

        $affiliate = EcommerceAffiliate::create([
            'user_id' => $referrer->id,
            'affiliate_code' => 'AFF123',
            'total_earnings' => 0.00,
            'total_referrals' => 0,
            'status' => 'Active',
        ]);

        Http::fake([
            '*/v1/internal/admin/wallet/adjust' => Http::response([
                'success' => true,
                'data' => [
                    'balance_after' => 15.00,
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Referred User',
            'email' => 'referred@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => 'AFF123',
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('ecommerce_referrals', [
            'referrer_id' => $referrer->id,
            'reward_amount_referrer' => 15.00,
            'reward_amount_referee' => 10.00,
        ]);

        $affiliate->refresh();
        $this->assertEquals(1, $affiliate->total_referrals);
        $this->assertEquals(15.00, (float)$affiliate->total_earnings);
    }

    public function test_commission_created_on_order_payment(): void
    {
        $referrer = User::factory()->create(['email' => 'referrer@example.com']);
        $referred = User::factory()->create(['email' => 'referred@example.com']);

        EcommerceAffiliate::create([
            'user_id' => $referrer->id,
            'affiliate_code' => 'AFF123',
            'status' => 'Active',
        ]);

        EcommerceReferral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'reward_amount_referrer' => 15.00,
            'reward_amount_referee' => 10.00,
        ]);

        // Place an order for referred user
        $order = EcommerceOrder::create([
            'user_id' => $referred->id,
            'order_number' => 'ORD10001',
            'subtotal' => 100.00,
            'total_amount' => 110.00,
            'payment_status' => 'pending',
            'status' => 'pending',
            'customer_name' => $referred->name,
            'customer_email' => $referred->email,
            'customer_phone' => '123456789',
            'order_date' => now(),
        ]);

        // When payment status changes to paid, commission should be created
        $order->payment_status = 'paid';
        $order->save();

        $this->assertDatabaseHas('ecommerce_referral_commissions', [
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'order_id' => $order->id,
            'order_amount' => 100.00,
            'commission_percentage' => 15.00,
            'commission_amount' => 15.00,
            'status' => 'pending',
        ]);
    }

    public function test_release_pending_commissions_command(): void
    {
        $referrer = User::factory()->create(['email' => 'referrer@example.com', 'wallet_balance' => 0.00]);
        $referred = User::factory()->create(['email' => 'referred@example.com']);

        $affiliate = EcommerceAffiliate::create([
            'user_id' => $referrer->id,
            'affiliate_code' => 'AFF123',
            'total_earnings' => 15.00, // from signup
            'status' => 'Active',
        ]);

        $order = EcommerceOrder::create([
            'user_id' => $referred->id,
            'order_number' => 'ORD10001',
            'subtotal' => 100.00,
            'total_amount' => 110.00,
            'customer_name' => $referred->name,
            'customer_email' => $referred->email,
            'customer_phone' => '123456789',
            'order_date' => now(),
        ]);

        $commission = EcommerceReferralCommission::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'order_id' => $order->id,
            'order_amount' => 100.00,
            'commission_percentage' => 15.00,
            'commission_amount' => 15.00,
            'status' => 'approved',
            'payout_at' => now()->subDay(), // ready for release
        ]);

        Http::fake([
            '*/v1/internal/admin/wallet/adjust' => Http::response([
                'success' => true,
                'data' => [
                    'balance_after' => 15.00,
                ]
            ], 200),
        ]);

        $this->artisan('affiliate:release-commissions')
            ->assertExitCode(0);

        $commission->refresh();
        $this->assertEquals('completed', $commission->status);

        $affiliate->refresh();
        $this->assertEquals(30.00, (float)$affiliate->total_earnings); // 15 initial + 15 commission
    }

    public function test_admin_manual_payout(): void
    {
        $referrer = User::factory()->create(['email' => 'referrer@example.com', 'wallet_balance' => 0.00]);
        $referred = User::factory()->create(['email' => 'referred@example.com']);

        $affiliate = EcommerceAffiliate::create([
            'user_id' => $referrer->id,
            'affiliate_code' => 'AFF123',
            'total_earnings' => 15.00,
            'status' => 'Active',
        ]);

        $order = EcommerceOrder::create([
            'user_id' => $referred->id,
            'order_number' => 'ORD10001',
            'subtotal' => 100.00,
            'total_amount' => 110.00,
            'customer_name' => $referred->name,
            'customer_email' => $referred->email,
            'customer_phone' => '123456789',
            'order_date' => now(),
        ]);

        $commission = EcommerceReferralCommission::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'order_id' => $order->id,
            'order_amount' => 100.00,
            'commission_percentage' => 15.00,
            'commission_amount' => 15.00,
            'status' => 'pending',
            'payout_at' => now()->addDays(10),
        ]);

        Http::fake([
            '*/v1/internal/admin/wallet/adjust' => Http::response([
                'success' => true,
                'data' => [
                    'balance_after' => 15.00,
                ]
            ], 200),
        ]);

        // Login as super admin to process payout
        $admin = User::factory()->create(['role' => 'super_admin']);
        \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');

        $response = $this->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson("/api/ecommerce/admin/referral-commissions/{$commission->id}/payout");

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));

        $commission->refresh();
        $this->assertEquals('completed', $commission->status);

        $affiliate->refresh();
        $this->assertEquals(30.00, (float)$affiliate->total_earnings); // Should be updated in manual payout too!
    }

    public function test_order_refund_deducts_affiliate_earnings(): void
    {
        $referrer = User::factory()->create(['email' => 'referrer@example.com', 'wallet_balance' => 30.00]);
        $referred = User::factory()->create(['email' => 'referred@example.com']);

        $affiliate = EcommerceAffiliate::create([
            'user_id' => $referrer->id,
            'affiliate_code' => 'AFF123',
            'total_earnings' => 30.00,
            'status' => 'Active',
        ]);

        $order = EcommerceOrder::create([
            'user_id' => $referred->id,
            'order_number' => 'ORD10001',
            'subtotal' => 100.00,
            'total_amount' => 110.00,
            'customer_name' => $referred->name,
            'customer_email' => $referred->email,
            'customer_phone' => '123456789',
            'order_date' => now(),
        ]);

        $commission = EcommerceReferralCommission::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'order_id' => $order->id,
            'order_amount' => 100.00,
            'commission_percentage' => 15.00,
            'commission_amount' => 15.00,
            'status' => 'completed',
            'payout_at' => now(),
        ]);

        Http::fake([
            '*/v1/internal/admin/wallet/adjust' => Http::response([
                'success' => true,
                'data' => [
                    'balance_after' => 15.00,
                ]
            ], 200),
        ]);

        // Create and approve a return
        $return = EcommerceReturn::create([
            'user_id' => $referred->id,
            'order_id' => $order->id,
            'refund_amount' => 100.00, // full refund
            'status' => 'pending',
            'return_number' => 'RET10001',
            'reason' => 'Customer return',
        ]);

        $return->status = 'refunded';
        $return->save();

        $commission->refresh();
        $this->assertEquals('cancelled', $commission->status);
        $this->assertEquals(0.00, (float)$commission->commission_amount);

        $affiliate->refresh();
        $this->assertEquals(15.00, (float)$affiliate->total_earnings); // 30 - 15 = 15 total earnings after refund
    }
}
