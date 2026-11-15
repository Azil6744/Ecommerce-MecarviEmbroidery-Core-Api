<?php

namespace Tests\Feature;

use App\Models\EcommerceSubscriptionPlan;
use App\Models\MembershipTrialClaim;
use App\Models\User;
use App\Services\MembershipTrialGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One free trial per person: card, device, phone and email are checked across accounts. */
class MembershipTrialGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.secret', null);
        Http::fake(['*/user/memberships' => Http::response(['success' => true, 'data' => ['id' => 501, 'status' => 'trialing']])]);
    }

    public function test_second_account_with_the_same_card_is_refused_the_trial_and_can_join_without_it(): void
    {
        $plan = $this->trialPlan();
        $this->buy($this->customer(), $plan, ['stripe_payment_method' => 'test_card_4242424242424242'])->assertOk();

        $other = $this->customer();
        $refused = $this->buy($other, $plan, ['stripe_payment_method' => 'pm_card_visa'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'trial_not_available');
        $this->assertStringContainsString('this card', $refused->json('message'));
        $this->assertEqualsWithDelta(19.99, (float) $refused->json('data.amount_due_without_trial'), 0.01);
        $this->assertDatabaseHas('membership_trial_claims', ['user_id' => $other->id, 'status' => 'refused', 'matched_rule' => 'card']);
        $this->assertFalse($this->purchaseSentToCentral($other), 'A refused trial creates no membership.');

        // "Join without trial": the card saved on the first attempt pays the first month now.
        $this->buy($other, $plan, ['skip_trial' => true, 'saved_payment_method_id' => $refused->json('data.saved_payment_method_id')])->assertOk();
        $this->assertTrue($this->purchaseSentToCentral($other, fn ($data) => ($data['status'] ?? null) === 'active'
            && (float) ($data['payment_summary']['amount_due_today'] ?? 0) === 19.99));
    }

    public function test_same_device_on_another_account_is_refused(): void
    {
        $plan = $this->trialPlan();
        $device = 'device-' . str_repeat('a', 30);
        MembershipTrialClaim::create(['user_id' => $this->customer()->id, 'status' => 'granted', 'device_hash' => hash('sha256', $device), 'card_fingerprint' => 'fp_someone_else']);

        $this->buy($this->customer(), $plan, ['stripe_payment_method' => 'pm_card_visa'], ['X-Device-Id' => $device])
            ->assertStatus(422)
            ->assertJsonPath('code', 'trial_not_available');
    }

    public function test_same_phone_and_disguised_email_are_matched(): void
    {
        $guard = app(MembershipTrialGuard::class);
        $first = $this->customer(['email' => 'jane.doe@gmail.com', 'phone' => '+1 (470) 555-0101']);
        MembershipTrialClaim::create(['user_id' => $first->id, 'status' => 'granted', 'email_normalized' => MembershipTrialGuard::normalizeEmail($first->email), 'phone_normalized' => MembershipTrialGuard::normalizePhone($first->phone)]);

        $samePhone = $guard->findRepeat($this->customer(), ['phone_normalized' => MembershipTrialGuard::normalizePhone('470-555-0101')]);
        $this->assertSame('phone', $samePhone['rule'] ?? null);

        $aliasEmail = $guard->findRepeat($this->customer(), ['email_normalized' => MembershipTrialGuard::normalizeEmail('Jane.Doe+trial2@GoogleMail.com')]);
        $this->assertSame('email', $aliasEmail['rule'] ?? null);

        $this->assertNull($guard->findRepeat($this->customer(), ['email_normalized' => 'someone.new@gmail.com', 'phone_normalized' => '4705550199']));
    }

    public function test_a_shared_ip_alone_allows_the_trial_but_flags_it_for_review(): void
    {
        $plan = $this->trialPlan();
        MembershipTrialClaim::create(['user_id' => $this->customer()->id, 'status' => 'granted', 'ip_address' => '127.0.0.1', 'card_fingerprint' => 'fp_other_card']);

        $user = $this->customer();
        // Pay by a card no earlier trial used, so only the IP matches.
        $user->paymentMethods()->create(['provider' => 'stripe_test', 'provider_payment_method_id' => 'pm_card_visa', 'card_fingerprint' => 'fp_new_card', 'brand' => 'Visa', 'last4' => '1881', 'is_default' => true]);
        $saved = $user->paymentMethods()->first();

        $this->buy($user, $plan, ['saved_payment_method_id' => $saved->id])->assertOk();

        $claim = MembershipTrialClaim::where('user_id', $user->id)->where('status', 'granted')->first();
        $this->assertNotNull($claim);
        $this->assertTrue($claim->ip_flagged);
        $this->assertSame(501, (int) $claim->central_membership_id);
        $this->assertDatabaseHas('ecommerce_membership_activities', ['user_id' => $user->id, 'action' => 'trial_review']);
    }

    public function test_a_failed_purchase_does_not_use_up_the_trial(): void
    {
        $plan = $this->trialPlan();
        $user = $this->customer();

        // Not an accepted test card: the purchase fails after the trial check.
        $this->buy($user, $plan, ['stripe_payment_method' => 'pm_card_chargeDeclined'])->assertStatus(422);

        $this->assertSame(0, MembershipTrialClaim::where('user_id', $user->id)->where('status', 'granted')->count());
    }

    private function buy(User $user, EcommerceSubscriptionPlan $plan, array $extra, array $headers = [])
    {
        $token = $user->createToken('trial-guard-test')->plainTextToken;

        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token] + $headers)
            ->postJson('/api/ecommerce/memberships', array_merge([
                'plan_id' => $plan->id,
                'payment_method' => 'stripe',
                'pin' => '1234',
                'terms_accepted' => true,
            ], $extra));
    }

    private function purchaseSentToCentral(User $user, ?callable $where = null): bool
    {
        return Http::recorded(function ($request) use ($user, $where) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/user/memberships')) {
                return false;
            }
            $sentBy = $request->header('Authorization')[0] ?? '';
            $ownToken = $user->tokens()->exists();

            return $ownToken && str_contains($sentBy, '|') && (! $where || $where($request->data()))
                && $user->tokens()->get()->contains(fn ($t) => str_starts_with(substr($sentBy, 7), $t->id . '|'));
        })->isNotEmpty();
    }

    private function trialPlan(): EcommerceSubscriptionPlan
    {
        return EcommerceSubscriptionPlan::create([
            'name' => 'Trial Guard Plan',
            'internal_code' => 'TRIAL-GUARD-' . uniqid(),
            'price' => 19.99,
            'billing_cycle' => 'Monthly',
            'coverage_type' => 'individual_site',
            'applicable_site' => 'mecarvi-embroidery',
            'status' => 'Active',
            'trial_available' => true,
            'trial_duration_days' => 7,
        ]);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
