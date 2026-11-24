<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCardOrder;
use App\Models\EcommerceGiftCardSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panels' purchase form reads GET /gift-card-settings and the order
 * endpoint enforces the same Admin → Gift Cards Settings rules.
 */
class GiftCardPurchaseRulesTest extends TestCase
{
    use RefreshDatabase;

    private function saveSettings(array $overrides): void
    {
        EcommerceGiftCardSetting::query()->delete();
        EcommerceGiftCardSetting::create(['settings' => $overrides]);
    }

    private function headersFor(User $user): array
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $user->role, 'guard_name' => 'web']);
        $user->assignRole($user->role);
        $token = $user->createToken('t')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'X-Central-Auth-Token' => $token];
    }

    private function order(array $h, array $overrides = [])
    {
        return $this->withHeaders($h)->postJson('/api/ecommerce/gift-card-orders', array_merge([
            'recipient_name' => 'Aisha Grant',
            'recipient_email' => 'aisha@example.com',
            'giftcard_amount' => 50,
            'delivery_method' => 'digital',
        ], $overrides));
    }

    public function test_public_settings_reflect_admin_settings(): void
    {
        $this->saveSettings([
            'egift' => ['enabled' => true, 'preset_values' => [20, 40, 80], 'allow_custom' => false, 'min_custom' => 5, 'max_custom' => 500],
            'physical' => ['enabled' => false, 'preset_values' => [10], 'allow_custom' => true, 'min_custom' => 5, 'max_custom' => 500],
        ]);

        $this->getJson('/api/ecommerce/gift-card-settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.digital.preset_values', [20, 40, 80])
            ->assertJsonPath('data.digital.allow_custom', false)
            ->assertJsonPath('data.physical.enabled', false);
    }

    public function test_order_must_use_an_allowed_amount_and_type(): void
    {
        $this->saveSettings([
            'egift' => ['enabled' => true, 'preset_values' => [25, 50], 'allow_custom' => true, 'min_custom' => 10, 'max_custom' => 200],
            'physical' => ['enabled' => false, 'preset_values' => [25], 'allow_custom' => false, 'min_custom' => 5, 'max_custom' => 500],
        ]);
        $h = $this->headersFor(User::factory()->create(['role' => 'customer']));

        $this->order($h, ['giftcard_amount' => 50])->assertCreated();
        $this->order($h, ['giftcard_amount' => 75])->assertCreated();
        $this->order($h, ['giftcard_amount' => 500])->assertStatus(422)->assertJsonValidationErrors(['giftcard_amount']);
        $this->order($h, ['giftcard_amount' => 5])->assertStatus(422);
        $this->order($h, [
            'delivery_method' => 'physical', 'giftcard_amount' => 25, 'address_line1' => '1 Main', 'city' => 'Orlando',
            'state' => 'FL', 'zip_code' => '32801', 'country' => 'US',
        ])->assertStatus(422);
    }

    public function test_presets_only_when_custom_amounts_are_off(): void
    {
        $this->saveSettings(['egift' => ['enabled' => true, 'preset_values' => [25, 50], 'allow_custom' => false, 'min_custom' => 5, 'max_custom' => 500]]);
        $h = $this->headersFor(User::factory()->create(['role' => 'customer']));

        $this->order($h, ['giftcard_amount' => 25])->assertCreated();
        $this->order($h, ['giftcard_amount' => 30])->assertStatus(422);
    }

    public function test_module_off_blocks_purchases(): void
    {
        $this->saveSettings(['enable_module' => false]);
        $h = $this->headersFor(User::factory()->create(['role' => 'customer']));

        $this->getJson('/api/ecommerce/gift-card-settings')->assertJsonPath('data.enabled', false);
        $this->order($h)->assertStatus(422);
    }

    public function test_daily_limit_counts_paid_orders_only(): void
    {
        $this->saveSettings(['daily_purchase_limit' => 1]);
        $user = User::factory()->create(['role' => 'customer']);
        $h = $this->headersFor($user);

        // An unpaid (abandoned) order doesn't use the allowance.
        $this->order($h)->assertCreated();
        $this->order($h)->assertCreated();

        EcommerceGiftCardOrder::where('customer_id', $user->id)->first()->update(['payment_status' => 'paid', 'order_status' => 'Pending Gift Card Issue']);
        $this->order($h)->assertStatus(422)->assertJsonFragment(['success' => false]);
    }
}
