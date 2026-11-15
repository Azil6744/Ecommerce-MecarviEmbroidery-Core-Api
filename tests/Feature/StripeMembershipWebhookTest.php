<?php

namespace Tests\Feature;

use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeMembershipWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeCentral(int $updateStatus = 200): void
    {
        Http::fake([
            '*/v1/internal/admin/memberships/update' => Http::response(['success' => $updateStatus === 200], $updateStatus),
            '*/v1/internal/admin/memberships*' => Http::response(['success' => true, 'data' => [
                ['id' => 77, 'status' => 'active', 'plan_name' => 'Gold', 'price' => 9.99, 'billing_cycle' => 'Monthly', 'user' => ['email' => 'buyer@example.com']],
                ['id' => 88, 'status' => 'active', 'plan_name' => 'Pro', 'price' => 49.99, 'billing_cycle' => 'Monthly', 'user' => ['email' => 'other@example.com']],
            ]], 200),
        ]);
    }

    private function invoiceEvent(string $id, array $metadata = []): array
    {
        return [
            'id' => $id,
            'type' => 'invoice.payment_succeeded',
            'data' => ['object' => [
                'id' => 'in_' . $id,
                'customer_email' => 'buyer@example.com',
                'payment_intent' => 'pi_' . $id,
                'amount_paid' => 999,
                'currency' => 'usd',
                'metadata' => $metadata,
                'lines' => ['data' => [['period' => ['start' => 1767225600, 'end' => 1769904000]]]],
            ]],
        ];
    }

    private function updateCalls(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/memberships/update'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_duplicate_event_is_applied_only_once(): void
    {
        $this->fakeCentral();
        $event = $this->invoiceEvent('evt_dup_1');

        $this->postJson('/api/ecommerce/webhooks/stripe', $event)->assertOk();
        $this->postJson('/api/ecommerce/webhooks/stripe', $event)
            ->assertOk()
            ->assertJsonFragment(['message' => 'Event already processed.']);

        $this->assertCount(1, $this->updateCalls());
        $this->assertSame('processed', StripeWebhookEvent::where('event_id', 'evt_dup_1')->value('status'));
    }

    public function test_failed_processing_returns_500_and_is_retried(): void
    {
        // Central is down for the first delivery, then recovers.
        Http::fake([
            '*/v1/internal/admin/memberships/update' => Http::sequence()
                ->push(['success' => false], 503)
                ->push(['success' => true], 200),
            '*/v1/internal/admin/memberships*' => Http::response(['success' => true, 'data' => [
                ['id' => 77, 'status' => 'active', 'plan_name' => 'Gold', 'price' => 9.99, 'billing_cycle' => 'Monthly', 'user' => ['email' => 'buyer@example.com']],
            ]], 200),
        ]);
        $event = $this->invoiceEvent('evt_retry_1');

        $this->postJson('/api/ecommerce/webhooks/stripe', $event)->assertStatus(500);
        $this->assertSame('failed', StripeWebhookEvent::where('event_id', 'evt_retry_1')->value('status'));

        $this->postJson('/api/ecommerce/webhooks/stripe', $event)->assertOk();

        $row = StripeWebhookEvent::where('event_id', 'evt_retry_1')->first();
        $this->assertSame('processed', $row->status);
        $this->assertSame(2, $row->attempts);
    }

    public function test_membership_is_matched_by_metadata_id_before_email(): void
    {
        $this->fakeCentral();
        $this->postJson('/api/ecommerce/webhooks/stripe', $this->invoiceEvent('evt_meta_1', ['membership_id' => '88']))->assertOk();

        $call = $this->updateCalls()[0];
        $this->assertSame(88, (int) $call['membership_id']);
        $this->assertSame('renewal', $call['transaction_type']);
        $this->assertEquals(9.99, (float) $call['gross_amount']);
        $this->assertSame('2026-02-01 00:00:00', $call['next_billing_date']);
    }

    public function test_refund_is_recorded_as_refund_transaction(): void
    {
        $this->fakeCentral();
        $this->postJson('/api/ecommerce/webhooks/stripe', [
            'id' => 'evt_refund_1',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_1',
                'refunded' => false,
                'amount_refunded' => 500,
                'billing_details' => ['email' => 'buyer@example.com'],
            ]],
        ])->assertOk();

        $call = $this->updateCalls()[0];
        $this->assertSame(77, (int) $call['membership_id']);
        $this->assertSame('refund', $call['transaction_type']);
        $this->assertSame('partially_refunded', $call['payment_status']);
        $this->assertEquals(5.00, (float) $call['refund_amount']);
    }

    public function test_unhandled_event_types_are_acknowledged_without_side_effects(): void
    {
        $this->fakeCentral();
        $this->postJson('/api/ecommerce/webhooks/stripe', ['id' => 'evt_other_1', 'type' => 'customer.created', 'data' => ['object' => []]])
            ->assertOk();

        $this->assertCount(0, $this->updateCalls());
        $this->assertSame('ignored', StripeWebhookEvent::where('event_id', 'evt_other_1')->value('status'));
    }
}
