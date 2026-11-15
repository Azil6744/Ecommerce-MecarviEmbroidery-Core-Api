<?php

namespace Tests\Feature;

use App\Models\EcommerceMembershipActivity;
use App\Models\EcommerceSubscriptionPlan;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Customer self-service lifecycle against a faked Central: upgrade (wallet),
 * failed payment, scheduled downgrade, preview, cancel + reactivate.
 */
class CustomerMembershipLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private EcommerceSubscriptionPlan $gold;
    private EcommerceSubscriptionPlan $pro;
    private EcommerceSubscriptionPlan $lite;
    private array $row;
    private bool $walletOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email' => 'lifecycle@example.com', 'phone' => null, 'wallet_balance' => 500]);
        $wallet = ['payment_methods' => ['credit_card', 'wallet']];
        $base = ['billing_cycle' => 'Monthly', 'coverage_type' => 'individual_site', 'applicable_site' => 'mecarvi-embroidery', 'status' => 'Active', 'benefit_config' => $wallet];
        $this->lite = EcommerceSubscriptionPlan::create($base + ['name' => 'LC Lite', 'internal_code' => 'LC-LITE', 'price' => 5.00]);
        $this->gold = EcommerceSubscriptionPlan::create($base + ['name' => 'LC Gold', 'internal_code' => 'LC-GOLD', 'price' => 10.00]);
        $this->pro = EcommerceSubscriptionPlan::create($base + ['name' => 'LC Pro', 'internal_code' => 'LC-PRO', 'price' => 30.00]);

        $this->row = [
            'id' => 901,
            'plan_id' => $this->gold->id,
            'plan_name' => 'LC Gold',
            'price' => 10.00,
            'status' => 'active',
            'billing_cycle' => 'Monthly',
            'account_type' => 'personal',
            'current_period_start' => now()->subDays(10)->startOfDay()->toDateTimeString(),
            'current_period_end' => now()->addDays(20)->startOfDay()->toDateTimeString(),
            'next_billing_date' => now()->addDays(20)->toDateString(),
            'auto_renew' => true,
            'metadata' => [],
            'user' => ['email' => $this->user->email, 'name' => $this->user->name],
        ];

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/wallet/adjust')) {
                return $this->walletOk
                    ? Http::response(['success' => true, 'data' => ['balance_after' => 480]], 200)
                    : Http::response(['success' => false, 'message' => 'Insufficient balance'], 422);
            }
            if (str_contains($url, '/memberships/update')) {
                $this->row = array_merge($this->row, array_intersect_key($request->data(), array_flip(['plan_id', 'plan_name', 'price', 'status', 'cancel_at_period_end', 'auto_renew'])));
                return Http::response(['success' => true, 'data' => $this->row], 200);
            }
            if (str_contains($url, '/user/memberships') || str_contains($url, '/internal/admin/memberships')) {
                return Http::response(['success' => true, 'data' => [$this->row]], 200);
            }

            return Http::response(['message' => 'Unauthenticated.'], 401);
        });
    }

    private function act(string $action, array $body = [])
    {
        $token = $this->user->createToken('lc')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token])
            ->postJson("/api/ecommerce/memberships/901/{$action}", $body);
    }

    private function sent(string $needle): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), $needle))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_preview_returns_breakdown_and_changes_nothing(): void
    {
        $res = $this->act('upgrade', ['plan_id' => $this->pro->id, 'preview' => true])->assertOk();

        $this->assertGreaterThan(0, (float) $res->json('data.breakdown.amount_due_today'));
        $this->assertCount(0, $this->sent('/memberships/update'));
        $this->assertCount(0, $this->sent('/wallet/adjust'));
    }

    public function test_wallet_upgrade_charges_prorated_amount_and_switches_plan(): void
    {
        $quote = (float) $this->act('upgrade', ['plan_id' => $this->pro->id, 'preview' => true])->json('data.breakdown.amount_due_today');

        $this->act('upgrade', ['plan_id' => $this->pro->id, 'payment_method' => 'wallet'])->assertOk();

        $charge = $this->sent('/wallet/adjust')[0] ?? null;
        $this->assertNotNull($charge, 'Wallet must be charged.');
        $this->assertEqualsWithDelta($quote, (float) $charge['amount'], 0.01);
        $this->assertLessThan(30.00, $quote, 'Upgrade is prorated, not the full new price.');
        $this->assertSame($this->pro->id, (int) $this->row['plan_id']);

        $this->assertTrue(EcommerceMembershipActivity::where('user_id', $this->user->id)->where('action', 'upgrade')->exists());
        $this->assertTrue(UserNotification::where('user_id', $this->user->id)->where('type', 'membership.upgrade')->exists());
    }

    public function test_upgrade_during_a_free_trial_gives_no_credit_for_the_trial(): void
    {
        $this->row['status'] = 'trialing';

        $breakdown = $this->act('upgrade', ['plan_id' => $this->pro->id, 'preview' => true])->json('data.breakdown');

        $this->assertEqualsWithDelta(0.0, (float) $breakdown['unused_credit'], 0.001, 'Nothing was paid for the trial.');
        $this->assertEqualsWithDelta((float) $breakdown['new_plan_charge'], (float) $breakdown['amount_due_today'], 0.01);
    }

    public function test_failed_wallet_payment_leaves_plan_unchanged(): void
    {
        // Central refuses the debit and the local balance can't cover it either.
        $this->walletOk = false;
        $this->user->forceFill(['wallet_balance' => 0])->save();

        $this->act('upgrade', ['plan_id' => $this->pro->id, 'payment_method' => 'wallet'])->assertStatus(422);

        $this->assertSame($this->gold->id, (int) $this->row['plan_id']);
        $this->assertCount(0, $this->sent('/memberships/update'));
    }

    public function test_downgrade_is_scheduled_for_next_cycle_without_charge(): void
    {
        $this->act('downgrade', ['plan_id' => $this->lite->id])->assertOk();

        $this->assertCount(0, $this->sent('/wallet/adjust'));
        $this->assertSame($this->gold->id, (int) $this->row['plan_id'], 'Plan stays until the next billing date.');
        $update = $this->sent('/memberships/update')[0] ?? [];
        $this->assertSame('pending_downgrade', $update['status'] ?? null);
        $this->assertSame('LC Lite', $update['scheduled_plan']['name'] ?? ($update['scheduled_plan']['plan_name'] ?? null));
        $this->assertNotEmpty($update['scheduled_plan']['effective_date'] ?? null);
        $this->assertTrue(UserNotification::where('user_id', $this->user->id)->where('type', 'membership.downgrade_scheduled')->exists());
    }

    public function test_cancel_then_reactivate_restores_the_membership(): void
    {
        $this->act('cancel', ['reason' => 'Too expensive'])->assertOk();
        $this->assertTrue((bool) ($this->row['cancel_at_period_end'] ?? false) || $this->row['status'] === 'pending_cancellation');

        $this->act('reactivate')->assertOk();
        $this->assertFalse((bool) ($this->row['cancel_at_period_end'] ?? false));
        $this->assertSame('active', $this->row['status']);

        $types = UserNotification::where('user_id', $this->user->id)->pluck('type')->all();
        $this->assertContains('membership.cancel', $types);
        $this->assertContains('membership.reactivate', $types);
    }
}
