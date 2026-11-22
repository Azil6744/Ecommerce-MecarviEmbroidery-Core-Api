<?php

namespace Tests\Feature;

use App\Models\EcommerceAffiliate;
use App\Models\EcommerceReferral;
use App\Models\EcommerceWalletTransaction;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AffiliateCommissionService;
use App\Services\AffiliatePayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Affiliate earnings are their own balance: convert into the wallet OR withdraw to the bank.
 * Wallet money (top-ups, store credit, converted earnings) can never be withdrawn.
 */
class AffiliateEarningsBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $referrer;
    private User $referred;
    private EcommerceAffiliate $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['balance_after' => 0]], 200)]);

        $row = SiteSetting::first() ?: new SiteSetting();
        $row->referral_commission_percentage = 10;
        $row->affiliate_settings = [
            'require_min_payout' => false,
            'require_tax_info' => false,
            'require_payout_method' => false,
            'payout_methods' => ['paypal' => true, 'bank_transfer' => true],
        ];
        $row->save();

        $this->referrer = User::factory()->create(['email' => 'referrer@example.com', 'wallet_balance' => 0]);
        $this->referred = User::factory()->create(['email' => 'referred@example.com']);
        $this->affiliate = EcommerceAffiliate::create(['user_id' => $this->referrer->id, 'affiliate_code' => 'AFF900', 'status' => 'Active']);
        EcommerceReferral::create(['referrer_id' => $this->referrer->id, 'referred_id' => $this->referred->id]);
    }

    private function centralAuthHeadersFor(User $user): array
    {
        $token = $user->createToken('feature-test')->plainTextToken;

        return ['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token];
    }

    public function test_gift_card_redeem_to_wallet_endpoint_is_gone(): void
    {
        // 405: the path now only matches the gift-cards/{id} resource routes, which don't accept POST.
        $status = $this->withHeaders($this->centralAuthHeadersFor($this->referrer))
            ->postJson('/api/ecommerce/gift-cards/redeem-to-wallet', ['code' => 'ANY'])
            ->status();
        $this->assertContains($status, [404, 405]);
    }

    private function walletRows()
    {
        return EcommerceWalletTransaction::where('user_id', $this->referrer->id);
    }

    public function test_released_commission_goes_to_affiliate_earnings_not_wallet(): void
    {
        $c = AffiliateCommissionService::record($this->referred, 'order', 'o-1', 100.0);
        AffiliateCommissionService::approve($c, null, true);

        $this->assertEquals('completed', $c->fresh()->status);
        $this->assertEquals(10.00, (float) $this->affiliate->fresh()->total_earnings);
        $this->assertEquals(10.00, AffiliatePayoutService::availableBalance($this->affiliate->fresh()));
        $this->assertSame(0, $this->walletRows()->count(), 'Earnings must not be deposited into the wallet automatically.');
    }

    public function test_convert_moves_earnings_into_wallet_and_out_of_withdrawable_balance(): void
    {
        $this->affiliate->update(['total_earnings' => 50.00]);

        $left = AffiliatePayoutService::convertToWallet($this->referrer, $this->affiliate, 20.00);

        $this->assertEquals(30.00, $left);
        $this->assertEquals(20.00, (float) $this->affiliate->fresh()->converted_to_wallet);
        $this->assertEquals(20.00, (float) $this->walletRows()->where('amount', '>', 0)->sum('amount'));

        // Converted money is wallet money now: it can't be withdrawn.
        $this->expectException(\DomainException::class);
        AffiliatePayoutService::assertCanWithdraw($this->referrer, $this->affiliate->fresh(), 40.00, 'bank_transfer');
    }

    public function test_wallet_money_can_never_be_withdrawn(): void
    {
        // $500 in the wallet from a card top-up, but no affiliate earnings.
        $this->referrer->update(['wallet_balance' => 500.00]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('available affiliate earnings');
        AffiliatePayoutService::assertCanWithdraw($this->referrer, $this->affiliate, 100.00, 'bank_transfer');
    }

    public function test_withdraw_uses_earnings_and_rejection_returns_them_without_wallet_movement(): void
    {
        $this->affiliate->update(['total_earnings' => 80.00]);

        AffiliatePayoutService::assertCanWithdraw($this->referrer, $this->affiliate, 80.00, 'bank_transfer');
        $payout = AffiliatePayoutService::create($this->referrer, $this->affiliate, 80.00, 'bank_transfer', 'Acct 1');
        $this->assertEquals(0.00, AffiliatePayoutService::availableBalance($this->affiliate->fresh()));

        AffiliatePayoutService::reject($payout, null, 'Wrong account');
        $this->assertEquals(80.00, AffiliatePayoutService::availableBalance($this->affiliate->fresh()));
        $this->assertSame(0, $this->walletRows()->count(), 'Payouts never touch the wallet.');
    }

    public function test_convert_blocked_when_affiliate_source_turned_off(): void
    {
        $this->affiliate->update(['total_earnings' => 50.00]);
        $s = \App\Models\EcommerceWalletSetting::current();
        $s['source_affiliate'] = false;
        $row = \App\Models\EcommerceWalletSetting::query()->first();
        $row ? $row->update(['settings' => json_encode($s)]) : \App\Models\EcommerceWalletSetting::create(['settings' => json_encode($s)]);

        $this->expectException(\DomainException::class);
        AffiliatePayoutService::convertToWallet($this->referrer, $this->affiliate, 10.00);
    }

    public function test_convert_endpoint(): void
    {
        $this->affiliate->update(['total_earnings' => 25.00]);

        $this->withHeaders($this->centralAuthHeadersFor($this->referrer))
            ->postJson('/api/ecommerce/affiliates/convert-to-wallet', ['amount' => 25])
            ->assertOk()
            ->assertJsonPath('data.available_balance', 0);

        $this->withHeaders($this->centralAuthHeadersFor($this->referrer))
            ->postJson('/api/ecommerce/affiliates/convert-to-wallet', ['amount' => 1])
            ->assertStatus(422);
    }
}
