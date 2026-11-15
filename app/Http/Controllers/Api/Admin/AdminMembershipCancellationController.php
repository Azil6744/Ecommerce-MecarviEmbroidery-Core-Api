<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceMembership;
use App\Models\User;
use App\Services\WalletService;
use App\Services\MembershipProrationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Admin cancellation of a customer's membership.
 *
 * Billing period and proration are computed here (server is authoritative):
 * the customer pays only for the days already used in the current billing
 * cycle; unused days are refunded when cancelling immediately.
 * The frontend mirrors the same math in components/admin/memberships/billingPeriod.ts.
 */
class AdminMembershipCancellationController extends Controller
{
    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'timing' => 'required|string|in:end_of_period,immediate',
            'refund_type' => 'nullable|string|in:prorated,none',
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:300',
            'email' => 'nullable|email',
        ]);

        $admin = $request->user();
        $adminLabel = $admin ? trim(($admin->name ?? 'Admin') . ($admin->email ? " <{$admin->email}>" : '')) : 'Admin';
        $timing = $validated['timing'];
        $refundType = $timing === 'immediate' ? ($validated['refund_type'] ?? 'prorated') : 'none';
        $email = isset($validated['email']) ? strtolower($validated['email']) : null;

        // 1. Locate the membership: central record first (what the admin list shows), then local.
        $central = $this->findCentralMembership((int) $id, $email);
        $local = null;
        if (! $central) {
            $local = EcommerceMembership::with('user')->find($id);
            if ($local && $email && strtolower((string) $local->user?->email) !== $email) {
                $local = null;
            }
            if (! $local && $email) {
                $local = EcommerceMembership::whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
                    ->with('user')
                    ->latest('updated_at')
                    ->first();
            }
        }

        if (! $central && ! $local) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }

        $customerEmail = $central ? strtolower((string) ($central['user']['email'] ?? '')) : strtolower((string) $local->user?->email);
        $customerName = $central ? ($central['user']['name'] ?? 'Customer') : ($local->user?->name ?? 'Customer');
        $status = strtolower((string) ($central['status'] ?? $local->status ?? ''));
        if (in_array($status, ['canceled', 'cancelled', 'expired', 'terminated'], true)) {
            return response()->json(['success' => false, 'message' => 'This membership is already cancelled.'], 422);
        }
        if ($timing === 'end_of_period' && ($status === 'pending_cancellation' || ($local?->cancel_at_period_end))) {
            return response()->json(['success' => false, 'message' => 'This membership is already scheduled to cancel at the end of the billing cycle.'], 422);
        }

        $price = (float) ($central['price'] ?? $local->price ?? 0);
        $cycle = (string) ($central['billing_cycle'] ?? $local->billing_cycle ?? 'monthly');

        // 2. Resolve the CURRENT billing period (rolled forward if stored dates are stale) and proration.
        $today = MembershipProrationService::today();
        $period = MembershipProrationService::resolveCurrentPeriod(
            $central['current_period_start'] ?? null,
            $central['current_period_end'] ?? null,
            $central['next_billing_date'] ?? ($local?->next_billing_date?->toDateString()),
            $cycle,
            1,
            $today
        );
        if (! $period) {
            // No billing dates stored at all: the cycle started when the membership started.
            $anchor = MembershipProrationService::parseDate($central['started_at'] ?? $central['created_at'] ?? $local?->created_at) ?? $today;
            $period = MembershipProrationService::resolveCurrentPeriod($anchor, null, null, $cycle, 1, $today);
        }
        $proration = self::proration($price, $period['start'], $period['end'], $today);
        $refundAmount = $refundType === 'prorated' ? $proration['refund'] : 0.0;
        $effectiveAt = $timing === 'immediate' ? $today->copy() : $period['end']->copy();

        // 3. Issue the prorated refund (wallet credit) first; abort if it fails so nothing is half-done.
        $localCustomer = $customerEmail ? User::whereRaw('LOWER(email) = ?', [$customerEmail])->first() : null;
        $refundReference = null;
        if ($refundAmount > 0) {
            if (! $localCustomer) {
                return response()->json(['success' => false, 'message' => 'Cannot issue refund: customer account not found on this store.'], 422);
            }
            $refundReference = 'MEM-CANCEL-REFUND-' . ($central['id'] ?? $local->id) . '-' . now()->format('YmdHis');
            $credited = WalletService::adjustWallet(
                $localCustomer->id,
                $refundAmount,
                'refund',
                sprintf(
                    'Prorated membership refund: %s (%d unused of %d days, %s – %s)',
                    $central['plan_name'] ?? $local->plan_name ?? 'Membership',
                    $proration['days_remaining'],
                    $proration['total_days'],
                    $period['start']->format('M j, Y'),
                    $period['end']->format('M j, Y')
                ),
                $refundReference
            );
            if (! $credited) {
                return response()->json(['success' => false, 'message' => 'Refund could not be issued. Membership was not cancelled.'], 500);
            }
        }

        $cancellation = [
            'timing' => $timing,
            'reason' => $validated['reason'],
            'details' => $validated['details'] ?? null,
            'requested_at' => now()->toIso8601String(),
            'effective_at' => $effectiveAt->toDateString(),
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
            'total_days' => $proration['total_days'],
            'days_used' => $proration['days_used'],
            'days_remaining' => $proration['days_remaining'],
            'daily_rate' => $proration['daily_rate'],
            'refund_type' => $refundType,
            'refund_amount' => $refundAmount,
            'refund_method' => $refundAmount > 0 ? 'wallet_credit' : null,
            'refund_reference' => $refundReference,
            'cancelled_by' => $adminLabel,
        ];

        // 4. Update the central membership (records an audit log entry, actor = staff).
        $centralData = null;
        if ($central) {
            $auditReason = sprintf(
                'Cancelled by %s (%s). Reason: %s.%s%s',
                $adminLabel,
                $timing === 'immediate' ? 'immediately' : 'at end of billing cycle on ' . $period['end']->format('M j, Y'),
                $validated['reason'],
                ! empty($validated['details']) ? ' Details: ' . $validated['details'] . '.' : '',
                $refundAmount > 0 ? sprintf(' Prorated refund $%s to wallet (%d of %d days unused).', number_format($refundAmount, 2), $proration['days_remaining'], $proration['total_days']) : ''
            );

            $payload = [
                'membership_id' => (int) $central['id'],
                'status' => $timing === 'immediate' ? 'canceled' : 'pending_cancellation',
                'cancel_at_period_end' => $timing !== 'immediate',
                'current_period_start' => $period['start']->toDateString(),
                'current_period_end' => $timing === 'immediate' ? $today->toDateString() : $period['end']->toDateString(),
                'next_billing_date' => $timing === 'immediate' ? $today->toDateString() : $period['end']->toDateString(),
                'final_access_at' => $effectiveAt->toDateString(),
                'metadata' => array_merge(is_array($central['metadata'] ?? null) ? $central['metadata'] : [], [
                    'auto_renewal' => false,
                    'cancellation' => $cancellation,
                ]),
                'admin_authorization_reason' => mb_substr($auditReason, 0, 1000),
            ];
            if ($refundAmount > 0) {
                $payload['refund_amount'] = $refundAmount;
            }

            $response = $this->centralRequest('post', '/v1/internal/admin/memberships/update', $payload);
            if (! $response || ! $response->successful()) {
                if ($refundAmount > 0 && $localCustomer) {
                    WalletService::adjustWallet($localCustomer->id, $refundAmount, 'debit', 'Reversal of membership refund (cancellation failed)', $refundReference . '-REV');
                }
                $message = $response ? ($response->json('message') ?: 'Central membership update failed.') : 'Membership service unreachable.';
                return response()->json(['success' => false, 'message' => $message], $response ? max(400, $response->status()) : 502);
            }
            $centralData = $response->json('data');
        }

        // 5. Keep the local store membership in sync.
        $localTarget = $local ?: ($localCustomer ? EcommerceMembership::where('user_id', $localCustomer->id)->latest('updated_at')->first() : null);
        if ($localTarget) {
            $localTarget->update([
                'status' => $timing === 'immediate' ? 'Cancelled' : $localTarget->status,
                'cancel_at_period_end' => $timing !== 'immediate',
                'cancels_at' => $effectiveAt->toDateString(),
                'cancelled_at' => $timing === 'immediate' ? now() : null,
                'cancellation_reason' => $validated['reason'],
                'cancellation_details' => $validated['details'] ?? null,
                'cancellation_refund_amount' => $refundAmount,
                'cancelled_by' => mb_substr($adminLabel, 0, 255),
                'next_billing_date' => $timing === 'immediate' ? null : $period['end']->toDateString(),
            ]);
        }

        // 6. Notify the customer.
        if ($customerEmail) {
            try {
                app(\App\Services\EmailNotificationService::class)->sendEvent('customer_membership_cancelled', [
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'membership_plan' => $central['plan_name'] ?? $local->plan_name ?? 'Membership',
                    'cancellation_date' => $effectiveAt->format('M j, Y'),
                    'refund_amount' => '$' . number_format($refundAmount, 2),
                    'site_name' => config('app.name'),
                ], $customerEmail);
            } catch (\Throwable $e) {
                Log::warning('Membership cancellation email failed: ' . $e->getMessage());
            }
        }

        if (! $central && $local) {
            \App\Services\MembershipActivityLog::admin($request, 'local', (int) $local->id, $local->user_id, $timing === 'immediate' ? 'cancelled' : 'cancel', [
                'amount' => $refundAmount > 0 ? $refundAmount : null,
                'details' => ['reason' => $request->input('reason'), 'effective' => $timing === 'immediate' ? null : $period['end']->toDateString()],
            ]);
        }

        // In-app (and SMS) notice for the member.
        \App\Services\MembershipNotifier::notify($customerEmail, 'admin_cancelled', [
            'plan_name' => $central['plan_name'] ?? $local->plan_name ?? null,
            'date' => $timing === 'immediate' ? null : $period['end']->toDateString(),
            'amount' => $refundAmount,
        ]);
        if ($refundAmount > 0) {
            \App\Services\MembershipNotifier::notify($customerEmail, 'refund', [
                'plan_name' => $central['plan_name'] ?? $local->plan_name ?? null,
                'amount' => $refundAmount,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $timing === 'immediate'
                ? 'Membership cancelled immediately.'
                : 'Membership will cancel on ' . $period['end']->format('M j, Y') . '.',
            'data' => $centralData ?? $localTarget?->fresh('user'),
            'cancellation' => $cancellation,
        ]);
    }

    /**
     * Customer pays only for days used; unused days are refunded.
     * Days used = full days elapsed from period start up to today (access ends today).
     */
    public static function proration(float $price, CarbonInterface $start, CarbonInterface $end, CarbonInterface $today): array
    {
        $s = CarbonImmutable::instance($start);
        $totalDays = max(1, MembershipProrationService::daysBetween($s, CarbonImmutable::instance($end)));
        $daysUsed = min($totalDays, max(0, MembershipProrationService::daysBetween($s, CarbonImmutable::instance($today))));
        $daysRemaining = $totalDays - $daysUsed;
        $priceCents = (int) round($price * 100);
        $refundCents = (int) round($priceCents * $daysRemaining / $totalDays);

        return [
            'total_days' => $totalDays,
            'days_used' => $daysUsed,
            'days_remaining' => $daysRemaining,
            'daily_rate' => round($price / $totalDays, 4),
            'used_amount' => ($priceCents - $refundCents) / 100,
            'refund' => $refundCents / 100,
        ];
    }

    private function findCentralMembership(int $id, ?string $email): ?array
    {
        $response = $this->centralRequest('get', '/v1/internal/admin/memberships');
        if (! $response || ! $response->successful() || ! is_array($response->json('data'))) {
            return null;
        }

        foreach ($response->json('data') as $row) {
            if ((int) ($row['id'] ?? 0) !== $id) {
                continue;
            }
            if ($email && strtolower((string) ($row['user']['email'] ?? '')) !== $email) {
                return null;
            }
            return $row;
        }

        return null;
    }

    private function centralRequest(string $method, string $path, array $data = [])
    {
        $centralUrl = rtrim((string) config('services.central_auth.url'), '/');
        if ($centralUrl === '') {
            return null;
        }

        try {
            $request = Http::acceptJson()
                ->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')])
                ->timeout(8);

            return strtolower($method) === 'post'
                ? $request->post($centralUrl . $path, $data)
                : $request->get($centralUrl . $path, $data);
        } catch (\Throwable $e) {
            Log::warning("Central membership request ({$method} {$path}) failed: " . $e->getMessage());
            return null;
        }
    }
}
