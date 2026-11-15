<?php

namespace Tests\Feature;

use App\Models\EcommerceSubscriptionPlan;
use App\Models\MembershipTeamSeat;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\MembershipTeamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MembershipTeamSeatsTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;
    private EcommerceSubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'team-owner@example.com', 'phone' => null]);
        $this->plan = EcommerceSubscriptionPlan::create([
            'name' => 'Team Biz', 'internal_code' => 'TEAM-BIZ', 'price' => 79.99, 'billing_cycle' => 'Monthly',
            'account_type' => 'business', 'coverage_type' => 'individual_site', 'applicable_site' => 'mecarvi-embroidery',
            'status' => 'Active', 'benefit_config' => ['team_seats' => 3],
        ]);
        $this->fakeCentral('active');
    }

    private string $centralStatus = 'active';

    private function fakeCentral(string $status): void
    {
        // Registered once; later calls just change the status the fake reports.
        $first = $this->faked === null;
        $this->centralStatus = $status;
        if (! $first) {
            return;
        }
        $this->faked = true;
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/v1/internal/admin/memberships')) {
                return Http::response(['success' => true, 'data' => [[
                    'id' => 700, 'plan_id' => $this->plan->id, 'plan_name' => 'Team Biz', 'status' => $this->centralStatus,
                    'account_type' => 'business', 'price' => 79.99, 'user' => ['email' => $this->owner->email],
                ]]], 200);
            }

            // Anything else (e.g. central token validation) fails fast so local auth is used.
            return Http::response(['message' => 'Unauthenticated.'], 401);
        });
    }

    private ?bool $faked = null;

    private function as(User $user): self
    {
        $token = $user->createToken('team-test')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token]);
    }

    public function test_owner_can_invite_up_to_the_plan_seat_limit(): void
    {
        $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'a@example.com'])->assertCreated();
        $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'A@example.com'])->assertStatus(422); // duplicate
        $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'b@example.com'])->assertCreated();
        // 3 seats = owner + 2
        $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'c@example.com'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'All 3 seats on your plan are in use. Remove someone or upgrade for more seats.']);

        $this->as($this->owner)->getJson('/api/ecommerce/membership-team')
            ->assertOk()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.seats_total', 3)
            ->assertJsonPath('data.seats_used', 3);
    }

    public function test_invitee_accepts_and_gets_shared_benefits_while_owner_is_live(): void
    {
        $member = User::factory()->create(['email' => 'teammate@example.com', 'phone' => null]);
        $seatId = $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => $member->email])->json('data.id');

        $this->assertTrue(UserNotification::where('user_id', $member->id)->where('type', 'membership.team_invite')->exists());

        $this->as($member)->postJson("/api/ecommerce/membership-team/invites/{$seatId}/accept")->assertOk();
        $this->assertSame('active', MembershipTeamSeat::find($seatId)->status);

        $shared = MembershipTeamService::sharedMembershipsFor($member);
        $this->assertCount(1, $shared);
        $this->assertSame(700, (int) $shared[0]['id']);
        $this->assertTrue($shared[0]['via_team_seat']);

        // Owner's membership ends → benefits stop.
        $this->fakeCentral('canceled');
        $this->assertCount(0, MembershipTeamService::sharedMembershipsFor($member));
    }

    public function test_someone_else_cannot_accept_an_invitation(): void
    {
        $seatId = $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'right@example.com'])->json('data.id');
        $stranger = User::factory()->create(['email' => 'wrong@example.com', 'phone' => null]);

        $this->as($stranger)->postJson("/api/ecommerce/membership-team/invites/{$seatId}/accept")->assertNotFound();
    }

    public function test_owner_can_remove_a_member_and_free_the_seat(): void
    {
        $seatId = $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'x@example.com'])->json('data.id');
        $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'y@example.com'])->assertCreated();

        $this->as($this->owner)->deleteJson("/api/ecommerce/membership-team/{$seatId}")->assertOk();
        $this->assertSame('removed', MembershipTeamSeat::find($seatId)->status);
        $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'z@example.com'])->assertCreated();
    }

    public function test_plans_without_team_seats_cannot_invite(): void
    {
        $this->plan->update(['benefit_config' => []]);
        $this->as($this->owner)->postJson('/api/ecommerce/membership-team/invite', ['email' => 'nope@example.com'])
            ->assertStatus(422);
    }
}
