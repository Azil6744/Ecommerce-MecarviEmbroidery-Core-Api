<?php

namespace Tests\Feature;

use App\Models\PaymentGateway;
use App\Services\StripeKeys;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StripeKeysTest extends TestCase
{
    use DatabaseTransactions;

    private const SK = 'sk_' . 'test_' . 'mock_unit_test_secret_key_12345';
    private const PK = 'pk_' . 'test_' . 'mock_unit_test_pub_key_12345';
    private const PLACEHOLDER_SK = 'sk_' . 'live_' . 'mock_xxxx_secret_key_12345';
    private const PLACEHOLDER_PK = 'pk_' . 'live_' . 'mock_xxxx_pub_key_12345';
    private const OLD_SK = 'sk_' . 'live_' . 'old_disabled_key_for_test_123';
    private const OLD_PK = 'pk_' . 'live_' . 'old_disabled_key_for_test_123';
    private const SAMPLE_SK = 'sk_' . 'test_' . 'sample_stripe_secret_key';
    private const SAMPLE_PK = 'pk_' . 'test_' . 'sample_stripe_key';
    private const LIVE_PK = 'pk_' . 'live_' . 'mock_live_pub_key_12345678';
    private const ONLY_SK = 'sk_' . 'test_' . 'mock_only_secret_no_pub_123';
    private const RK = 'rk_' . 'test_' . 'mock_restricted_key_for_test_123';

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::where('provider', 'stripe')->delete();
        Config::set('services.stripe.secret', self::PLACEHOLDER_SK);
        Config::set('services.stripe.key', self::PLACEHOLDER_PK);
    }

    public function test_an_older_inactive_row_does_not_hide_the_active_test_keys(): void
    {
        $this->gateway(['is_active' => false, 'secret_key' => self::OLD_SK, 'public_key' => self::OLD_PK]);
        $active = $this->gateway(['secret_key' => self::SK, 'public_key' => self::PK]);

        $keys = StripeKeys::resolve();
        $this->assertSame(self::SK, $keys['secret']);
        $this->assertSame(self::PK, $keys['publishable']);
        $this->assertSame('test', $keys['mode']);
        $this->assertSame("payment_gateway #{$active->id}", $keys['source']);
    }

    public function test_placeholder_keys_are_never_used(): void
    {
        $this->gateway(['secret_key' => self::SAMPLE_SK, 'public_key' => self::SAMPLE_PK]);

        // The seeded sample row and the "sk_live_xxxx" .env value are both placeholders.
        $this->assertSame('', StripeKeys::secret());
        $this->assertSame('none', StripeKeys::resolve()['source']);
    }

    public function test_a_test_and_live_mix_is_skipped(): void
    {
        $this->gateway(['secret_key' => self::SK, 'public_key' => self::LIVE_PK]);

        $this->assertSame('', StripeKeys::secret());
    }

    public function test_env_keys_are_used_when_no_gateway_row_is_usable(): void
    {
        Config::set('services.stripe.secret', self::SK);
        Config::set('services.stripe.key', self::PK);

        $this->assertSame('.env', StripeKeys::resolve()['source']);
        $this->assertSame(self::SK, StripeKeys::secret());
    }

    public function test_a_complete_key_pair_wins_over_a_secret_key_alone(): void
    {
        $this->gateway(['secret_key' => self::ONLY_SK]);
        $complete = $this->gateway(['secret_key' => self::SK, 'public_key' => self::PK]);
        $complete->forceFill(['updated_at' => now()->subDay()])->save();

        $this->assertSame(self::PK, StripeKeys::publishable());
    }

    public function test_restricted_keys_are_accepted(): void
    {
        $this->gateway(['secret_key' => self::RK, 'public_key' => self::PK]);

        $this->assertSame(self::RK, StripeKeys::secret());
        $this->assertSame('test', StripeKeys::resolve()['mode']);
    }

    public function test_card_setup_reports_why_cards_are_unavailable(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $user = \App\Models\User::factory()->create();

        $setup = app(\App\Services\MembershipCardService::class)->setup($user);
        $this->assertSame('unavailable', $setup['mode']);
        $this->assertSame('no_usable_stripe_keys', $setup['reason']);

        $this->gateway(['secret_key' => self::SK]);
        $setup = app(\App\Services\MembershipCardService::class)->setup($user);
        $this->assertSame('unavailable', $setup['mode']);
        $this->assertSame('publishable_key_missing', $setup['reason']);
    }

    private function gateway(array $attributes): PaymentGateway
    {
        return PaymentGateway::create(array_merge([
            'name' => 'Stripe',
            'provider' => 'stripe',
            'is_active' => true,
            'is_test_mode' => true,
        ], $attributes));
    }
}
