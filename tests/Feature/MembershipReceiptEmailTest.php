<?php

namespace Tests\Feature;

use App\Models\EcommerceMembership;
use App\Models\EmailNotificationLog;
use App\Models\EmailNotificationSetting;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Admin → Membership Transactions → invoice → "Send Receipt" sends the real receipt template. */
class MembershipReceiptEmailTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(); // no Central in tests: only Core's local rows are listed
        app(EmailNotificationService::class)->setting();
        EmailNotificationSetting::query()->update(['is_enabled' => true]);
        app(EmailNotificationService::class)->sendEvent('noop_to_seed_templates', []);
        EmailTemplate::where('event_key', 'customer_membership_receipt')->update(['status' => 'published', 'send_to_customer' => true, 'send_to_admin' => false]);
    }

    public function test_send_receipt_uses_the_membership_receipt_template_with_the_payment_figures(): void
    {
        [$admin, $membership, $customer] = $this->fixture();

        $this->asAdmin($admin)
            ->post("/api/ecommerce/admin/membership-transactions/local_{$membership->id}/resend-receipt", ['use_template' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $log = EmailNotificationLog::where('event_key', 'customer_membership_receipt')->latest('id')->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame($customer->email, $log->recipient_email);
        $this->assertSame('$19.99', $log->payload['amount_paid']);
        $this->assertSame('RCP-MEM-' . $membership->id, $log->payload['receipt_number']);
    }

    public function test_a_disabled_template_is_reported_instead_of_claiming_success(): void
    {
        [$admin, $membership] = $this->fixture();
        EmailTemplate::where('event_key', 'customer_membership_receipt')->update(['status' => 'draft']);

        $this->asAdmin($admin)
            ->post("/api/ecommerce/admin/membership-transactions/local_{$membership->id}/resend-receipt", ['use_template' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    private function fixture(): array
    {
        // Same setup as the other admin route tests: super admin role (admin.only + admin.mfa pass it).
        $admin = User::factory()->create(['role' => 'super_admin']);
        \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');
        $customer = User::factory()->create(['email' => 'receipt.customer@example.test']);
        $membership = EcommerceMembership::create([
            'user_id' => $customer->id,
            'plan_name' => 'Mecarvi Gold',
            'status' => 'Active',
            'price' => 19.99,
            'billing_cycle' => 'Monthly',
            'next_billing_date' => now()->addMonth(),
        ]);

        return [$admin, $membership, $customer];
    }

    private function asAdmin(User $admin)
    {
        $token = $admin->createToken('receipt-test')->plainTextToken;

        return $this->actingAs($admin, 'sanctum')->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Central-Auth-Token' => $token]);
    }
}
