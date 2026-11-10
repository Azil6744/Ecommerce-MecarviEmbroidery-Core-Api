<?php

namespace Tests\Feature;

use App\Models\EcommerceDispute;
use App\Models\EcommerceDisputeMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EcommerceDisputeAccessTest extends TestCase
{
    use DatabaseTransactions;

    private function makeDispute(array $overrides = []): EcommerceDispute
    {
        return EcommerceDispute::create(array_merge([
            'dispute_number' => 'DSP-TEST-' . strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'customer_name' => 'Owner Customer',
            'email' => 'owner@example.test',
            'user_id' => null,
            'type' => 'General Dispute',
            'status' => 'Open',
            'description' => 'Test dispute',
            'items' => [],
            'answers' => [],
            'amount' => 0,
            'evidence' => [],
        ], $overrides));
    }

    private function makeCustomer(string $email): User
    {
        return User::factory()->create(['email' => $email, 'role' => 'customer']);
    }

    private function headersFor(User $user): array
    {
        $token = $user->createToken('dispute-test-token')->plainTextToken;

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Central-Auth-Token' => $token,
        ];
    }

    public function test_guest_cannot_read_dispute_by_id_or_number(): void
    {
        // The show route requires sign-in, so guests cannot walk ids or guess numbers.
        $dispute = $this->makeDispute();

        $this->getJson("/api/ecommerce/disputes/{$dispute->id}?email=owner@example.test")
            ->assertStatus(401);
        $this->getJson("/api/ecommerce/disputes/{$dispute->dispute_number}?email=owner@example.test")
            ->assertStatus(401);
    }

    public function test_customer_cannot_read_another_customers_dispute(): void
    {
        $dispute = $this->makeDispute(['user_id' => null]);
        $stranger = $this->makeCustomer('stranger@example.test');

        $this->withHeaders($this->headersFor($stranger))
            ->getJson("/api/ecommerce/disputes/{$dispute->dispute_number}")
            ->assertStatus(403);
    }

    public function test_customer_cannot_delete_a_dispute(): void
    {
        $owner = $this->makeCustomer('owner@example.test');
        $dispute = $this->makeDispute(['user_id' => $owner->id]);

        $this->withHeaders($this->headersFor($owner))
            ->deleteJson("/api/ecommerce/disputes/{$dispute->dispute_number}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ecommerce_disputes', ['id' => $dispute->id]);
    }

    public function test_customer_cannot_change_status_on_update(): void
    {
        $owner = $this->makeCustomer('owner@example.test');
        $dispute = $this->makeDispute(['user_id' => $owner->id]);

        $this->withHeaders($this->headersFor($owner))
            ->putJson("/api/ecommerce/disputes/{$dispute->dispute_number}", ['status' => 'Resolved'])
            ->assertStatus(403);

        $this->assertDatabaseHas('ecommerce_disputes', ['id' => $dispute->id, 'status' => 'Open']);
    }

    public function test_dispute_with_unknown_order_number_is_rejected(): void
    {
        $this->postJson('/api/ecommerce/disputes', [
            'order_number' => 'NX-DOES-NOT-EXIST',
            'customer_name' => 'Guest',
            'email' => 'guest@example.test',
            'description' => 'Something is wrong',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('ecommerce_disputes', ['order_number' => 'NX-DOES-NOT-EXIST']);
    }

    public function test_customer_reply_is_stored_and_internal_notes_are_hidden_from_customer(): void
    {
        $owner = $this->makeCustomer('owner@example.test');
        $dispute = $this->makeDispute(['user_id' => $owner->id]);

        $this->withHeaders($this->headersFor($owner))
            ->postJson("/api/ecommerce/disputes/{$dispute->dispute_number}/messages", ['body' => 'Hello support'])
            ->assertStatus(201);

        EcommerceDisputeMessage::create([
            'ecommerce_dispute_id' => $dispute->id,
            'sender_type' => EcommerceDisputeMessage::SENDER_ADMIN,
            'sender_name' => 'Staff',
            'body' => 'Secret staff note',
            'is_internal' => true,
        ]);

        $response = $this->withHeaders($this->headersFor($owner))
            ->getJson("/api/ecommerce/disputes/{$dispute->dispute_number}/messages")
            ->assertOk();

        $bodies = collect($response->json('data'))->pluck('body')->all();
        $this->assertContains('Hello support', $bodies);
        $this->assertNotContains('Secret staff note', $bodies);
    }
}
