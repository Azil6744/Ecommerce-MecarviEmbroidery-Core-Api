<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePinAuthorization;
use App\Models\EcommercePaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cards saved from the Payment Methods page: only Stripe-confirmed cards can be charged. */
class PaymentMethodStripeCardTest extends TestCase
{
    use DatabaseTransactions;

    private const MOCK_STRIPE_SECRET = 'sk_' . 'test_' . 'mock_unit_test_key_cards_12345';

    protected function setUp(): void
    {
        parent::setUp();
        // The PIN step is covered by its own tests; here it would only need a real Central PIN.
        $this->withoutMiddleware(EnsurePinAuthorization::class);
    }

    public function test_test_mode_card_is_saved_as_chargeable(): void
    {
        Config::set('services.stripe.secret', null);
        $user = User::factory()->create();

        $this->save($user, ['stripe_payment_method' => 'test_card_4242424242424242', 'cardholder_name' => 'Jane Buyer', 'isDefault' => true])
            ->assertCreated()
            ->assertJsonPath('data.last4', '4242')
            ->assertJsonPath('data.chargeable', true);

        $card = EcommercePaymentMethod::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('stripe_test', $card->provider);
        $this->assertSame('pm_card_visa', $card->provider_payment_method_id);
        $this->assertSame('Jane Buyer', $card->cardholder_name);
    }

    public function test_live_card_must_belong_to_this_customer(): void
    {
        Config::set('services.stripe.secret', self::MOCK_STRIPE_SECRET);
        $user = User::factory()->create();
        Http::fake([
            'api.stripe.com/v1/payment_methods/pm_mine' => Http::response(['id' => 'pm_mine', 'type' => 'card', 'customer' => 'cus_mine', 'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 1, 'exp_year' => 2032, 'fingerprint' => 'fp_1']]),
            'api.stripe.com/v1/customers/cus_mine' => Http::response(['id' => 'cus_mine', 'metadata' => ['user_id' => (string) $user->id]]),
            'api.stripe.com/v1/payment_methods/pm_theirs' => Http::response(['id' => 'pm_theirs', 'type' => 'card', 'customer' => 'cus_theirs', 'card' => ['brand' => 'visa', 'last4' => '2222']]),
            'api.stripe.com/v1/customers/cus_theirs' => Http::response(['id' => 'cus_theirs', 'metadata' => ['user_id' => '999999999']]),
        ]);

        $this->save($user, ['stripe_payment_method' => 'pm_mine'])->assertCreated()->assertJsonPath('data.chargeable', true);
        $this->save($user, ['stripe_payment_method' => 'pm_theirs'])->assertStatus(422);

        $this->assertSame(1, EcommercePaymentMethod::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('ecommerce_payment_methods', ['user_id' => $user->id, 'provider_payment_method_id' => 'pm_mine', 'card_fingerprint' => 'fp_1']);
    }

    public function test_a_card_without_stripe_cannot_carry_someone_elses_stripe_ids(): void
    {
        $user = User::factory()->create();

        $this->save($user, [
            'brand' => 'Visa',
            'last4' => '4242',
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_victim',
            'provider_payment_method_id' => 'pm_victim',
        ])->assertCreated()->assertJsonPath('data.chargeable', false);

        $card = EcommercePaymentMethod::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('manual', $card->provider);
        $this->assertNull($card->provider_customer_id);
        $this->assertNull($card->provider_payment_method_id);
    }

    public function test_saving_a_second_card_keeps_the_existing_default_unless_asked(): void
    {
        Config::set('services.stripe.secret', null);
        $user = User::factory()->create();
        $first = $user->paymentMethods()->create(['provider' => 'manual', 'brand' => 'Visa', 'last4' => '1881', 'is_default' => true]);

        $this->save($user, ['stripe_payment_method' => 'pm_card_visa', 'isDefault' => false])->assertCreated()->assertJsonPath('data.isDefault', false);

        $this->assertTrue((bool) $first->fresh()->is_default);
    }

    private function save(User $user, array $data)
    {
        $token = $user->createToken('payment-method-test')->plainTextToken;

        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token])
            ->postJson('/api/ecommerce/payment-methods', $data);
    }
}
