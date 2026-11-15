<?php

namespace Tests\Feature;

use App\Models\EcommerceMembershipActivity;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\MembershipNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MembershipNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    private function headersFor(User $user): array
    {
        $token = $user->createToken('notif-test')->plainTextToken;

        return ['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token];
    }

    public function test_membership_activity_creates_an_in_app_notification(): void
    {
        $user = User::factory()->create(['phone' => null]);

        EcommerceMembershipActivity::create([
            'user_id' => $user->id,
            'source' => 'central',
            'action' => 'upgrade',
            'from_plan' => 'Gold',
            'to_plan' => 'Platinum',
            'amount' => 12.50,
            'performed_by' => 'customer',
        ]);

        $n = UserNotification::where('user_id', $user->id)->first();
        $this->assertNotNull($n);
        $this->assertSame('membership.upgrade', $n->type);
        $this->assertSame('Membership upgraded', $n->title);
        $this->assertStringContainsString('Platinum', $n->body);
        $this->assertStringContainsString('$12.50', $n->body);
        $this->assertNull($n->read_at);
    }

    public function test_dedupe_key_prevents_repeat_notifications(): void
    {
        $user = User::factory()->create(['phone' => null]);

        MembershipNotifier::notify($user, 'payment_failed', ['plan_name' => 'Gold'], 'same-key');
        MembershipNotifier::notify($user, 'payment_failed', ['plan_name' => 'Gold'], 'same-key');

        $this->assertSame(1, UserNotification::where('user_id', $user->id)->count());
    }

    public function test_bell_api_lists_counts_and_marks_read(): void
    {
        $user = User::factory()->create(['phone' => null]);
        $other = User::factory()->create(['phone' => null]);
        MembershipNotifier::notify($user, 'pause', ['date' => '2026-12-01']);
        MembershipNotifier::notify($user, 'resume', ['plan_name' => 'Gold']);
        MembershipNotifier::notify($other, 'resume', ['plan_name' => 'Gold']);

        $headers = $this->headersFor($user);
        $list = $this->withHeaders($headers)->getJson('/api/ecommerce/notifications')->assertOk();
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame(2, $list->json('meta.unread'));

        $firstId = $list->json('data.0.id');
        $this->withHeaders($headers)->postJson("/api/ecommerce/notifications/{$firstId}/read")->assertOk();
        $this->withHeaders($headers)->getJson('/api/ecommerce/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 1);

        // Cannot touch another member's notification.
        $othersId = UserNotification::where('user_id', $other->id)->value('id');
        $this->withHeaders($headers)->postJson("/api/ecommerce/notifications/{$othersId}/read")->assertNotFound();

        $this->withHeaders($headers)->postJson('/api/ecommerce/notifications/read-all')->assertOk();
        $this->withHeaders($headers)->getJson('/api/ecommerce/notifications/unread-count')->assertJsonPath('data.unread', 0);
    }

    public function test_stripe_renewal_notifies_once_even_when_redelivered(): void
    {
        $user = User::factory()->create(['email' => 'renew-notify@example.com', 'phone' => null]);
        Http::fake([
            '*/v1/internal/admin/memberships/update' => Http::response(['success' => true], 200),
            '*/v1/internal/admin/memberships*' => Http::response(['success' => true, 'data' => [
                ['id' => 9, 'status' => 'active', 'plan_name' => 'Gold', 'price' => 9.99, 'billing_cycle' => 'Monthly', 'user' => ['email' => $user->email]],
            ]], 200),
        ]);
        $event = [
            'id' => 'evt_notify_renew',
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => ['id' => 'in_1', 'customer_email' => $user->email, 'payment_intent' => 'pi_1', 'amount_paid' => 999]],
        ];

        $this->postJson('/api/ecommerce/webhooks/stripe', $event)->assertOk();
        $this->postJson('/api/ecommerce/webhooks/stripe', $event)->assertOk();

        $notes = UserNotification::where('user_id', $user->id)->get();
        $this->assertCount(1, $notes);
        $this->assertSame('membership.renewal_paid', $notes->first()->type);
    }
}
