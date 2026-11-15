<?php

namespace Tests\Feature;

use App\Models\EcommerceSubscriptionPlan;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcommerceMembershipWalletTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_index_filters_by_site_and_includes_universal_plans(): void
    {
        EcommerceSubscriptionPlan::create([
            'name' => 'Universal VIP',
            'internal_code' => 'UNIV-VIP',
            'price' => 29.99,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'universal',
            'applicable_site' => 'all-sites',
            'status' => 'Active',
        ]);

        EcommerceSubscriptionPlan::create([
            'name' => 'Embroidery Pro',
            'internal_code' => 'EMB-PRO',
            'price' => 19.99,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site',
            'applicable_site' => 'mecarvi-embroidery',
            'status' => 'Active',
        ]);

        EcommerceSubscriptionPlan::create([
            'name' => 'Apparel Pro',
            'internal_code' => 'APP-PRO',
            'price' => 14.99,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site',
            'applicable_site' => 'mecarvi-apparel',
            'status' => 'Active',
        ]);

        $response = $this->getJson('/api/ecommerce/subscription-plans?site=mecarvi-embroidery');

        $response->assertStatus(200);
        $data = $response->json('data');

        $names = collect($data)->pluck('name')->all();

        $this->assertContains('Universal VIP', $names);
        $this->assertContains('Embroidery Pro', $names);
        $this->assertNotContains('Apparel Pro', $names);
    }

    private function centralAuthHeadersFor(User $user): array
    {
        $token = $user->createToken('feature-test')->plainTextToken;
        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Central-Auth-Token' => $token,
        ];
    }

    public function test_wallet_membership_purchase_fails_when_insufficient_balance(): void
    {
        Http::fake([
            '*/v1/internal/admin/memberships*' => Http::response(['success' => true], 200),
            '*/v1/internal/admin/wallet/adjust' => Http::response(['success' => false], 422),
        ]);

        $user = User::factory()->create([
            'wallet_balance' => 5.00,
        ]);

        $plan = EcommerceSubscriptionPlan::create([
            'name' => 'Embroidery Gold',
            'internal_code' => 'EMB-GOLD',
            // Wallet must be an accepted payment method on the plan.
            'benefit_config' => ['payment_methods' => ['credit_card', 'wallet']],
            'price' => 49.99,
            'setup_fee' => 10.00,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site',
            'applicable_site' => 'mecarvi-embroidery',
            'status' => 'Active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders($this->centralAuthHeadersFor($user))
            ->postJson('/api/ecommerce/memberships', [
                'plan_id' => $plan->id,
                'payment_method' => 'wallet',
                'pin' => '1234',
                'terms_accepted' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Insufficient wallet balance to subscribe to this plan.',
        ]);
    }

    public function test_wallet_membership_purchase_succeeds_with_sufficient_balance(): void
    {
        Http::fake([
            '*/user/memberships' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 101,
                    'status' => 'active',
                    'plan_name' => 'Embroidery Gold',
                    'payment_method' => 'wallet',
                ],
            ], 200),
            '*/v1/internal/admin/wallet/adjust' => Http::response([
                'success' => true,
                'data' => ['balance_after' => 40.01],
            ], 200),
        ]);

        $user = User::factory()->create([
            'wallet_balance' => 100.00,
        ]);

        $plan = EcommerceSubscriptionPlan::create([
            'name' => 'Embroidery Gold',
            'internal_code' => 'EMB-GOLD-2',
            // Wallet must be an accepted payment method on the plan.
            'benefit_config' => ['payment_methods' => ['credit_card', 'wallet']],
            'price' => 49.99,
            'setup_fee' => 10.00,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site',
            'applicable_site' => 'mecarvi-embroidery',
            'status' => 'Active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders($this->centralAuthHeadersFor($user))
            ->postJson('/api/ecommerce/memberships', [
                'plan_id' => $plan->id,
                'payment_method' => 'wallet',
                'pin' => '1234',
                'terms_accepted' => true,
            ]);

        $response->assertStatus(200);

        // Verify balance was debited (100.00 - 59.99 = 40.01)
        $user->refresh();
        $this->assertEquals(40.01, (float) $user->wallet_balance);
    }
    public function test_wallet_purchase_is_rejected_when_plan_does_not_accept_wallet(): void
    {
        Http::fake([
            '*/v1/internal/admin/wallet/adjust' => Http::response(['success' => true], 200),
        ]);

        $user = User::factory()->create(['wallet_balance' => 500.00]);

        $plan = EcommerceSubscriptionPlan::create([
            'name' => 'Card Only Plan',
            'internal_code' => 'CARD-ONLY',
            'price' => 19.99,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site',
            'applicable_site' => 'mecarvi-embroidery',
            'status' => 'Active',
            // No payment_methods configured: defaults to card + PayPal only.
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders($this->centralAuthHeadersFor($user))
            ->postJson('/api/ecommerce/memberships', [
                'plan_id' => $plan->id,
                'payment_method' => 'wallet',
                'pin' => '1234',
                'terms_accepted' => true,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('does not accept Wallet Balance', (string) $response->json('message'));
        $user->refresh();
        $this->assertEquals(500.00, (float) $user->wallet_balance);
    }
}
