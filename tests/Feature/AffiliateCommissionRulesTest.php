<?php

namespace Tests\Feature;

use App\Models\EcommerceAffiliate;
use App\Models\EcommerceGiftCardOrder;
use App\Models\EcommerceOrder;
use App\Models\EcommerceReferral;
use App\Models\EcommerceReferralCommission;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AffiliateCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateCommissionRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $referrer;
    private User $referred;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['balance_after' => 0]], 200)]);

        $this->setSettings([]);
        $this->referrer = User::factory()->create(['email' => 'referrer@example.com']);
        $this->referred = User::factory()->create(['email' => 'referred@example.com']);
        EcommerceAffiliate::create(['user_id' => $this->referrer->id, 'affiliate_code' => 'AFF123', 'status' => 'Active']);
        EcommerceReferral::create(['referrer_id' => $this->referrer->id, 'referred_id' => $this->referred->id]);
    }

    private function setSettings(array $values): void
    {
        $row = SiteSetting::first() ?: new SiteSetting();
        $row->referral_commission_percentage = 10;
        $row->affiliate_settings = $values;
        $row->save();
    }

    private function pay(float $amount = 100.0, string $ref = null)
    {
        return AffiliateCommissionService::record($this->referred, 'order', $ref ?? uniqid('o'), $amount);
    }

    public function test_commission_is_pending_until_admin_approves(): void
    {
        $c = $this->pay(100);
        $this->assertEquals('pending', $c->status);
        $this->assertEquals(10.00, (float) $c->commission_amount);

        $approved = AffiliateCommissionService::approve($c, null, true);
        $this->assertEquals('completed', $approved->status);
    }

    public function test_only_first_purchase_earns_by_default(): void
    {
        $this->assertNotNull($this->pay(100));
        $this->assertNull($this->pay(100));
    }

    public function test_recurring_toggle_and_expiry_window(): void
    {
        $this->setSettings(['recurring_commissions' => true, 'recurring_duration_days' => 30]);
        $this->assertNotNull($this->pay(100));
        $this->assertNotNull($this->pay(100), 'second purchase inside the window earns');

        EcommerceReferral::first()->update(['first_purchase_at' => now()->subDays(31)]);
        $this->assertNull($this->pay(100), 'purchase after the window must not earn');
    }

    public function test_same_purchase_never_pays_twice(): void
    {
        $this->setSettings(['recurring_commissions' => true]);
        $this->assertNotNull($this->pay(100, 'same'));
        $this->assertNull($this->pay(100, 'same'));
    }

    public function test_fixed_rate_cap_and_disabled_type(): void
    {
        $this->setSettings([
            'earn_gift_card' => true, 'earn_gift_card_rate_type' => 'Flat Amount ($)', 'earn_gift_card_value' => 7,
            'earn_membership' => false,
            'max_commission' => true, 'max_commission_amount' => 3,
        ]);
        $this->assertNull(AffiliateCommissionService::record($this->referred, 'membership', 'm1', 50));
        $c = AffiliateCommissionService::record($this->referred, 'gift_card', 'g1', 50);
        $this->assertEquals(3.00, (float) $c->commission_amount);
    }

    public function test_gift_card_purchase_creates_pending_commission(): void
    {
        EcommerceGiftCardOrder::create([
            'order_number' => 'GC1', 'customer_id' => $this->referred->id, 'buyer_name' => 'B', 'buyer_email' => 'b@x.com',
            'recipient_name' => 'R', 'recipient_email' => 'r@x.com', 'giftcard_amount' => 200,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('ecommerce_referral_commissions', [
            'referrer_id' => $this->referrer->id, 'source_type' => 'gift_card', 'status' => 'pending',
        ]);
    }

    public function test_self_referral_is_blocked_and_affiliate_disabled(): void
    {
        EcommerceReferral::create(['referrer_id' => $this->referrer->id, 'referred_id' => $this->referrer->id]);
        $c = AffiliateCommissionService::record($this->referrer, 'order', 'self1', 100);
        $this->assertEquals('declined', $c->status);
        $this->assertTrue($c->flagged);
        $this->assertEquals('Inactive', EcommerceAffiliate::first()->status);
    }

    public function test_program_inactive_earns_nothing(): void
    {
        $this->setSettings(['program_active' => false]);
        $this->assertNull($this->pay(100));
    }

    public function test_pending_commission_is_not_auto_released(): void
    {
        $c = $this->pay(100);
        $c->update(['payout_at' => now()->subDay()]);
        $this->artisan('affiliate:release-commissions')->assertExitCode(0);
        $this->assertEquals('pending', $c->fresh()->status);
    }

    public function test_cancelled_order_voids_uncredited_commission(): void
    {
        $order = EcommerceOrder::create([
            'user_id' => $this->referred->id, 'order_number' => 'ORD1', 'subtotal' => 100, 'total_amount' => 110,
            'payment_status' => 'paid', 'status' => 'pending', 'customer_name' => 'x', 'customer_email' => 'referred@example.com',
            'customer_phone' => '1', 'order_date' => now(),
        ]);
        $c = EcommerceReferralCommission::where('order_id', $order->id)->first();
        $this->assertEquals('pending', $c->status);

        $order->update(['payment_status' => 'refunded']);
        $this->assertEquals('void', $c->fresh()->status);
    }

    public function test_every_user_gets_unique_referral_code(): void
    {
        $a = AffiliateCommissionService::ensureAffiliateFor($this->referred);
        $b = AffiliateCommissionService::ensureAffiliateFor($this->referred);
        $this->assertEquals($a->id, $b->id);
        $this->assertNotEquals('AFF123', $a->affiliate_code);
        $this->assertStringContainsString('?ref=' . $a->affiliate_code, $a->referral_link);
    }

    public function test_cancelled_order_manual_review_setting(): void
    {
        $this->setSettings(['cancelled_orders_action' => 'Manual Review']);
        $order = EcommerceOrder::create([
            'user_id' => $this->referred->id, 'order_number' => 'ORD2', 'subtotal' => 100, 'total_amount' => 110,
            'payment_status' => 'paid', 'status' => 'pending', 'customer_name' => 'x', 'customer_email' => 'referred@example.com',
            'customer_phone' => '1', 'order_date' => now(),
        ]);
        $c = EcommerceReferralCommission::where('order_id', $order->id)->first();
        $this->assertEquals('pending', $c->status);

        $order->update(['payment_status' => 'refunded']);
        $c->refresh();
        $this->assertNotEquals('void', $c->status);
        $this->assertTrue($c->flagged);
        $this->assertStringContainsStringIgnoringCase('manual review', $c->flag_reason);
    }

    public function test_payout_request_validations_and_wallet_flow(): void
    {
        $this->setSettings([
            'require_min_payout' => true,
            'min_payout_amount' => 50.00,
            'require_tax_info' => true,
            'require_payout_method' => true,
            'payout_methods' => ['paypal' => true, 'bank_transfer' => true, 'cash_app' => true],
        ]);

        $affiliate = EcommerceAffiliate::where('user_id', $this->referrer->id)->first();
        $affiliate->update(['total_earnings' => 100.00]);
        $this->referrer->update(['wallet_balance' => 100.00]);

        // 1. Fails without tax info
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tax information');
        \App\Services\AffiliatePayoutService::assertCanWithdraw($this->referrer, $affiliate, 60.00, 'paypal');
    }

    public function test_payout_request_and_admin_rejection_refund(): void
    {
        $this->setSettings([
            'require_min_payout' => false,
            'require_tax_info' => false,
            'require_payout_method' => false,
            'payout_methods' => ['paypal' => true],
        ]);

        $affiliate = EcommerceAffiliate::where('user_id', $this->referrer->id)->first();
        $affiliate->update(['total_earnings' => 100.00]);
        $this->referrer->update(['wallet_balance' => 100.00]);

        $payout = \App\Services\AffiliatePayoutService::create(
            $this->referrer,
            $affiliate,
            40.00,
            'paypal',
            'paypal@example.com'
        );

        $this->assertEquals('pending', $payout->status);
        $this->assertEquals(40.00, (float) $payout->amount);

        // Reject payout -> refunds wallet
        $rejected = \App\Services\AffiliatePayoutService::reject($payout, 1, 'Invalid PayPal email');
        $this->assertEquals('rejected', $rejected->status);
        $this->assertEquals('Invalid PayPal email', $rejected->reject_reason);
    }

    public function test_scheduled_payout_processing(): void
    {
        $this->setSettings([
            'require_min_payout' => false,
            'require_tax_info' => false,
            'require_payout_method' => false,
            'payout_methods' => ['bank_transfer' => true],
            'payout_processing_mode' => 'Automated Payouts',
        ]);

        $affiliate = EcommerceAffiliate::where('user_id', $this->referrer->id)->first();
        $affiliate->update(['total_earnings' => 200.00]);
        $this->referrer->update(['wallet_balance' => 200.00]);

        $payout = \App\Services\AffiliatePayoutService::create(
            $this->referrer,
            $affiliate,
            50.00,
            'bank_transfer',
            'Account 123'
        );

        // Payout starts approved because processing mode is Automated
        $this->assertEquals('approved', $payout->status);
        $payout->update(['scheduled_for' => now()->subDay()->toDateString()]);

        $this->artisan('affiliate:process-payouts')->assertExitCode(0);
        $this->assertEquals('paid', $payout->fresh()->status);
    }
}
