<?php

namespace Tests\Feature;

use App\Models\EcommerceSubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MembershipPayPalUpgradeTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private EcommerceSubscriptionPlan $gold;
    private EcommerceSubscriptionPlan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paypal.client_id' => null, 'services.paypal.secret' => null]); // sandbox simulation

        $this->user = User::factory()->create(['email' => 'paypal-member@example.com']);
        $methods = ['payment_methods' => ['credit_card', 'paypal']];
        $this->gold = EcommerceSubscriptionPlan::create([
            'name' => 'PP Gold', 'internal_code' => 'PP-GOLD', 'price' => 10.00, 'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site', 'applicable_site' => 'mecarvi-embroidery', 'status' => 'Active', 'benefit_config' => $methods,
        ]);
        $this->pro = EcommerceSubscriptionPlan::create([
            'name' => 'PP Pro', 'internal_code' => 'PP-PRO', 'price' => 30.00, 'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site', 'applicable_site' => 'mecarvi-embroidery', 'status' => 'Active', 'benefit_config' => $methods,
        ]);

        $row = [
            'id' => 501,
            'plan_id' => $this->gold->id,
            'plan_name' => 'PP Gold',
            'price' => 10.00,
            'status' => 'active',
            'billing_cycle' => 'Monthly',
            'account_type' => 'personal',
            'current_period_start' => now()->subDays(15)->toDateTimeString(),
            'current_period_end' => now()->addDays(16)->toDateTimeString(),
            'next_billing_date' => now()->addDays(16)->toDateString(),
            'auto_renew' => true,
            'user' => ['email' => $this->user->email, 'name' => $this->user->name],
        ];
        Http::fake([
            '*/v1/internal/admin/memberships/update' => Http::response(['success' => true, 'data' => array_merge($row, ['plan_id' => $this->pro->id, 'plan_name' => 'PP Pro', 'price' => 30.00])], 200),
            '*/user/memberships*' => Http::response(['success' => true, 'data' => [$row]], 200),
            '*/v1/internal/admin/memberships*' => Http::response(['success' => true, 'data' => [$row]], 200),
            '*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    private function headers(): array
    {
        $token = $this->user->createToken('paypal-test')->plainTextToken;

        return ['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token, 'Origin' => 'http://localhost:3001'];
    }

    private function centralUpdates(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/memberships/update'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_paypal_upgrade_redirects_then_captures_once(): void
    {
        $headers = $this->headers();
        $start = $this->withHeaders($headers)->postJson('/api/ecommerce/memberships/501/upgrade', [
            'plan_id' => $this->pro->id,
            'payment_method' => 'paypal',
            'return_url' => 'http://localhost:3001/embroidery/memberships?paypal=return',
        ]);

        $start->assertOk()->assertJson(['requires_redirect' => true]);
        $this->assertCount(0, $this->centralUpdates(), 'Nothing may change before PayPal approval.');

        parse_str((string) parse_url($start->json('redirect_url'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['paymentId'] ?? null);

        $finish = $this->withHeaders($headers)->postJson('/api/ecommerce/memberships/501/upgrade', [
            'plan_id' => $this->pro->id,
            'payment_method' => 'paypal',
            'paypal_payment_id' => $query['paymentId'],
            'paypal_payer_id' => $query['PayerID'],
        ]);
        $finish->assertOk()->assertJson(['success' => true]);

        $updates = $this->centralUpdates();
        $this->assertNotEmpty($updates);
        $this->assertSame('paypal', collect($updates)->pluck('payment_method')->filter()->first());

        // Re-using the same approval must not charge or change anything again.
        $replay = $this->withHeaders($headers)->postJson('/api/ecommerce/memberships/501/upgrade', [
            'plan_id' => $this->pro->id,
            'payment_method' => 'paypal',
            'paypal_payment_id' => $query['paymentId'],
            'paypal_payer_id' => $query['PayerID'],
        ]);
        $replay->assertStatus(422);
    }

    public function test_return_url_must_point_back_to_the_requesting_site(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/ecommerce/memberships/501/upgrade', [
            'plan_id' => $this->pro->id,
            'payment_method' => 'paypal',
            'return_url' => 'https://evil.example.com/steal',
        ])->assertStatus(422);
    }

    public function test_approval_cannot_be_used_for_a_different_plan(): void
    {
        $headers = $this->headers();
        $start = $this->withHeaders($headers)->postJson('/api/ecommerce/memberships/501/upgrade', [
            'plan_id' => $this->pro->id,
            'payment_method' => 'paypal',
            'return_url' => 'http://localhost:3001/embroidery/memberships',
        ])->assertOk();
        parse_str((string) parse_url($start->json('redirect_url'), PHP_URL_QUERY), $query);

        $other = EcommerceSubscriptionPlan::create([
            'name' => 'PP Max', 'internal_code' => 'PP-MAX', 'price' => 30.00, 'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site', 'applicable_site' => 'mecarvi-embroidery', 'status' => 'Active',
            'benefit_config' => ['payment_methods' => ['paypal']],
        ]);

        $this->withHeaders($headers)->postJson('/api/ecommerce/memberships/501/upgrade', [
            'plan_id' => $other->id,
            'payment_method' => 'paypal',
            'paypal_payment_id' => $query['paymentId'],
            'paypal_payer_id' => $query['PayerID'],
        ])->assertStatus(422);

        $this->assertCount(0, $this->centralUpdates());
    }
}
