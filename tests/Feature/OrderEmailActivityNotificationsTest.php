<?php

namespace Tests\Feature;

use App\Models\EcommerceDispute;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderProof;
use App\Models\EcommerceOrderVerification;
use App\Models\EmailNotificationLog;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailNotificationService;
use Tests\TestCase;

class OrderEmailActivityNotificationsTest extends TestCase
{
    public function test_default_templates_include_all_order_activity_events(): void
    {
        $service = app(EmailNotificationService::class);
        $service->ensureDefaultTemplates();

        $expectedEvents = [
            'order_proof_ready',
            'order_proof_approved',
            'order_proof_revision_requested',
            'order_proof_rejected',
            'order_proof_comment_added',
            'order_verification_required',
            'order_verification_submitted',
            'order_verification_approved',
            'order_verification_declined',
            'order_verification_more_info',
            'order_in_production',
            'order_ready_for_pickup',
            'order_on_hold',
            'order_cancellation_requested',
            'order_cancellation_rejected',
            'customer_refund_more_info',
            'dispute_opened',
            'dispute_under_review',
            'dispute_awaiting_response',
            'dispute_resolved',
            'dispute_closed',
        ];

        foreach ($expectedEvents as $eventKey) {
            $this->assertArrayHasKey($eventKey, EmailNotificationService::EVENTS, "Event {$eventKey} missing from EVENTS definition.");
            
            $template = EmailTemplate::where('event_key', $eventKey)->first();
            $this->assertNotNull($template, "Email template for {$eventKey} was not seeded in the database.");
            $this->assertEquals('published', $template->status);
            $this->assertNotEmpty($template->subject);
            $this->assertNotEmpty($template->heading);
            $this->assertNotEmpty($template->body_text);
        }
    }

    public function test_send_proof_event_dispatches_proper_payload(): void
    {
        $service = app(EmailNotificationService::class);
        $service->ensureDefaultTemplates();

        $user = User::firstOrCreate(
            ['email' => 'proof-customer@example.com'],
            ['name' => 'Proof Customer', 'password' => bcrypt('secret123')]
        );

        $order = EcommerceOrder::firstOrCreate(
            ['order_number' => 'ORD-PROOF-TEST'],
            [
                'user_id' => $user->id,
                'customer_name' => 'Proof Customer',
                'customer_email' => 'proof-customer@example.com',
                'total_amount' => 250.00,
                'status' => 'processing',
                'order_date' => now(),
            ]
        );

        $proof = EcommerceOrderProof::create([
            'order_id' => $order->id,
            'proof_type' => 'Embroidery Logo Mockup',
            'title' => 'Left Chest Monogram',
            'status' => 'awaiting_approval',
            'file_path' => 'proofs/test.pdf',
            'metadata' => ['version' => 'Version 2'],
        ]);

        $results = $service->sendProofEvent('order_proof_ready', $proof);

        $this->assertNotEmpty($results);
        $this->assertInstanceOf(EmailNotificationLog::class, $results[0]);
        $this->assertEquals('order_proof_ready', $results[0]->event_key);
        $this->assertEquals('proof-customer@example.com', $results[0]->recipient_email);
        $this->assertStringContainsString('ORD-PROOF-TEST', $results[0]->subject);

        // Clean up test proof and order
        $proof->delete();
        $order->delete();
    }

    public function test_send_verification_event_dispatches_proper_payload(): void
    {
        $service = app(EmailNotificationService::class);
        $service->ensureDefaultTemplates();

        $user = User::firstOrCreate(
            ['email' => 'verify-customer@example.com'],
            ['name' => 'Verify Customer', 'password' => bcrypt('secret123')]
        );

        $order = EcommerceOrder::firstOrCreate(
            ['order_number' => 'ORD-VERIFY-TEST'],
            [
                'user_id' => $user->id,
                'customer_name' => 'Verify Customer',
                'customer_email' => 'verify-customer@example.com',
                'total_amount' => 450.00,
                'status' => 'pending_verification',
                'order_date' => now(),
            ]
        );

        $verification = EcommerceOrderVerification::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $user->id,
            'risk_level' => 'high',
            'status' => 'action_required',
            'flag_reason' => 'High-value transaction verification',
            'required_documents' => ['Photo ID', 'Proof of Address'],
            'total_amount' => 450.00,
        ]);

        $results = $service->sendVerificationEvent('order_verification_required', $verification);

        $this->assertNotEmpty($results);
        $this->assertInstanceOf(EmailNotificationLog::class, $results[0]);
        $this->assertEquals('order_verification_required', $results[0]->event_key);
        $this->assertEquals('verify-customer@example.com', $results[0]->recipient_email);
        $this->assertStringContainsString('ORD-VERIFY-TEST', $results[0]->subject);

        // Clean up
        $verification->delete();
        $order->delete();
    }

    public function test_send_dispute_event_dispatches_proper_payload(): void
    {
        $service = app(EmailNotificationService::class);
        $service->ensureDefaultTemplates();

        $user = User::firstOrCreate(
            ['email' => 'dispute-customer@example.com'],
            ['name' => 'Dispute Customer', 'password' => bcrypt('secret123')]
        );

        $dispute = EcommerceDispute::create([
            'dispute_number' => 'DSP-TEST-998877',
            'order_number' => 'ORD-DISPUTE-TEST',
            'customer_name' => 'Dispute Customer',
            'email' => 'dispute-customer@example.com',
            'type' => 'Damaged Item',
            'status' => 'Open',
            'description' => 'Thread broke during first wash.',
        ]);

        $results = $service->sendDisputeEvent('dispute_opened', $dispute);

        $this->assertNotEmpty($results);
        $this->assertInstanceOf(EmailNotificationLog::class, $results[0]);
        $this->assertEquals('dispute_opened', $results[0]->event_key);
        $this->assertEquals('dispute-customer@example.com', $results[0]->recipient_email);
        $this->assertStringContainsString('DSP-TEST-998877', $results[0]->subject);

        // Clean up
        $dispute->delete();
    }

    public function test_order_record_activity_and_notify(): void
    {
        $order = EcommerceOrder::firstOrCreate(
            ['order_number' => 'ORD-ACTIVITY-TEST'],
            [
                'customer_name' => 'Activity Customer',
                'customer_email' => 'activity-customer@example.com',
                'total_amount' => 120.00,
                'status' => 'processing',
                'order_date' => now(),
            ]
        );

        $order->recordActivityAndNotify('order_in_production', 'in_production', 'Order entered production', 'Artisans began stitching');

        $this->assertDatabaseHas('ecommerce_order_status_events', [
            'order_id' => $order->id,
            'status' => 'in_production',
            'label' => 'Order entered production',
        ]);

        $order->statusEvents()->delete();
        $order->delete();
    }
}
