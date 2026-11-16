<?php

namespace Tests\Feature;

use App\Models\EcommerceMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The customer's actions change the membership Central holds (what the admin sees). If Central
 * cannot be asked, the local copy must not be changed on its own: that is how the customer panel
 * came to show Platinum+ while the admin panel still showed Essentials.
 */
class MembershipCopiesStayInStepTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_action_is_refused_when_central_cannot_be_reached(): void
    {
        Http::fake(['*' => Http::response(['message' => 'down'], 500)]);
        [$user, $membership] = $this->member();

        $this->asCustomer($user, "/api/ecommerce/memberships/{$membership->id}/cancel", ['reason' => 'Too expensive'])
            ->assertStatus(503);

        $this->assertSame('Active', $membership->fresh()->status);
    }

    public function test_customer_found_in_central_by_email_when_the_token_lookup_is_empty(): void
    {
        [$user, $local] = $this->member();
        Http::fake([
            '*/user/memberships' => Http::response(['success' => true, 'data' => []]),
            '*/v1/internal/admin/memberships' => Http::response(['success' => true, 'data' => [[
                'id' => 901,
                'status' => 'active',
                'plan_name' => 'Central Plan',
                'price' => 10,
                'billing_cycle' => 'Monthly',
                'user' => ['email' => $user->email],
            ]]]),
        ]);

        $this->asCustomer($user, '/api/ecommerce/memberships', [], 'GET')
            ->assertOk()
            ->assertJsonPath('data.0.source', 'central')
            ->assertJsonPath('data.0.id', 901);
    }

    private function member(): array
    {
        $user = User::factory()->create();
        $membership = EcommerceMembership::create([
            'user_id' => $user->id,
            'plan_name' => 'Local Plan',
            'status' => 'Active',
            'price' => 10,
            'billing_cycle' => 'Monthly',
            'next_billing_date' => now()->addMonth(),
        ]);

        return [$user, $membership];
    }

    private function asCustomer(User $user, string $uri, array $data = [], string $method = 'POST')
    {
        $token = $user->createToken('copies-test')->plainTextToken;
        $request = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token, 'Accept' => 'application/json']);

        return $method === 'GET' ? $request->getJson($uri) : $request->postJson($uri, $data);
    }
}
