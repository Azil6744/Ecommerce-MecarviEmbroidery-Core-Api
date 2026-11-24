<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin "Issue Gift Card" popup + Gift Card Settings: settings drive the code
 * format, expiry and allowed amounts; physical cards require a shipping address.
 */
class AdminIssueGiftCardTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin->assignRole('super_admin');
        $token = $admin->createToken('feature-test')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'X-Central-Auth-Token' => $token];
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'sender_type' => 'customer',
            'sender_name' => 'John Smith',
            'sender_email' => 'john@example.com',
            'recipient_name' => 'Emma Davis',
            'recipient_email' => 'emma@example.com',
            'giftcard_type' => 'egift',
            'amount' => 100,
            'currency' => 'USD',
            'quantity' => 1,
            'code_gen_method' => 'auto',
            'issue_type' => 'Complimentary',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_settings_round_trip_with_new_shape(): void
    {
        $headers = $this->adminHeaders();

        $this->withHeaders($headers)->getJson('/api/v1/admin/gift-card-settings')
            ->assertOk()
            ->assertJsonPath('data.prefix', 'GFT')
            ->assertJsonPath('data.code_length', 15)
            ->assertJsonPath('data.egift.enabled', true);

        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-card-settings', [
            'prefix' => 'mc',
            'code_length' => 14,
            'code_format' => 'Numeric',
            'expiry_months' => 6,
            'egift' => ['enabled' => true, 'preset_values' => [20, 40], 'allow_custom' => false, 'min_custom' => 5, 'max_custom' => 100],
            'notification_rules' => [
                ['id' => 'a', 'name' => 'Only Rule', 'trigger_condition' => 'When used', 'notify_when' => 'Any', 'notification_method' => 'Email', 'status' => 'Inactive'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.prefix', 'MC')
            ->assertJsonPath('data.egift.preset_values', [20, 40])
            ->assertJsonCount(1, 'data.notification_rules');

        $this->withHeaders($headers)->getJson('/api/v1/admin/gift-card-settings')
            ->assertJsonPath('data.code_format', 'Numeric')
            ->assertJsonPath('data.notification_rules.0.status', 'Inactive');
    }

    public function test_rejects_min_custom_above_max(): void
    {
        $this->withHeaders($this->adminHeaders())->postJson('/api/v1/admin/gift-card-settings', [
            'physical' => ['min_custom' => 600, 'max_custom' => 500, 'allow_custom' => true],
        ])->assertStatus(422);
    }

    public function test_issue_uses_settings_code_format_and_default_expiry(): void
    {
        $headers = $this->adminHeaders();
        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-card-settings', [
            'prefix' => 'GFT', 'code_length' => 15, 'code_format' => 'Numeric', 'expiry_enabled' => true, 'expiry_months' => 6,
        ])->assertOk();

        $res = $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload())->assertCreated();

        $code = $res->json('data.code');
        $this->assertMatchesRegularExpression('/^GFT-\d{4}-\d{4}-\d{4}$/', $code);
        $card = EcommerceGiftCard::where('code', $code)->firstOrFail();
        $this->assertSame(now()->addMonths(6)->toDateString(), $card->expires_at->toDateString());
        $this->assertSame('Complimentary', $card->issuance_type);
        $this->assertSame('Manual', $card->issue_type);
        $this->assertSame('egift', $card->giftcard_type);
        $this->assertSame('customer', $card->sender_type);
        $this->assertNull($card->shipping_address_line1);
    }

    public function test_physical_card_requires_and_stores_shipping_address(): void
    {
        $headers = $this->adminHeaders();

        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload(['giftcard_type' => 'physical', 'recipient_email' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_name', 'shipping_address_line1', 'shipping_city', 'shipping_state', 'shipping_zip']);

        $res = $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload([
            'giftcard_type' => 'physical',
            'recipient_email' => null,
            'shipping_name' => 'Emma Davis',
            'shipping_address_line1' => '12 Peach St',
            'shipping_address_line2' => 'Apt 4',
            'shipping_city' => 'Atlanta',
            'shipping_state' => 'GA',
            'shipping_zip' => '30301',
        ]))->assertCreated();

        $res->assertJsonPath('data.giftcard_type', 'physical')
            ->assertJsonPath('data.delivery_type', 'Physical')
            ->assertJsonPath('data.shipping_address.city', 'Atlanta');
    }

    public function test_egift_requires_recipient_email(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/admin/gift-cards', $this->basePayload(['recipient_email' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_email']);
    }

    public function test_amount_must_respect_preset_and_custom_range(): void
    {
        $headers = $this->adminHeaders();
        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-card-settings', [
            'egift' => ['enabled' => true, 'preset_values' => [25, 50], 'allow_custom' => true, 'min_custom' => 10, 'max_custom' => 200],
        ])->assertOk();

        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload(['amount' => 500]))->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload(['amount' => 75]))->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-card-settings', [
            'egift' => ['allow_custom' => false],
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload(['amount' => 75]))->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload(['amount' => 50]))->assertCreated();
    }

    public function test_custom_code_must_be_unique(): void
    {
        $headers = $this->adminHeaders();
        $payload = $this->basePayload(['code_gen_method' => 'custom', 'custom_code' => 'SPRING-2027']);

        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $payload)->assertCreated()->assertJsonPath('data.code', 'SPRING-2027');
        $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $payload)->assertStatus(422);
        $this->assertSame(1, EcommerceGiftCard::where('code', 'SPRING-2027')->count());
    }

    public function test_quantity_issues_multiple_unique_cards(): void
    {
        $res = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/admin/gift-cards', $this->basePayload(['quantity' => 3]))
            ->assertCreated();

        $codes = collect($res->json('cards'))->pluck('code');
        $this->assertCount(3, $codes->unique());
    }

    public function test_original_value_and_balance_cannot_be_edited_and_voided_cards_stay_voided(): void
    {
        $headers = $this->adminHeaders();
        $id = $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload())->assertCreated()->json('data.id');

        $this->withHeaders($headers)->putJson("/api/v1/admin/gift-cards/{$id}", ['initial_balance' => 999])->assertStatus(422);
        $this->withHeaders($headers)->putJson("/api/v1/admin/gift-cards/{$id}", ['current_balance' => 999])->assertStatus(422);
        $this->withHeaders($headers)->putJson("/api/v1/admin/gift-cards/{$id}", ['recipient_name' => 'New Name', 'initial_balance' => 100])->assertOk();
        $this->assertSame('100.00', (string) EcommerceGiftCard::find($id)->initial_balance);

        EcommerceGiftCard::whereKey($id)->update(['status' => 'voided']);
        $this->withHeaders($headers)->postJson("/api/v1/admin/gift-cards/{$id}/enable")->assertStatus(422);
        $this->withHeaders($headers)->putJson("/api/v1/admin/gift-cards/{$id}", ['status' => 'active'])->assertStatus(422);
        $this->assertSame('voided', EcommerceGiftCard::find($id)->status);
    }

    public function test_internal_notes_are_hidden_from_the_card_holder(): void
    {
        $holder = User::factory()->create(['role' => 'customer', 'email' => 'emma@example.com']);
        $this->withHeaders($this->adminHeaders())->postJson('/api/v1/admin/gift-cards', $this->basePayload(['internal_notes' => 'Goodwill after late order']))->assertCreated();

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $holder->assignRole('customer');
        $token = $holder->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Central-Auth-Token' => $token])
            ->getJson('/api/ecommerce/gift-cards')
            ->assertOk()
            ->assertJsonPath('data.0.internal_notes', null);
    }

    public function test_scheduled_egift_is_not_sent_until_due(): void
    {
        $headers = $this->adminHeaders();
        $res = $this->withHeaders($headers)->postJson('/api/v1/admin/gift-cards', $this->basePayload([
            'scheduled_for' => now()->addDays(3)->toDateString(),
        ]))->assertCreated();

        $card = EcommerceGiftCard::findOrFail($res->json('data.id'));
        $this->assertSame('scheduled', $card->status);

        $this->artisan('ecommerce:send-scheduled-gift-cards')->assertSuccessful();
        $this->assertSame('scheduled', $card->fresh()->status);

        $card->update(['scheduled_for' => now()->subMinute()]);
        $this->artisan('ecommerce:send-scheduled-gift-cards')->assertSuccessful();
        $this->assertContains($card->fresh()->status, ['delivered', 'active']);
    }
}
