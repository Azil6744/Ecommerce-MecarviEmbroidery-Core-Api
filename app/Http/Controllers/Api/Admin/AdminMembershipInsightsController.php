<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Ecommerce\CustomerMembershipController;
use App\Http\Controllers\Controller;
use App\Models\EcommerceMembership;
use App\Models\EcommerceMembershipActivity;
use App\Models\EcommercePaymentMethod;
use App\Models\EcommerceSubscriptionPlan;
use App\Models\User;
use App\Services\MembershipProrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live numbers for the Manage Customer / Business Members pages:
 *  - GET /admin/memberships/stats?account_type=personal|business
 *      every plan for that account type with subscriber counts by status and revenue,
 *      plus the "Pending Actions" lists (each with the members behind the count, for the popups).
 *  - GET /admin/memberships/{id}/activity?source=central|local
 *      the membership's activity log (subscribe, upgrade, downgrade, pause, resume, cancel, ...).
 */
class AdminMembershipInsightsController extends Controller
{
    private const PAUSED = ['paused'];
    private const CANCELLED = ['canceled', 'cancelled', 'expired', 'terminated', 'inactive'];
    private const FLAGGED = ['past_due', 'payment_failed', 'grace_period', 'suspended', 'banned'];

    public function stats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_type' => 'nullable|string|in:personal,business,all',
        ]);
        $mode = $validated['account_type'] ?? 'all';

        CustomerMembershipController::syncLocalScheduledChanges();

        [$rows, $source] = $this->memberships();
        $members = $rows->reject(fn ($r) => self::isDeleted($r))
            ->map(fn ($r) => $this->normalize($r, $source))
            ->filter(fn ($m) => $mode === 'all' || $m['account_type'] === $mode)
            ->values();

        $plans = EcommerceSubscriptionPlan::query()
            ->when($mode !== 'all', fn ($q) => $q->whereRaw('LOWER(COALESCE(account_type, ?)) = ?', ['personal', $mode]))
            ->whereRaw('LOWER(COALESCE(status, ?)) NOT IN (?, ?)', ['active', 'archived', 'retired'])
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        $lifetime = $this->lifetimeRevenueByPlan($source);

        $planStats = $plans->map(function (EcommerceSubscriptionPlan $plan) use ($members, $lifetime) {
            $onPlan = $members->filter(fn ($m) => $this->onPlan($m, $plan));
            $active = $onPlan->where('bucket', 'active');

            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
                'account_type' => strtolower((string) ($plan->account_type ?: 'personal')),
                'status' => $plan->status,
                // "Current subscribers" = everyone still holding the plan (not cancelled/expired).
                'current_subscribers' => $onPlan->whereIn('bucket', ['active', 'paused', 'flagged'])->count(),
                'active' => $active->count(),
                'paused' => $onPlan->where('bucket', 'paused')->count(),
                'cancelled' => $onPlan->where('bucket', 'cancelled')->count(),
                'flagged' => $onPlan->where('bucket', 'flagged')->count(),
                'current_monthly_revenue' => round($active->sum('monthly_price'), 2),
                'lifetime_revenue' => round((float) ($lifetime[strtolower($plan->name)] ?? 0), 2),
            ];
        })->values();

        // Members whose plan is not one of the listed plans (renamed/retired plans or test data).
        $unmatched = $members->reject(fn ($m) => $plans->contains(fn ($p) => $this->onPlan($m, $p)))->values();

        $today = MembershipProrationService::today();
        $in7 = $today->addDays(7);

        $expiring = $members->filter(function ($m) use ($today, $in7) {
            if (in_array($m['bucket'], ['cancelled'], true)) {
                return false;
            }
            $ends = $m['access_ends_at'] ?? ($m['auto_renew'] ? null : $m['next_billing_date']);
            $d = MembershipProrationService::parseDate($ends);
            return $d && $d->gte($today) && $d->lte($in7);
        });

        $paymentFailed = $members->filter(fn ($m) => in_array($m['status'], ['past_due', 'payment_failed', 'grace_period'], true)
            || in_array(strtolower((string) ($m['payment_status'] ?? '')), ['failed', 'payment_failed'], true));

        $autoRenewOff = $members->filter(fn ($m) => ! $m['auto_renew'] && $m['bucket'] !== 'cancelled');

        $cardExpiring = $this->paymentMethodsExpiring($members);
        $duplicates = $this->duplicateAccounts($members);

        $pick = fn (Collection $c, ?string $note = null) => $c->map(fn ($m) => $this->summary($m, $note ? ($m[$note] ?? null) : null))->values();

        return response()->json([
            'success' => true,
            'data' => [
                'account_type' => $mode,
                'source' => $source,
                'totals' => [
                    'members' => $members->count(),
                    'active' => $members->where('bucket', 'active')->count(),
                    'paused' => $members->where('bucket', 'paused')->count(),
                    'cancelled' => $members->where('bucket', 'cancelled')->count(),
                    'flagged' => $members->where('bucket', 'flagged')->count(),
                    'current_monthly_revenue' => round($members->where('bucket', 'active')->sum('monthly_price'), 2),
                ],
                'plans' => $planStats,
                'unmatched_plan_members' => [
                    'count' => $unmatched->count(),
                    'items' => $pick($unmatched->map(function ($m) {
                        $linked = ! empty($m['plan_id']) ? EcommerceSubscriptionPlan::find($m['plan_id']) : null;
                        $linkedType = $linked ? strtolower((string) ($linked->account_type ?: 'personal')) : null;
                        $note = $linked && $linkedType !== $m['account_type']
                            ? ucfirst($m['account_type']) . " membership is linked to the {$linkedType} plan \"{$linked->name}\""
                            : 'Plan "' . ($m['plan_name'] ?? 'unknown') . '" is not an active plan';
                        return $m + ['plan_note' => $note];
                    }), 'plan_note'),
                ],
                'pending_actions' => [
                    ['key' => 'expiring_soon', 'label' => 'Expiring Soon (Next 7 Days)', 'description' => 'Memberships that end in the next 7 days and will not renew.', 'count' => $expiring->count(), 'items' => $pick($expiring)],
                    ['key' => 'payment_failed', 'label' => 'Payment Failed', 'description' => 'Renewal payment failed or is past due.', 'count' => $paymentFailed->count(), 'items' => $pick($paymentFailed)],
                    ['key' => 'payment_method_expiring', 'label' => 'Payment Method Expiring', 'description' => 'Saved card expires this month or next.', 'count' => $cardExpiring->count(), 'items' => $cardExpiring->values()],
                    ['key' => 'duplicate_accounts', 'label' => 'Duplicate Accounts (Identity)', 'description' => 'Different accounts sharing the same phone number, or one account holding more than one live membership.', 'count' => $duplicates->count(), 'items' => $duplicates->values()],
                    ['key' => 'auto_renew_disabled', 'label' => 'Auto-Renewal Disabled', 'description' => 'Members who turned off auto-renewal or are scheduled to cancel.', 'count' => $autoRenewOff->count(), 'items' => $pick($autoRenewOff)],
                ],
            ],
        ]);
    }

    public function activity(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'source' => 'nullable|string|in:central,local',
            'email' => 'nullable|email',
        ]);
        $source = $validated['source'] ?? 'central';
        $items = collect();
        $localUser = null;

        if ($source === 'central') {
            $response = $this->central('get', '/v1/internal/admin/membership-audit-logs');
            if ($response && $response->successful()) {
                $logs = collect($response->json('data') ?? [])
                    ->filter(fn ($l) => (int) ($l['central_membership_id'] ?? 0) === (int) $id)
                    ->sortBy('created_at')
                    ->values();

                foreach ($logs as $log) {
                    $mapped = $this->mapAuditLog($log);
                    if ($mapped) {
                        $items->push($mapped);
                    }
                    $localUser ??= ! empty($log['user']['email'])
                        ? User::whereRaw('LOWER(email) = ?', [strtolower($log['user']['email'])])->first()
                        : null;
                }
            }
            if (! $localUser && ! empty($validated['email'])) {
                $localUser = User::whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->first();
            }

            // Local entries that carry a payment reference are already in Central's log; add the rest.
            if ($localUser) {
                EcommerceMembershipActivity::where('user_id', $localUser->id)
                    ->where('source', 'central')
                    ->get()
                    ->filter(fn ($a) => (int) (($a->details ?? [])['central_membership_id'] ?? 0) === (int) $id)
                    ->filter(fn ($a) => in_array($a->action, ['auto_renew_on', 'auto_renew_off', 'trial_review', 'message_sent'], true))
                    ->each(fn ($a) => $items->push($this->mapLocalActivity($a)));
            }
        } else {
            $membership = EcommerceMembership::with('user')->find($id);
            if (! $membership) {
                return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
            }
            $items->push([
                'id' => 'local-created-' . $membership->id,
                'date' => optional($membership->created_at)->toIso8601String(),
                'type' => 'subscribe',
                'label' => 'Subscribe',
                'details' => 'Subscribed to ' . $membership->plan_name . ' (' . ($membership->billing_cycle ?: 'Monthly') . ')',
                'performed_by' => 'customer',
                'performed_by_label' => 'Customer (Self)',
            ]);
            EcommerceMembershipActivity::where(function ($q) use ($membership) {
                $q->where('membership_id', $membership->id)->orWhere(fn ($q2) => $q2->where('user_id', $membership->user_id)->where('source', 'local'));
            })->get()->each(fn ($a) => $items->push($this->mapLocalActivity($a)));
        }

        $sorted = $items->sortByDesc(fn ($i) => strtotime((string) ($i['date'] ?? '')) ?: 0)->values();

        return response()->json(['success' => true, 'data' => $sorted]);
    }

    /**
     * POST /admin/memberships/{id}/ban  { source, email, banned: true|false }
     * Bans (or unbans) the member's account on this store and in Central, same as Customers → Ban.
     */
    public function ban(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'source' => 'nullable|string|in:central,local',
            'email' => 'nullable|email',
            'banned' => 'required|boolean',
        ]);

        $user = $this->memberUser($id, $validated['source'] ?? 'central', $validated['email'] ?? null);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Member account not found on this store.'], 404);
        }

        $admin = $request->user();
        if ($admin && (int) $admin->id === (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'You cannot ban your own account.'], 422);
        }

        $banned = (bool) $validated['banned'];
        $user->banned_at = $banned ? now() : null;
        $user->save();

        $synced = false;
        $response = $this->central('post', '/v1/internal/admin/users/ban-status', [
            'email' => $user->email,
            'site_slug' => config('services.mccarvy_site.slug', 'embroidery'),
            'status' => $banned ? 'banned' : 'active',
        ]);
        if ($response && $response->successful()) {
            $synced = true;
        } else {
            Log::warning('Membership ban: Central sync failed for ' . $user->email);
        }

        EcommerceMembershipActivity::create([
            'membership_id' => ($validated['source'] ?? '') === 'local' ? (int) $id : null,
            'user_id' => $user->id,
            'source' => $validated['source'] ?? 'central',
            'action' => $banned ? 'banned' : 'unbanned',
            'details' => ['central_membership_id' => ($validated['source'] ?? 'central') === 'central' ? (int) $id : null],
            'performed_by' => 'admin',
            'actor_name' => $admin?->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => ($banned ? "{$user->name} has been banned." : "{$user->name} has been unbanned.")
                . ($synced ? '' : ' (Central sync pending — the store ban is in effect.)'),
            'data' => ['user_id' => $user->id, 'banned_at' => optional($user->banned_at)->toIso8601String()],
        ]);
    }

    /**
     * DELETE /admin/memberships/{id}?source=central|local&email=
     * Removes the membership from the admin list. Local rows are deleted; Central has no delete,
     * so the Central record is terminated and flagged as removed (kept for audit/billing history).
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'source' => 'nullable|string|in:central,local',
            'email' => 'nullable|email',
        ]);
        $source = $validated['source'] ?? 'central';
        $admin = $request->user();
        $label = $admin ? trim(($admin->name ?? 'Admin') . ' <' . ($admin->email ?? '') . '>') : 'Admin';

        if ($source === 'local') {
            $membership = EcommerceMembership::with('user')->find($id);
            if (! $membership) {
                return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
            }
            if (! empty($validated['email']) && strtolower((string) $membership->user?->email) !== strtolower($validated['email'])) {
                return response()->json(['success' => false, 'message' => 'Membership does not belong to that member.'], 422);
            }
            EcommerceMembershipActivity::create([
                'user_id' => $membership->user_id,
                'source' => 'local',
                'action' => 'deleted',
                'from_plan' => $membership->plan_name,
                'performed_by' => 'admin',
                'actor_name' => $admin?->name,
                'ip_address' => $request->ip(),
            ]);
            $membership->delete();

            return response()->json(['success' => true, 'message' => 'Membership deleted.']);
        }

        $response = $this->central('get', '/v1/internal/admin/memberships');
        $row = $response && $response->successful() ? collect($response->json('data') ?? [])->firstWhere('id', (int) $id) : null;
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }
        if (! empty($validated['email']) && strtolower((string) ($row['user']['email'] ?? '')) !== strtolower($validated['email'])) {
            return response()->json(['success' => false, 'message' => 'Membership does not belong to that member.'], 422);
        }

        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $metadata['deleted'] = ['by' => $label, 'at' => now()->toIso8601String()];
        $update = $this->central('post', '/v1/internal/admin/memberships/update', [
            'membership_id' => (int) $id,
            'status' => 'terminated',
            'cancel_at_period_end' => false,
            'final_access_at' => now()->toDateString(),
            'metadata' => array_merge($metadata, ['auto_renewal' => false]),
            'admin_authorization_reason' => "Membership deleted by {$label}.",
        ]);
        if (! $update || ! $update->successful()) {
            return response()->json([
                'success' => false,
                'message' => ($update?->json('message') ?: 'Membership service is unavailable.') . ' Membership was not deleted.',
            ], $update && $update->status() === 422 ? 422 : 502);
        }

        // The member's store-side mirror goes too.
        $localUser = ! empty($row['user']['email']) ? User::whereRaw('LOWER(email) = ?', [strtolower($row['user']['email'])])->first() : null;
        if ($localUser) {
            EcommerceMembership::where('user_id', $localUser->id)->delete();
        }

        return response()->json(['success' => true, 'message' => 'Membership deleted.']);
    }

    /** Rows an admin deleted stay in Central for audit but are hidden everywhere in the admin. */
    public static function isDeleted(array $row): bool
    {
        return is_array($row['metadata'] ?? null) && ! empty($row['metadata']['deleted']);
    }

    private function memberUser($id, string $source, ?string $email): ?User
    {
        if ($source === 'local') {
            $m = EcommerceMembership::with('user')->find($id);
            return $m?->user;
        }
        if (! $email) {
            $response = $this->central('get', '/v1/internal/admin/memberships');
            $row = $response && $response->successful() ? collect($response->json('data') ?? [])->firstWhere('id', (int) $id) : null;
            $email = $row['user']['email'] ?? null;
        }
        return $email ? User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first() : null;
    }

    // ─── Data loading ───────────────────────────────────────────────────────

    /** @return array{0: Collection, 1: string} rows and the store they came from (same rule as the admin list) */
    private function memberships(): array
    {
        $response = $this->central('get', '/v1/internal/admin/memberships');
        if ($response && $response->successful() && ! empty($response->json('data'))) {
            return [collect($response->json('data')), 'central'];
        }

        return [EcommerceMembership::with('user')->get()->map(fn ($m) => $m->toArray()), 'local'];
    }

    private function normalize(array $row, string $source): array
    {
        $presented = CustomerMembershipController::presentMembership(array_merge($row, ['source' => $source]));
        $status = $presented['normalized_status'];
        $user = $row['user'] ?? [];

        $accountType = strtolower((string) ($row['account_type'] ?? ''));
        if ($accountType === '') {
            $planType = $presented['plan']['account_type'] ?? null;
            $accountType = $planType ? strtolower((string) $planType) : (filled($user['business_name'] ?? null) ? 'business' : 'personal');
        }

        $bucket = match (true) {
            in_array($status, self::PAUSED, true) => 'paused',
            in_array($status, self::CANCELLED, true) => 'cancelled',
            in_array($status, self::FLAGGED, true), ! empty($user['banned_at']) => 'flagged',
            default => 'active',
        };

        $price = (float) ($row['price'] ?? 0);
        $months = MembershipProrationService::cycleMonths($presented['billing_cycle'] ?? 'Monthly');

        return [
            'id' => $row['id'] ?? null,
            'source' => $source,
            'user_id' => $row['user_id'] ?? ($user['id'] ?? null),
            'name' => filled($user['business_name'] ?? null) ? $user['business_name'] : ($user['name'] ?? 'Member'),
            'email' => strtolower((string) ($user['email'] ?? '')),
            'phone' => $user['phone'] ?? null,
            'account_type' => $accountType === 'business' ? 'business' : 'personal',
            'plan_id' => $presented['plan_id'],
            'plan_name' => $presented['plan_name'],
            'status' => $status,
            'status_label' => $presented['status_label'],
            'bucket' => $bucket,
            'price' => $price,
            'monthly_price' => $months > 0 ? $price / $months : $price,
            'payment_status' => $row['payment_status'] ?? null,
            'next_billing_date' => $presented['next_billing_date'],
            'access_ends_at' => $presented['access_ends_at'],
            'auto_renew' => (bool) $presented['auto_renew'],
        ];
    }

    private function onPlan(array $member, EcommerceSubscriptionPlan $plan): bool
    {
        if (! empty($member['plan_id'])) {
            return (int) $member['plan_id'] === (int) $plan->id;
        }
        return strcasecmp((string) $member['plan_name'], (string) $plan->name) === 0;
    }

    /** @return array<string, float> lower-cased plan name => all money collected for that plan */
    private function lifetimeRevenueByPlan(string $source): array
    {
        $totals = [];
        $add = function (?string $plan, float $amount) use (&$totals) {
            if (! $plan || $amount <= 0) {
                return;
            }
            $key = strtolower($plan);
            $totals[$key] = ($totals[$key] ?? 0) + $amount;
        };

        $refs = [];
        $response = $this->central('get', '/v1/internal/admin/membership-transactions');
        if ($response && $response->successful()) {
            foreach ($response->json('data') ?? [] as $tx) {
                if (in_array(strtolower((string) ($tx['payment_status'] ?? 'paid')), ['failed', 'payment_failed', 'pending'], true)) {
                    continue;
                }
                $plan = $tx['details']['plan_name'] ?? ($tx['membership']['plan_name'] ?? null);
                $add($plan, (float) ($tx['net_amount'] ?? 0));
                if (! empty($tx['transaction_reference'])) {
                    $refs[] = $tx['transaction_reference'];
                }
            }
        }

        EcommerceMembershipActivity::whereNotNull('amount')->where('amount', '>', 0)->get()
            ->reject(fn ($a) => $a->reference && in_array($a->reference, $refs, true))
            ->each(fn ($a) => $add($a->to_plan ?: $a->from_plan, (float) $a->amount));

        return $totals;
    }

    private function paymentMethodsExpiring(Collection $members): Collection
    {
        $byUser = $members->filter(fn ($m) => $m['bucket'] !== 'cancelled')->keyBy('email');
        if ($byUser->isEmpty()) {
            return collect();
        }

        $users = User::whereIn(DB::raw('LOWER(email)'), $byUser->keys()->all())->get(['id', 'email']);
        $emailById = $users->pluck('email', 'id')->map(fn ($e) => strtolower($e));

        $now = now();
        $next = now()->addMonthNoOverflow();

        return EcommercePaymentMethod::whereIn('user_id', $emailById->keys())
            ->whereNotNull('exp_year')
            ->whereNotNull('exp_month')
            ->get()
            ->filter(function ($pm) use ($now, $next) {
                $ym = $pm->exp_year * 100 + $pm->exp_month;
                return $ym >= ($now->year * 100 + $now->month) && $ym <= ($next->year * 100 + $next->month);
            })
            ->map(function ($pm) use ($emailById, $byUser) {
                $m = $byUser[$emailById[$pm->user_id]] ?? null;
                return $m ? $this->summary($m, sprintf('%s •••• %s expires %02d/%d', strtoupper((string) ($pm->brand ?: 'Card')), $pm->last4, $pm->exp_month, $pm->exp_year)) : null;
            })
            ->filter()
            ->unique('id')
            ->values();
    }

    private function duplicateAccounts(Collection $members): Collection
    {
        $live = $members->filter(fn ($m) => $m['bucket'] !== 'cancelled');
        $out = collect();

        // One account holding more than one live membership.
        $live->groupBy('email')->filter(fn ($g, $email) => $email !== '' && $g->count() > 1)
            ->each(function ($group) use ($out) {
                foreach ($group as $m) {
                    $out->push($this->summary($m, $group->count() . ' live memberships on one account'));
                }
            });

        // Different accounts sharing the same phone number.
        $live->filter(fn ($m) => strlen(preg_replace('/\D/', '', (string) $m['phone'])) >= 7)
            ->groupBy(fn ($m) => substr(preg_replace('/\D/', '', (string) $m['phone']), -10))
            ->filter(fn ($g) => $g->pluck('email')->unique()->count() > 1)
            ->each(function ($group) use ($out) {
                $emails = $group->pluck('email')->unique()->implode(', ');
                foreach ($group as $m) {
                    $out->push($this->summary($m, 'Same phone as ' . $emails));
                }
            });

        return $out->unique(fn ($s) => $s['source'] . ':' . $s['id']);
    }

    private function summary(array $m, ?string $note = null): array
    {
        return [
            'id' => $m['id'],
            'source' => $m['source'],
            'name' => $m['name'],
            'email' => $m['email'],
            'plan_name' => $m['plan_name'],
            'status' => $m['status_label'],
            'next_billing_date' => $m['next_billing_date'],
            'access_ends_at' => $m['access_ends_at'],
            'auto_renew' => $m['auto_renew'],
            'note' => $note,
        ];
    }

    // ─── Activity mapping ───────────────────────────────────────────────────

    private function mapAuditLog(array $log): ?array
    {
        $prev = is_array($log['previous_value'] ?? null) ? $log['previous_value'] : [];
        $new = is_array($log['new_value'] ?? null) ? $log['new_value'] : [];
        $action = (string) ($log['action'] ?? '');
        $prevStatus = strtolower((string) ($prev['status'] ?? ''));
        $newStatus = strtolower((string) ($new['status'] ?? ''));
        $prevPlan = $prev['plan_name'] ?? null;
        $newPlan = $new['plan_name'] ?? null;

        if ($action === 'membership_pin_verified') {
            return null;
        }

        [$type, $details] = match (true) {
            in_array($action, ['membership_purchased', 'membership_admin_created'], true) => ['subscribe', 'Subscribed to ' . ($newPlan ?? 'membership') . ' (' . ucfirst((string) ($new['billing_cycle'] ?? 'monthly')) . ')'],
            $prevPlan && $newPlan && strcasecmp($prevPlan, $newPlan) !== 0 => [
                (float) ($new['price'] ?? 0) >= (float) ($prev['price'] ?? 0) ? 'upgrade' : 'downgrade',
                ((float) ($new['price'] ?? 0) >= (float) ($prev['price'] ?? 0) ? 'Upgraded' : 'Downgraded') . " from {$prevPlan} to {$newPlan}",
            ],
            $newStatus === 'pending_downgrade' && $prevStatus !== 'pending_downgrade' => ['downgrade', 'Scheduled downgrade to ' . ($new['scheduled_plan']['plan_name'] ?? 'a lower plan')],
            $newStatus === 'pending_upgrade' && $prevStatus !== 'pending_upgrade' => ['upgrade', 'Scheduled upgrade to ' . ($new['scheduled_plan']['plan_name'] ?? 'a higher plan')],
            $newStatus === 'paused' && $prevStatus !== 'paused' => ['pause', $this->pauseText($new)],
            $prevStatus === 'paused' && $newStatus === 'active' => ['resume', 'Resumed membership (previously paused)'],
            in_array($newStatus, ['pending_cancellation', 'canceled', 'cancelled'], true) && $newStatus !== $prevStatus => ['cancel', $newStatus === 'pending_cancellation'
                ? 'Cancellation scheduled for ' . $this->fmt($new['final_access_at'] ?? null)
                : 'Cancelled membership' . (((float) ($new['payment_summary']['refund_amount'] ?? 0)) > 0 ? ' (Refund: $' . number_format((float) $new['payment_summary']['refund_amount'], 2) . ')' : '')],
            $prevStatus === 'pending_cancellation' && $newStatus === 'active' => ['reactivate', 'Reactivated membership (cancellation removed)'],
            in_array($newStatus, ['inactive', 'suspended', 'terminated'], true) && $newStatus !== $prevStatus => ['deactivate', 'Deactivated membership'],
            in_array($newStatus, ['past_due', 'payment_failed'], true) && $newStatus !== $prevStatus => ['payment_failed', 'Renewal payment failed'],
            ($prev['next_billing_date'] ?? null) !== ($new['next_billing_date'] ?? null) && $newStatus === 'active' && $prevStatus === 'active' => ['renew', 'Renewed until ' . $this->fmt($new['next_billing_date'] ?? null)],
            default => ['update', $log['reason'] ?: 'Membership details updated'],
        };

        $actor = strtolower((string) ($log['actor_type'] ?? 'staff'));

        return [
            'id' => 'audit-' . ($log['id'] ?? uniqid()),
            'date' => $log['created_at'] ?? null,
            'type' => $type,
            'label' => ucwords(str_replace('_', ' ', $type)),
            'details' => $details,
            'note' => $log['reason'] ?? null,
            'performed_by' => $actor === 'user' ? 'customer' : ($actor === 'system' ? 'system' : 'admin'),
            'performed_by_label' => $actor === 'user' ? 'Customer (Self)' : ($actor === 'system' ? 'System' : 'Admin'),
            'ip_address' => $log['ip_address'] ?? null,
        ];
    }

    private function mapLocalActivity(EcommerceMembershipActivity $a): array
    {
        $details = $a->details ?? [];
        $type = match ($a->action) {
            'downgrade_scheduled' => 'downgrade',
            'lateral' => 'change_plan',
            'cancelled' => 'cancel',
            'auto_renew_on', 'auto_renew_off' => 'auto_renew',
            default => $a->action,
        };

        $text = match ($a->action) {
            'upgrade' => "Upgraded from {$a->from_plan} to {$a->to_plan}",
            'downgrade' => "Downgraded from {$a->from_plan} to {$a->to_plan}",
            'downgrade_scheduled' => "Scheduled downgrade from {$a->from_plan} to {$a->to_plan}",
            'lateral' => "Changed plan from {$a->from_plan} to {$a->to_plan}",
            'pause' => 'Paused membership' . (! empty($details['starts_at']) ? ' (' . $this->fmt($details['starts_at']) . ' – ' . $this->fmt($details['resumes_at'] ?? null) . ')' : ''),
            'resume' => 'Resumed membership (previously paused)',
            'cancel' => 'Requested cancellation at end of billing period',
            'cancelled' => 'Membership ended (cancellation took effect)',
            'reactivate' => 'Reactivated membership (cancellation removed)',
            'auto_renew_on' => 'Turned auto-renewal on',
            'auto_renew_off' => 'Turned auto-renewal off',
            'scheduled_change_cancelled' => 'Cancelled the scheduled plan change',
            'trial_review' => 'Free trial needs review: ' . ($details['reason'] ?? 'possible repeat trial.'),
            'deactivation_scheduled' => 'Deactivation scheduled for ' . $this->fmt($details['effective'] ?? null) . (! empty($details['reason']) ? " – {$details['reason']}" : ''),
            'deactivated' => 'Membership deactivated' . (! empty($details['reason']) ? " – {$details['reason']}" : ''),
            'message_sent' => 'Message sent: "' . ($details['subject'] ?? '') . '"' . (! empty($details['channels']) ? ' (' . implode(', ', array_map(fn ($c) => $c === 'in_app' ? 'in-app' : $c, $details['channels'])) . ')' : ''),
            'plan_change_scheduled' => "Plan change to {$a->to_plan} scheduled for " . $this->fmt($details['effective'] ?? null),
            'plan_changed' => "Changed plan from {$a->from_plan} to {$a->to_plan}",
            'upgrade_scheduled' => "Upgrade to {$a->to_plan} scheduled for " . $this->fmt($details['effective'] ?? null),
            default => ucwords(str_replace('_', ' ', $a->action)),
        };
        if ($a->amount !== null && (float) $a->amount > 0) {
            $text .= ' – $' . number_format((float) $a->amount, 2) . ' paid';
        }

        $by = $a->performed_by;

        return [
            'id' => 'local-' . $a->id,
            'date' => optional($a->created_at)->toIso8601String(),
            'type' => $type,
            'label' => ucwords(str_replace('_', ' ', $type)),
            'details' => $text,
            'note' => null,
            'performed_by' => $by,
            'performed_by_label' => $by === 'customer' ? 'Customer (Self)' : ($by === 'system' ? 'System' : 'Admin' . ($a->actor_name ? " ({$a->actor_name})" : '')),
            'ip_address' => $a->ip_address,
        ];
    }

    private function pauseText(array $new): string
    {
        $pause = $new['metadata']['pause'] ?? null;
        if (is_array($pause) && ! empty($pause['starts_at'])) {
            return 'Paused membership (' . $this->fmt($pause['starts_at']) . ' – ' . ($pause['resumes_at'] ? $this->fmt($pause['resumes_at']) : 'until resumed') . ')';
        }
        return 'Paused membership';
    }

    private function fmt(mixed $date): string
    {
        return MembershipProrationService::parseDate($date)?->format('M j, Y') ?? '—';
    }

    private function central(string $method, string $path, array $data = [])
    {
        $url = rtrim((string) config('services.central_auth.url'), '/');
        if ($url === '') {
            return null;
        }
        try {
            $http = Http::acceptJson()->timeout(8)->withHeaders([
                'X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret'),
            ]);
            return strtolower($method) === 'post' ? $http->post($url . $path, $data) : $http->get($url . $path, $data);
        } catch (\Throwable $e) {
            Log::warning("Membership insights central call {$path} failed: " . $e->getMessage());
            return null;
        }
    }
}
