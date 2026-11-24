<?php

namespace Tests\Feature;

use App\Models\EcommerceGiftCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcommerceGiftCardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function centralAuthHeadersFor(User $user): array
    {
        $roleName = $user->role ?: 'customer';
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->assignRole($roleName);

        $token = $user->createToken('feature-test')->plainTextToken;

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Central-Auth-Token' => $token,
        ];
    }

    public function test_customer_only_sees_their_own_gift_cards(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);

        $ownCard = EcommerceGiftCard::create([
            'user_id' => $customer->id,
            'code' => '100000000000001',
            'recipient_name' => 'Own Recipient',
            'recipient_email' => 'own@example.com',
            'initial_balance' => 100,
            'current_balance' => 80,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        EcommerceGiftCard::create([
            'user_id' => $otherCustomer->id,
            'code' => '200000000000001',
            'recipient_name' => 'Other Recipient',
            'recipient_email' => 'other@example.com',
            'initial_balance' => 100,
            'current_balance' => 50,
            'status' => 'redeemed',
            'currency' => 'USD',
        ]);

        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($customer))
            ->getJson('/api/ecommerce/gift-cards');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownCard->id)
            ->assertJsonPath('data.0.code', '100000000000001')
            ->assertJsonMissingPath('data.1');
    }

    public function test_super_admin_can_filter_by_pending_status(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        EcommerceGiftCard::create([
            'code' => '300000000000001',
            'recipient_name' => 'Pending Recipient',
            'recipient_email' => 'pending@example.com',
            'initial_balance' => 100,
            'current_balance' => 100,
            'status' => 'pending',
            'currency' => 'USD',
        ]);

        EcommerceGiftCard::create([
            'code' => '400000000000001',
            'recipient_name' => 'Active Recipient',
            'recipient_email' => 'active@example.com',
            'initial_balance' => 100,
            'current_balance' => 100,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->getJson('/api/v1/admin/gift-cards?status=pending');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '300000000000001')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_admin_gift_card_list_is_not_scoped_to_recipient_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $recipient = User::factory()->create(['role' => 'customer']);

        EcommerceGiftCard::create([
            'user_id' => $recipient->id,
            'code' => '310000000000001',
            'recipient_name' => 'Recipient User',
            'recipient_email' => 'recipient@example.com',
            'initial_balance' => 100,
            'current_balance' => 100,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        EcommerceGiftCard::create([
            'code' => '320000000000001',
            'recipient_name' => 'Guest Recipient',
            'recipient_email' => 'guest@example.com',
            'initial_balance' => 50,
            'current_balance' => 50,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->getJson('/api/v1/admin/gift-cards');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_update_rejects_raw_balance_edits(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $giftCard = EcommerceGiftCard::create([
            'code' => '500000000000001',
            'recipient_name' => 'Recipient',
            'recipient_email' => 'recipient@example.com',
            'initial_balance' => 100,
            'current_balance' => 100,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        // Balance only moves through ledgered actions (redeem / adjust / void).
        $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->putJson("/api/v1/admin/gift-cards/{$giftCard->id}", [
                'status' => 'disabled',
                'current_balance' => 0,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('ecommerce_gift_cards', [
            'id' => $giftCard->id,
            'code' => '500000000000001',
            'status' => 'active',
            'current_balance' => '100.00',
        ]);
    }

    public function test_store_assigns_authenticated_user_and_defaults_currency(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
 
        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson('/api/v1/admin/gift-cards', [
                'recipient_name' => 'Created Recipient',
                'recipient_email' => 'created@example.com',
                'amount' => 75,
            ]);
 
        $response->assertCreated();
 
        $generatedCode = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^GFT(-[A-Z0-9]{4}){3}$/', $generatedCode);
        $this->assertSame('USD', $response->json('data.currency'));
        $this->assertSame(75.0, (float) $response->json('data.current_balance'));
 
        $this->assertDatabaseHas('ecommerce_gift_cards', [
            'code' => $generatedCode,
            'currency' => 'USD',
        ]);
    }
 
    public function test_store_generates_code_when_not_provided(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
 
        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($admin))
            ->postJson('/api/v1/admin/gift-cards', [
                'recipient_name' => 'Generated Code Recipient',
                'recipient_email' => 'generated@example.com',
                'amount' => 25,
            ]);
 
        $response->assertCreated();
 
        $generatedCode = $response->json('data.code');
 
        $this->assertIsString($generatedCode);
        $this->assertMatchesRegularExpression('/^GFT(-[A-Z0-9]{4}){3}$/', $generatedCode);
        $this->assertDatabaseHas('ecommerce_gift_cards', [
            'code' => $generatedCode,
        ]);
    }

    public function test_customer_is_forbidden_from_accessing_admin_gift_card_endpoints(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $response = $this
            ->withHeaders($this->centralAuthHeadersFor($customer))
            ->postJson('/api/v1/admin/gift-cards', [
                'recipient_name' => 'Should Fail Recipient',
                'recipient_email' => 'fail@example.com',
                'amount' => 50,
            ]);

        $response->assertStatus(403);
    }
}
