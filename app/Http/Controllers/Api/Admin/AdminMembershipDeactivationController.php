<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceMembership;
use App\Models\User;
use App\Services\MembershipProrationService;
use App\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Admin deactivation of a customer's membership.
 *
 * The server is authoritative for dates and money:
 *  - The CURRENT billing period is resolved from the stored dates, rolled forward
 *    whole cycles when the stored renewal date has already passed.
 *  - Proration: the customer pays only for the days used. Days used = days from the
 *    period start up to (not including) today; unused days = the rest of the period.
 *    Refund = price × unused days ÷ days in period (integer cents).
 *  - "end_of_period": nothing is refunded; access continues until the period end, then
 *    the membership is deactivated (central renewal job / list sync applies it).
 *  - "immediate": deactivated today; the unused days are optionally refunded to the
 *    customer's wallet.
 *
 * The modal mirrors this math with lib/membership-proration.ts.
 */
class AdminMembershipDeactivationController extends Controller
{
    private const ACTIVE_LIKE = ['active', 'trialing', 'past_due', 'grace_period', 'pending_upgrade', 'pending_downgrade', 'pending_cancellation'];
    private const INACTIVE = ['inactive', 'suspended', 'terminated', 'canceled', 'cancelled', 'expired'];

    public function deactivate(Request $request, $id)
    {
        $validated = $request->validate([
            'source' => 'nullable|string|in:central,local',
            'email' => 'nullable|email',
            'timing' => 'required|string|in:end_of_period,immediate',
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:300',
            'refund' => 'nullable|boolean',
            'client_date' => 'nullable|date_format:Y-m-d',
        ]);

        $admin = $request->user();
        $adminLabel = $admin ? trim(($admin->name ?? 'Admin') . ($admin->email ? " <{$admin->email}>" : '')) : 'Admin';
        $email = isset($validated['email']) ? strtolower($validated['email']) : null;
        $source = $validated['source'] ?? null;
        $immediate = $validated['timing'] === 'immediate';

        // 1. Locate the membership the admin is looking at.
        $central = $source === 'local' ? null : $this->findCentralMembership((int) $id, $email);
        $local = null;
        if (! $central) {
            $local = EcommerceMembership::with('user')->find($id);
            if ($local && $email && strtolower((string) $local->user?->email) !== $email) {
                $local = null;
            }
        }
        if (! $central && ! $local) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }

        $customerEmail = strtolower((string) ($central ? ($central['user']['email'] ?? '') : $local->user?->email));
        $planName = (string) ($central['plan_name'] ?? $local->plan_name ?? 'Membership');
        $status = strtolower((string) ($central['status'] ?? $local->status ?? ''));
        $metadata = is_array($central['metadata'] ?? null) ? $central['metadata'] : [];

        if (in_array($status, self::INACTIVE, true)) {
            return response()->json(['success' => false, 'message' => 'This membership is already inactive.'], 422);
        }
        if (! $immediate && (! empty($metadata['scheduled_deactivation']) || $local?->deactivation_scheduled_at)) {
            return response()->json(['success' => false, 'message' => 'A deactivation is already scheduled for the end of this billing cycle.'], 422);
        }

        // 2. Current billing period + proration. A paused membership has no running paid cycle.
        // "Today" is the admin's calendar date (what the modal showed) when it differs from the
        // server's UTC date only by timezone (±1 day); otherwise the server date is used.
        $today = MembershipProrationService::today();
        $clientDate = MembershipProrationService::parseDate($validated['client_date'] ?? null);
        if ($clientDate && abs(MembershipProrationService::daysBetween($today, $clientDate)) <= 1) {
            $today = $clientDate;
        }
        $price = (float) ($central['price'] ?? $local->price ?? 0);
        $cycle = (string) ($central['billing_cycle'] ?? $local->billing_cycle ?? 'monthly');
        $period = null;
        if (in_array($status, self::ACTIVE_LIKE, true)) {
            $period = MembershipProrationService::resolveCurrentPeriod(
                $central['current_period_start'] ?? null,
                $central['current_period_end'] ?? null,
                $central['next_billing_date'] ?? $local?->next_billing_date?->toDateString(),
                $cycle,
                1,
                $today
            );
            if (! $period) {
                $anchor = MembershipProrationService::parseDate($central['started_at'] ?? $central['created_at'] ?? $local?->created_at) ?? $today;
                $period = MembershipProrationService::resolveCurrentPeriod($anchor, null, null, $cycle, 1, $today);
            }
        }
        if (! $immediate && ! $period) {
            return response()->json(['success' => false, 'message' => 'This membership has no running billing cycle. Deactivate it immediately instead.'], 422);
        }

        $proration = self::proration($price, $period, $today);
        $refundAmount = ($immediate && $request->boolean('refund')) ? $proration['refund_amount'] : 0.0;
        $effective = $immediate ? $today : $period['end'];

        // 3. Refund first; nothing else changes if it fails.
        $localCustomer = $customerEmail ? User::whereRaw('LOWER(email) = ?', [$customerEmail])->first() : null;
        $refundReference = null;
        if ($refundAmount > 0) {
            if (! $localCustomer) {
                return response()->json(['success' => false, 'message' => 'Cannot issue refund: customer account not found on this store.'], 422);
            }
            $refundReference = 'MEM-DEACT-REFUND-' . ($central['id'] ?? $local->id) . '-' . now()->format('YmdHis');
            $credited = WalletService::adjustWallet(
                $localCustomer->id,
                $refundAmount,
                'refund',
                sprintf(
                    'Prorated membership refund: %s deactivated (%d unused of %d days, %s – %s)',
                    $planName,
                    $proration['unused_days'],
                    $proration['cycle_days'],
                    $period['start']->format('M j, Y'),
                    $period['end']->format('M j, Y')
                ),
                $refundReference
            );
            if (! $credited) {
                return response()->json(['success' => false, 'message' => 'Refund could not be issued. Membership was not deactivated.'], 500);
            }
        }

        $deactivation = array_merge($proration, [
            'timing' => $validated['timing'],
            'reason' => $validated['reason'],
            'details' => $validated['details'] ?? null,
            'effective_date' => $effective->toDateString(),
            'requested_at' => now()->toIso8601String(),
            'requested_by' => $adminLabel,
            'refund_amount' => $refundAmount,
            'refund_method' => $refundAmount > 0 ? 'wallet_credit' : null,
            'refund_reference' => $refundReference,
        ]);

        // 4. Central membership (writes a staff audit log entry).
        $centralData = null;
        if ($central) {
            if ($immediate) {
                unset($metadata['scheduled_deactivation']);
                $metadata['deactivation'] = $deactivation;
            } else {
                $metadata['scheduled_deactivation'] = $deactivation;
            }
            $auditReason = sprintf(
                'Deactivated by %s (%s). Reason: %s.%s%s',
                $adminLabel,
                $immediate ? 'immediately' : 'at end of billing cycle on ' . $effective->format('M j, Y'),
                $validated['reason'],
                ! empty($validated['details']) ? ' Details: ' . $validated['details'] . '.' : '',
                $refundAmount > 0 ? sprintf(' Prorated refund $%s to wallet (%d of %d days unused).', number_format($refundAmount, 2), $proration['unused_days'], $proration['cycle_days']) : ''
            );

            $payload = [
                'membership_id' => (int) $central['id'],
                'final_access_at' => $effective->toDateString(),
                'metadata' => $metadata,
                'admin_authorization_reason' => mb_substr($auditReason, 0, 1000),
            ];
            if ($period) {
                // Persist the real current cycle so stale stored dates are corrected.
                $payload['current_period_start'] = $period['start']->toDateString();
                $payload['current_period_end'] = $period['end']->toDateString();
                $payload['next_billing_date'] = $period['end']->toDateString();
            }
            if ($immediate) {
                $payload['status'] = 'suspended';
                $payload['cancel_at_period_end'] = true;
            }

            $response = $this->centralRequest('post', '/v1/internal/admin/memberships/update', $payload);
            if (! $response || ! $response->successful()) {
                if ($refundAmount > 0 && $localCustomer) {
                    WalletService::adjustWallet($localCustomer->id, $refundAmount, 'debit', 'Reversal of membership refund (deactivation failed)', $refundReference . '-REV');
                }
                $message = $response ? ($response->json('message') ?: 'Central membership update failed.') : 'Membership service unreachable.';
                return response()->json(['success' => false, 'message' => $message], $response ? max(400, $response->status()) : 502);
            }
            $centralData = $response->json('data');
        }

        // 5. Local store membership (the record itself, or the customer's mirror of the central one).
        $localTarget = $local ?: ($localCustomer ? EcommerceMembership::where('user_id', $localCustomer->id)->latest('updated_at')->first() : null);
        if ($localTarget) {
            $localTarget->update($immediate
                ? [
                    'status' => 'Inactive',
                    'deactivated_at' => now(),
                    'deactivation_scheduled_at' => null,
                    'deactivation_reason' => $validated['reason'],
                    'deactivation_details' => $validated['details'] ?? null,
                    'deactivation_refund_amount' => $refundAmount,
                    'next_billing_date' => null,
                ]
                : [
                    'deactivation_scheduled_at' => $effective->toDateString(),
                    'deactivation_reason' => $validated['reason'],
                    'deactivation_details' => $validated['details'] ?? null,
                    'deactivation_refund_amount' => 0,
                    'next_billing_date' => $period['end']->toDateString(),
                ]);
        }

        if (! $central && $local) {
            \App\Services\MembershipActivityLog::admin($request, 'local', (int) $local->id, $local->user_id, $immediate ? 'deactivated' : 'deactivation_scheduled', [
                'amount' => $refundAmount > 0 ? $refundAmount : null,
                'details' => ['reason' => $validated['reason'], 'effective' => $effective->toDateString()],
            ]);
        }

        // In-app (and SMS) notice for the member.
        \App\Services\MembershipNotifier::notify($customerEmail, $immediate ? 'deactivated' : 'deactivation_scheduled', [
            'plan_name' => $planName,
            'date' => $effective->toDateString(),
        ]);
        if ($refundAmount > 0) {
            \App\Services\MembershipNotifier::notify($customerEmail, 'refund', ['plan_name' => $planName, 'amount' => $refundAmount]);
        }

        return response()->json([
            'success' => true,
            'message' => $immediate
                ? 'Membership deactivated.' . ($refundAmount > 0 ? sprintf(' $%s refunded to the customer\'s wallet.', number_format($refundAmount, 2)) : '')
                : 'Membership will be deactivated on ' . $effective->format('M j, Y') . '.',
            'data' => [
                'source' => $central ? 'central' : 'local',
                'status' => $immediate ? 'inactive' : $status,
                'effective_date' => $effective->toDateString(),
                'deactivation' => $deactivation,
                'membership' => $centralData ?? $localTarget?->fresh(),
            ],
        ]);
    }

    /**
     * @param array{start: CarbonImmutable, end: CarbonImmutable, total_days: int}|null $period
     */
    public static function proration(float $price, ?array $period, CarbonImmutable $today): array
    {
        if (! $period || $period['total_days'] <= 0) {
            return [
                'period_start' => null, 'period_end' => null, 'cycle_days' => 0, 'used_days' => 0,
                'unused_days' => 0, 'daily_rate' => 0.0, 'refund_amount_available' => 0.0, 'refund_amount' => 0.0,
            ];
        }
        $total = (int) $period['total_days'];
        $used = min(max(MembershipProrationService::daysBetween($period['start'], $today), 0), $total);
        $unused = $total - $used;
        $priceCents = (int) round($price * 100);
        $refund = (int) round($priceCents * $unused / $total) / 100;

        return [
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
            'cycle_days' => $total,
            'used_days' => $used,
            'unused_days' => $unused,
            'daily_rate' => round($price / $total, 4),
            'refund_amount_available' => $refund,
            'refund_amount' => $refund,
        ];
    }

    /**
     * Admin list helper for central rows: tags the source, applies scheduled deactivations
     * whose date has arrived, and attaches the customer's real loyalty balance.
     */
    public static function prepareCentralRows(array $rows): array
    {
        $self = app(self::class);
        $today = MembershipProrationService::today();
        $emails = collect($rows)->map(fn ($r) => strtolower((string) ($r['user']['email'] ?? '')))->filter()->unique()->values()->all();
        $users = empty($emails) ? collect() : User::whereIn(DB::raw('LOWER(email)'), $emails)->get()->keyBy(fn ($u) => strtolower($u->email));

        return collect($rows)->map(function ($row) use ($self, $today, $users) {
            $row['source'] = 'central';

            $scheduled = $row['metadata']['scheduled_deactivation'] ?? null;
            $effective = is_array($scheduled) ? MembershipProrationService::parseDate($scheduled['effective_date'] ?? null) : null;
            if ($effective && $effective->lte($today) && in_array(strtolower((string) ($row['status'] ?? '')), self::ACTIVE_LIKE, true)) {
                $metadata = $row['metadata'];
                unset($metadata['scheduled_deactivation']);
                $metadata['deactivation'] = array_merge($scheduled, ['applied_at' => now()->toIso8601String()]);
                $response = $self->centralRequest('post', '/v1/internal/admin/memberships/update', [
                    'membership_id' => (int) $row['id'],
                    'status' => 'suspended',
                    'metadata' => $metadata,
                    'admin_authorization_reason' => 'Scheduled deactivation applied at end of billing cycle (' . $effective->toDateString() . ').',
                ]);
                if ($response && $response->successful() && is_array($response->json('data'))) {
                    $row = array_merge($response->json('data'), ['source' => 'central']);
                }
            }

            $user = $users->get(strtolower((string) ($row['user']['email'] ?? '')));
            // Store-side account state the admin list needs (ban, last sign-in on this store).
            $row['member_banned_at'] = $user?->banned_at?->toIso8601String();
            $row['store_last_login_at'] = $user?->last_login_at?->toIso8601String();
            if ($user && ! isset($row['points_total'])) {
                $row = array_merge($row, self::loyaltyProgress($user));
            }

            return $row;
        })->all();
    }

    /**
     * Points and loyalty-tier progress for the member card: same tier rules as the customer's
     * own rewards page (progress by available points toward the next active tier).
     */
    public static function loyaltyProgress(\App\Models\User $user): array
    {
        try {
            $balance = \App\Services\LoyaltyService::getOrCreateBalance($user);
            $available = (int) $balance->available_points;
            $business = \App\Services\LoyaltyService::isBusinessUser($user);
            $tier = \App\Services\LoyaltyService::tierFor($available, $business);
            $next = \App\Services\LoyaltyService::nextTierFor($available, $business);
            $progress = 100.0;
            if ($next) {
                $span = max(1, (int) $next['min_points'] - (int) $tier['min_points']);
                $progress = round(min(100, max(0, (($available - (int) $tier['min_points']) / $span) * 100)), 1);
            }

            return [
                'points_total' => $available,
                'points_redeemed' => (int) $balance->redeemed_points,
                'points_earned' => (int) $balance->lifetime_earned,
                'loyalty_tier' => $tier['name'] ?? null,
                'next_tier' => $next['name'] ?? null,
                'target_points' => $next ? (int) $next['min_points'] : $available,
                'progress_percent' => $progress,
                'points_needed' => $next ? max(0, (int) $next['min_points'] - $available) : 0,
            ];
        } catch (\Throwable $e) {
            return ['points_total' => (int) ($user->loyalty_points ?? 0)];
        }
    }

    /** Local fallback: apply scheduled deactivations whose date has arrived. */
    public static function syncLocalScheduledDeactivations(): void
    {
        try {
            EcommerceMembership::whereNotNull('deactivation_scheduled_at')
                ->whereDate('deactivation_scheduled_at', '<=', MembershipProrationService::today()->toDateString())
                ->update([
                    'status' => 'Inactive',
                    'deactivated_at' => now(),
                    'deactivation_scheduled_at' => null,
                    'next_billing_date' => null,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Scheduled membership deactivation sync failed: ' . $e->getMessage());
        }
    }

    private function centralRequest(string $method, string $path, array $data = [])
    {
        $base = rtrim((string) config('services.central_auth.url'), '/');
        if ($base === '') {
            return null;
        }
        try {
            $request = Http::acceptJson()
                ->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')])
                ->timeout(6);
            return strtolower($method) === 'post' ? $request->post($base . $path, $data) : $request->get($base . $path, $data);
        } catch (\Throwable $e) {
            Log::warning("Central call ({$method} {$path}) failed: " . $e->getMessage());
            return null;
        }
    }

    private function findCentralMembership(int $id, ?string $email): ?array
    {
        $response = $this->centralRequest('get', '/v1/internal/admin/memberships');
        if (! $response || ! $response->successful() || ! is_array($response->json('data'))) {
            return null;
        }
        $row = collect($response->json('data'))->first(fn ($m) => (int) ($m['id'] ?? 0) === $id);
        if ($row && $email && strtolower((string) ($row['user']['email'] ?? '')) !== $email) {
            return null;
        }
        return $row;
    }
}
