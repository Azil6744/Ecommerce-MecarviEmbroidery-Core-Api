<?php

namespace Tests\Feature;

use App\Models\EcommercePaymentMethod;
use App\Models\EcommerceSubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Card membership purchases: a card is always collected and saved, and renewals can charge it. */
class MembershipCardPurchaseTest extends TestCase
{
    use DatabaseTransactions;

    private const MOCK_STRIPE_SECRET = 'sk_' . 'test_' . 'mock_unit_test_key_cards_12345';

    public function test_trial_purchase_with_nothing_due_still_requires_a_card(): void
    {
        Config::set('services.stripe.secret', null);
        Http::fake(['*/user/memberships' => Http::response(['success' => true, 'data' => ['id' => 1]])]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->withHeaders($this->tokenHeaders($user))
            ->postJson('/api/ecommerce/memberships', ['plan_id' => $this->plan(trial: true)->id, 'payment_method' => 'stripe', 'pin' => '1234', 'terms_accepted' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stripe_payment_method');

        Http::assertNotSent(fn ($request) => $this->isPurchaseCall($request));
    }

    public function test_trial_purchase_saves_the_card_and_sends_it_to_central(): void
    {
        Config::set('services.stripe.secret', null);
        Http::fake(['*/user/memberships' => Http::response(['success' => true, 'data' => ['id' => 1, 'status' => 'trialing']])]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->withHeaders($this->tokenHeaders($user))
            ->postJson('/api/ecommerce/memberships', [
                'plan_id' => $this->plan(trial: true)->id,
                'payment_method' => 'stripe',
                'stripe_payment_method' => 'test_card_4242424242424242',
                'pin' => '1234',
                'terms_accepted' => true,
            ])
            ->assertOk();

        $saved = EcommercePaymentMethod::where('user_id', $user->id)->first();
        $this->assertNotNull($saved);
        $this->assertSame('stripe_test', $saved->provider);
        $this->assertSame('4242', $saved->last4);
        $this->assertTrue((bool) $saved->is_default);

        Http::assertSent(function ($request) {
            if (! $this->isPurchaseCall($request)) {
                return false;
            }

            return $request['status'] === 'trialing'
                && $request['payment_summary']['payment_processor'] === 'stripe_test'
                && $request['metadata']['payment_card']['last4'] === '4242'
                && $request['metadata']['payment_card']['payment_method'] === 'pm_card_visa';
        });
    }

    public function test_live_purchase_charges_the_confirmed_card_through_stripe(): void
    {
        Config::set('services.stripe.secret', self::MOCK_STRIPE_SECRET);
        $user = User::factory()->create();
        Http::fake([
            'api.stripe.com/v1/payment_methods/pm_live_1' => Http::response(['id' => 'pm_live_1', 'type' => 'card', 'customer' => 'cus_9', 'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 4, 'exp_year' => 2031]]),
            'api.stripe.com/v1/customers/cus_9' => Http::response(['id' => 'cus_9', 'metadata' => ['user_id' => (string) $user->id]]),
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_live_1', 'status' => 'succeeded']),
            '*/user/memberships' => Http::response(['success' => true, 'data' => ['id' => 2, 'status' => 'active']]),
        ]);

        $this->actingAs($user, 'sanctum')->withHeaders($this->tokenHeaders($user))
            ->postJson('/api/ecommerce/memberships', [
                'plan_id' => $this->plan()->id,
                'payment_method' => 'stripe',
                'stripe_payment_method' => 'pm_live_1',
                'pin' => '1234',
                'terms_accepted' => true,
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/payment_intents')
            && $request['amount'] == 1999
            && $request['customer'] === 'cus_9'
            && $request['payment_method'] === 'pm_live_1'
            && $request->hasHeader('Idempotency-Key'));
        Http::assertSent(fn ($request) => $this->isPurchaseCall($request)
            && $request['transaction_reference'] === 'pi_live_1'
            && $request['metadata']['payment_card']['customer'] === 'cus_9');
        $this->assertDatabaseHas('ecommerce_payment_methods', ['user_id' => $user->id, 'provider' => 'stripe', 'provider_payment_method_id' => 'pm_live_1', 'last4' => '1111']);
    }

    public function test_a_card_saved_on_another_stripe_customer_is_refused(): void
    {
        Config::set('services.stripe.secret', self::MOCK_STRIPE_SECRET);
        $user = User::factory()->create();
        Http::fake([
            'api.stripe.com/v1/payment_methods/pm_other' => Http::response(['id' => 'pm_other', 'type' => 'card', 'customer' => 'cus_other', 'card' => ['brand' => 'visa', 'last4' => '0000']]),
            'api.stripe.com/v1/customers/cus_other' => Http::response(['id' => 'cus_other', 'metadata' => ['user_id' => '999999999']]),
        ]);

        $this->actingAs($user, 'sanctum')->withHeaders($this->tokenHeaders($user))
            ->postJson('/api/ecommerce/memberships', ['plan_id' => $this->plan()->id, 'payment_method' => 'stripe', 'stripe_payment_method' => 'pm_other', 'pin' => '1234', 'terms_accepted' => true])
            ->assertStatus(422);

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/payment_intents'));
    }

    public function test_free_plan_needs_no_card(): void
    {
        Config::set('services.stripe.secret', null);
        Http::fake(['*/user/memberships' => Http::response(['success' => true, 'data' => ['id' => 3]])]);
        $user = User::factory()->create();
        $plan = EcommerceSubscriptionPlan::create([
            'name' => 'Free Test Plan', 'internal_code' => 'CARD-TEST-FREE', 'price' => 0, 'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site', 'applicable_site' => 'mecarvi-embroidery', 'status' => 'Active',
        ]);

        $this->actingAs($user, 'sanctum')->withHeaders($this->tokenHeaders($user))
            ->postJson('/api/ecommerce/memberships', ['plan_id' => $plan->id, 'payment_method' => 'stripe', 'pin' => '1234', 'terms_accepted' => true])
            ->assertOk();

        $this->assertSame(0, EcommercePaymentMethod::where('user_id', $user->id)->count());
    }

    public function test_refund_returns_a_card_payment_and_skips_test_payments(): void
    {
        $cards = app(\App\Services\MembershipCardService::class);

        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'])]);

        Config::set('services.stripe.secret', null);
        $this->assertTrue($cards->refund('test_stripe_abc', 10.00), 'A test-mode payment moved no money.');
        Http::assertNothingSent();

        Config::set('services.stripe.secret', self::MOCK_STRIPE_SECRET);
        $this->assertTrue($cards->refund('pi_live_9', 10.00));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/refunds')
            && $request['payment_intent'] === 'pi_live_9'
            && $request['amount'] == 1000
            && $request->hasHeader('Idempotency-Key'));
    }

    public function test_internal_renewal_charge_needs_the_shared_secret(): void
    {
        $this->postJson('/api/ecommerce/internal/memberships/charge-card', ['membership_id' => 1, 'email' => 'a@example.test', 'amount' => 9.99, 'idempotency_key' => 'k'])
            ->assertStatus(401);
    }

    public function test_internal_renewal_charge_uses_the_members_default_card(): void
    {
        Config::set('services.stripe.secret', self::MOCK_STRIPE_SECRET);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_renew_1', 'status' => 'succeeded'])]);
        $user = User::factory()->create();
        $user->paymentMethods()->create(['provider' => 'stripe', 'provider_customer_id' => 'cus_5', 'provider_payment_method_id' => 'pm_default', 'brand' => 'Visa', 'last4' => '4444', 'is_default' => true]);

        $this->withHeaders(['X-Internal-Notification-Secret' => config('services.internal_notifications.secret')])
            ->postJson('/api/ecommerce/internal/memberships/charge-card', [
                'membership_id' => 77,
                'email' => $user->email,
                'amount' => 9.99,
                'idempotency_key' => 'mem_renew_77_2026-12-01_2026-12-01',
                'payment_card' => ['mode' => 'stripe', 'customer' => 'cus_old', 'payment_method' => 'pm_signup'],
            ])
            ->assertOk()
            ->assertJson(['paid' => true, 'reference' => 'pi_renew_1']);

        Http::assertSent(fn ($request) => $request['payment_method'] === 'pm_default'
            && $request['customer'] === 'cus_5'
            && $request['amount'] == 999
            && $request['off_session'] === 'true'
            && $request->header('Idempotency-Key')[0] === 'mem_renew_77_2026-12-01_2026-12-01');
    }

    public function test_internal_renewal_charge_reports_a_decline(): void
    {
        Config::set('services.stripe.secret', self::MOCK_STRIPE_SECRET);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['message' => 'Your card was declined.', 'decline_code' => 'generic_decline']], 402)]);

        $this->withHeaders(['X-Internal-Notification-Secret' => config('services.internal_notifications.secret')])
            ->postJson('/api/ecommerce/internal/memberships/charge-card', [
                'membership_id' => 78,
                'email' => 'nobody@example.test',
                'amount' => 9.99,
                'idempotency_key' => 'k2',
                'payment_card' => ['mode' => 'stripe', 'customer' => 'cus_1', 'payment_method' => 'pm_signup'],
            ])
            ->assertOk()
            ->assertJson(['paid' => false, 'reason' => 'Your card was declined.']);
    }

    /** The purchase itself (Core also reads the member's history from the same Central path). */
    private function isPurchaseCall($request): bool
    {
        return $request->method() === 'POST' && str_contains($request->url(), '/user/memberships');
    }

    private function plan(bool $trial = false): EcommerceSubscriptionPlan
    {
        return EcommerceSubscriptionPlan::create([
            'name' => 'Card Test Plan',
            'internal_code' => 'CARD-TEST-' . ($trial ? 'TRIAL' : 'PAID'),
            'price' => 19.99,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site',
            'applicable_site' => 'mecarvi-embroidery',
            'status' => 'Active',
            'trial_available' => $trial,
            'trial_duration_days' => $trial ? 7 : 0,
        ]);
    }

    private function tokenHeaders(User $user): array
    {
        $token = $user->createToken('feature-test')->plainTextToken;

        return ['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token];
    }
}
