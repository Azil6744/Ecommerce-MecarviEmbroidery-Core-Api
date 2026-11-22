<?php

namespace Tests\Feature;

use App\Models\EcommerceWalletAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The four stat cards on the admin Mecarvi Cash page filter the wallet directory. */
class MecarviCashFilterTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['balance_after' => 0]], 200)]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');
        $token = $admin->createToken('feature-test')->plainTextToken;
        $this->headers = ['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token];

        $rich = User::factory()->create(['name' => 'Rich Wallet', 'role' => 'customer', 'wallet_balance' => 50]);
        $credit = User::factory()->create(['name' => 'Credit Holder', 'role' => 'customer', 'wallet_balance' => 0]);
        $off = User::factory()->create(['name' => 'Disabled Person', 'role' => 'customer', 'wallet_balance' => 0]);

        EcommerceWalletAccount::create(['user_id' => $credit->id, 'wallet_code' => 'WLT-C', 'wallet_type' => 'customer', 'status' => 'active', 'store_credit_balance' => 25]);
        EcommerceWalletAccount::create(['user_id' => $off->id, 'wallet_code' => 'WLT-O', 'wallet_type' => 'customer', 'status' => 'inactive']);
    }

    private function names(string $query): array
    {
        return collect($this->withHeaders($this->headers)->getJson('/api/v1/admin/mecarvi-cash?' . $query)->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();
    }

    public function test_stat_card_filters_and_search(): void
    {
        $this->assertSame(['Rich Wallet'], $this->names('filter=wallet_balance'));
        $this->assertSame(['Credit Holder'], $this->names('filter=store_credit'));
        $this->assertSame(['Disabled Person'], $this->names('filter=inactive'));
        $this->assertSame(['Credit Holder', 'Rich Wallet'], $this->names('filter=active'));
        $this->assertSame(['Credit Holder'], $this->names('filter=all&search=credit'));
    }
}
