<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\PaymentGateway;
use App\Models\User;
use Spatie\Permission\Models\Role;

class PaymentGatewayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function getAdminHeaders(): array
    {
        $admin = User::factory()->create();
        Role::findOrCreate('admin', 'web');
        $admin->assignRole('admin');
        $token = $admin->createToken('admin-test-token')->plainTextToken;

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Central-Auth-Token' => $token,
        ];
    }

    public function test_payment_gateways_index_populates_clean_defaults_without_duplicates(): void
    {
        $headers = $this->getAdminHeaders();
        $response = $this->getJson('/api/v1/admin/payment-gateways', $headers);
        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        $gateways = $response->json('data.gateways');
        $this->assertNotEmpty($gateways);

        $codGateways = collect($gateways)->filter(function ($g) {
            return strtolower($g['name']) === 'cash on delivery' || $g['provider'] === 'cod' || $g['provider'] === 'manual';
        });

        // Ensure exactly ONE Cash on Delivery gateway
        $this->assertCount(1, $codGateways);
        $this->assertEquals('cod', $codGateways->first()['provider']);
    }

    public function test_legacy_manual_and_cod_duplicates_are_cleaned_up_automatically(): void
    {
        // Simulate legacy state with both manual and cod
        PaymentGateway::create([
            'name' => 'Cash on Delivery',
            'display_label' => 'Pay when you receive',
            'provider' => 'manual',
            'is_active' => true,
            'is_test_mode' => false,
            'sort_order' => 4,
        ]);
        PaymentGateway::create([
            'name' => 'Cash on Delivery',
            'display_label' => 'Cash on Delivery (COD)',
            'provider' => 'cod',
            'is_active' => true,
            'is_test_mode' => false,
            'sort_order' => 8,
        ]);

        $headers = $this->getAdminHeaders();
        $response = $this->getJson('/api/v1/admin/payment-gateways', $headers);
        $response->assertStatus(200);

        $gateways = $response->json('data.gateways');
        $codGateways = collect($gateways)->filter(function ($g) {
            return strtolower($g['name']) === 'cash on delivery' || $g['provider'] === 'cod' || $g['provider'] === 'manual';
        });

        $this->assertCount(1, $codGateways);
        $this->assertEquals('cod', $codGateways->first()['provider']);
    }

    public function test_payment_gateway_toggle_and_update_are_dynamic(): void
    {
        $gateway = PaymentGateway::create([
            'name' => 'Custom Gateway',
            'provider' => 'custom_gw',
            'display_label' => 'Custom Label',
            'is_active' => true,
            'is_test_mode' => false,
            'sort_order' => 10,
        ]);

        $headers = $this->getAdminHeaders();

        // Toggle active
        $toggleRes = $this->postJson("/api/v1/admin/payment-gateways/{$gateway->id}/toggle", [
            'field' => 'is_active',
            'value' => false,
        ], $headers);
        $toggleRes->assertStatus(200);
        $this->assertFalse((bool) PaymentGateway::find($gateway->id)->is_active);

        // Update display label
        $updateRes = $this->putJson("/api/v1/admin/payment-gateways/{$gateway->id}", [
            'display_label' => 'Updated Custom Label',
        ], $headers);
        $updateRes->assertStatus(200);
        $this->assertEquals('Updated Custom Label', PaymentGateway::find($gateway->id)->display_label);
    }

    public function test_payment_gateway_deletion_persists_and_is_not_recreated(): void
    {
        $headers = $this->getAdminHeaders();

        // First populate defaults
        $this->getJson('/api/v1/admin/payment-gateways', $headers);
        $this->assertDatabaseHas('payment_gateways', ['provider' => 'square']);

        $square = PaymentGateway::where('provider', 'square')->first();
        $this->assertNotNull($square);

        // Delete square
        $delRes = $this->deleteJson("/api/v1/admin/payment-gateways/{$square->id}", [], $headers);
        $delRes->assertStatus(200);

        $this->assertDatabaseMissing('payment_gateways', ['provider' => 'square']);

        // Subsequent index call should NOT resurrect square
        $indexRes = $this->getJson('/api/v1/admin/payment-gateways', $headers);
        $indexRes->assertStatus(200);

        $gateways = $indexRes->json('data.gateways');
        $providers = collect($gateways)->pluck('provider')->toArray();
        $this->assertNotContains('square', $providers);
    }

    public function test_public_index_returns_only_active_gateways(): void
    {
        PaymentGateway::create([
            'name' => 'Active Gateway',
            'provider' => 'active_one',
            'is_active' => true,
            'is_test_mode' => false,
            'sort_order' => 1,
        ]);
        PaymentGateway::create([
            'name' => 'Inactive Gateway',
            'provider' => 'inactive_one',
            'is_active' => false,
            'is_test_mode' => false,
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/ecommerce/payment-gateways');
        $response->assertStatus(200);

        $gateways = $response->json('data.gateways');
        $providers = collect($gateways)->pluck('provider')->toArray();

        $this->assertContains('active_one', $providers);
        $this->assertNotContains('inactive_one', $providers);
    }
}
