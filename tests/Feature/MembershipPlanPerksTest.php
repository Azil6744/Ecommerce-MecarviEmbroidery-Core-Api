<?php

namespace Tests\Feature;

use App\Models\EcommerceMembership;
use App\Models\EcommerceSubscriptionPlan;
use App\Models\User;
use App\Services\MembershipBenefitService;
use App\Services\MembershipTeamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Plan-editor benefits beyond price: multiplier, priority, seats; and store credit once a month. */
class MembershipPlanPerksTest extends TestCase
{
    use DatabaseTransactions;

    public function test_perks_are_read_from_the_plan_editor_benefits_table(): void
    {
        $perks = MembershipBenefitService::planPerks($this->essentials());

        $this->assertSame(2.0, $perks['loyalty_multiplier']);
        $this->assertTrue($perks['priority_processing']);
        $this->assertTrue($perks['priority_support']);
        $this->assertTrue($perks['early_access']);
        $this->assertSame(5, $perks['free_digitizing_per_month']);
        $this->assertSame(5, $perks['team_seats']);
        $this->assertSame(5, MembershipTeamService::seatsForPlan($this->essentials()));
    }

    public function test_a_switched_off_row_does_not_count(): void
    {
        $plan = $this->essentials([
            ['title' => 'Priority Order Processing', 'details' => 'Priority queue', 'status' => false],
        ]);

        $this->assertFalse(MembershipBenefitService::planPerks($plan)['priority_processing']);
    }

    public function test_trialing_and_cancelling_members_keep_their_perks(): void
    {
        foreach (['Trialing', 'Pending Cancellation', 'Active'] as $status) {
            $user = User::factory()->create();
            $plan = $this->essentials();
            EcommerceMembership::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'status' => $status, 'price' => 10, 'billing_cycle' => 'Monthly']);

            $perks = MembershipBenefitService::perksFor($user);
            $this->assertSame(2.0, $perks['loyalty_multiplier'], "status {$status}");
            $this->assertTrue($perks['priority_support'], "status {$status}");
        }

        $cancelled = User::factory()->create();
        EcommerceMembership::create(['user_id' => $cancelled->id, 'plan_id' => $this->essentials()->id, 'plan_name' => 'X', 'status' => 'Canceled', 'price' => 10, 'billing_cycle' => 'Monthly']);
        $this->assertSame(1.0, MembershipBenefitService::perksFor($cancelled)['loyalty_multiplier']);
    }

    public function test_monthly_store_credit_left_on_unlimited_applies_once_a_month(): void
    {
        $user = User::factory()->create();
        $plan = $this->essentials();
        $membership = ['status' => 'active', 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'benefits_snapshot' => $plan->benefit_config];
        $service = app(MembershipBenefitService::class);

        $first = $service->evaluate([$membership], 100.0, 0.0, null, false, $user->id);
        $creditUsed = collect($first['membership_benefit_usage'])->firstWhere('type', 'monthly_store_credit');
        $this->assertNotNull($creditUsed, 'First order of the month gets the store credit.');

        DB::table('ecommerce_orders')->insert([
            'user_id' => $user->id,
            'order_number' => 'PERK-' . uniqid(),
            'status' => 'completed',
            'membership_benefit_usage' => json_encode($first['membership_benefit_usage']),
            'created_at' => now(),
            'updated_at' => now(),
        ] + $this->orderDefaults());

        $second = $service->evaluate([$membership], 100.0, 0.0, null, false, $user->id);
        $this->assertNull(collect($second['membership_benefit_usage'])->firstWhere('type', 'monthly_store_credit'), 'Second order the same month does not.');
        $this->assertNotNull(collect($second['membership_benefit_usage'])->firstWhere('type', 'percentage_discount'), 'The member discount still applies.');
    }

    private function essentials(?array $rows = null): EcommerceSubscriptionPlan
    {
        return EcommerceSubscriptionPlan::create([
            'name' => 'Mecarvi Essentials ' . uniqid(),
            'internal_code' => 'PERK-' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'Monthly',
            'status' => 'Active',
            'benefit_config' => ['benefits_table' => $rows ?? [
                ['title' => 'Up to 5 Users (Seats) Included', 'details' => '5 users'],
                ['title' => 'Member Discount', 'type' => 'discount', 'details' => '10% off'],
                ['title' => 'Priority Order Processing', 'details' => 'Priority queue'],
                ['title' => 'Free Digitizing Files', 'details' => '5 per month'],
                ['title' => 'Priority Support', 'details' => '24/7 Priority Support'],
                ['title' => 'Monthly Store Credit', 'type' => 'credit', 'details' => '$10 store credit', 'usage_limit' => 'Unlimited'],
                ['title' => 'Reward Points Multiplier', 'details' => '2x points'],
                ['title' => 'Early Access', 'details' => 'Early access (1 week)'],
            ]],
        ]);
    }

    /** Required columns of ecommerce_orders that have no default in the test database. */
    private function orderDefaults(): array
    {
        $columns = collect(DB::select("select column_name, data_type from information_schema.columns where table_name = 'ecommerce_orders' and is_nullable = 'NO' and column_default is null"))
            ->reject(fn ($c) => in_array($c->column_name, ['id', 'user_id', 'order_number', 'status', 'membership_benefit_usage', 'created_at', 'updated_at'], true));

        return $columns->mapWithKeys(fn ($c) => [$c->column_name => match (true) {
            str_contains($c->data_type, 'timestamp') || $c->data_type === 'date' => now(),
            in_array($c->data_type, ['integer', 'bigint', 'smallint', 'numeric', 'double precision', 'real'], true) => 0,
            $c->data_type === 'boolean' => false,
            str_contains($c->data_type, 'json') => '{}',
            default => 'x',
        }])->all();
    }
}
