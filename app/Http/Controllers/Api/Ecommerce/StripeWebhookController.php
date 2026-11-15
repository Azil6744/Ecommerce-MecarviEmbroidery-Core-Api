<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe membership webhooks.
 *
 * Every event is stored in stripe_webhook_events and claimed atomically, so each
 * event is applied exactly once even when Stripe retries or delivers it twice.
 * An event is only marked processed after its handler succeeds; if the handler
 * fails we answer 500 so Stripe retries it later.
 */
class StripeWebhookController extends Controller
{
    private const HANDLED = [
        'invoice.payment_succeeded',
        'invoice.paid',
        'invoice.payment_failed',
        'customer.subscription.deleted',
        'charge.refunded',
        'charge.dispute.created',
        'charge.dispute.closed',
    ];

    /** A claim older than this is considered abandoned and may be retried. */
    private const STALE_CLAIM_MINUTES = 5;

    public function handle(Request $request): JsonResponse
    {
        $endpointSecret = (string) config('services.stripe.webhook_secret');
        $mustVerify = ! app()->environment(['local', 'testing']);

        if ($endpointSecret !== '') {
            if (! $this->verifyStripeSignature($request, $endpointSecret)) {
                Log::warning('Stripe webhook signature verification failed.');
                return response()->json(['error' => 'Invalid signature'], 400);
            }
        } elseif ($mustVerify) {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured; refusing unsigned event.');
            return response()->json(['error' => 'Webhook secret not configured'], 400);
        }

        $event = $request->json()->all();
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if ($eventId === '' || $type === '') {
            return response()->json(['error' => 'Malformed event'], 400);
        }

        if (! in_array($type, self::HANDLED, true)) {
            StripeWebhookEvent::firstOrCreate(
                ['event_id' => $eventId],
                ['type' => $type, 'status' => 'ignored', 'processed_at' => now()]
            );
            return response()->json(['success' => true, 'message' => 'Event type not handled.']);
        }

        // Claim the event atomically.
        $claim = DB::transaction(function () use ($eventId, $type, $event) {
            $row = StripeWebhookEvent::where('event_id', $eventId)->lockForUpdate()->first();
            if (! $row) {
                return StripeWebhookEvent::create([
                    'event_id' => $eventId,
                    'type' => $type,
                    'status' => 'processing',
                    'attempts' => 1,
                    'payload' => $event,
                ]);
            }
            if ($row->status === 'processed') {
                return 'duplicate';
            }
            if ($row->status === 'processing' && $row->updated_at && $row->updated_at->gt(now()->subMinutes(self::STALE_CLAIM_MINUTES))) {
                return 'in_progress';
            }
            $row->update(['status' => 'processing', 'attempts' => $row->attempts + 1]);

            return $row;
        });

        if ($claim === 'duplicate') {
            return response()->json(['success' => true, 'message' => 'Event already processed.']);
        }
        if ($claim === 'in_progress') {
            // Another delivery is handling it right now; let Stripe retry later.
            return response()->json(['success' => false, 'message' => 'Event is being processed.'], 409);
        }

        $object = $event['data']['object'] ?? [];
        try {
            $note = match ($type) {
                'invoice.payment_succeeded', 'invoice.paid' => $this->handlePaymentSucceeded($object, $eventId),
                'invoice.payment_failed' => $this->handlePaymentFailed($object, $eventId),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($object, $eventId),
                'charge.refunded' => $this->handleChargeRefunded($object),
                'charge.dispute.created', 'charge.dispute.closed' => $this->handleDispute($object, $type),
            };
            $claim->update(['status' => 'processed', 'processed_at' => now(), 'last_error' => $note]);
        } catch (\Throwable $e) {
            Log::error("Stripe webhook {$type} ({$eventId}) failed: " . $e->getMessage());
            $claim->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);

            return response()->json(['success' => false, 'message' => 'Processing failed; will be retried.'], 500);
        }

        return response()->json(['success' => true]);
    }

    // ─── Handlers (return a short note, or throw to have Stripe retry) ─────────

    private function handlePaymentSucceeded(array $invoice, string $eventId): ?string
    {
        $membership = $this->resolveMembership($invoice, $invoice['customer_email'] ?? null);
        if (! $membership) {
            return 'No matching membership; nothing to renew.';
        }

        // Use the billing period Stripe actually charged for.
        $line = $invoice['lines']['data'][0] ?? [];
        $start = isset($line['period']['start']) ? Carbon::createFromTimestamp($line['period']['start']) : now();
        $end = isset($line['period']['end'])
            ? Carbon::createFromTimestamp($line['period']['end'])
            : $this->calculateNextPeriodEnd($start, (string) ($membership['billing_cycle'] ?? 'monthly'));

        $this->centralUpdate($membership, [
            'status' => 'active',
            'current_period_start' => $start->toDateTimeString(),
            'current_period_end' => $end->toDateTimeString(),
            'next_billing_date' => $end->toDateTimeString(),
            'transaction_reference' => $invoice['payment_intent'] ?? $invoice['id'] ?? $eventId,
            'transaction_type' => 'renewal',
            'payment_status' => 'paid',
            'payment_method' => 'card',
            'payment_processor' => 'stripe',
            'gross_amount' => isset($invoice['amount_paid']) ? round($invoice['amount_paid'] / 100, 2) : null,
            'tax_amount' => isset($invoice['tax']) ? round(((int) $invoice['tax']) / 100, 2) : null,
            'currency' => isset($invoice['currency']) ? strtoupper($invoice['currency']) : null,
        ]);

        \App\Services\MembershipNotifier::notify($membership['user']['email'] ?? null, 'renewal_paid', [
            'plan_name' => $membership['plan_name'] ?? null,
            'amount' => isset($invoice['amount_paid']) ? round($invoice['amount_paid'] / 100, 2) : null,
            'date' => $end->toDateString(),
        ], 'stripe-' . $eventId);

        return null;
    }

    private function handlePaymentFailed(array $invoice, string $eventId): ?string
    {
        $membership = $this->resolveMembership($invoice, $invoice['customer_email'] ?? null);
        if (! $membership) {
            return 'No matching membership.';
        }

        $this->centralUpdate($membership, [
            'status' => 'past_due',
            'transaction_reference' => 'failed_' . ($invoice['id'] ?? $eventId) . '_' . ($invoice['attempt_count'] ?? 1),
            'transaction_type' => 'payment_failed',
            'payment_status' => 'failed',
            'payment_processor' => 'stripe',
            'gross_amount' => isset($invoice['amount_due']) ? round($invoice['amount_due'] / 100, 2) : null,
        ]);

        \App\Services\MembershipNotifier::notify($membership['user']['email'] ?? null, 'payment_failed', ['plan_name' => $membership['plan_name'] ?? null], 'stripe-' . $eventId);

        return null;
    }

    private function handleSubscriptionDeleted(array $subscription, string $eventId): ?string
    {
        $membership = $this->resolveMembership($subscription, $subscription['metadata']['customer_email'] ?? null);
        if (! $membership) {
            return 'No matching membership.';
        }

        $this->centralUpdate($membership, [
            'status' => 'canceled',
            'transaction_reference' => 'sub_deleted_' . ($subscription['id'] ?? $eventId),
            'transaction_type' => 'cancellation',
            'gross_amount' => 0,
        ]);

        \App\Services\MembershipNotifier::notify($membership['user']['email'] ?? null, 'expired', ['plan_name' => $membership['plan_name'] ?? null], 'stripe-' . $eventId);

        return null;
    }

    private function handleChargeRefunded(array $charge): ?string
    {
        $email = $charge['billing_details']['email'] ?? $charge['receipt_email'] ?? null;
        $membership = $this->resolveMembership($charge, $email);
        if (! $membership) {
            return 'Refund is not for a membership charge.';
        }

        $refunded = round(((int) ($charge['amount_refunded'] ?? 0)) / 100, 2);
        $full = ! empty($charge['refunded']);

        $this->centralUpdate($membership, [
            'transaction_reference' => 'refund_' . ($charge['id'] ?? uniqid()) . '_' . (int) ($charge['amount_refunded'] ?? 0),
            'transaction_type' => 'refund',
            'payment_status' => $full ? 'refunded' : 'partially_refunded',
            'payment_processor' => 'stripe',
            'gross_amount' => 0,
            'refund_amount' => $refunded,
        ]);

        \App\Services\MembershipNotifier::notify($membership['user']['email'] ?? null, 'refund', ['plan_name' => $membership['plan_name'] ?? null, 'amount' => $refunded], 'refund-' . ($charge['id'] ?? '') . '-' . (int) ($charge['amount_refunded'] ?? 0));

        return null;
    }

    private function handleDispute(array $dispute, string $type): ?string
    {
        $email = $dispute['evidence']['customer_email_address'] ?? null;
        $membership = $this->resolveMembership($dispute, $email);
        if (! $membership) {
            return 'Dispute is not for a membership charge.';
        }

        $closed = $type === 'charge.dispute.closed';
        $status = (string) ($dispute['status'] ?? '');
        $lost = $closed && $status === 'lost';

        $this->centralUpdate($membership, array_filter([
            'status' => $lost ? 'past_due' : null,
            'transaction_reference' => 'dispute_' . ($dispute['id'] ?? uniqid()) . '_' . ($closed ? $status : 'open'),
            'transaction_type' => $closed ? 'dispute_' . ($status ?: 'closed') : 'dispute_opened',
            'payment_status' => $closed ? ($lost ? 'chargeback' : 'paid') : 'disputed',
            'payment_processor' => 'stripe',
            'gross_amount' => 0,
            'refund_amount' => $lost ? round(((int) ($dispute['amount'] ?? 0)) / 100, 2) : 0,
        ], fn ($v) => $v !== null));

        return null;
    }

    // ─── Membership lookup ─────────────────────────────────────────────────────

    /**
     * Prefer an explicit membership id from Stripe metadata (set when the subscription
     * is created); fall back to the customer's email.
     */
    private function resolveMembership(array $object, ?string $email): ?array
    {
        $meta = array_merge(
            (array) ($object['metadata'] ?? []),
            (array) ($object['subscription_details']['metadata'] ?? []),
            (array) ($object['lines']['data'][0]['metadata'] ?? []),
        );
        $membershipId = $meta['membership_id'] ?? $meta['central_membership_id'] ?? null;
        $email = $email ?: ($meta['customer_email'] ?? null);

        $response = $this->centralCall('get', '/v1/internal/admin/memberships');
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new \RuntimeException('Central membership lookup failed (HTTP ' . $response->status() . ').');
        }
        $rows = $response->json('data');

        if ($membershipId) {
            foreach ($rows as $m) {
                if ((string) ($m['id'] ?? '') === (string) $membershipId) {
                    return $m;
                }
            }
        }

        if ($email) {
            $live = ['active', 'trialing', 'past_due', 'grace_period', 'paused', 'pending_upgrade', 'pending_downgrade', 'pending_cancellation'];
            foreach ($rows as $m) {
                if (strtolower($m['user']['email'] ?? '') === strtolower($email)
                    && in_array(strtolower($m['status'] ?? ''), $live, true)) {
                    return $m;
                }
            }
        }

        return null;
    }

    private function centralUpdate(array $membership, array $fields): void
    {
        $response = $this->centralCall('post', '/v1/internal/admin/memberships/update', array_filter(array_merge([
            'membership_id' => $membership['id'],
            'email' => $membership['user']['email'] ?? null,
            'plan_name' => $membership['plan_name'] ?? null,
            'price' => $membership['price'] ?? null,
            'actor_type' => 'system',
        ], $fields), fn ($v) => $v !== null));

        if (! $response->successful()) {
            throw new \RuntimeException('Central membership update failed (HTTP ' . $response->status() . '): ' . mb_substr((string) $response->body(), 0, 300));
        }
    }

    private function centralCall(string $method, string $path, array $data = [])
    {
        $centralUrl = rtrim((string) config('services.central_auth.url'), '/');
        $secret = (string) config('services.internal_notifications.secret');

        $request = Http::acceptJson()
            ->withHeaders(['X-Internal-Notification-Secret' => $secret])
            ->timeout(10);

        return strtolower($method) === 'post'
            ? $request->post($centralUrl . $path, $data)
            : $request->get($centralUrl . $path, $data);
    }

    private function calculateNextPeriodEnd(Carbon $start, string $cycle): Carbon
    {
        $date = $start->copy();
        $norm = strtolower(trim($cycle));

        if (str_contains($norm, 'year') || str_contains($norm, 'annual')) {
            return $date->addYear();
        }
        if (str_contains($norm, 'six') || str_contains($norm, '6')) {
            return $date->addMonths(6);
        }
        if (str_contains($norm, 'quarter')) {
            return $date->addMonths(3);
        }

        return $date->addMonth();
    }

    private function verifyStripeSignature(Request $request, string $secret): bool
    {
        $signatureHeader = (string) $request->header('Stripe-Signature');
        if ($signatureHeader === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                if (trim($kv[0]) === 't') {
                    $timestamp = trim($kv[1]);
                } elseif (trim($kv[0]) === 'v1') {
                    $signatures[] = trim($kv[1]);
                }
            }
        }

        if (! $timestamp || empty($signatures) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($signature, $expected)) {
                return true;
            }
        }

        return false;
    }
}
