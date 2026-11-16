<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController;
use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Models\EcommercePaymentMethod;
use App\Models\EmailNotificationSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Membership Transactions screen.
 *
 * GET  /admin/membership-transactions                       Central ledger rows + live profile, order, loyalty, card and timeline data.
 * POST /admin/membership-transactions/{id}/{action}         refund | void | cancel | note | log  (written to the Central ledger).
 * POST /admin/membership-transactions/{id}/resend-receipt   Emails the receipt (optional PDF attachment, optional CC).
 *
 * Central is the source of truth, so the customer and business panels (which read the same
 * Central rows through /membership-transactions) see every change on their next fetch.
 */
class AdminMembershipTransactionController extends Controller
{
    private const ACTIONS = ['refund', 'void', 'cancel', 'note', 'log'];

    public function index(Request $request): JsonResponse
    {
        $raw = app(EcommerceMembershipController::class)->adminTransactions($request)->getData(true);
        $rows = $this->enrich(is_array($raw['data'] ?? null) ? $raw['data'] : []);

        // One member's history (member details → Billing History). Central and local ids are not
        // shared, so the store is part of the match.
        if ($request->filled('membership_id')) {
            $membershipId = (string) $request->input('membership_id');
            $source = strtolower((string) $request->input('source', ''));
            $rows = array_values(array_filter($rows, fn ($row) => (string) ($row['membership_id'] ?? '') === $membershipId
                && ($source === '' || strtolower((string) ($row['source'] ?? '')) === $source)));
        }

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => ['company' => $this->company(), 'generated_at' => now()->toIso8601String()],
        ]);
    }

    public function action(Request $request, $id, string $action): JsonResponse
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return response()->json(['success' => false, 'message' => 'Unsupported transaction action.'], 404);
        }
        if (! ctype_digit((string) $id)) {
            return response()->json(['success' => false, 'message' => 'This record is not in the central ledger, so it cannot be changed here.'], 422);
        }

        $payload = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'refund_type' => 'nullable|string|in:full,partial,prorated',
            'reason' => 'nullable|string|max:255',
            'details' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:1000',
            'fee_amount' => 'nullable|numeric|min:0',
            'cancel_future_renewals' => 'nullable|boolean',
            'notify_customer' => 'nullable|boolean',
            'prorated' => 'nullable|array',
            'event' => 'nullable|array',
            'event.title' => 'nullable|string|max:120',
            'event.description' => 'nullable|string|max:500',
        ]);
        $payload['action'] = $action;
        $payload['actor'] = $this->actor($request);

        $response = $this->central('post', "/v1/internal/admin/membership-transactions/{$id}/action", $payload);
        if (! $response) {
            return response()->json(['success' => false, 'message' => 'Membership ledger is unavailable. Nothing was changed.'], 502);
        }
        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => $response->json('message') ?: 'The membership ledger rejected this action.',
                'errors' => $response->json('errors'),
            ], $response->status() >= 400 && $response->status() < 500 ? $response->status() : 502);
        }

        $tx = $response->json('data') ?? [];
        $row = $this->enrich([$tx])[0] ?? $tx;
        $message = match ($action) {
            'refund' => 'Refund of $' . number_format((float) ($payload['amount'] ?? 0), 2) . ' recorded for ' . ($tx['transaction_reference'] ?? "#{$id}") . '.',
            'void' => 'Transaction ' . ($tx['transaction_reference'] ?? "#{$id}") . ' voided.',
            'cancel' => 'Transaction ' . ($tx['transaction_reference'] ?? "#{$id}") . ' cancelled.',
            'note' => 'Note saved.',
            default => 'Activity logged.',
        };

        if ($action === 'refund' && ! empty($payload['notify_customer']) && ! empty($row['customer']['email'])) {
            $amount = number_format((float) ($payload['amount'] ?? 0), 2);
            $body = "We have issued a refund of **\${$amount} USD** for your {$row['plan_name']} membership payment ({$row['reference']}).\n\n"
                . "- Reason: {$payload['reason']}\n- Refunded to: {$row['payment']['label']}\n\n"
                . 'Depending on your bank, it can take 5–10 business days for the refund to appear on your statement.';
            $log = $this->mailer()->sendTransactionalEmail(
                ['name' => $row['customer']['name'], 'email' => $row['customer']['email']],
                "Refund issued for {$row['reference']}",
                $body,
                null,
                $request->user(),
                null,
                'admin_membership_refund'
            );
            $message .= $log->status === 'sent' ? ' Confirmation emailed to the customer.' : ' The confirmation email could not be sent: ' . ($log->error_message ?: 'mail error') . '.';
        }

        return response()->json(['success' => true, 'message' => $message, 'data' => $row]);
    }

    /**
     * Email the customer their receipt using the "Membership Receipt" template (Admin → Email
     * Templates), filled with this payment's figures. Same email customers get from their panel.
     */
    private function sendReceiptTemplate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['email' => 'nullable|email']);

        $row = collect($this->enrich($this->rawRows()))->firstWhere('id', $id);
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        $email = $validated['email'] ?? ($row['customer']['email'] ?? null);
        if (! $email) {
            return response()->json(['success' => false, 'message' => 'This customer has no email address on file.'], 422);
        }

        $amounts = $row['amounts'] ?? [];
        $money = fn ($v) => '$' . number_format((float) ($v ?? 0), 2);
        $date = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('M j, Y') : null;
        $start = $date($row['billing_period']['start'] ?? null);
        $end = $date($row['billing_period']['end'] ?? null);

        $results = app(\App\Services\EmailNotificationService::class)->sendEvent('customer_membership_receipt', [
            'customer_name' => $row['customer']['name'] ?? 'Member',
            'customer_email' => $email,
            'membership_plan' => $row['plan_name'] ?? 'your membership',
            'receipt_number' => 'RCP-' . ($row['reference'] ?? $id),
            'receipt_date' => $date($row['created_at'] ?? null) ?? now()->format('M j, Y'),
            'billing_period' => $start && $end && $start !== $end ? "{$start} – {$end}" : ($start ?? '—'),
            'payment_method' => $row['payment']['label'] ?? ucfirst((string) ($row['payment']['method'] ?? 'Card')),
            'subtotal' => $money($amounts['gross'] ?? 0),
            'discount' => $money($amounts['discount'] ?? 0),
            'tax' => $money($amounts['tax'] ?? 0),
            'amount_paid' => $money($amounts['paid'] ?? $amounts['net'] ?? 0),
            'site_name' => config('app.name'),
        ], $email);

        $customerLog = collect($results)->first(fn ($log) => strcasecmp((string) $log->recipient_email, $email) === 0);
        if (! $customerLog || $customerLog->status !== 'sent') {
            $reason = $customerLog?->error_message
                ?: collect($results)->pluck('error_message')->filter()->first()
                ?: 'the email was not sent';

            return response()->json(['success' => false, 'message' => "Receipt not sent to {$email}: {$reason}"], 422);
        }

        if (ctype_digit($id)) {
            $this->central('post', "/v1/internal/admin/membership-transactions/{$id}/action", [
                'action' => 'log',
                'actor' => $this->actor($request),
                'event' => ['title' => 'Receipt Emailed', 'description' => "Membership receipt sent to {$email}"],
            ]);
        }

        return response()->json(['success' => true, 'message' => "Receipt sent to {$email}."]);
    }

    public function resendReceipt(Request $request, $id): JsonResponse
    {
        // The invoice popup's "Send Receipt": the real receipt (Membership Receipt email template
        // with this payment's amounts), not a free-text note.
        if ($request->boolean('use_template')) {
            return $this->sendReceiptTemplate($request, (string) $id);
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'cc' => 'nullable|email',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:4000',
            'attachment' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $row = collect($this->enrich($this->rawRows()))->firstWhere('id', (string) $id);
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        $attachment = null;
        /** @var UploadedFile|null $file */
        $file = $request->file('attachment');
        if ($file) {
            $path = $file->storeAs('membership-receipts/' . now()->format('Y/m'), 'Receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '', $row['reference']) . '-' . now()->format('His') . '.pdf', 'public');
            $attachment = [
                'name' => 'Receipt-' . $row['reference'] . '.pdf',
                'absolute_path' => storage_path('app/public/' . $path),
                'mime' => 'application/pdf',
                'url' => asset('storage/' . $path),
            ];
        }

        $log = $this->mailer()->sendTransactionalEmail(
            ['name' => $row['customer']['name'], 'email' => $validated['email']],
            $validated['subject'],
            $validated['message'],
            $attachment,
            $request->user(),
            $validated['cc'] ?? null,
            'admin_membership_receipt'
        );

        if ($log->status !== 'sent') {
            return response()->json(['success' => false, 'message' => 'Receipt email could not be sent: ' . ($log->error_message ?: 'mail error')], 502);
        }

        $updated = $row;
        if (ctype_digit((string) $id)) {
            $response = $this->central('post', "/v1/internal/admin/membership-transactions/{$id}/action", [
                'action' => 'log',
                'actor' => $this->actor($request),
                'event' => [
                    'title' => 'Receipt Emailed',
                    'description' => 'Sent to ' . $validated['email'] . (! empty($validated['cc']) ? ' (cc ' . $validated['cc'] . ')' : '') . ($attachment ? ' with PDF' : ''),
                ],
            ]);
            if ($response && $response->successful() && is_array($response->json('data'))) {
                $updated = $this->enrich([$response->json('data')])[0];
            }
        }

        return response()->json(['success' => true, 'message' => 'Receipt sent to ' . $validated['email'] . '.', 'data' => $updated]);
    }

    // ---------------------------------------------------------------------
    // Row building
    // ---------------------------------------------------------------------

    private function rawRows(): array
    {
        $raw = app(EcommerceMembershipController::class)->adminTransactions(request())->getData(true);
        return is_array($raw['data'] ?? null) ? $raw['data'] : [];
    }

    private function enrich(array $rows): array
    {
        $emails = collect($rows)->map(fn ($r) => strtolower((string) ($r['user']['email'] ?? '')))->filter()->unique()->values()->all();
        $users = empty($emails)
            ? collect()
            : User::whereIn(DB::raw('LOWER(email)'), $emails)->get()->keyBy(fn ($u) => strtolower($u->email));

        $userIds = $users->pluck('id')->all();
        $orderCounts = empty($userIds) ? collect() : EcommerceOrder::whereIn('user_id', $userIds)->selectRaw('user_id, COUNT(*) as c')->groupBy('user_id')->pluck('c', 'user_id');
        $cards = empty($userIds) ? collect() : EcommercePaymentMethod::whereIn('user_id', $userIds)->orderByDesc('is_default')->orderByDesc('updated_at')->get()->groupBy('user_id')->map->first();
        $addresses = (empty($userIds) || ! Schema::hasTable('ecommerce_addresses')) ? collect() : DB::table('ecommerce_addresses')->whereIn('user_id', $userIds)->orderByDesc('is_default')->orderByDesc('updated_at')->get()->groupBy('user_id')->map->first();

        return collect($rows)->map(function (array $t) use ($users, $orderCounts, $cards, $addresses) {
            $centralUser = is_array($t['user'] ?? null) ? $t['user'] : [];
            $membership = is_array($t['membership'] ?? null) ? $t['membership'] : [];
            $details = is_array($t['details'] ?? null) ? $t['details'] : (is_string($t['details'] ?? null) ? (json_decode($t['details'], true) ?: []) : []);
            $local = $users->get(strtolower((string) ($centralUser['email'] ?? '')));

            $loyalty = null;
            if ($local) {
                try {
                    $loyalty = (int) \App\Services\LoyaltyService::getOrCreateBalance($local)->available_points;
                } catch (\Throwable) {
                    $loyalty = (int) ($local->loyalty_points ?? 0);
                }
            }

            $accountType = strtolower((string) ($membership['account_type'] ?? $centralUser['account_type'] ?? ''));
            if ($accountType === '') {
                $accountType = ($local && ($local->role === 'business' || filled($local->business_name))) ? 'business' : 'personal';
            }
            $isBusiness = $accountType === 'business';
            $name = trim((string) ($centralUser['name'] ?? '')) ?: trim(($centralUser['first_name'] ?? '') . ' ' . ($centralUser['last_name'] ?? '')) ?: ($local?->name ?? ($centralUser['email'] ?? 'Member'));

            $gross = (float) ($t['gross_amount'] ?? 0);
            $discount = (float) ($t['discount_amount'] ?? 0);
            $tax = (float) ($t['tax_amount'] ?? 0);
            $credit = (float) ($t['credit_applied'] ?? 0);
            $paid = round(max(0, $gross - $discount + $tax - $credit), 2);
            $refunded = round((float) ($t['refund_amount'] ?? 0), 2);
            $status = $this->status((string) ($t['payment_status'] ?? ''), $refunded, $paid);

            $payment = $this->payment($t, $details, $cards->get($local?->id));

            $periodStart = $t['billing_period_start'] ?? $membership['current_period_start'] ?? null;
            $periodEnd = $t['billing_period_end'] ?? $membership['current_period_end'] ?? null;
            $membershipStatus = strtolower(str_replace(' ', '_', (string) ($membership['status'] ?? '')));
            $renews = ! empty($membership) && empty($membership['cancel_at_period_end']) && in_array($membershipStatus, ['active', 'trialing', 'past_due', 'grace_period', 'pending_upgrade', 'pending_downgrade'], true);

            $address = $this->address($addresses->get($local?->id), $local, $centralUser);

            return [
                'id' => (string) ($t['id'] ?? ''),
                'source' => str_starts_with((string) ($t['id'] ?? ''), 'local_') ? 'local' : 'central',
                'reference' => (string) ($t['transaction_reference'] ?? ('MEM-' . ($t['id'] ?? ''))),
                'transaction_type' => (string) ($t['transaction_type'] ?? 'subscription'),
                'membership_id' => $membership['id'] ?? $t['central_membership_id'] ?? null,
                'created_at' => $t['created_at'] ?? null,
                'updated_at' => $t['updated_at'] ?? null,
                'status' => $status,
                'raw_payment_status' => $t['payment_status'] ?? null,
                'plan_name' => $this->planLabel((string) ($membership['plan_name'] ?? $details['plan_name'] ?? 'Membership')),
                'plan_code' => $membership['plan_code'] ?? null,
                'account_type' => $accountType,
                'coverage_type' => $membership['coverage_type'] ?? ($details['coverage'] ?? null),
                'billing_cycle' => $membership['billing_cycle'] ?? $details['billing_frequency'] ?? null,
                'billing_period' => ['start' => $periodStart, 'end' => $periodEnd],
                'next_billing_date' => $renews ? ($membership['next_billing_date'] ?? $details['next_renewal_date'] ?? null) : null,
                'auto_renew' => empty($membership) ? null : $renews,
                'membership' => [
                    'status' => $membership['status'] ?? null,
                    'started_at' => $membership['started_at'] ?? $membership['created_at'] ?? null,
                    'final_access_at' => $membership['final_access_at'] ?? null,
                ],
                'customer' => [
                    'name' => $name,
                    'email' => $centralUser['email'] ?? $local?->email,
                    'phone' => ($centralUser['phone'] ?? null) ?: $local?->phone,
                    'address' => $address,
                    'business_name' => $local?->business_name ?: ($isBusiness ? ($membership['business_name'] ?? null) : null),
                    'business_type' => $local?->business_type,
                    'tax_id' => $local?->tax_id,
                    'account_number' => $centralUser['account_number'] ?? $local?->customer_account_number,
                    'member_since' => $centralUser['created_at'] ?? $local?->created_at?->toIso8601String(),
                    'total_orders' => $local ? (int) ($orderCounts[$local->id] ?? 0) : null,
                    'loyalty_points' => $loyalty,
                ],
                'payment' => $payment,
                'amounts' => [
                    'gross' => round($gross, 2),
                    'discount' => round($discount, 2),
                    'tax' => round($tax, 2),
                    'tax_rate' => $gross > 0 && $tax > 0 ? round($tax / max(0.01, $gross - $discount) * 100, 2) : 0,
                    'credit' => round($credit, 2),
                    'paid' => $paid,
                    'refunded' => $refunded,
                    'refundable' => in_array($status, ['Completed', 'Partially Refunded'], true) ? round(max(0, $paid - $refunded), 2) : 0,
                    'net' => round((float) ($t['net_amount'] ?? $paid - $refunded), 2),
                    'currency' => $t['currency'] ?? 'USD',
                ],
                'refunds' => array_values($details['refunds'] ?? []),
                'void' => $details['void'] ?? null,
                'cancellation' => $details['cancellation'] ?? null,
                'notes' => array_values($details['admin_notes'] ?? []),
                'timeline' => $this->timeline($t, $details, $status, $paid, $payment['label']),
            ];
        })->values()->all();
    }

    private function status(string $raw, float $refunded, float $paid): string
    {
        $s = strtolower(str_replace([' ', '-'], '_', $raw));
        if (in_array($s, ['voided', 'void'], true)) return 'Voided';
        if (in_array($s, ['cancelled', 'canceled'], true)) return 'Cancelled';
        if (in_array($s, ['failed', 'declined', 'payment_failed', 'error'], true)) return 'Failed';
        if ($s === 'partially_refunded' || ($refunded > 0 && $refunded < $paid - 0.005)) return 'Partially Refunded';
        if ($s === 'refunded' || ($refunded > 0 && $refunded >= $paid - 0.005)) return 'Refunded';
        if (in_array($s, ['pending', 'authorized', 'payment_pending', 'processing', 'requires_capture'], true)) return 'Pending';
        return 'Completed';
    }

    private function payment(array $t, array $details, ?EcommercePaymentMethod $saved): array
    {
        $method = strtolower((string) ($t['payment_method'] ?? ''));
        $brand = $details['card_brand'] ?? $details['brand'] ?? null;
        $last4 = $details['card_last4'] ?? $details['last4'] ?? null;
        if (! $last4 && preg_match('/(\d{4})\s*$/', (string) ($t['payment_method'] ?? ''), $m)) {
            $last4 = $m[1];
        }
        $isCard = in_array($method, ['card', 'credit_card', 'debit_card', 'stripe', ''], true) || $brand || $last4;
        $fromSaved = false;
        if ($isCard && ! $last4 && $saved) {
            $brand = $saved->brand;
            $last4 = $saved->last4;
            $fromSaved = true;
        }

        $brandLabel = $brand ? $this->brandLabel((string) $brand) : null;
        $label = match (true) {
            $method === 'wallet' => 'Wallet Balance',
            $method === 'paypal' => 'PayPal',
            $isCard && $last4 => ($brandLabel ?: 'Card') . ' •••• ' . $last4,
            $isCard => $brandLabel ?: 'Card',
            default => ucwords(str_replace('_', ' ', $method)),
        };

        return [
            'method' => $method ?: null,
            'brand' => $brandLabel,
            'last4' => $last4,
            'label' => $label,
            'card_from_profile' => $fromSaved,
            'gateway' => $t['payment_processor'] ?? $details['gateway'] ?? $details['payment_processor'] ?? ($method === 'wallet' ? 'Wallet' : null),
            'type' => in_array(strtolower((string) ($t['transaction_type'] ?? '')), ['renewal', 'recurring'], true) ? 'Recurring Payment' : 'One-time Payment',
            'authorization_reference' => $details['authorization_reference'] ?? $details['auth_code'] ?? null,
        ];
    }

    private function brandLabel(string $brand): string
    {
        $b = strtolower($brand);
        return match (true) {
            str_contains($b, 'visa') => 'VISA',
            str_contains($b, 'master') => 'Mastercard',
            str_contains($b, 'amex') || str_contains($b, 'american') => 'AMEX',
            str_contains($b, 'discover') => 'Discover',
            default => ucfirst($brand),
        };
    }

    private function planLabel(string $plan): string
    {
        return $plan === strtolower($plan) ? ucwords(str_replace(['_', '-'], ' ', $plan)) : $plan;
    }

    private function address(?object $row, ?User $local, array $centralUser): ?string
    {
        if ($row) {
            $street = trim(implode(', ', array_filter([$row->address_line_1 ?? null, $row->address_line_2 ?? null]))) ?: ($row->address ?? '');
            $stateZip = trim(($row->state ?? '') . ' ' . ($row->postal_code ?? $row->zip_code ?? ''));
            $formatted = implode(', ', array_filter([$street, $row->city ?? null, $stateZip, $row->country ?? null], fn ($p) => trim((string) $p) !== ''));
            if ($formatted !== '') {
                return $formatted;
            }
        }
        if ($local && filled($local->address)) {
            return is_string($local->address) ? $local->address : json_encode($local->address);
        }
        return $centralUser['country'] ?? null;
    }

    private function timeline(array $t, array $details, string $status, float $paid, string $paymentLabel): array
    {
        $created = $t['created_at'] ?? null;
        $plan = $this->planLabel((string) ($t['membership']['plan_name'] ?? $details['plan_name'] ?? 'Membership'));
        $original = strtolower((string) ($details['void']['previous_status'] ?? $details['cancellation']['previous_status'] ?? $t['payment_status'] ?? ''));
        $wasCaptured = ! in_array($original, ['pending', 'authorized', 'payment_pending', 'processing', 'requires_capture', 'failed', 'declined', 'payment_failed'], true);

        $items = [[
            'title' => 'Payment Initiated',
            'description' => $plan . ' ' . str_replace('_', ' ', (string) ($t['transaction_type'] ?? 'subscription')),
            'at' => $created,
            'kind' => 'info',
        ]];

        if (in_array($original, ['failed', 'declined', 'payment_failed'], true)) {
            $items[] = ['title' => 'Payment Failed', 'description' => '$' . number_format($paid, 2) . ' via ' . $paymentLabel . ' was declined', 'at' => $t['updated_at'] ?? $created, 'kind' => 'error'];
        } elseif ($wasCaptured) {
            $items[] = ['title' => 'Payment Completed', 'description' => 'Payment of $' . number_format($paid, 2) . ' via ' . $paymentLabel . ' succeeded', 'at' => $created, 'kind' => 'success'];
            $items[] = ['title' => 'Membership Active', 'description' => $plan . ' benefits unlocked', 'at' => $created, 'kind' => 'info'];
        } else {
            $items[] = ['title' => 'Awaiting Capture', 'description' => '$' . number_format($paid, 2) . ' authorized on ' . $paymentLabel, 'at' => $created, 'kind' => 'pending'];
        }

        foreach ($details['events'] ?? [] as $e) {
            $items[] = [
                'title' => (string) ($e['title'] ?? 'Update'),
                'description' => trim((string) ($e['description'] ?? '') . (! empty($e['by']) ? ' · by ' . $e['by'] : '')),
                'at' => $e['at'] ?? null,
                'kind' => in_array($e['type'] ?? '', ['refund', 'void', 'cancel', 'renewals_cancelled'], true) ? 'warning' : 'info',
            ];
        }

        return $items;
    }

    private function company(): array
    {
        $site = null;
        $mail = null;
        try {
            $site = SiteSetting::query()->first();
        } catch (\Throwable) {
        }
        try {
            $mail = EmailNotificationSetting::query()->first();
        } catch (\Throwable) {
        }

        return [
            'name' => $site?->site_name ?: ($mail?->from_name && strtolower($mail->from_name) !== 'laravel' ? $mail->from_name : 'Mecarvi Embroidery'),
            'logo' => $site?->email_template_logo ?: $site?->logo,
            'address' => $site?->contact_us_address,
            'phone' => $site?->contact_us_phone,
            'email' => $site?->contact_us_email ?: ($mail?->reply_to_email ?: null),
            'tax_id' => null,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function actor(Request $request): string
    {
        $admin = $request->user();
        return $admin ? trim(($admin->name ?? 'Admin') . ' <' . ($admin->email ?? '') . '>') : 'Admin';
    }

    private function mailer(): AdminMembershipMessageController
    {
        return app(AdminMembershipMessageController::class);
    }

    private function central(string $method, string $path, array $data = [])
    {
        $url = rtrim((string) config('services.central_auth.url'), '/');
        if ($url === '') {
            return null;
        }
        try {
            $req = Http::acceptJson()
                ->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')])
                ->timeout(8);
            return strtolower($method) === 'post' ? $req->post($url . $path, $data) : $req->get($url . $path, $data);
        } catch (\Throwable $e) {
            Log::warning("Central call {$method} {$path} failed: " . $e->getMessage());
            return null;
        }
    }
}
