<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCard;
use App\Models\EcommerceOrder;
use App\Models\EcommerceReturn;
use App\Models\EcommerceVoucher;
use App\Models\EcommerceWalletTransaction;
use App\Models\User;
use App\Services\OrderTenderRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gift cards and vouchers never mix with the wallet: on cancel or refund the gift card share goes
 * back to the gift card and the voucher share back to the voucher. Only the rest uses the refund method.
 */
class GiftCardVoucherRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private EcommerceOrder $order;
    private EcommerceGiftCard $card;
    private EcommerceVoucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['balance_after' => 0]], 200)]);

        $this->customer = User::factory()->create(['email' => 'buyer@example.com', 'wallet_balance' => 0]);

        // $100 order: $40 gift card + $20 voucher + $40 card payment (total_amount is net of both).
        $this->order = EcommerceOrder::create([
            'user_id' => $this->customer->id, 'order_number' => 'ORD-GV-1', 'subtotal' => 100, 'total_amount' => 40,
            'payment_status' => 'paid', 'status' => 'processing', 'customer_name' => 'Buyer',
            'customer_email' => 'buyer@example.com', 'customer_phone' => '1', 'order_date' => now(),
        ]);

        $this->card = EcommerceGiftCard::create([
            'user_id' => $this->customer->id, 'code' => '300000000000001', 'recipient_name' => 'Buyer',
            'recipient_email' => 'buyer@example.com', 'initial_balance' => 50, 'current_balance' => 10,
            'status' => 'partially used', 'currency' => 'USD',
        ]);
        $this->card->transactions()->create([
            'transaction_type' => 'Redemption', 'amount' => -40, 'order_id' => $this->order->id, 'notes' => 'used',
        ]);

        $this->voucher = EcommerceVoucher::create([
            'code' => 'VCH-TEST-1', 'user_id' => $this->customer->id, 'holder_name' => 'Buyer', 'holder_email' => 'buyer@example.com', 'issued_at' => now()->toDateString(), 'amount' => 20, 'balance' => 0,
            'status' => EcommerceVoucher::STATUS_REDEEMED, 'currency' => 'USD',
        ]);
        $this->order->update(['metadata' => [
            'vouchers_applied' => [['code' => 'VCH-TEST-1', 'amount' => 20]],
            'voucher_deduction' => 20,
        ]]);
        $this->order->refresh();
    }

    public function test_split_is_proportional_and_full_refund_returns_everything(): void
    {
        $this->assertSame(['gift_card' => 20.0, 'voucher' => 10.0, 'other' => 20.0], OrderTenderRefundService::split($this->order, 50));
        $this->assertSame(['gift_card' => 40.0, 'voucher' => 20.0, 'other' => 40.0], OrderTenderRefundService::split($this->order, 100));
    }

    public function test_cancel_returns_gift_card_and_voucher_once(): void
    {
        $back = OrderTenderRefundService::restoreAll($this->order, 'cancelled');
        $this->assertEquals(['gift_card' => 40.0, 'voucher' => 20.0], $back);
        $this->assertEquals(50.00, (float) $this->card->fresh()->current_balance);
        $this->assertEquals('active', $this->card->fresh()->status);
        // Original voucher stays spent; a NEW linked voucher holds the refunded $20.
        $this->assertEquals(0.00, (float) $this->voucher->fresh()->balance);
        $refund = EcommerceVoucher::where('refunded_from_voucher_id', $this->voucher->id)->sole();
        $this->assertNotSame('VCH-TEST-1', $refund->code);
        $this->assertEquals(20.00, (float) $refund->balance);
        $this->assertEquals(EcommerceVoucher::STATUS_ACTIVE, $refund->status);
        $this->assertEquals($this->customer->id, $refund->user_id);
        $this->assertEquals('ORD-GV-1', $refund->order_number);

        // Running it again (e.g. status toggled twice) returns nothing more.
        $this->assertEquals(['gift_card' => 0.0, 'voucher' => 0.0], OrderTenderRefundService::restoreAll($this->order->fresh(), 'again'));
        $this->assertEquals(50.00, (float) $this->card->fresh()->current_balance);
        $this->assertSame(1, EcommerceVoucher::where('refunded_from_voucher_id', $this->voucher->id)->count());
        $this->assertSame(0, EcommerceWalletTransaction::where('user_id', $this->customer->id)->count());
    }

    public function test_wallet_refund_only_gets_the_non_gift_card_non_voucher_share(): void
    {
        $return = EcommerceReturn::create([
            'user_id' => $this->customer->id, 'order_id' => $this->order->id, 'refund_amount' => 100,
            'status' => 'pending', 'return_number' => 'RET-GV-1', 'reason' => 'Damaged',
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');
        $token = $admin->createToken('t')->plainTextToken;

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token])
            ->postJson("/api/admin/refunds/{$return->id}/approve", ['approved_amount' => 100, 'refund_method' => 'wallet'])
            ->assertOk();

        $refund = EcommerceVoucher::where('refunded_from_voucher_id', $this->voucher->id)->sole();
        $this->assertEquals([
            'gift_card' => 40, 'voucher' => 20,
            'refund_vouchers' => [['code' => $refund->code, 'amount' => 20, 'original' => 'VCH-TEST-1']],
            'refund_method' => 'wallet', 'refund_method_amount' => 40,
        ], $res->json('refund_breakdown'));
        $this->assertStringContainsString('as new voucher ' . $refund->code, $res->json('message'));
        $this->assertEquals(50.00, (float) $this->card->fresh()->current_balance);
        $this->assertEquals(20.00, (float) $refund->balance);
        $this->assertEquals(40.00, (float) EcommerceWalletTransaction::where('user_id', $this->customer->id)->sum('amount'));
    }

    public function test_refund_voucher_can_be_used_again_and_shows_its_link(): void
    {
        $issued = OrderTenderRefundService::issueRefundVouchers($this->order, null, 'cancelled');
        $code = $issued['vouchers'][0]['code'];

        // Usable at checkout like any voucher.
        $check = \App\Services\VoucherService::validateForOrder([$code], 50.0);
        $this->assertTrue($check['valid'], $check['message'] ?? '');
        $this->assertEquals(20.00, (float) $check['total_applied']);

        // Customer sees it in their vouchers, linked to the original.
        $token = $this->customer->createToken('t')->plainTextToken;
        $mine = collect($this->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token])
            ->getJson('/api/ecommerce/vouchers')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame('VCH-TEST-1', $mine[$code]['refunded_from']['code']);
        $this->assertSame($code, $mine['VCH-TEST-1']['refund_vouchers'][0]['code']);
    }

    public function test_partial_refunds_issue_vouchers_only_up_to_what_was_used(): void
    {
        OrderTenderRefundService::issueRefundVouchers($this->order, 15, 'refund 1');
        OrderTenderRefundService::issueRefundVouchers($this->order->fresh(), 15, 'refund 2');

        $amounts = EcommerceVoucher::where('refunded_from_voucher_id', $this->voucher->id)->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all();
        $this->assertSame([15.0, 5.0], $amounts); // $20 used in total, never more
    }

    public function test_no_refund_voucher_when_setting_is_off(): void
    {
        $settings = \App\Models\EcommerceVoucherSetting::current();
        $settings['restore_on_cancel'] = false;
        $row = \App\Models\EcommerceVoucherSetting::query()->first();
        $row ? $row->update(['settings' => json_encode($settings)]) : \App\Models\EcommerceVoucherSetting::create(['settings' => json_encode($settings)]);

        $this->assertEquals(0.0, OrderTenderRefundService::issueRefundVouchers($this->order, null, 'cancelled')['amount']);
        $this->assertSame(0, EcommerceVoucher::where('refunded_from_voucher_id', $this->voucher->id)->count());
    }

    public function test_partial_refunds_never_return_more_than_was_used(): void
    {
        OrderTenderRefundService::restoreGiftCards($this->order, 30, 'refund 1');
        OrderTenderRefundService::restoreGiftCards($this->order, 30, 'refund 2');

        $this->assertEquals(50.00, (float) $this->card->fresh()->current_balance); // 10 + 40, not 10 + 60
        $this->assertSame([], OrderTenderRefundService::giftCardRemaining($this->order));
    }
}
