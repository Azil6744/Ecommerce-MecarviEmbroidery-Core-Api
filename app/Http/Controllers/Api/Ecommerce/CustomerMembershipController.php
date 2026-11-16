<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Api\Admin\AdminMembershipCancellationController;
use App\Http\Controllers\Controller;
use App\Models\EcommerceMembership;
use App\Models\EcommerceMembershipActivity;
use App\Models\EcommerceOrder;
use App\Models\EcommercePaymentMethod;
use App\Models\EcommerceSubscriptionPlan;
use App\Models\User;
use App\Services\MembershipPlanPolicy;
use App\Services\MembershipProrationService;
use App\Services\PayPalMembershipPaymentService;
use App\Services\StripeMembershipPaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-service for the user panels (unified panel + business panel).
 *
 * A membership lives either in Central (source "central") or only in this store (source "local").
 * Every action is applied in the store the row lives in, the same way the admin actions do,
 * and nothing is reported as successful unless it was actually saved.
 */
class CustomerMembershipController extends Controller
{
    private const LIVE_CENTRAL_STATUSES = [
        'active', 'trialing', 'payment_pending', 'past_due', 'grace_period',
        'pending_upgrade', 'pending_downgrade', 'pending_cancellation', 'paused',
    ];

    private const ACTION_ALIASES = [
        'upgrade' => 'upgrade',
        'upgrade-business' => 'upgrade',
        'downgrade' => 'downgrade',
        'downgrade-free' => 'downgrade',
        'downgrade-essentials' => 'downgrade',
        'change-plan' => 'change_plan',
        'change_plan' => 'change_plan',
        'cancel-downgrade' => 'cancel_scheduled_change',
        'cancel_scheduled_change' => 'cancel_scheduled_change',
        'pause' => 'pause',
        'resume' => 'resume',
        'cancel' => 'cancel',
        'reactivate' => 'reactivate',
        'confirm' => 'confirm',
        'confirm-subscription' => 'confirm',
        'confirm_subscription' => 'confirm',
        'toggle_auto_renew' => 'auto_renew',
        'auto_renew' => 'auto_renew',
        'auto-renew' => 'auto_renew',
    ];

    // ─── Current membership ─────────────────────────────────────────────────

    /**
     * GET /ecommerce/memberships — the signed-in customer's membership, from the same store
     * that actions are applied to, so the panel always shows what was actually saved.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => true, 'data' => []]);
        }

        self::syncLocalScheduledChanges();

        $found = self::resolveCustomerMembership($request);

        if (! $found) {
            // No membership anywhere: put the customer on the free plan for their account type, if one exists.
            $type = strtolower((string) ($user->account_type ?? '')) === 'business' || filled($user->business_name ?? null) ? 'business' : 'personal';
            $free = EcommerceSubscriptionPlan::whereRaw('LOWER(COALESCE(account_type, ?)) = ?', ['personal', $type])
                ->whereRaw('LOWER(COALESCE(status, ?)) IN (?, ?, ?)', ['active', 'active', 'featured', 'published'])
                ->where('price', '<=', 0)
                ->orderBy('sort_order')
                ->first();

            if (! $free) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $model = EcommerceMembership::firstOrCreate(['user_id' => $user->id], [
                'plan_id' => $free->id,
                'plan_name' => $free->name,
                'status' => 'Active',
                'price' => 0,
                'billing_cycle' => $free->billing_cycle ?: 'Monthly',
                'next_billing_date' => null,
                'auto_renew' => true,
            ]);
            $found = ['source' => 'local', 'row' => $model->toArray(), 'model' => $model];
        }

        return response()->json([
            'success' => true,
            'data' => [self::presentMembership(array_merge($found['row'], ['source' => $found['source']]))],
        ]);
    }

    // ─── Actions ────────────────────────────────────────────────────────────

    public function action(Request $request, $id, string $action): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Please sign in to manage your membership.'], 401);
        }

        $normalized = self::ACTION_ALIASES[strtolower(trim($action))] ?? null;
        if (! $normalized) {
            return response()->json(['success' => false, 'message' => "Unsupported membership action '{$action}'."], 422);
        }

        $found = $this->resolveCustomerMembership($request, $id);
        if (! $found) {
            return response()->json(['success' => false, 'message' => 'No membership found for your account.'], 404);
        }
        if (! empty($found['central_unreachable'])) {
            return response()->json(['success' => false, 'message' => 'The membership service is unavailable right now, so nothing was changed. Please try again in a moment.'], 503);
        }

        return match ($normalized) {
            'upgrade', 'downgrade', 'change_plan' => $this->changePlan($request, $found, $normalized),
            'cancel_scheduled_change' => $this->cancelScheduledChange($request, $found),
            'pause' => $this->pause($request, $found),
            'resume' => $this->resume($request, $found),
            'cancel' => $this->cancel($request, $found),
            'reactivate' => $this->reactivate($request, $found),
            'auto_renew' => $this->autoRenew($request, $found),
            'confirm' => $this->confirmSubscription($request, $found),
        };
    }

    private function changePlan(Request $request, array $found, string $action): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer',
            'payment_method' => 'nullable|string|in:wallet,card,stripe,credit_card,paypal',
            'paypal_payment_id' => 'nullable|string|max:255',
            'paypal_payer_id' => 'nullable|string|max:255',
            'return_url' => 'nullable|string|max:2000',
            'cancel_url' => 'nullable|string|max:2000',
            'stripe_payment_method' => 'nullable|string|max:255',
            'payment_method_id' => 'nullable|string|max:255',
        ]);

        $row = $found['row'];
        $user = $request->user();

        $plan = EcommerceSubscriptionPlan::find($validated['plan_id']);
        if (! $plan || ! in_array(strtolower((string) ($plan->status ?? 'active')), ['active', 'featured', 'published'], true)) {
            return response()->json(['success' => false, 'message' => 'The selected plan is not available.'], 422);
        }

        // Visibility, availability status/dates and eligibility set in the plan editor.
        $denied = \App\Services\PlanAvailabilityService::denialReason($plan, $user, $request, true);
        if ($denied !== null) {
            return response()->json(['success' => false, 'message' => $denied], 422);
        }

        $memberType = $this->accountType($row, $user);
        $planType = strtolower((string) ($plan->account_type ?: 'personal'));
        if ($memberType !== $planType) {
            return response()->json(['success' => false, 'message' => "A {$planType} plan cannot be applied to a {$memberType} membership."], 422);
        }

        $status = $this->normalizedStatus($row);
        if (! in_array($status, ['active', 'trialing', 'past_due', 'grace_period', 'pending_upgrade', 'pending_downgrade'], true)) {
            return response()->json(['success' => false, 'message' => "Your plan cannot be changed while the membership is {$this->statusLabel($status)}."], 422);
        }

        if ($this->isSamePlan($row, $plan)) {
            return response()->json(['success' => false, 'message' => "You are already on {$plan->name}."], 422);
        }

        // Pricing & Billing settings of the target plan (admin plan editor).
        $policy = MembershipPlanPolicy::for($plan);

        $currentPrice = (float) ($row['price'] ?? 0);
        $newPrice = (float) $plan->price;
        $direction = round($newPrice * 100) > round($currentPrice * 100) ? 'upgrade' : (round($newPrice * 100) < round($currentPrice * 100) ? 'downgrade' : 'lateral');

        if ($action === 'upgrade' && $direction === 'downgrade') {
            return response()->json(['success' => false, 'message' => "{$plan->name} costs less than your current plan. Use Downgrade instead."], 422);
        }
        if ($action === 'downgrade' && $direction === 'upgrade') {
            return response()->json(['success' => false, 'message' => "{$plan->name} costs more than your current plan. Use Upgrade instead."], 422);
        }
        if ($direction === 'downgrade' && ($block = MembershipPlanPolicy::commitmentBlock($this->metadata($row), 'downgrade'))) {
            return response()->json(['success' => false, 'message' => $block], 422);
        }

        // Upgrades (and same-price switches) start today with prorated credit; downgrades start next cycle.
        $timing = $direction === 'downgrade' ? 'next_cycle' : 'immediate';
        $today = MembershipProrationService::today();
        $period = $this->currentPeriod($row, $today);
        $breakdown = MembershipProrationService::compute(
            $timing,
            // A free trial was not paid for, so it leaves no unused time to credit.
            $status === 'trialing' && $timing === 'immediate' ? 0.0 : $currentPrice,
            $newPrice,
            $plan->billing_cycle,
            $plan->billing_interval_count,
            $period,
            $today
        );

        if ($request->boolean('preview')) {
            return response()->json(['success' => true, 'data' => ['breakdown' => $breakdown, 'plan' => $this->planSnapshot($plan), 'billing_policy' => $policy]]);
        }

        if ($timing === 'immediate' && $breakdown['amount_due_today'] > 0) {
            if ($error = MembershipPlanPolicy::assertPaymentMethod($policy, $request->input('payment_method', 'wallet'))) {
                return response()->json(['success' => false, 'message' => $error], 422);
            }
        }

        // Take payment for anything due today before changing the plan.
        $payment = null;
        if ($timing === 'immediate' && $breakdown['amount_due_today'] > 0) {
            $payment = $this->collectPayment($request, $user, $plan, (float) $breakdown['amount_due_today'], $direction);
            if ($payment instanceof JsonResponse) {
                return $payment;
            }
        }

        $fromPlan = $row['plan_name'] ?? null;
        $snapshot = $this->planSnapshot($plan);

        try {
            if ($found['source'] === 'central') {
                $payload = [
                    'membership_id' => (int) $row['id'],
                    'actor_type' => 'user',
                    'admin_authorization_reason' => "Customer self-service {$breakdown['direction']}: {$fromPlan} → {$plan->name} ({$timing})",
                    'metadata' => array_merge($this->metadata($row), $timing === 'immediate' ? [
                        'billing_policy' => $policy,
                        'signup_price' => $newPrice,
                        'renewal_price' => MembershipPlanPolicy::renewalPrice($policy, $newPrice),
                        'auto_renewal' => $policy['auto_renew'] && (($this->metadata($row)['auto_renewal'] ?? true) !== false),
                        'max_ends_at' => $policy['max_duration_months'] > 0
                            ? MembershipProrationService::addMonths(MembershipProrationService::today(), $policy['max_duration_months'])->toDateString()
                            : null,
                        'commitment_ends_at' => $policy['min_commitment_months'] > 0
                            ? MembershipProrationService::addMonths(MembershipProrationService::today(), $policy['min_commitment_months'])->toDateString()
                            : null,
                    ] : [], [
                        'last_plan_change' => [
                            'by' => 'customer',
                            'at' => now()->toIso8601String(),
                            'from_plan' => $fromPlan,
                            'to_plan' => $plan->name,
                            'breakdown' => $breakdown,
                        ],
                    ]),
                ];

                if ($timing === 'next_cycle') {
                    $payload += [
                        'status' => 'pending_downgrade',
                        'scheduled_plan' => $snapshot + ['effective_date' => $breakdown['effective_date'], 'billing_policy' => $policy],
                        'current_period_start' => $breakdown['period_start'],
                        'current_period_end' => $breakdown['period_end'],
                        'next_billing_date' => $breakdown['next_billing_date'],
                    ];
                } else {
                    $payload += [
                        'status' => 'active',
                        'scheduled_plan' => null,
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                        'plan_code' => $plan->internal_code,
                        'price' => $newPrice,
                        'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
                        'benefits_snapshot' => $snapshot['benefit_config'],
                        'current_period_start' => $breakdown['new_period_start'],
                        'current_period_end' => $breakdown['new_period_end'],
                        'next_billing_date' => $breakdown['next_billing_date'],
                        'transaction_reference' => $payment['transaction_reference'] ?? ('customer_plan_change_' . $row['id'] . '_' . now()->timestamp),
                        'transaction_type' => 'plan_change',
                        'payment_method' => $payment['payment_method'] ?? ($row['payment_method'] ?? null),
                        'payment_processor' => $payment['payment_processor'] ?? null,
                        'payment_status' => 'paid',
                        'credit_applied' => min($breakdown['unused_credit'], $breakdown['new_plan_charge']),
                        'refund_amount' => $breakdown['refund_amount'],
                    ];
                }

                $response = $this->central('post', '/v1/internal/admin/memberships/update', $payload);
                if (! $response || ! $response->successful()) {
                    $this->reversePayment($user, $payment, $plan);
                    return $this->centralFailure($response, 'Your plan was not changed.');
                }
                $data = $response->json('data') ?? [];

                if ($timing === 'immediate') {
                    $this->mirrorLocalPlan($user, $plan, $breakdown['next_billing_date']);
                }
            } else {
                /** @var EcommerceMembership $model */
                $model = $found['model'];
                if ($timing === 'next_cycle') {
                    $model->update([
                        'scheduled_plan_id' => $plan->id,
                        'scheduled_change_at' => $breakdown['effective_date'],
                    ]);
                } else {
                    $model->update([
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                        'price' => $newPrice,
                        'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
                        'status' => 'Active',
                        'next_billing_date' => $breakdown['next_billing_date'],
                        'scheduled_plan_id' => null,
                        'scheduled_change_at' => null,
                    ]);
                }
                $data = $model->fresh()->toArray();
            }
        } catch (\Throwable $e) {
            $this->reversePayment($user, $payment, $plan);
            Log::error('Customer plan change failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Your plan could not be changed. You have not been charged.'], 500);
        }

        $this->recordActivity($request, $found, $timing === 'next_cycle' ? 'downgrade_scheduled' : $breakdown['direction'], [
            'from_plan' => $fromPlan,
            'to_plan' => $plan->name,
            'amount' => $payment ? (float) $breakdown['amount_due_today'] : null,
            'payment_method' => $payment['payment_method'] ?? null,
            'reference' => $payment['transaction_reference'] ?? null,
            'details' => ['breakdown' => $breakdown],
        ]);

        if ($payment && $direction === 'upgrade') {
            try {
                \App\Services\LoyaltyService::awardActivity(
                    (int) $user->id,
                    'membership_purchase',
                    'membership_bonus',
                    "Loyalty points for membership upgrade ({$plan->name}).",
                    'membership',
                    $found['source'] . '-' . $row['id'] . '-upgrade-' . now()->format('Ymd')
                );
            } catch (\Throwable $e) {
                Log::warning('Membership upgrade loyalty award failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => $timing === 'immediate'
                ? "You are now on {$plan->name}."
                : "You will move to {$plan->name} on " . MembershipProrationService::parseDate($breakdown['effective_date'])?->format('M j, Y') . '.',
            'data' => $this->presentMembership(array_merge($data, ['source' => $found['source']])),
            'breakdown' => $breakdown,
        ]);
    }

    /**
     * Trial ended on a plan set to "Require confirmation": the member confirms and pays the first cycle.
     */
    private function confirmSubscription(Request $request, array $found): JsonResponse
    {
        $request->validate([
            'payment_method' => 'nullable|string|in:wallet,card,stripe,credit_card,paypal',
            'paypal_payment_id' => 'nullable|string|max:255',
            'paypal_payer_id' => 'nullable|string|max:255',
            'return_url' => 'nullable|string|max:2000',
            'cancel_url' => 'nullable|string|max:2000',
            'stripe_payment_method' => 'nullable|string|max:255',
            'payment_method_id' => 'nullable|string|max:255',
        ]);
        $row = $found['row'];
        $meta = $this->metadata($row);
        if ($found['source'] !== 'central' || $this->normalizedStatus($row) !== 'payment_pending' || empty($meta['awaiting_confirmation'])) {
            return response()->json(['success' => false, 'message' => 'There is no subscription waiting for your confirmation.'], 422);
        }
        $plan = ! empty($row['plan_id']) ? EcommerceSubscriptionPlan::find($row['plan_id']) : null;
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'This plan is no longer available. Choose another plan.'], 422);
        }
        $policy = MembershipPlanPolicy::for($plan);
        if ($error = MembershipPlanPolicy::assertPaymentMethod($policy, $request->input('payment_method', 'wallet'))) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $amount = round((float) ($meta['awaiting_confirmation']['amount'] ?? $meta['renewal_price'] ?? $plan->price), 2);
        $user = $request->user();
        $payment = null;
        if ($amount > 0) {
            $payment = $this->collectPayment($request, $user, $plan, $amount, 'reactivate');
            if ($payment instanceof JsonResponse) {
                return $payment;
            }
        }

        $today = MembershipProrationService::today();
        $end = MembershipProrationService::addMonths($today, $policy['cycle_months']);
        unset($meta['awaiting_confirmation']);
        $meta['confirmed_at'] = now()->toIso8601String();

        $response = $this->central('post', '/v1/internal/admin/memberships/update', [
            'membership_id' => (int) $row['id'],
            'actor_type' => 'user',
            'status' => 'active',
            'current_period_start' => $today->toDateString(),
            'current_period_end' => $end->toDateString(),
            'next_billing_date' => $end->toDateString(),
            'payment_status' => 'paid',
            'payment_method' => $payment['payment_method'] ?? ($row['payment_method'] ?? null),
            'payment_processor' => $payment['payment_processor'] ?? null,
            'transaction_reference' => $payment['transaction_reference'] ?? ('customer_confirm_' . $row['id'] . '_' . now()->timestamp),
            'transaction_type' => 'subscription',
            'gross_amount' => $amount,
            'metadata' => $meta,
            'admin_authorization_reason' => 'Customer confirmed the subscription after the trial.',
        ]);
        if (! $response || ! $response->successful()) {
            $this->reversePayment($user, $payment, $plan);
            return $this->centralFailure($response, 'Your subscription was not confirmed.');
        }
        $this->mirrorLocalPlan($user, $plan, $end->toDateString());
        $this->recordActivity($request, $found, 'subscription_confirmed', [
            'to_plan' => $plan->name,
            'amount' => $amount,
            'payment_method' => $payment['payment_method'] ?? null,
            'reference' => $payment['transaction_reference'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Your {$plan->name} membership is active.",
            'data' => $this->presentMembership(array_merge($response->json('data') ?? [], ['source' => 'central'])),
        ]);
    }

    private function cancelScheduledChange(Request $request, array $found): JsonResponse
    {
        $row = $found['row'];

        if ($found['source'] === 'central') {
            if ($this->normalizedStatus($row) !== 'pending_downgrade' && empty($row['scheduled_plan'])) {
                return response()->json(['success' => false, 'message' => 'There is no scheduled plan change to cancel.'], 422);
            }
            $response = $this->central('post', '/v1/internal/admin/memberships/update', [
                'membership_id' => (int) $row['id'],
                'actor_type' => 'user',
                'status' => 'active',
                'scheduled_plan' => null,
                'admin_authorization_reason' => 'Customer cancelled the scheduled plan change.',
            ]);
            if (! $response || ! $response->successful()) {
                return $this->centralFailure($response, 'The scheduled change was not cancelled.');
            }
            $data = $response->json('data') ?? [];
        } else {
            $model = $found['model'];
            if (! $model->scheduled_plan_id) {
                return response()->json(['success' => false, 'message' => 'There is no scheduled plan change to cancel.'], 422);
            }
            $model->update(['scheduled_plan_id' => null, 'scheduled_change_at' => null]);
            $data = $model->fresh()->toArray();
        }

        $this->recordActivity($request, $found, 'scheduled_change_cancelled', ['from_plan' => $row['plan_name'] ?? null]);

        return response()->json([
            'success' => true,
            'message' => 'Your scheduled plan change was cancelled.',
            'data' => $this->presentMembership(array_merge($data, ['source' => $found['source']])),
        ]);
    }

    private function pause(Request $request, array $found): JsonResponse
    {
        $validated = $request->validate([
            'duration' => 'required|integer|in:1,2,3',
            'reason' => 'nullable|string|max:300',
        ]);

        // Resume on the same day of the month (13th → 13th), clamped for short months.
        $today = MembershipProrationService::today();
        $resume = MembershipProrationService::addMonths($today, (int) $validated['duration']);

        $response = $this->delegate($request, EcommerceMembershipController::class, 'adminPause', $found, [
            'start_date' => $today->toDateString(),
            'resume_date' => $resume->toDateString(),
            'reason' => $validated['reason'] ?? 'Paused by customer',
            'notify' => true,
        ]);

        if ($this->succeeded($response)) {
            $this->recordActivity($request, $found, 'pause', [
                'from_plan' => $found['row']['plan_name'] ?? null,
                'details' => [
                    'starts_at' => $today->toDateString(),
                    'resumes_at' => $resume->toDateString(),
                    'duration_months' => (int) $validated['duration'],
                ],
            ]);
        }

        return $this->withPresentedData($response, $found['source']);
    }

    private function resume(Request $request, array $found): JsonResponse
    {
        $response = $this->delegate($request, EcommerceMembershipController::class, 'adminResume', $found, ['notify' => true]);

        if ($this->succeeded($response)) {
            $this->recordActivity($request, $found, 'resume', ['from_plan' => $found['row']['plan_name'] ?? null]);
        }

        return $this->withPresentedData($response, $found['source']);
    }

    private function cancel(Request $request, array $found): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
            'details' => 'nullable|string|max:300',
        ]);

        if ($block = MembershipPlanPolicy::commitmentBlock($this->metadata($found['row']), 'cancel')) {
            return response()->json(['success' => false, 'message' => $block], 422);
        }

        // Customers always cancel at the end of the paid period (perks stay until then).
        $response = $this->delegate($request, AdminMembershipCancellationController::class, 'cancel', $found, [
            'timing' => 'end_of_period',
            'reason' => $validated['reason'] ?? 'Cancelled by customer',
            'details' => $validated['details'] ?? null,
            'email' => $request->user()->email,
        ]);

        if ($this->succeeded($response)) {
            $this->recordActivity($request, $found, 'cancel', [
                'from_plan' => $found['row']['plan_name'] ?? null,
                'details' => ['reason' => $validated['reason'] ?? null],
            ]);
        }

        return $this->withPresentedData($response, $found['source']);
    }

    private function reactivate(Request $request, array $found): JsonResponse
    {
        $row = $found['row'];
        $status = $this->normalizedStatus($row);
        $pendingCancel = $status === 'pending_cancellation' || ! empty($row['cancel_at_period_end']);

        if (in_array($status, ['canceled', 'cancelled', 'expired', 'terminated', 'inactive'], true) && ! $pendingCancel) {
            return response()->json([
                'success' => false,
                'message' => 'This membership has already ended. Choose a plan to subscribe again.',
            ], 422);
        }
        if (! $pendingCancel) {
            return response()->json(['success' => false, 'message' => 'Your membership is not scheduled to cancel.'], 422);
        }

        if ($found['source'] === 'central') {
            $metadata = $this->metadata($row);
            unset($metadata['cancellation']);
            $metadata['auto_renewal'] = true;
            $response = $this->central('post', '/v1/internal/admin/memberships/update', [
                'membership_id' => (int) $row['id'],
                'actor_type' => 'user',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'final_access_at' => null,
                'metadata' => $metadata,
                'admin_authorization_reason' => 'Customer kept their membership (scheduled cancellation removed).',
            ]);
            if (! $response || ! $response->successful()) {
                return $this->centralFailure($response, 'Your membership was not reactivated.');
            }
            $data = $response->json('data') ?? [];
            $this->mirrorLocalFields($request->user(), ['cancel_at_period_end' => false, 'cancels_at' => null, 'auto_renew' => true]);
        } else {
            $model = $found['model'];
            $model->update([
                'status' => 'Active',
                'cancel_at_period_end' => false,
                'cancels_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'cancelled_by' => null,
                'auto_renew' => true,
            ]);
            $data = $model->fresh()->toArray();
        }

        $this->recordActivity($request, $found, 'reactivate', ['from_plan' => $row['plan_name'] ?? null]);

        return response()->json([
            'success' => true,
            'message' => 'Your membership will continue and renew as normal.',
            'data' => $this->presentMembership(array_merge($data, ['source' => $found['source']])),
        ]);
    }

    private function autoRenew(Request $request, array $found): JsonResponse
    {
        $validated = $request->validate(['auto_renew' => 'required|boolean']);
        $enabled = (bool) $validated['auto_renew'];
        $row = $found['row'];

        $rowPlan = ! empty($row['plan_id']) ? EcommerceSubscriptionPlan::find($row['plan_id']) : null;
        if ($enabled && $rowPlan && ! MembershipPlanPolicy::for($rowPlan)['auto_renew']) {
            return response()->json(['success' => false, 'message' => "{$rowPlan->name} does not renew automatically. Choose a plan again when it ends."], 422);
        }

        if ($found['source'] === 'central') {
            $response = $this->central('post', '/v1/internal/admin/memberships/update', [
                'membership_id' => (int) $row['id'],
                'actor_type' => 'user',
                'metadata' => array_merge($this->metadata($row), ['auto_renewal' => $enabled]),
                'admin_authorization_reason' => 'Customer turned auto-renewal ' . ($enabled ? 'on' : 'off') . '.',
            ]);
            if (! $response || ! $response->successful()) {
                return $this->centralFailure($response, 'Auto-renewal was not changed.');
            }
            $data = $response->json('data') ?? [];
            $this->mirrorLocalFields($request->user(), ['auto_renew' => $enabled]);
        } else {
            $found['model']->update(['auto_renew' => $enabled]);
            $data = $found['model']->fresh()->toArray();
        }

        $this->recordActivity($request, $found, $enabled ? 'auto_renew_on' : 'auto_renew_off', ['from_plan' => $row['plan_name'] ?? null]);

        $presented = $this->presentMembership(array_merge($data, ['source' => $found['source']]));
        $date = $presented['next_billing_date'] ? MembershipProrationService::parseDate($presented['next_billing_date'])?->format('M j, Y') : null;

        return response()->json([
            'success' => true,
            'message' => $enabled
                ? ($date ? "Your membership will auto-renew on {$date}." : 'Auto-renewal is on.')
                : ($date ? "Auto-renewal is off. Your benefits end on {$date}." : 'Auto-renewal is off.'),
            'data' => $presented,
        ]);
    }

    // ─── Billing history, receipt, savings ─────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $items = collect();

        $token = $request->bearerToken();
        if ($token) {
            $response = $this->central('get', '/user/membership-transactions', [], $token);
            if ($response && $response->successful() && is_array($response->json('data'))) {
                foreach ($response->json('data') as $tx) {
                    // Amount charged; refunds are reported separately in refund_amount.
                    $amount = isset($tx['gross_amount'])
                        ? max(0, (float) $tx['gross_amount'] - (float) ($tx['discount_amount'] ?? 0) + (float) ($tx['tax_amount'] ?? 0) - (float) ($tx['credit_applied'] ?? 0))
                        : (float) ($tx['net_amount'] ?? 0);
                    $items->push([
                        'id' => (string) $tx['id'],
                        'source' => 'central',
                        'number' => $tx['transaction_reference'] ?? ('MEM-' . str_pad((string) $tx['id'], 8, '0', STR_PAD_LEFT)),
                        'date' => $tx['created_at'] ?? null,
                        'description' => $this->transactionDescription($tx['transaction_type'] ?? 'subscription', $tx['membership']['plan_name'] ?? null),
                        'type' => $tx['transaction_type'] ?? 'subscription',
                        'payment_method' => $this->paymentMethodLabel($tx['payment_method'] ?? null),
                        'amount' => round($amount, 2),
                        'refund_amount' => round((float) ($tx['refund_amount'] ?? 0), 2),
                        'currency' => $tx['currency'] ?? 'USD',
                        'status' => $this->transactionStatus($tx['payment_status'] ?? null, (float) ($tx['refund_amount'] ?? 0)),
                    ]);
                }
            }
        }

        // Local charges/refunds (local-only memberships, and customer self-service payments).
        $centralRefs = $items->pluck('number')->filter()->all();
        EcommerceMembershipActivity::where('user_id', $user->id)
            ->whereNotNull('amount')
            ->where('amount', '>', 0)
            ->latest()
            ->get()
            ->reject(fn ($a) => $a->reference && in_array($a->reference, $centralRefs, true))
            ->each(function (EcommerceMembershipActivity $a) use ($items) {
                $items->push([
                    'id' => 'local-' . $a->id,
                    'source' => 'local',
                    'number' => $a->reference ?: ('MEM-L' . str_pad((string) $a->id, 7, '0', STR_PAD_LEFT)),
                    'date' => optional($a->created_at)->toIso8601String(),
                    'description' => $this->transactionDescription($a->action, $a->to_plan ?: $a->from_plan),
                    'type' => $a->action,
                    'payment_method' => $this->paymentMethodLabel($a->payment_method),
                    'amount' => round((float) $a->amount, 2),
                    'refund_amount' => 0,
                    'currency' => 'USD',
                    'status' => 'Paid',
                ]);
            });

        $sorted = $items->sortByDesc(fn ($i) => strtotime((string) ($i['date'] ?? '')) ?: 0)->values();

        return response()->json(['success' => true, 'data' => $sorted]);
    }

    public function receipt(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }

        if (str_starts_with((string) $id, 'local-')) {
            $activity = EcommerceMembershipActivity::where('user_id', $user->id)->find((int) substr((string) $id, 6));
            if (! $activity || $activity->amount === null) {
                return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
            }
            $amount = (float) $activity->amount;
            return response()->json(['success' => true, 'data' => [
                'receipt_number' => $activity->reference ?: ('MEM-L' . str_pad((string) $activity->id, 7, '0', STR_PAD_LEFT)),
                'issued_at' => optional($activity->created_at)->toIso8601String(),
                'customer' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'membership' => ['plan_name' => $activity->to_plan ?: $activity->from_plan],
                'payment' => ['transaction_type' => $activity->action, 'payment_method' => $activity->payment_method, 'payment_status' => 'paid', 'currency' => 'USD'],
                'line_items' => [[
                    'description' => $this->transactionDescription($activity->action, $activity->to_plan ?: $activity->from_plan),
                    'quantity' => 1,
                    'unit_amount' => $amount,
                    'amount' => $amount,
                    'currency' => 'USD',
                ]],
                'totals' => ['gross_amount' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'credit_applied' => 0, 'refund_amount' => 0, 'net_amount' => $amount, 'currency' => 'USD'],
                'details' => $activity->details ?? [],
            ]]);
        }

        $token = $request->bearerToken();
        if ($token && ctype_digit((string) $id)) {
            $response = $this->central('get', "/user/membership-transactions/{$id}/receipt", [], $token);
            if ($response && $response->successful()) {
                return response()->json($response->json(), 200);
            }
        }

        return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
    }

    /** Email the signed-in member a copy of one of their own membership receipts. */
    public function emailReceipt(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $response = $this->receipt($request, $id);
        $payload = $response->getData(true);
        if (! $user || $response->getStatusCode() !== 200 || empty($payload['data'])) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }

        $r = $payload['data'];
        $totals = $r['totals'] ?? [];
        $money = fn ($v) => '$' . number_format((float) ($v ?? 0), 2);
        $start = $r['billing_period']['start'] ?? null;
        $end = $r['billing_period']['end'] ?? null;

        $results = app(\App\Services\EmailNotificationService::class)->sendEvent('customer_membership_receipt', [
            'customer_name' => $r['customer']['name'] ?? $user->name,
            'membership_plan' => $r['membership']['plan_name'] ?? 'your membership',
            'receipt_number' => $r['receipt_number'] ?? (string) $id,
            'receipt_date' => ! empty($r['issued_at']) ? \Carbon\Carbon::parse($r['issued_at'])->format('M j, Y') : now()->format('M j, Y'),
            'billing_period' => $start && $end ? \Carbon\Carbon::parse($start)->format('M j, Y') . ' – ' . \Carbon\Carbon::parse($end)->format('M j, Y') : '—',
            'payment_method' => $this->paymentMethodLabel($r['payment']['payment_method'] ?? null),
            'subtotal' => $money($totals['gross_amount'] ?? 0),
            'discount' => $money($totals['discount_amount'] ?? 0),
            'tax' => $money($totals['tax_amount'] ?? 0),
            'amount_paid' => $money($totals['net_amount'] ?? 0),
            'site_name' => config('app.name'),
        ], $user->email);

        $sent = collect($results)->contains(fn ($log) => in_array($log->status ?? null, ['sent', 'queued'], true));
        if (! $sent) {
            $reason = collect($results)->pluck('error_message')->filter()->first();

            return response()->json(['success' => false, 'message' => 'The receipt email could not be sent' . ($reason ? ": {$reason}" : '.')], 422);
        }

        return response()->json(['success' => true, 'message' => "Receipt sent to {$user->email}."]);
    }

    public function savings(Request $request): JsonResponse
    {
        $user = $request->user();
        $discounts = 0.0;
        $shipping = 0.0;
        $lifetime = 0.0;
        $orderCount = 0;

        if ($user) {
            $since = now()->startOfMonth();
            $orders = EcommerceOrder::where('user_id', $user->id)->get();

            foreach ($orders as $order) {
                $discount = (float) ($order->membership_discount_amount ?? 0);
                $usage = is_array($order->membership_benefit_usage)
                    ? $order->membership_benefit_usage
                    : (json_decode((string) ($order->membership_benefit_usage ?? ''), true) ?: []);
                $shippingSaved = (float) ($usage['shipping_saved'] ?? $usage['free_delivery_amount'] ?? 0);

                $lifetime += $discount + $shippingSaved;
                if ($order->created_at && $order->created_at->gte($since)) {
                    $discounts += $discount;
                    $shipping += $shippingSaved;
                    $orderCount++;
                }
            }
        }

        // Order spend over the last 12 months, so the panel can estimate plan savings from real numbers.
        $spend12 = $user
            ? (float) EcommerceOrder::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subYear())
                ->whereNotIn('status', ['cancelled', 'canceled', 'refunded', 'failed'])
                ->sum('subtotal')
            : 0.0;

        // Loyalty points earned on orders this month (pending or released, not reversed) and their dollar value.
        $pointsMonth = 0;
        $pointsValueMonth = 0.0;
        if ($user) {
            $earned = \App\Models\EcommerceLoyaltyTransaction::where('user_id', $user->id)
                ->where('transaction_type', 'earned')
                ->where('created_at', '>=', now()->startOfMonth())
                ->where('status', '!=', \App\Models\EcommerceLoyaltyTransaction::STATUS_REVERSED)
                ->selectRaw('COALESCE(SUM(points), 0) as pts, COALESCE(SUM(dollar_value), 0) as val')
                ->first();
            $pointsMonth = (int) ($earned->pts ?? 0);
            $pointsValueMonth = round((float) ($earned->val ?? 0), 2);
        }

        $month = round($discounts + $shipping, 2);
        // Annual estimate from the actual average monthly saving since the first membership order.
        $firstOrder = $user ? EcommerceOrder::where('user_id', $user->id)->where('membership_discount_amount', '>', 0)->min('created_at') : null;
        $months = $firstOrder ? max(1, (int) ceil(now()->diffInDays($firstOrder) / 30)) : 0;
        $annual = $months > 0 ? round(($lifetime / $months) * 12, 2) : 0.0;

        return response()->json([
            'success' => true,
            'data' => [
                'discounts_saved' => round($discounts, 2),
                'shipping_saved' => round($shipping, 2),
                'points_value' => $pointsValueMonth,
                'points_earned_this_month' => $pointsMonth,
                'points_value_this_month' => $pointsValueMonth,
                'total_saved_this_month' => $month,
                'lifetime_saved' => round($lifetime, 2),
                'orders_this_month' => $orderCount,
                'estimated_annual_savings' => $annual,
                'spend_last_12_months' => round($spend12, 2),
                'message' => 'You saved $' . number_format($month, 2) . ' so far this month.',
            ],
        ]);
    }

    // ─── Membership lookup ─────────────────────────────────────────────────

    /**
     * The membership the customer panel shows: their live Central membership first, then the local row.
     *
     * @return array{source: string, row: array, model: ?EcommerceMembership}|null
     */
    public static function resolveCustomerMembership(Request $request, $id = null): ?array
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $source = strtolower((string) $request->input('source', ''));
        $self = new static();

        $centralReached = true;
        if ($source !== 'local') {
            $rows = $self->centralMembershipsFor($request);
            $centralReached = $rows !== null;
            if ($rows) {
                $row = $id !== null ? collect($rows)->firstWhere('id', (int) $id) : null;
                $row ??= collect($rows)->first(fn ($m) => in_array(strtolower(str_replace(' ', '_', (string) ($m['status'] ?? ''))), self::LIVE_CENTRAL_STATUSES, true));
                $row ??= $rows[0];
                return ['source' => 'central', 'row' => $row, 'model' => null];
            }
            if ($source === 'central') {
                return null;
            }
        }

        $model = ($id !== null ? EcommerceMembership::where('user_id', $user->id)->find($id) : null)
            ?: EcommerceMembership::where('user_id', $user->id)->latest('updated_at')->first();

        // central_unreachable: Central could not be asked, so this local copy may be out of date.
        // Actions must not change it (that is how the customer's and admin's copies drifted apart).
        return $model
            ? ['source' => 'local', 'row' => $model->load('user')->toArray(), 'model' => $model, 'central_unreachable' => ! $centralReached]
            : null;
    }

    /**
     * The customer's memberships in Central: by their sign-in token, then by email.
     *
     * @return array<int, array>|null [] = Central has none, null = Central could not be reached
     */
    private function centralMembershipsFor(Request $request): ?array
    {
        $token = $request->bearerToken();
        if ($token) {
            $response = $this->central('get', '/user/memberships', [], $token);
            if ($response && $response->successful() && is_array($response->json('data')) && $response->json('data') !== []) {
                return array_values($response->json('data'));
            }
            // Empty or refused (e.g. a store-only token): look the customer up by email before
            // deciding they have no Central membership.
        }

        $email = strtolower((string) $request->user()?->email);
        if ($email === '') {
            return [];
        }
        $response = $this->central('get', '/v1/internal/admin/memberships');
        if (! $response || ! $response->successful()) {
            return null;
        }

        return collect($response->json('data') ?? [])
            ->filter(fn ($m) => strtolower((string) ($m['user']['email'] ?? '')) === $email)
            ->values()
            ->all();
    }

    /**
     * Normalised shape the user panels render, so they never need hardcoded fallbacks.
     */
    public static function presentMembership(array $row): array
    {
        $source = $row['source'] ?? 'local';
        $status = strtolower(str_replace(' ', '_', (string) ($row['status'] ?? 'active')));
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

        $plan = null;
        if (! empty($row['plan_id'])) {
            $plan = EcommerceSubscriptionPlan::find($row['plan_id']);
        }
        if (! $plan && ! empty($row['plan_name'])) {
            $plan = EcommerceSubscriptionPlan::whereRaw('LOWER(name) = ?', [strtolower((string) $row['plan_name'])])
                ->orWhereRaw('LOWER(internal_code) = ?', [strtolower((string) $row['plan_name'])])
                ->first();
        }

        $scheduled = null;
        if ($source === 'central' && is_array($row['scheduled_plan'] ?? null) && ! empty($row['scheduled_plan'])) {
            $scheduled = [
                'plan_id' => $row['scheduled_plan']['plan_id'] ?? null,
                'plan_name' => $row['scheduled_plan']['plan_name'] ?? null,
                'price' => isset($row['scheduled_plan']['price']) ? (float) $row['scheduled_plan']['price'] : null,
                'effective_date' => $row['scheduled_plan']['effective_date'] ?? ($row['next_billing_date'] ?? null),
            ];
        } elseif ($source === 'local' && ! empty($row['scheduled_plan_id'])) {
            $sp = EcommerceSubscriptionPlan::find($row['scheduled_plan_id']);
            $scheduled = $sp ? [
                'plan_id' => $sp->id,
                'plan_name' => $sp->name,
                'price' => (float) $sp->price,
                'effective_date' => self::dateString($row['scheduled_change_at'] ?? null),
            ] : null;
        }

        $pause = null;
        if ($source === 'central' && is_array($metadata['pause'] ?? null)) {
            $pause = ['starts_at' => $metadata['pause']['starts_at'] ?? null, 'resumes_at' => $metadata['pause']['resumes_at'] ?? null];
        } elseif (! empty($row['pause_starts_at'])) {
            $pause = ['starts_at' => self::dateString($row['pause_starts_at']), 'resumes_at' => self::dateString($row['pause_resumes_at'] ?? null)];
        }

        $cancelAtPeriodEnd = (bool) ($row['cancel_at_period_end'] ?? false) || $status === 'pending_cancellation';
        $autoRenew = $source === 'central'
            ? (($metadata['auto_renewal'] ?? true) !== false && ! $cancelAtPeriodEnd)
            : ((bool) ($row['auto_renew'] ?? true) && ! $cancelAtPeriodEnd);

        $statusLabel = match (true) {
            $status === 'paused' => 'Paused',
            $cancelAtPeriodEnd && ! in_array($status, ['canceled', 'cancelled'], true) => 'Cancelling',
            in_array($status, ['canceled', 'cancelled', 'terminated'], true) => 'Cancelled',
            $status === 'expired' => 'Expired',
            in_array($status, ['past_due', 'payment_failed', 'grace_period'], true) => 'Payment Due',
            in_array($status, ['inactive', 'suspended'], true) => 'Inactive',
            $status === 'trialing' => 'Trial',
            $status === 'payment_pending' => 'Awaiting Confirmation',
            default => 'Active',
        };

        return array_merge($row, [
            'source' => $source,
            'plan_id' => $plan?->id ?? ($row['plan_id'] ?? null),
            'plan_name' => $plan?->name ?? ($row['plan_name'] ?? null),
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'billing_cycle' => $plan->billing_cycle,
                'account_type' => $plan->account_type,
                'features' => $plan->features ?? [],
                'benefit_config' => $plan->benefit_config ?? [],
                'billing_policy' => MembershipPlanPolicy::for($plan),
            ] : null,
            'renewal_price' => isset($metadata['renewal_price']) ? (float) $metadata['renewal_price'] : (float) ($row['price'] ?? 0),
            'trial_ends_at' => self::dateString($metadata['trial']['ends_at'] ?? null),
            'commitment_ends_at' => self::dateString($metadata['commitment_ends_at'] ?? null),
            'max_ends_at' => self::dateString($metadata['max_ends_at'] ?? null),
            'price' => (float) ($row['price'] ?? 0),
            'billing_cycle' => $row['billing_cycle'] ?? ($plan?->billing_cycle ?? 'Monthly'),
            'normalized_status' => $status,
            'status_label' => $statusLabel,
            'next_billing_date' => self::dateString($row['next_billing_date'] ?? null),
            'member_since' => self::dateString($row['started_at'] ?? $row['created_at'] ?? null),
            'auto_renew' => $autoRenew,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'access_ends_at' => self::dateString($row['final_access_at'] ?? $row['cancels_at'] ?? null),
            'scheduled_change' => $scheduled,
            'pause' => $pause,
        ]);
    }

    /**
     * Apply local scheduled downgrades and end-of-period cancellations that are now due.
     * (Central rows are handled by Central's billing-cycle command.)
     */
    public static function syncLocalScheduledChanges(): void
    {
        try {
            $today = MembershipProrationService::today()->toDateString();

            EcommerceMembership::whereNotNull('scheduled_plan_id')
                ->whereDate('scheduled_change_at', '<=', $today)
                ->get()
                ->each(function (EcommerceMembership $m) {
                    $plan = EcommerceSubscriptionPlan::find($m->scheduled_plan_id);
                    if (! $plan) {
                        $m->update(['scheduled_plan_id' => null, 'scheduled_change_at' => null]);
                        return;
                    }
                    $from = $m->plan_name;
                    $start = MembershipProrationService::parseDate($m->scheduled_change_at) ?? MembershipProrationService::today();
                    $m->update([
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                        'price' => (float) $plan->price,
                        'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
                        'next_billing_date' => MembershipProrationService::addMonths($start, MembershipProrationService::cycleMonths($plan->billing_cycle, $plan->billing_interval_count))->toDateString(),
                        'scheduled_plan_id' => null,
                        'scheduled_change_at' => null,
                    ]);
                    EcommerceMembershipActivity::create([
                        'membership_id' => $m->id,
                        'user_id' => $m->user_id,
                        'source' => 'local',
                        'action' => 'downgrade',
                        'from_plan' => $from,
                        'to_plan' => $plan->name,
                        'performed_by' => 'system',
                        'actor_name' => 'Scheduled change',
                    ]);
                });

            EcommerceMembership::where('cancel_at_period_end', true)
                ->whereNotNull('cancels_at')
                ->whereDate('cancels_at', '<=', $today)
                ->whereRaw('LOWER(status) NOT IN (?, ?)', ['cancelled', 'canceled'])
                ->get()
                ->each(function (EcommerceMembership $m) {
                    $m->update(['status' => 'Cancelled', 'cancel_at_period_end' => false, 'cancelled_at' => now(), 'next_billing_date' => null]);
                    EcommerceMembershipActivity::create([
                        'membership_id' => $m->id,
                        'user_id' => $m->user_id,
                        'source' => 'local',
                        'action' => 'cancelled',
                        'from_plan' => $m->plan_name,
                        'performed_by' => 'system',
                        'actor_name' => 'End of billing period',
                    ]);
                });
        } catch (\Throwable $e) {
            Log::warning('Local membership scheduled-change sync failed: ' . $e->getMessage());
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Run an existing admin membership action on the customer's own membership,
     * so pause/resume/cancel follow exactly the same rules (proration, wallet credit, emails).
     */
    private function delegate(Request $request, string $controller, string $method, array $found, array $input): JsonResponse
    {
        $sub = Request::create($request->getRequestUri(), 'POST', array_merge($input, ['source' => $found['source']]));
        // Only carry auth headers: copying the JSON content-type would make Laravel ignore $input.
        foreach (['Authorization', 'X-Central-Auth-Token', 'X-Pin-Authorization'] as $header) {
            if ($request->headers->has($header)) {
                $sub->headers->set($header, $request->headers->get($header));
            }
        }
        $sub->headers->set('Accept', 'application/json');
        $sub->setUserResolver(fn () => $request->user());
        $sub->server->set('REMOTE_ADDR', $request->ip());

        $id = $found['source'] === 'central' ? (int) $found['row']['id'] : (int) $found['model']->id;

        return app($controller)->{$method}($sub, $id);
    }

    private function succeeded(JsonResponse $response): bool
    {
        return $response->getStatusCode() < 300 && (bool) ($response->getData(true)['success'] ?? false);
    }

    private function withPresentedData(JsonResponse $response, string $source): JsonResponse
    {
        if (! $this->succeeded($response)) {
            return $response;
        }
        $body = $response->getData(true);
        if (is_array($body['data'] ?? null)) {
            $body['data'] = $this->presentMembership(array_merge($body['data'], ['source' => $body['data']['source'] ?? $source]));
        }
        return response()->json($body, $response->getStatusCode());
    }

    /** @return array|JsonResponse payment info, or an error response */
    private function collectPayment(Request $request, User $user, EcommerceSubscriptionPlan $plan, float $amount, string $action = 'change')
    {
        $method = strtolower((string) $request->input('payment_method', 'wallet'));

        if ($method === 'paypal') {
            return $this->collectPayPal($request, $user, $plan, $amount, $action);
        }

        if ($method === 'wallet') {
            $reference = 'WAL-MEM-' . $plan->id . '-' . $user->id . '-' . now()->format('YmdHis');
            $debited = WalletService::adjustWallet($user->id, $amount, 'debit', 'Membership upgrade: ' . $plan->name, $reference);
            if (! $debited) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance. $' . number_format($amount, 2) . ' is due today.',
                ], 422);
            }
            return ['payment_method' => 'wallet', 'payment_processor' => 'wallet', 'transaction_reference' => $reference, 'amount' => $amount];
        }

        // Card: the card the customer just entered, else their saved card that can be charged
        // (one saved through Stripe; cards typed into the profile without Stripe cannot be).
        $stripePm = $request->input('stripe_payment_method') ?: $request->input('payment_method_id');
        $cardInput = ['payment_method' => 'card', 'payment_summary' => ['amount_due_today' => $amount]];
        if ($stripePm) {
            $cardInput['stripe_payment_method'] = $stripePm;
        } else {
            $savedId = app(\App\Services\MembershipCardService::class)->chargeableCardId($user);
            if (! $savedId) {
                return response()->json([
                    'success' => false,
                    'code' => 'card_required',
                    'message' => $this->noCardMessage($plan),
                ], 422);
            }
            $cardInput['saved_payment_method_id'] = $savedId;
        }

        $sub = Request::create('/', 'POST', $cardInput);
        // So the card is checked against, and saved to, this customer's account.
        $sub->setUserResolver(fn () => $user);

        try {
            $result = app(StripeMembershipPaymentService::class)->authorizePlanPurchase($plan, $sub);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first() ?: 'Card payment failed.', 'errors' => $e->errors()], 422);
        }

        return [
            'payment_method' => 'card',
            'payment_processor' => $result['payment_processor'] ?? 'stripe',
            'transaction_reference' => $result['transaction_reference'] ?? null,
            'amount' => $amount,
        ];
    }

    /** "No card" message that only offers the other ways this plan actually accepts. */
    private function noCardMessage(EcommerceSubscriptionPlan $plan): string
    {
        $methods = MembershipPlanPolicy::for($plan)['payment_methods'] ?? ['card'];
        $others = array_values(array_filter(array_map(
            fn ($m) => ['wallet' => 'your wallet balance', 'paypal' => 'PayPal'][$m] ?? null,
            $methods
        )));

        return 'No card on your account can be charged. Add a card under Payment Methods'
            . ($others ? ', or pay with ' . implode(' or ', $others) : '')
            . '.';
    }

    /**
     * PayPal is two-step: the first call returns a redirect to PayPal; after the customer
     * approves, the panel repeats the same action with paypal_payment_id + paypal_payer_id
     * and the payment is captured here (only if it matches what was approved).
     */
    private function collectPayPal(Request $request, User $user, EcommerceSubscriptionPlan $plan, float $amount, string $action)
    {
        $service = app(PayPalMembershipPaymentService::class);
        $paymentId = trim((string) $request->input('paypal_payment_id', ''));
        $payerId = trim((string) $request->input('paypal_payer_id', ''));

        try {
            if ($paymentId !== '' && $payerId !== '') {
                return $service->complete($user, $plan, $amount, $action, $paymentId, $payerId);
            }

            $returnUrl = $this->safeReturnUrl($request, (string) $request->input('return_url', ''));
            if (! $returnUrl) {
                return response()->json(['success' => false, 'message' => 'A valid return_url is required to pay with PayPal.'], 422);
            }
            $cancelUrl = $this->safeReturnUrl($request, (string) $request->input('cancel_url', ''))
                ?: $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'paypal=cancel';

            $started = $service->start($user, $plan, $amount, $action, $returnUrl, $cancelUrl);

            return response()->json([
                'success' => true,
                'requires_redirect' => true,
                'redirect_url' => $started['redirect_url'],
                'payment_id' => $started['payment_id'],
                'amount_due_today' => round($amount, 2),
                'message' => 'Approve the $' . number_format($amount, 2) . ' payment with PayPal to finish.',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first() ?: 'PayPal payment failed.', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Membership PayPal payment failed: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'PayPal payment failed. Nothing was charged.'], 502);
        }
    }

    /** Only allow returning to the site the request came from (prevents open redirects). */
    private function safeReturnUrl(Request $request, string $url): ?string
    {
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_filter(array_map('strtolower', array_merge(
            [parse_url((string) $request->header('Origin'), PHP_URL_HOST), parse_url((string) $request->header('Referer'), PHP_URL_HOST)],
            [parse_url((string) config('app.frontend_url'), PHP_URL_HOST)],
            array_map('trim', explode(',', (string) env('MEMBERSHIP_RETURN_HOSTS', ''))),
            app()->environment(['local', 'testing']) ? ['localhost', '127.0.0.1'] : [],
        )));

        return in_array($host, $allowed, true) ? $url : null;
    }

    private function reversePayment(User $user, ?array $payment, EcommerceSubscriptionPlan $plan): void
    {
        if ($payment && ($payment['payment_method'] ?? null) === 'paypal') {
            $ok = app(PayPalMembershipPaymentService::class)->refund((string) ($payment['transaction_reference'] ?? ''), (float) $payment['amount']);
            if (! $ok) {
                Log::error('Membership change failed after a PayPal payment and the automatic refund failed; manual refund needed.', ['user_id' => $user->id, 'reference' => $payment['transaction_reference'] ?? null]);
            }
            return;
        }
        if (! $payment || ($payment['payment_method'] ?? null) !== 'wallet') {
            if ($payment) {
                // Card: refund the payment through Stripe (nothing to return for a test-mode payment).
                $ok = app(\App\Services\MembershipCardService::class)->refund((string) ($payment['transaction_reference'] ?? ''), (float) ($payment['amount'] ?? 0));
                if (! $ok) {
                    Log::error('Membership plan change failed after a card payment and the automatic refund failed; manual refund needed.', ['user_id' => $user->id, 'reference' => $payment['transaction_reference'] ?? null]);
                }
            }
            return;
        }
        WalletService::adjustWallet($user->id, (float) $payment['amount'], 'refund', 'Reversal: membership upgrade to ' . $plan->name . ' failed', ($payment['transaction_reference'] ?? 'WAL-MEM') . '-REV');
    }

    private function mirrorLocalPlan(User $user, EcommerceSubscriptionPlan $plan, ?string $nextBilling): void
    {
        $this->mirrorLocalFields($user, [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => (float) $plan->price,
            'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
            'next_billing_date' => $nextBilling,
            'status' => 'Active',
            'scheduled_plan_id' => null,
            'scheduled_change_at' => null,
        ]);
    }

    private function mirrorLocalFields(User $user, array $fields): void
    {
        try {
            EcommerceMembership::where('user_id', $user->id)->update($fields);
        } catch (\Throwable $e) {
            Log::warning('Local membership mirror update failed: ' . $e->getMessage());
        }
    }

    private function recordActivity(Request $request, array $found, string $action, array $extra = []): void
    {
        try {
            $user = $request->user();
            EcommerceMembershipActivity::create(array_merge([
                'membership_id' => $found['source'] === 'local' ? $found['model']?->id : null,
                'user_id' => $user?->id,
                'source' => $found['source'],
                'action' => $action,
                'performed_by' => 'customer',
                'actor_name' => $user?->name,
                'ip_address' => $request->ip(),
            ], $extra, [
                'details' => array_merge($extra['details'] ?? [], $found['source'] === 'central' ? ['central_membership_id' => $found['row']['id'] ?? null] : []),
            ]));
        } catch (\Throwable $e) {
            Log::warning('Membership activity log failed: ' . $e->getMessage());
        }
    }

    private function currentPeriod(array $row, $today): ?array
    {
        $period = MembershipProrationService::resolveCurrentPeriod(
            $row['current_period_start'] ?? null,
            $row['current_period_end'] ?? null,
            $row['next_billing_date'] ?? null,
            $row['billing_cycle'] ?? 'Monthly',
            1,
            $today
        );
        if (! $period) {
            $anchor = MembershipProrationService::parseDate($row['started_at'] ?? $row['created_at'] ?? null) ?? $today;
            $period = MembershipProrationService::resolveCurrentPeriod($anchor, null, null, $row['billing_cycle'] ?? 'Monthly', 1, $today);
        }
        return $period;
    }

    private function planSnapshot(EcommerceSubscriptionPlan $plan): array
    {
        return [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->internal_code,
            'price' => (float) $plan->price,
            'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
            'account_type' => strtolower((string) ($plan->account_type ?: 'personal')),
            'coverage_type' => $plan->coverage_type,
            'applicable_site' => $plan->applicable_site,
            'covered_sites' => $plan->covered_sites,
            'benefit_config' => $plan->benefit_config ?: $plan->features,
        ];
    }

    private function isSamePlan(array $row, EcommerceSubscriptionPlan $plan): bool
    {
        return (! empty($row['plan_id']) && (int) $row['plan_id'] === (int) $plan->id)
            || strcasecmp((string) ($row['plan_name'] ?? ''), (string) $plan->name) === 0;
    }

    private function accountType(array $row, User $user): string
    {
        $type = $row['account_type'] ?? null;
        if (! $type && ! empty($row['plan_id'])) {
            $type = EcommerceSubscriptionPlan::find($row['plan_id'])?->account_type;
        }
        $type ??= ($user->account_type ?? null) ?: (filled($user->business_name ?? null) ? 'business' : 'personal');
        return strtolower((string) $type) === 'business' ? 'business' : 'personal';
    }

    private function normalizedStatus(array $row): string
    {
        return strtolower(str_replace(' ', '_', (string) ($row['status'] ?? '')));
    }

    private function statusLabel(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    private function metadata(array $row): array
    {
        return is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    }

    private function transactionDescription(string $type, ?string $plan): string
    {
        $plan = $plan ?: 'Membership';
        return match (strtolower($type)) {
            'subscription', 'membership_purchase' => "{$plan} Subscription",
            'renewal' => "{$plan} Renewal",
            'upgrade' => "Upgrade to {$plan}",
            'plan_change', 'admin_plan_change', 'lateral' => "Plan change to {$plan}",
            'payment_failed' => "{$plan} Payment Failed",
            'cancellation' => "{$plan} Cancellation",
            default => ucwords(str_replace('_', ' ', $type)) . " – {$plan}",
        };
    }

    private function transactionStatus(?string $paymentStatus, float $refund): string
    {
        $status = strtolower((string) $paymentStatus);
        if (in_array($status, ['voided', 'void'], true)) {
            return 'Voided';
        }
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return 'Cancelled';
        }
        if ($status === 'partially_refunded') {
            return 'Partially Refunded';
        }
        if ($refund > 0 || $status === 'refunded') {
            return 'Refunded';
        }
        return match ($status) {
            'failed', 'payment_failed' => 'Failed',
            'pending', 'payment_pending', 'authorized' => 'Pending',
            default => 'Paid',
        };
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'wallet' => 'Wallet Balance',
            'stripe', 'card', 'credit_card' => 'Card',
            '' => '—',
            default => ucfirst((string) $method),
        };
    }

    private static function dateString(mixed $value): ?string
    {
        $date = MembershipProrationService::parseDate($value);
        return $date?->toDateString();
    }

    private function centralFailure($response, string $suffix): JsonResponse
    {
        $message = $response ? ($response->json('message') ?: 'The membership service rejected the change.') : 'The membership service is unavailable.';
        return response()->json([
            'success' => false,
            'message' => $message . ' ' . $suffix,
            'errors' => $response?->json('errors'),
        ], $response && $response->status() === 422 ? 422 : 502);
    }

    private function central(string $method, string $path, array $data = [], ?string $token = null)
    {
        $url = rtrim((string) config('services.central_auth.url'), '/');
        if ($url === '') {
            Log::error('Membership: CENTRAL_AUTH url is not configured (services.central_auth.url).');
            return null;
        }

        try {
            $http = Http::acceptJson()->timeout(8);
            $http = $token
                ? $http->withToken($token)
                : $http->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')]);

            return strtolower($method) === 'post' ? $http->post($url . $path, $data) : $http->get($url . $path, $data);
        } catch (\Throwable $e) {
            Log::warning("Membership central call {$method} {$path} failed: " . $e->getMessage());
            return null;
        }
    }
}
