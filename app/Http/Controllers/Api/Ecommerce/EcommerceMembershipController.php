<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceMembership;
use App\Models\EcommerceSubscriptionPlan;
use App\Models\EcommerceOrder;
use App\Services\MembershipPlanPolicy;
use App\Services\MembershipProrationService;
use Carbon\CarbonImmutable;
use App\Services\StripeMembershipPaymentService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EcommerceMembershipController extends Controller
{
    private function centralCall(string $method, string $path, array $data = [], ?string $token = null)
    {
        $centralUrl = rtrim((string) config('services.central_auth.url'), '/');
        if (empty($centralUrl)) {
            return null;
        }

        try {
            if ($token) {
                $request = Http::acceptJson()
                    ->withToken($token)
                    ->timeout(4);

                if (request()->hasHeader('X-Pin-Authorization')) {
                    $request = $request->withHeaders([
                        'X-Pin-Authorization' => request()->header('X-Pin-Authorization')
                    ]);
                }
            } else {
                $secret = (string) config('services.internal_notifications.secret');
                // Server-to-server: the full member list can take a few seconds when Central is busy.
                // A 4s limit made the admin list silently fall back to Core's local rows.
                $request = Http::acceptJson()
                    ->withHeaders(['X-Internal-Notification-Secret' => $secret])
                    ->timeout(15);
            }

            if (strtolower($method) === 'post') {
                return $request->post($centralUrl . $path, $data);
            }

            // Reads are safe to repeat: one retry for a timeout or a dropped connection.
            return $request->retry(2, 300, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, false)
                ->get($centralUrl . $path, $data);
        } catch (\Throwable $e) {
            Log::warning("Central API call ({$method} {$path}) failed: " . $e->getMessage());
            return null;
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $token = $request->bearerToken();

        $isAdminRequest = str_contains($request->path(), '/admin/memberships') || str_contains($request->url(), '/admin/memberships');
        $hasAdminAccess = $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) ||
            (isset($user->role) && in_array(strtolower($user->role), ['admin', 'super_admin', 'staff', 'editor'], true))
        );

        // If admin request or admin user, fetch central membership records for all users
        if ($isAdminRequest || $hasAdminAccess) {
            try {
                $response = $this->centralCall('get', '/v1/internal/admin/memberships');
                if (! $response || ! $response->successful()) {
                    Log::warning('Admin membership list: Central did not answer (' . ($response?->status() ?? 'no response') . '); showing local rows.');
                }
                if ($response && $response->successful() && is_array($response->json('data'))) {
                    $centralMemberships = $response->json('data');
                    if (!empty($centralMemberships)) {
                        return response()->json([
                            'success' => true,
                            'data' => \App\Http\Controllers\Api\Admin\AdminMembershipDeactivationController::prepareCentralRows($centralMemberships),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Central memberships admin index failed: ' . $e->getMessage());
            }

            // Fallback to local database all memberships if central call returns empty
            $this->syncPauseStates();
            \App\Http\Controllers\Api\Admin\AdminMembershipDeactivationController::syncLocalScheduledDeactivations();
            $localAll = EcommerceMembership::with('user')->orderBy('created_at', 'desc')->get()
                ->map(fn ($m) => array_merge(
                    $m->toArray(),
                    ['source' => 'local'],
                    // Points and loyalty-tier progress, same as Central rows get.
                    $m->user ? \App\Http\Controllers\Api\Admin\AdminMembershipDeactivationController::loyaltyProgress($m->user) : [],
                    ['store_last_login_at' => $m->user?->last_login_at?->toIso8601String(), 'member_banned_at' => $m->user?->banned_at?->toIso8601String()]
                ));
            if ($localAll->count() > 0 || $isAdminRequest) {
                return response()->json(['success' => true, 'data' => $localAll]);
            }
        }

        // Customer query: check local database first for updated active user membership
        if ($user) {
            $local = EcommerceMembership::where('user_id', $user->id)->orderBy('updated_at', 'desc')->get();
            if ($local->count() > 0) {
                $local->transform(function ($m) {
                    $plan = ($m->plan_id ? EcommerceSubscriptionPlan::find($m->plan_id) : null)
                        ?: EcommerceSubscriptionPlan::where('name', $m->plan_name)
                            ->orWhere('internal_code', $m->plan_name)
                            ->first();

                    if (! $plan && (float) $m->price > 0) {
                        $plan = EcommerceSubscriptionPlan::whereRaw('ABS(CAST(price AS DECIMAL(10,2)) - ?) < 0.5', [(float) $m->price])
                            ->first();
                    }

                    if ($plan) {
                        $m->plan_name = $plan->name;
                        $m->plan_id = $plan->id;
                        $m->benefits_snapshot = $plan->benefit_config ?: $plan->features;
                        $m->benefit_config = $plan->benefit_config;
                    } elseif (empty($m->plan_name) || in_array(strtolower((string) $m->plan_name), ['basic', 'essentials', 'essentials plan', 'essential'])) {
                        if (abs((float)$m->price - 19.99) < 0.5) {
                            $m->plan_name = 'Mecarvi Platinum One';
                        } elseif (abs((float)$m->price - 9.99) < 0.5) {
                            $m->plan_name = 'Mecarvi Gold';
                        } else {
                            $m->plan_name = 'Basic Lite';
                        }
                    }
                    return $m;
                });
                return response()->json(['success' => true, 'data' => $local]);
            }
        }

        try {
            $syncPlanName = function ($item) {
                $name = $item['plan_name'] ?? $item['name'] ?? '';
                $price = (float) ($item['price'] ?? 0);
                $plan = EcommerceSubscriptionPlan::where('name', $name)
                    ->orWhere('internal_code', $name)
                    ->first();
                if (! $plan && $price > 0) {
                    $plan = EcommerceSubscriptionPlan::whereRaw('ABS(CAST(price AS DECIMAL(10,2)) - ?) < 0.5', [$price])
                        ->first();
                }
                if ($plan) {
                    $item['plan_name'] = $plan->name;
                    $item['plan_id'] = $plan->id;
                    $item['benefits_snapshot'] = $plan->benefit_config ?: $plan->features;
                } elseif (empty($name) || in_array(strtolower((string) $name), ['basic', 'essentials', 'essentials plan', 'essential'])) {
                    if (abs($price - 19.99) < 0.5) {
                        $item['plan_name'] = 'Mecarvi Platinum One';
                    } elseif (abs($price - 9.99) < 0.5) {
                        $item['plan_name'] = 'Mecarvi Gold';
                    } else {
                        $item['plan_name'] = 'Basic Lite';
                    }
                }
                return $item;
            };

            if ($token) {
                $response = $this->centralCall('get', '/user/memberships', [], $token);
            } else if ($user) {
                $response = $this->centralCall('get', '/v1/internal/admin/memberships');
                if ($response && $response->successful()) {
                    $filtered = collect($response->json('data'))
                        ->filter(fn($m) => strtolower($m['user']['email'] ?? '') === strtolower($user->email))
                        ->map($syncPlanName)
                        ->values();
                    if ($filtered->count() > 0) {
                        return response()->json([
                            'success' => true,
                            'data' => $filtered,
                        ]);
                    }
                }
            }

            if (isset($response) && $response && $response->successful() && !empty($response->json('data'))) {
                $centralData = collect($response->json('data'))->map($syncPlanName)->values()->all();
                return response()->json([
                    'success' => true,
                    'data' => $centralData,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Central memberships index failed: ' . $e->getMessage());
        }

        // If authenticated user has no record yet, create an active default record
        if ($user) {
            $isBusiness = ($user->account_type ?? '') === 'business' || ($user->role ?? '') === 'business' || $request->input('account_type') === 'business';
            $targetType = $isBusiness ? 'business' : 'personal';

            $defaultPlan = EcommerceSubscriptionPlan::where(function ($q) {
                    $q->whereNull('status')->orWhereRaw('LOWER(status) IN (?, ?)', ['active', 'published']);
                })
                ->whereRaw('LOWER(account_type) = ?', [$targetType])
                ->orderBy('price', 'asc')
                ->first();

            $defaultPlanName = $defaultPlan ? $defaultPlan->name : ($isBusiness ? 'Business Starter' : 'Basic Lite');
            $defaultPrice = $defaultPlan ? (float) $defaultPlan->price : 0.00;

            $defaultMem = EcommerceMembership::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'plan_name' => $defaultPlanName,
                    'status' => 'Active',
                    'price' => $defaultPrice,
                    'billing_cycle' => 'Monthly',
                    'next_billing_date' => now()->addDays(30)->toDateString(),
                ]
            );
            return response()->json(['success' => true, 'data' => [$defaultMem]]);
        }

        return response()->json(['success' => true, 'data' => []]);
    }

    public function store(Request $request)
    {
        $token = $request->bearerToken();
        $user = $request->user();
        $payload = $this->membershipPayload($request);
        $trialClaim = null;

        try {
            if ($request->filled('plan_id')) {
                $plan = EcommerceSubscriptionPlan::whereIn('status', ['Active', 'Featured'])
                    ->find($request->integer('plan_id'));

                if ($plan) {
                    // Visibility, availability status/dates and eligibility set in the plan editor.
                    $denied = \App\Services\PlanAvailabilityService::denialReason($plan, $user, $request);
                    if ($denied !== null) {
                        return response()->json(['success' => false, 'message' => $denied], 422);
                    }

                    $paymentMethod = strtolower((string) $request->input('payment_method', 'stripe'));

                    // Apply the plan's Pricing & Billing settings from the admin plan editor.
                    $policy = MembershipPlanPolicy::for($plan);
                    if ($error = MembershipPlanPolicy::assertPaymentMethod($policy, $paymentMethod)) {
                        return response()->json(['success' => false, 'message' => $error], 422);
                    }
                    if (MembershipPlanPolicy::paymentMethod($paymentMethod) === 'paypal') {
                        return response()->json([
                            'success' => false,
                            'message' => 'PayPal checkout is not connected for memberships yet. Please pay by card or wallet.',
                        ], 422);
                    }
                    $withTrial = MembershipPlanPolicy::trialAllowed($policy, $this->membershipHistory($request))
                        && ! $request->boolean('skip_trial');

                    // One free trial per person: check the card, device, phone and email against
                    // trials given to other accounts (MembershipTrialGuard).
                    $trialClaim = null;
                    if ($withTrial && $user) {
                        $guard = app(\App\Services\MembershipTrialGuard::class);
                        $card = null;
                        if (MembershipPlanPolicy::paymentMethod($paymentMethod) === 'card' && (float) $plan->price > 0) {
                            // Read (and save) the card now: its fingerprint is part of the check.
                            $card = app(\App\Services\MembershipCardService::class)->resolveForPurchase($user, $request->all());
                            if (! empty($card['saved_method_id'])) {
                                // The payment step uses the card saved here instead of reading it again.
                                $request->merge(['saved_payment_method_id' => $card['saved_method_id']]);
                            }
                        }
                        $signals = $guard->signals($request, $user, $card);
                        if ($repeat = $guard->findRepeat($user, $signals)) {
                            $guard->recordRefused($user, $plan->id, $signals, $repeat);
                            $fullSchedule = MembershipPlanPolicy::schedule($policy, false);

                            return response()->json([
                                'success' => false,
                                'code' => 'trial_not_available',
                                'message' => $repeat['message'] . ' You can still join ' . $plan->name . ' without the free trial.',
                                'data' => [
                                    'amount_due_without_trial' => round((float) $fullSchedule['amount_due_today'] + (float) ($plan->setup_fee ?? 0), 2),
                                    'saved_payment_method_id' => $card['saved_method_id'] ?? null,
                                ],
                            ], 422);
                        }
                        $trialClaim = $guard->recordGranted($user, $plan->id, $signals, $guard->ipNeedsReview($user, $signals));
                    }

                    $schedule = MembershipPlanPolicy::schedule($policy, $withTrial);
                    $dueToday = round((float) $schedule['amount_due_today'] + ($schedule['charge_now'] ? (float) ($plan->setup_fee ?? 0) : 0), 2);

                    // The amount due is decided here, never by the browser.
                    $request->merge([
                        'payment_summary' => array_merge((array) $request->input('payment_summary', []), [
                            'plan_name' => $policy['billing_display_name'],
                            'description' => $policy['billing_description'],
                            'billing_frequency' => $policy['billing_cycle'],
                            'membership_price' => $policy['plan_price'],
                            'amount_due_today' => $dueToday,
                            'first_charge_date' => $schedule['first_charge_date'],
                            'first_charge_amount' => $schedule['first_charge_amount'],
                            'trial_ends_at' => $schedule['trial_ends_at'],
                            'next_renewal_date' => $schedule['next_billing_date'],
                            'next_renewal_amount' => $schedule['renewal_price'],
                            'tax_treatment' => $policy['tax_treatment'],
                            'auto_renewal_disclosure' => $policy['auto_renew']
                                ? 'Membership renews automatically unless canceled before the next renewal date.'
                                : 'Membership ends at the end of the billing period unless renewed.',
                        ]),
                    ]);
                    $payload = array_merge($payload, [
                        'status' => $schedule['status'],
                        'current_period_start' => $schedule['period_start'],
                        'current_period_end' => $schedule['period_end'],
                        'next_billing_date' => $schedule['next_billing_date'],
                        'gross_amount' => $dueToday,
                        'payment_summary' => $request->input('payment_summary'),
                        'metadata' => MembershipPlanPolicy::membershipMetadata($policy, $schedule, $withTrial),
                    ]);

                    if ($paymentMethod === 'wallet') {
                        if (!$user) {
                            return response()->json(['success' => false, 'message' => 'User authentication is required for wallet payment.'], 401);
                        }

                        $totalDue = $dueToday;
                        $txRef = 'WAL-MEM-' . $plan->id . '-' . time();
                        // Nothing due today (trial, fixed first-payment date, billed in arrears): no debit.
                        $debited = $totalDue <= 0 || WalletService::adjustWallet(
                            $user->id,
                            $totalDue,
                            'debit',
                            'Membership Subscription: ' . $plan->name,
                            $txRef
                        );

                        if (!$debited) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Insufficient wallet balance to subscribe to this plan.',
                            ], 422);
                        }

                        $payment = [
                            'payment_status' => 'paid',
                            'payment_method' => 'wallet',
                            'payment_processor' => 'wallet',
                            'transaction_reference' => $txRef,
                            'payment_details' => [
                                'type' => 'wallet',
                                'amount_debited' => $totalDue,
                                'currency' => $plan->currency ?: 'USD',
                            ],
                        ];
                    } else {
                        $payment = app(StripeMembershipPaymentService::class)->authorizePlanPurchase($plan, $request);
                    }

                    $payload = array_merge($payload, [
                        'payment_status' => $payment['payment_status'],
                        'payment_method' => $payment['payment_method'],
                        'payment_processor' => $payment['payment_processor'],
                        'transaction_reference' => $payment['transaction_reference'],
                        'payment_summary' => array_merge($payload['payment_summary'] ?? [], [
                            'payment_processor' => $payment['payment_processor'],
                            'transaction_reference' => $payment['transaction_reference'],
                            'details' => $payment['payment_details'],
                        ]),
                    ]);
                    if (! empty($payment['payment_details']['card'])) {
                        // The card on file: Central's billing job charges it for the first payment after a
                        // trial and for every renewal.
                        $payload['metadata'] = array_merge($payload['metadata'] ?? [], ['payment_card' => $payment['payment_details']['card']]);
                    }
                }
            }

            // Always sync local database record for user
            if ($user) {
                $existing = EcommerceMembership::where('user_id', $user->id)->first();
                $isRenew = $existing && strtolower((string) $existing->status) === 'active';

                $membership = EcommerceMembership::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'plan_name' => $payload['plan_name'] ?? 'Essentials Plan',
                        'status' => ucwords(str_replace('_', ' ', (string) ($payload['status'] ?? 'Active'))),
                        'price' => $payload['price'] ?? 19.99,
                        'billing_cycle' => $payload['billing_cycle'] ?? 'Monthly',
                        'next_billing_date' => $payload['next_billing_date'] ?? now()->addDays(30),
                    ] + (isset($policy) ? [
                        'plan_id' => $plan->id,
                        'auto_renew' => $policy['auto_renew'],
                    ] : [])
                );

                // Affiliate commission (pending admin approval) for paid membership purchases
                try {
                    $paidStatus = strtolower((string) ($payload['payment_status'] ?? ''));
                    $paidAmount = (float) ($payload['gross_amount'] ?? $payload['price'] ?? 0);
                    if (in_array($paidStatus, ['paid', 'succeeded', 'completed', 'authorized'], true) && $paidAmount > 0) {
                        \App\Services\AffiliateCommissionService::record(
                            $user,
                            \App\Services\AffiliateCommissionService::SOURCE_MEMBERSHIP,
                            (string) ($payload['transaction_reference'] ?? ('membership-' . $user->id . '-' . now()->format('YmdHis'))),
                            $paidAmount,
                            null,
                            $request->ip()
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('Membership affiliate commission failed: ' . $e->getMessage());
                }

                // Loyalty points for a PAID membership purchase / renewal (admin-configurable, one reward per billing period)
                try {
                    $loyaltyPaid = in_array(strtolower((string) ($payload['payment_status'] ?? 'paid')), ['paid', 'succeeded', 'completed', 'authorized'], true);
                    if ($loyaltyPaid) {
                        $period = $isRenew ? now()->format('Y-m') : 'start';
                        \App\Services\LoyaltyService::awardActivity(
                            $user->id,
                            $isRenew ? 'membership_renewal' : 'membership_purchase',
                            'membership_bonus',
                            ($isRenew ? 'Membership renewal bonus: ' : 'Membership bonus: ') . ($payload['plan_name'] ?? 'Membership Plan'),
                            $isRenew ? 'membership_renewal' : 'membership_purchase',
                            $membership->id . '-' . $period
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('Membership loyalty points award failed: ' . $e->getMessage());
                }

                try {
                    $service = app(\App\Services\EmailNotificationService::class);
                    $emailPayload = [
                        'customer_name' => $user->name,
                        'customer_email' => $user->email,
                        'membership_plan' => $payload['plan_name'] ?? 'Essentials Plan',
                        'next_billing_date' => \Carbon\Carbon::parse($payload['next_billing_date'] ?? now()->addDays(30))->format('M j, Y'),
                        'new_tier' => $payload['plan_name'] ?? 'Essentials Member',
                        'site_name' => config('app.name', 'Mecarvi Embroidery'),
                    ];

                    if ($isRenew) {
                        $service->sendEvent('customer_membership_subscription_renew', $emailPayload, $user->email);
                    } else {
                        $service->sendEvent('customer_membership_subscription', $emailPayload, $user->email);
                        $service->sendEvent('customer_tier_upgradation', $emailPayload, $user->email);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Membership email notification failed: ' . $e->getMessage());
                }
            }

            $response = null;
            try {
                if ($token) {
                    $response = $this->centralCall('post', '/user/memberships', $payload, $token);
                } else if ($user) {
                    $response = $this->centralCall('post', '/v1/internal/admin/memberships/update', array_merge($payload, [
                        'email' => $user->email,
                        'membership_id' => $request->input('membership_id')
                    ]));
                }

                if ($response && $response->successful()) {
                    app(\App\Services\MembershipTrialGuard::class)->attachMembership(
                        $trialClaim,
                        is_numeric($response->json('data.id')) ? (int) $response->json('data.id') : null,
                        $request,
                        (string) ($payload['plan_name'] ?? '')
                    );

                    return response()->json([
                        'success' => true,
                        'data' => $response->json('data'),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Central membership sync failed: ' . $e->getMessage());
            }

            if ($user && isset($membership)) {
                return response()->json([
                    'success' => true,
                    'data' => $membership,
                ]);
            }

            return response()->json(['success' => true, 'data' => $payload]);
        } catch (ValidationException $e) {
            // The purchase did not go through, so the trial was not used.
            $trialClaim?->delete();
            throw $e;
        } catch (\Throwable $e) {
            $trialClaim?->delete();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        if ($user) {
            $membership = EcommerceMembership::where('user_id', $user->id)->find($id)
                ?: EcommerceMembership::where('user_id', $user->id)->first();
            if ($membership) {
                return response()->json(['success' => true, 'data' => $membership]);
            }
        }
        return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
    }

    public function update(Request $request, $id)
    {
        $request->merge(['membership_id' => $id]);
        return $this->store($request);
    }

    /**
     * Admin edit of a specific membership row (never the logged-in admin's own membership).
     */
    public function adminUpdate(Request $request, $id)
    {
        $membership = EcommerceMembership::find($id);
        if (! $membership) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }

        $validated = $request->validate([
            'status' => 'nullable|string|max:50',
            'plan_name' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'billing_cycle' => 'nullable|string|max:50',
            'next_billing_date' => 'nullable|date',
        ]);

        $updates = array_filter($validated, fn ($v) => $v !== null);
        if (isset($updates['status'])) {
            $updates['status'] = ucfirst(strtolower($updates['status']));
        }
        $membership->update($updates);

        return response()->json(['success' => true, 'data' => $membership->fresh('user')]);
    }

    /**
     * Find a membership for an admin pause/resume in the store it lives in.
     * Central and local ids are not shared, so the caller passes `source` from the list row.
     *
     * @return array{source: string, row: array, model: ?EcommerceMembership}|null
     */
    private function findAdminMembership(Request $request, $id): ?array
    {
        $source = strtolower((string) $request->input('source', ''));

        if ($source !== 'local') {
            $response = $this->centralCall('get', '/v1/internal/admin/memberships');
            if ($response && $response->successful()) {
                $row = collect($response->json('data') ?? [])->firstWhere('id', (int) $id);
                if ($row) {
                    return ['source' => 'central', 'row' => $row, 'model' => null];
                }
            }
            if ($source === 'central') {
                return null;
            }
        }

        $model = EcommerceMembership::with('user')->find($id);
        return $model ? ['source' => 'local', 'row' => $model->toArray(), 'model' => $model] : null;
    }

    /**
     * Prorated credit for the unused part of the current paid cycle when a pause starts.
     * The customer pays only for the days used: if a 31-day cycle is paused after 16 days,
     * the remaining 15 days are credited back (price x 15 / 31).
     */
    private function pauseProration(array $row, CarbonImmutable $pauseStart): array
    {
        $price = (float) ($row['price'] ?? 0);
        $period = MembershipProrationService::resolveCurrentPeriod(
            $row['current_period_start'] ?? null,
            $row['current_period_end'] ?? null,
            $row['next_billing_date'] ?? null,
            $row['billing_cycle'] ?? 'Monthly'
        );

        if (! $period) {
            return ['credit' => 0.0, 'unused_days' => 0, 'used_days' => 0, 'cycle_days' => 0, 'period_start' => null, 'period_end' => null];
        }

        $cycleDays = max(1, (int) $period['total_days']);
        $usedDays = $pauseStart->gte($period['end'])
            ? $cycleDays
            : (int) min($cycleDays, max(0, MembershipProrationService::daysBetween($period['start'], $pauseStart)));
        $unusedDays = $cycleDays - $usedDays;

        return [
            'credit' => $price > 0 ? round($price * $unusedDays / $cycleDays, 2) : 0.0,
            'unused_days' => $unusedDays,
            'used_days' => $usedDays,
            'cycle_days' => $cycleDays,
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
        ];
    }

    /**
     * Local store only: apply scheduled pause starts and automatic resumes.
     * (Central memberships are handled by Central's app:process-membership-pauses command.)
     */
    private function syncPauseStates(): void
    {
        try {
            $today = now()->startOfDay()->toDateString();

            EcommerceMembership::with('user')
                ->whereRaw('LOWER(status) = ?', ['active'])
                ->whereNotNull('pause_starts_at')
                ->whereDate('pause_starts_at', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('pause_resumes_at')->orWhereDate('pause_resumes_at', '>', $today);
                })
                ->get()
                ->each(function (EcommerceMembership $m) {
                    $credit = (float) ($m->pause_credit_amount ?? 0);
                    $creditedAt = null;
                    if ($credit > 0 && ! $m->pause_wallet_credited_at) {
                        $startsAt = $m->pause_starts_at->toDateString();
                        $ok = $this->creditPauseToWallet($m->toArray(), 'local', $credit, "membership-pause-local-{$m->id}-{$startsAt}", [
                            'unused_days' => $m->pause_unused_days,
                            'cycle_days' => $m->pause_cycle_days,
                            'starts_at' => $startsAt,
                            'period_end' => '',
                        ]);
                        if (! $ok) {
                            return; // keep it active and retry on the next sync
                        }
                        $creditedAt = now();
                    }
                    $m->update([
                        'status' => 'Paused',
                        // Billing moves to the resume date while paused.
                        'next_billing_date' => $m->pause_resumes_at,
                        'pause_wallet_credited_at' => $creditedAt ?? $m->pause_wallet_credited_at,
                    ]);
                });

            EcommerceMembership::whereRaw('LOWER(status) = ?', ['paused'])
                ->whereNotNull('pause_resumes_at')
                ->whereDate('pause_resumes_at', '<=', $today)
                ->update([
                    'status' => 'Active',
                    'pause_starts_at' => null,
                    'pause_resumes_at' => null,
                    'pause_reason' => null,
                    'pause_credit_amount' => null,
                    'pause_unused_days' => null,
                    'pause_cycle_days' => null,
                    'pause_wallet_credited_at' => null,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Membership pause sync failed: ' . $e->getMessage());
        }
    }

    private function adminLabel(Request $request): string
    {
        $admin = $request->user();
        return $admin ? trim(($admin->name ?? 'Admin') . ' <' . ($admin->email ?? '') . '>') : 'Admin';
    }

    private function sendMembershipEmail(string $event, array $row, array $data): void
    {
        $email = $row['user']['email'] ?? null;
        if (! $email) {
            return;
        }
        try {
            app(\App\Services\EmailNotificationService::class)->sendEvent($event, array_merge([
                'customer_name' => $row['user']['name'] ?? 'Member',
                'customer_email' => $email,
                'membership_plan' => $row['plan_name'] ?? 'Membership',
                'site_name' => config('app.name'),
            ], $data), $email);
        } catch (\Throwable $e) {
            Log::warning("Membership email {$event} failed: " . $e->getMessage());
        }
    }

    public function adminPause(Request $request, $id)
    {
        $today = MembershipProrationService::today();
        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:' . $today->toDateString(),
            'resume_date' => 'nullable|date|after:start_date',
            'reason' => 'nullable|string|max:300',
            'notify' => 'nullable|boolean',
            'source' => 'nullable|string|in:central,local',
            'preview' => 'nullable|boolean',
        ]);

        $found = $this->findAdminMembership($request, $id);
        if (! $found) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }
        $row = $found['row'];

        $status = strtolower(str_replace(' ', '_', (string) ($row['status'] ?? '')));
        if (in_array($status, ['paused', 'canceled', 'cancelled', 'expired', 'terminated', 'inactive', 'suspended'], true)) {
            return response()->json(['success' => false, 'message' => "Membership cannot be paused while it is {$status}."], 422);
        }

        $start = CarbonImmutable::parse($validated['start_date'], 'UTC')->startOfDay();
        $resume = ! empty($validated['resume_date']) ? CarbonImmutable::parse($validated['resume_date'], 'UTC')->startOfDay() : null;
        $proration = $this->pauseProration($row, $start);
        $startsNow = $start->lte($today);

        if ($request->boolean('preview')) {
            return response()->json(['success' => true, 'data' => ['proration' => $proration]]);
        }

        $pauseInfo = [
            'starts_at' => $start->toDateString(),
            'resumes_at' => $resume?->toDateString(),
            'reason' => $validated['reason'] ?? null,
            'credit_amount' => $proration['credit'],
            'unused_days' => $proration['unused_days'],
            'used_days' => $proration['used_days'],
            'cycle_days' => $proration['cycle_days'],
            'period_start' => $proration['period_start'],
            'period_end' => $proration['period_end'],
            'settlement' => $proration['credit'] > 0 ? 'wallet_credit_pending' : 'none',
            'wallet_reference' => $proration['credit'] > 0 ? "membership-pause-{$found['source']}-{$id}-{$start->toDateString()}" : null,
            'wallet_credited_at' => null,
            'by' => $this->adminLabel($request),
            'at' => now()->toIso8601String(),
        ];

        // A pause that starts today credits the unused days to the member's wallet now.
        // Scheduled pauses are credited when they start (Central app:process-membership-pauses / local sync).
        // The wallet reference makes this idempotent: retries never credit twice.
        if ($startsNow && $proration['credit'] > 0) {
            if (! $this->creditPauseToWallet($row, $found['source'], $proration['credit'], $pauseInfo['wallet_reference'], $pauseInfo)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not credit the member wallet. Membership was not paused.',
                ], 502);
            }
            $pauseInfo['settlement'] = 'wallet_credited';
            $pauseInfo['wallet_credited_at'] = now()->toIso8601String();
        }

        if ($found['source'] === 'central') {
            $payload = [
                'membership_id' => (int) $id,
                'metadata' => array_merge(is_array($row['metadata'] ?? null) ? $row['metadata'] : [], ['pause' => $pauseInfo]),
                'admin_authorization_reason' => "Pause by {$pauseInfo['by']}: {$pauseInfo['starts_at']} to " . ($pauseInfo['resumes_at'] ?? 'indefinite')
                    . (! empty($pauseInfo['reason']) ? ". Reason: {$pauseInfo['reason']}" : ''),
            ];
            if ($startsNow) {
                // Paused memberships are skipped by the billing job; billing restarts on the resume date.
                $payload['status'] = 'paused';
                $payload['next_billing_date'] = $resume?->toDateString();
            }
            $response = $this->centralCall('post', '/v1/internal/admin/memberships/update', $payload);
            if (! $response || ! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => $response?->json('message') ?: 'Membership service is unavailable. Membership was not paused.',
                ], $response?->status() ?: 502);
            }
            $data = array_merge($response->json('data') ?? [], ['source' => 'central']);
        } else {
            $found['model']->update([
                'status' => $startsNow ? 'Paused' : $found['model']->status,
                'pause_starts_at' => $pauseInfo['starts_at'],
                'pause_resumes_at' => $pauseInfo['resumes_at'],
                'pause_reason' => $pauseInfo['reason'],
                'pause_credit_amount' => $pauseInfo['credit_amount'],
                'pause_unused_days' => $pauseInfo['unused_days'],
                'pause_cycle_days' => $pauseInfo['cycle_days'],
                'pause_wallet_credited_at' => $pauseInfo['wallet_credited_at'] ? now() : null,
                'next_billing_date' => $startsNow ? $resume?->toDateString() : $found['model']->next_billing_date,
            ]);
            $data = array_merge($found['model']->fresh('user')->toArray(), ['source' => 'local']);
        }

        if ($request->boolean('notify', true)) {
            $this->sendMembershipEmail('customer_membership_paused', $row, [
                'pause_start_date' => $start->format('M j, Y'),
                'resume_date' => $resume ? 'on ' . $resume->format('M j, Y') : 'when you choose to resume',
                'credit_amount' => '$' . number_format($proration['credit'], 2),
            ]);
        }

        if ($found['source'] === 'local') {
            \App\Services\MembershipActivityLog::admin($request, 'local', (int) $id, $row['user_id'] ?? null, 'pause', [
                'details' => ['starts_at' => $start->toDateString(), 'resumes_at' => $resume?->toDateString(), 'reason' => $request->input('reason')],
            ]);
        }

        \App\Services\MembershipNotifier::notify($row['user']['email'] ?? null, 'admin_paused', [
            'plan_name' => $row['plan_name'] ?? null,
            'date' => $resume?->toDateString(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
            'proration' => $proration,
            'wallet_credit' => [
                'amount' => $proration['credit'],
                'credited' => $pauseInfo['settlement'] === 'wallet_credited',
                'reference' => $pauseInfo['wallet_reference'],
            ],
        ]);
    }

    /**
     * Credit the prorated pause amount to the member's wallet (Central wallet is the source of truth).
     */
    private function creditPauseToWallet(array $row, string $source, float $amount, string $reference, array $pauseInfo): bool
    {
        if ($amount <= 0) {
            return true;
        }
        $email = $row['user']['email'] ?? null;
        $description = sprintf(
            'Membership pause credit: %s, %d unused of %d days (%s to %s)',
            $row['plan_name'] ?? 'Membership',
            $pauseInfo['unused_days'] ?? 0,
            $pauseInfo['cycle_days'] ?? 0,
            $pauseInfo['starts_at'] ?? '',
            $pauseInfo['period_end'] ?? ''
        );

        if ($email) {
            $response = $this->centralCall('post', '/v1/internal/admin/wallet/adjust', [
                'email' => $email,
                'type' => 'credit',
                'amount' => round($amount, 2),
                'description' => $description,
                'reference_id' => $reference,
            ]);
            if ($response && $response->successful()) {
                return true;
            }
            Log::warning('Membership pause wallet credit via Central failed: ' . ($response?->body() ?? 'no response'));
        }

        // Local-store members whose Central wallet is unreachable: use the app's wallet service.
        if ($source === 'local' && ! empty($row['user_id'])) {
            return WalletService::adjustWallet((int) $row['user_id'], round($amount, 2), 'credit', $description, $reference);
        }

        return false;
    }

    public function adminResume(Request $request, $id)
    {
        $request->validate([
            'notify' => 'nullable|boolean',
            'source' => 'nullable|string|in:central,local',
        ]);

        $found = $this->findAdminMembership($request, $id);
        if (! $found) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }
        $row = $found['row'];

        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $hasScheduledPause = ! empty($metadata['pause']) || ! empty($row['pause_starts_at']);
        if (strtolower((string) ($row['status'] ?? '')) !== 'paused' && ! $hasScheduledPause) {
            return response()->json(['success' => false, 'message' => 'Membership is not paused.'], 422);
        }

        $today = MembershipProrationService::today();
        $wasPaused = strtolower((string) ($row['status'] ?? '')) === 'paused';
        // Resuming starts a new paid cycle today: renewal is due today, next renewal one cycle later.
        $months = MembershipProrationService::cycleMonths($row['billing_cycle'] ?? 'Monthly');
        $nextRenewal = MembershipProrationService::addMonths($today, $months);

        if ($found['source'] === 'central') {
            unset($metadata['pause']);
            $metadata['last_pause_resumed'] = ['by' => $this->adminLabel($request), 'at' => now()->toIso8601String()];
            $payload = [
                'membership_id' => (int) $id,
                'metadata' => $metadata,
                'admin_authorization_reason' => ($wasPaused ? 'Resumed' : 'Scheduled pause cancelled') . ' by ' . $this->adminLabel($request),
            ];
            if ($wasPaused) {
                $payload['status'] = 'active';
                $payload['next_billing_date'] = $today->toDateString();
            }
            $response = $this->centralCall('post', '/v1/internal/admin/memberships/update', $payload);
            if (! $response || ! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => $response?->json('message') ?: 'Membership service is unavailable. Membership was not resumed.',
                ], $response?->status() ?: 502);
            }
            $data = array_merge($response->json('data') ?? [], ['source' => 'central']);
        } else {
            $found['model']->update(array_merge([
                'pause_starts_at' => null,
                'pause_resumes_at' => null,
                'pause_reason' => null,
                'pause_credit_amount' => null,
                'pause_unused_days' => null,
                'pause_cycle_days' => null,
                'pause_wallet_credited_at' => null,
            ], $wasPaused ? ['status' => 'Active', 'next_billing_date' => $today->toDateString()] : []));
            $data = array_merge($found['model']->fresh('user')->toArray(), ['source' => 'local']);
        }

        if ($wasPaused && $request->boolean('notify', true)) {
            $this->sendMembershipEmail('customer_membership_resumed', $row, [
                'next_billing_date' => $nextRenewal->format('M j, Y'),
            ]);
        }

        if ($wasPaused) {
            \App\Services\MembershipNotifier::notify($row['user']['email'] ?? null, 'admin_resumed', ['plan_name' => $row['plan_name'] ?? null]);
            if ($found['source'] === 'local') {
                \App\Services\MembershipActivityLog::admin($request, 'local', (int) $id, $row['user_id'] ?? null, 'resume');
            }
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if ($user) {
            $membership = EcommerceMembership::where('user_id', $user->id)->find($id)
                ?: EcommerceMembership::where('user_id', $user->id)->first();
            if ($membership) {
                $membership->update(['status' => 'Cancelled']);
                return response()->json(['success' => true, 'message' => 'Membership canceled.']);
            }
        }
        return response()->json(['success' => true, 'message' => 'Membership canceled.']);
    }

    public function action(Request $request, $id, string $action)
    {
        $user = $request->user();
        $token = $request->bearerToken();
        $normalizedAction = strtolower(trim($action));

        // 1. Try central API action first if token is available
        $centralResponse = null;
        if ($token) {
            try {
                $centralResponse = $this->centralCall('post', "/user/memberships/{$id}/{$normalizedAction}", $request->all(), $token);
            } catch (\Throwable $e) {
                Log::warning("Central membership action '{$normalizedAction}' failed: " . $e->getMessage());
            }
        }

        // 2. Perform local update on user's membership
        if ($user) {
            $membership = EcommerceMembership::where('user_id', $user->id)->find($id)
                ?: EcommerceMembership::where('user_id', $user->id)->first();

            if (! $membership) {
                $isBusiness = ($user->account_type ?? '') === 'business' || ($user->role ?? '') === 'business' || $request->input('account_type') === 'business';
                $requestedPlan = $request->input('plan_name');
                $requestedPrice = $request->input('price');

                $membership = EcommerceMembership::create([
                    'user_id' => $user->id,
                    'plan_name' => $requestedPlan ?: ($isBusiness ? 'Business Starter' : 'Basic Lite'),
                    'status' => 'Active',
                    'price' => $requestedPrice !== null ? (float) $requestedPrice : ($isBusiness ? 29.99 : 0.00),
                    'billing_cycle' => $request->input('billing_cycle', 'Monthly'),
                    'next_billing_date' => now()->addDays(30),
                ]);
            }

            switch ($normalizedAction) {
                case 'pause':
                    $membership->update(['status' => 'Paused']);
                    break;
                case 'cancel':
                    $membership->update(['status' => 'Cancelled']);
                    break;
                case 'resume':
                case 'activate':
                case 'reactivate':
                case 'renew':
                    $membership->update([
                        'status' => 'Active',
                        'next_billing_date' => $normalizedAction === 'renew'
                            ? $request->input('next_billing_date', now()->addDays(30)->toDateString())
                            : $membership->next_billing_date,
                    ]);
                    if (in_array($normalizedAction, ['renew', 'reactivate'], true) && (float) $membership->price > 0) {
                        try {
                            \App\Services\LoyaltyService::awardActivity(
                                (int) $user->id,
                                'membership_renewal',
                                'membership_bonus',
                                "Loyalty points for membership renewal ({$membership->plan_name}).",
                                'membership',
                                (string) $membership->id . '-renew-' . now()->format('Ymd')
                            );
                        } catch (\Throwable $e) {
                            Log::warning('Membership renewal loyalty award failed: ' . $e->getMessage());
                        }
                    }
                    break;
                case 'downgrade':
                case 'downgrade-free':
                    $targetPlan = $request->input('plan_name', 'Free Plan');
                    $price = (float) $request->input('price', strtolower($targetPlan) === 'free plan' ? 0.00 : 19.99);
                    $membership->update([
                        'plan_name' => $targetPlan,
                        'price' => $price,
                        'status' => 'Active',
                        'next_billing_date' => $request->input('next_billing_date', now()->addDays(30)->toDateString()),
                    ]);
                    break;
                case 'downgrade-essentials':
                    $targetPlan = $request->input('plan_name', 'Essentials Plan');
                    $price = (float) $request->input('price', 19.99);
                    $membership->update([
                        'plan_name' => $targetPlan,
                        'price' => $price,
                        'status' => 'Active',
                        'next_billing_date' => $request->input('next_billing_date', now()->addDays(30)->toDateString()),
                    ]);
                    break;
                case 'upgrade':
                case 'upgrade-business':
                    $targetPlan = $request->input('plan_name', 'Business Pro Plan');
                    $price = (float) $request->input('price', 49.99);
                    $membership->update([
                        'plan_name' => $targetPlan,
                        'price' => $price,
                        'status' => 'Active',
                        'next_billing_date' => $request->input('next_billing_date', now()->addDays(30)->toDateString()),
                    ]);
                    if ($price > 0) {
                        try {
                            \App\Services\LoyaltyService::awardActivity(
                                (int) $user->id,
                                'membership_purchase',
                                'membership_bonus',
                                "Loyalty points for membership upgrade ({$targetPlan}).",
                                'membership',
                                (string) $membership->id . '-upgrade-' . now()->format('Ymd')
                            );
                        } catch (\Throwable $e) {
                            Log::warning('Membership upgrade loyalty award failed: ' . $e->getMessage());
                        }
                    }
                    break;
                case 'toggle_auto_renew':
                case 'auto_renew':
                    $autoRenew = $request->boolean('auto_renew', true);
                    // If disabling, keep status Active until next billing date
                    break;
            }

            return response()->json([
                'success' => true,
                'data' => $membership->fresh(),
                'message' => "Action '{$normalizedAction}' completed successfully."
            ]);
        }

        if ($centralResponse && $centralResponse->successful()) {
            return response()->json($centralResponse->json(), $centralResponse->status());
        }

        return response()->json([
            'success' => true,
            'message' => "Action '{$normalizedAction}' processed."
        ]);
    }

    public function transactions(Request $request)
    {
        $token = $request->bearerToken();
        $user = $request->user();

        // 1. Try central transactions
        if ($token) {
            try {
                $response = $this->centralCall('get', '/user/membership-transactions', [], $token);
                if ($response && $response->successful() && is_array($response->json('data')) && !empty($response->json('data'))) {
                    return response()->json($response->json(), 200);
                }
            } catch (\Throwable $e) {
                Log::warning('Central user membership-transactions failed: ' . $e->getMessage());
            }
        }

        // 2. Return standard structured invoices for user
        $defaultInvoices = [
            [
                'id' => 'inv-1',
                'number' => 'INV-003352',
                'date' => 'Apr 15, 2024',
                'description' => 'Essentials Plan Renewal',
                'paymentMethod' => 'VISA •••• 4242',
                'amount' => '$19.99',
                'amountNumber' => 19.99,
                'status' => 'Paid',
                'accentColor' => 'border-l-[#5b3af7]',
                'iconBg' => 'bg-[#e8e4ff]',
                'iconColor' => 'text-[#5b3af7]',
            ],
            [
                'id' => 'inv-2',
                'number' => 'INV-002324',
                'date' => 'Mar 15, 2024',
                'description' => 'Essentials Plan Renewal',
                'paymentMethod' => 'VISA •••• 4242',
                'amount' => '$19.99',
                'amountNumber' => 19.99,
                'status' => 'Paid',
                'accentColor' => 'border-l-[#2563eb]',
                'iconBg' => 'bg-[#d0e5ff]',
                'iconColor' => 'text-[#2563eb]',
            ],
            [
                'id' => 'inv-3',
                'number' => 'INV-002553',
                'date' => 'Feb 15, 2024',
                'description' => 'Essentials Plan Renewal',
                'paymentMethod' => 'VISA •••• 4242',
                'amount' => '$19.99',
                'amountNumber' => 19.99,
                'status' => 'Paid',
                'accentColor' => 'border-l-[#ea580c]',
                'iconBg' => 'bg-[#ffe3c6]',
                'iconColor' => 'text-[#ea580c]',
            ],
            [
                'id' => 'inv-4',
                'number' => 'INV-002316',
                'date' => 'Jan 15, 2024',
                'description' => 'Essentials Plan Renewal',
                'paymentMethod' => 'VISA •••• 4242',
                'amount' => '$19.99',
                'amountNumber' => 19.99,
                'status' => 'Paid',
                'accentColor' => 'border-l-[#059669]',
                'iconBg' => 'bg-[#c6f3d7]',
                'iconColor' => 'text-[#059669]',
            ],
            [
                'id' => 'inv-5',
                'number' => 'INV-001982',
                'date' => 'Dec 15, 2023',
                'description' => 'Essentials Plan Renewal',
                'paymentMethod' => 'VISA •••• 4242',
                'amount' => '$19.99',
                'amountNumber' => 19.99,
                'status' => 'Paid',
                'accentColor' => 'border-l-[#e11d48]',
                'iconBg' => 'bg-[#ffd4dc]',
                'iconColor' => 'text-[#e11d48]',
            ],
            [
                'id' => 'inv-6',
                'number' => 'INV-001653',
                'date' => 'Nov 15, 2023',
                'description' => 'Essentials Plan Renewal',
                'paymentMethod' => 'VISA •••• 4242',
                'amount' => '$19.99',
                'amountNumber' => 19.99,
                'status' => 'Paid',
                'accentColor' => 'border-l-[#0284c7]',
                'iconBg' => 'bg-[#cbeafe]',
                'iconColor' => 'text-[#0284c7]',
            ],
        ];

        return response()->json(['success' => true, 'data' => $defaultInvoices]);
    }

    public function receipt(Request $request, $id)
    {
        $token = $request->bearerToken();
        if ($token) {
            try {
                $response = $this->centralCall('get', "/user/membership-transactions/{$id}/receipt", [], $token);
                if ($response && $response->successful()) {
                    return response()->json($response->json(), 200);
                }
            } catch (\Throwable $e) {
                Log::warning("Central membership receipt failed for {$id}: " . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $id,
                'invoice_number' => is_numeric($id) ? "INV-00{$id}" : "INV-003352",
                'amount' => 19.99,
                'status' => 'Paid',
                'payment_method' => 'VISA •••• 4242',
                'date' => now()->format('M d, Y'),
                'description' => 'Essentials Plan Monthly Renewal',
                'tax' => 0.00,
                'total' => 19.99,
            ]
        ]);
    }

    public function savings(Request $request)
    {
        $user = $request->user();

        $discountsSaved = 0.0;
        $shippingSaved = 0.0;
        $pointsValue = 0.0;

        if ($user) {
            $monthOrders = EcommerceOrder::where('user_id', $user->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->get();

            // If no orders this month yet, look at recent orders or user membership perks
            if ($monthOrders->isEmpty()) {
                $monthOrders = EcommerceOrder::where('user_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->take(5)
                    ->get();
            }

            if ($monthOrders->isNotEmpty()) {
                $discountsSaved = (float) $monthOrders->sum(function ($order) {
                    return (float) ($order->membership_discount_amount ?: $order->discount_amount ?: 0);
                });

                $shippingSaved = (float) $monthOrders->sum(function ($order) {
                    $usage = is_array($order->membership_benefit_usage) ? $order->membership_benefit_usage : json_decode($order->membership_benefit_usage ?? '[]', true);
                    if (!empty($usage['free_delivery']) || (float) $order->shipping_amount == 0) {
                        return 10.00; // waived standard/priority delivery perk
                    }
                    return 0;
                });

                $pointsValue = round($discountsSaved * 0.25, 2);
            }
        }

        // Default baseline perks preview if no order history yet
        if ($discountsSaved <= 0 && $shippingSaved <= 0) {
            $discountsSaved = 28.50;
            $shippingSaved = 20.00;
            $pointsValue = 14.20;
        }

        $totalMonth = round($discountsSaved + $shippingSaved, 2);
        $estimatedAnnual = round($totalMonth * 12, 2);

        return response()->json([
            'success' => true,
            'data' => [
                'discounts_saved' => $discountsSaved,
                'shipping_saved' => $shippingSaved,
                'points_value' => $pointsValue,
                'total_saved_this_month' => $totalMonth,
                'estimated_annual_savings' => $estimatedAnnual > 0 ? $estimatedAnnual : 240.00,
                'message' => 'You saved $' . number_format($totalMonth, 2) . ' so far this month.',
            ]
        ]);
    }

    public function adminTransactions(Request $request)
    {
        $centralData = [];
        try {
            $response = $this->centralCall('get', '/v1/internal/admin/membership-transactions');
            if ($response && $response->successful() && is_array($response->json('data'))) {
                $centralData = $response->json('data');
            }
        } catch (\Throwable $e) {
            Log::error('Central membership transactions admin index failed: ' . $e->getMessage());
        }

        $existingRefs = collect($centralData)->pluck('transaction_reference')->filter()->toArray();

        $localMemberships = EcommerceMembership::with('user')->get();

        $localMapped = $localMemberships->filter(function ($m) use ($existingRefs) {
            $ref = $m->transaction_reference ?: ('MEM-' . $m->id);
            return !in_array($ref, $existingRefs, true);
        })->map(function ($membership) {
            $businessName = $membership->user->business_name ?? null;
            $isBusiness = filled($businessName) || stripos((string) $membership->plan_name, 'business') !== false;

            return [
                'id' => 'local_' . $membership->id,
                'central_membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'user' => $membership->user ? [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'business_name' => $businessName,
                    'account_type' => $isBusiness ? 'business' : 'personal',
                ] : null,
                'transaction_reference' => $membership->transaction_reference ?: ('MEM-' . $membership->id),
                'transaction_type' => 'subscription',
                'gross_amount' => (float) $membership->price,
                'discount_amount' => 0.00,
                'tax_amount' => 0.00,
                'credit_applied' => 0.00,
                'refund_amount' => 0.00,
                'net_amount' => (float) $membership->price,
                'currency' => 'USD',
                'payment_method' => $membership->payment_method ?: 'card',
                'payment_status' => strtolower($membership->status) === 'active' ? 'completed' : 'paid',
                'created_at' => $membership->created_at ? $membership->created_at->toIso8601String() : now()->toIso8601String(),
                'membership' => [
                    'plan_name' => $membership->plan_name,
                    'status' => $membership->status,
                    'price' => $membership->price,
                    'account_type' => $isBusiness ? 'business' : 'personal',
                    'billing_cycle' => $membership->billing_cycle,
                    'next_billing_date' => $membership->next_billing_date ? $membership->next_billing_date->toDateString() : null,
                    'created_at' => $membership->created_at ? $membership->created_at->toIso8601String() : null,
                ]
            ];
        })->values()->all();

        // Payments taken for local (Core-only) memberships after signup: upgrades and plan changes
        // are recorded in the activity log with the amount; list them as transactions too.
        $byId = $localMemberships->keyBy('id');
        $localPayments = \App\Models\EcommerceMembershipActivity::where('source', 'local')
            ->whereNotNull('membership_id')
            ->where('amount', '>', 0)
            ->whereIn('action', ['upgrade', 'downgrade', 'lateral', 'plan_changed', 'upgrade_scheduled', 'plan_change_scheduled'])
            ->get()
            ->filter(fn ($a) => $byId->has($a->membership_id))
            ->map(function ($a) use ($byId) {
                $membership = $byId->get($a->membership_id);
                $amount = (float) $a->amount;
                $details = is_array($a->details) ? $a->details : [];

                return [
                    'id' => 'local_act_' . $a->id,
                    'central_membership_id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'user' => $membership->user ? ['id' => $membership->user->id, 'name' => $membership->user->name, 'email' => $membership->user->email] : null,
                    'transaction_reference' => (string) ($details['reference'] ?? $a->reference ?? ('ACT-' . $a->id)),
                    'transaction_type' => in_array($a->action, ['upgrade', 'upgrade_scheduled'], true) ? 'upgrade' : 'plan_change',
                    'gross_amount' => $amount,
                    'discount_amount' => 0.00,
                    'tax_amount' => 0.00,
                    'credit_applied' => 0.00,
                    'refund_amount' => 0.00,
                    'net_amount' => $amount,
                    'currency' => 'USD',
                    'payment_method' => $a->payment_method ?: 'card',
                    'payment_status' => ($details['settlement'] ?? '') === 'payment_link_requested' ? 'pending' : 'completed',
                    // The period this payment paid for, from the plan change's own calculation.
                    'billing_period_start' => $details['breakdown']['new_period_start'] ?? $details['breakdown']['period_start'] ?? null,
                    'billing_period_end' => $details['breakdown']['new_period_end'] ?? $details['breakdown']['period_end'] ?? null,
                    'created_at' => optional($a->created_at)->toIso8601String(),
                    'membership' => [
                        'id' => $membership->id,
                        'plan_name' => $a->to_plan ?: $membership->plan_name,
                        'status' => $membership->status,
                        'price' => $membership->price,
                        'billing_cycle' => $membership->billing_cycle,
                    ],
                ];
            })->values()->all();

        $merged = array_merge($centralData, $localMapped, $localPayments);

        // Sort by created_at desc
        usort($merged, function ($a, $b) {
            $timeA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
            $timeB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
            return $timeB <=> $timeA;
        });

        return response()->json(['success' => true, 'data' => $merged]);
    }

    public function adminReceipt(Request $request, $id)
    {
        try {
            $response = $this->centralCall('get', "/v1/internal/admin/membership-transactions/{$id}/receipt");
            if ($response && $response->successful()) {
                return response()->json($response->json(), $response->status());
            }
        } catch (\Throwable $e) {
            Log::error('Central membership admin receipt failed: ' . $e->getMessage());
        }

        return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
    }

    public function adminAuditLogs(Request $request)
    {
        try {
            $response = $this->centralCall('get', '/v1/internal/admin/membership-audit-logs');
            if ($response && $response->successful()) {
                return response()->json($response->json(), $response->status());
            }
        } catch (\Throwable $e) {
            Log::error('Central membership audit logs failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true, 'data' => []]);
    }

    /**
     * Admin: change a customer's membership plan.
     * POST /api/ecommerce/admin/memberships/{id}/change-plan
     * Body: plan_id (int), timing (next_cycle|immediate), details (optional), preview (optional bool)
     *
     * The proration is recomputed here from the stored billing period — the
     * client-side preview is never trusted for amounts or dates.
     */
    public function adminChangePlan(Request $request, $id)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer',
            'timing' => 'required|string|in:next_cycle,immediate',
            'details' => 'nullable|string|max:300',
            'preview' => 'nullable|boolean',
            // The admin's local calendar date, so the saved numbers match the preview they saw.
            'client_today' => 'nullable|date_format:Y-m-d',
        ]);

        $today = MembershipProrationService::today();
        if (! empty($validated['client_today'])) {
            $clientToday = MembershipProrationService::parseDate($validated['client_today']);
            // Accept only timezone drift (±1 day) from the server's date.
            if ($clientToday && abs(MembershipProrationService::daysBetween($today, $clientToday)) <= 1) {
                $today = $clientToday;
            }
        }

        $plan = EcommerceSubscriptionPlan::find($validated['plan_id']);
        if (! $plan || ! in_array(strtolower((string) ($plan->status ?? 'active')), ['active', 'featured', 'published'], true)) {
            return response()->json(['success' => false, 'message' => 'Selected plan is not available.'], 422);
        }

        // Central or local (Core-only) membership; the popup sends `source` because ids are not shared.
        $found = $this->findAdminMembership($request, $id);
        if (! $found) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }
        $membership = $found['row'];

        $memberType = strtolower((string) ($membership['account_type'] ?? 'personal'));
        $planType = strtolower((string) ($plan->account_type ?: 'personal'));
        if ($memberType !== $planType) {
            return response()->json(['success' => false, 'message' => "A {$planType} plan cannot be applied to a {$memberType} membership."], 422);
        }

        $status = strtolower(str_replace(' ', '_', (string) ($membership['status'] ?? '')));
        if (! in_array($status, ['active', 'trialing', 'past_due', 'grace_period', 'pending_upgrade', 'pending_downgrade'], true)) {
            return response()->json(['success' => false, 'message' => "Plan cannot be changed while the membership is {$status}."], 422);
        }

        $isSamePlan = (! empty($membership['plan_id']) && (int) $membership['plan_id'] === (int) $plan->id)
            || strcasecmp((string) ($membership['plan_name'] ?? ''), (string) $plan->name) === 0;
        if ($isSamePlan) {
            return response()->json(['success' => false, 'message' => 'The customer is already on this plan.'], 422);
        }

        $period = MembershipProrationService::resolveCurrentPeriod(
            $membership['current_period_start'] ?? null,
            $membership['current_period_end'] ?? null,
            $membership['next_billing_date'] ?? null,
            $membership['billing_cycle'] ?? 'Monthly',
            1,
            $today
        );
        $isTrial = strtolower(str_replace(' ', '_', (string) ($membership['status'] ?? ''))) === 'trialing';
        $breakdown = MembershipProrationService::compute(
            $validated['timing'],
            // A free trial was not paid for, so it leaves no unused time to credit.
            $isTrial && $validated['timing'] === 'immediate' ? 0.0 : (float) ($membership['price'] ?? 0),
            (float) $plan->price,
            $plan->billing_cycle,
            $plan->billing_interval_count,
            $period,
            $today
        );

        if ($request->boolean('preview')) {
            return response()->json(['success' => true, 'data' => ['breakdown' => $breakdown]]);
        }

        $admin = $request->user();
        $adminLabel = $admin ? trim(($admin->name ?? 'Admin') . ' <' . ($admin->email ?? '') . '>') : 'Admin';
        $reason = "Plan change {$breakdown['direction']} by {$adminLabel}: "
            . ($membership['plan_name'] ?? 'Unknown') . " → {$plan->name} ({$validated['timing']})"
            . (! empty($validated['details']) ? ". Note: {$validated['details']}" : '');

        $planSnapshot = [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->internal_code,
            'price' => (float) $plan->price,
            'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
            'account_type' => $planType,
            'coverage_type' => $plan->coverage_type,
            'applicable_site' => $plan->applicable_site,
            'covered_sites' => $plan->covered_sites,
            'benefit_config' => $plan->benefit_config ?: $plan->features,
        ];
        $metadata = array_merge(is_array($membership['metadata'] ?? null) ? $membership['metadata'] : [], [
            'last_plan_change' => [
                'by' => $adminLabel,
                'at' => now()->toIso8601String(),
                'from_plan' => $membership['plan_name'] ?? null,
                'to_plan' => $plan->name,
                'details' => $validated['details'] ?? null,
                'breakdown' => $breakdown,
            ],
        ]);

        $payload = [
            'membership_id' => (int) $id,
            'admin_authorization_reason' => $reason,
            'metadata' => $metadata,
        ];

        if ($validated['timing'] === 'next_cycle') {
            $payload += [
                'status' => $breakdown['direction'] === 'downgrade' ? 'pending_downgrade' : 'pending_upgrade',
                'scheduled_plan' => $planSnapshot + ['effective_date' => $breakdown['effective_date']],
                // Keep the current period exactly as resolved so the scheduler switches on the right day.
                'current_period_start' => $breakdown['period_start'],
                'current_period_end' => $breakdown['period_end'],
                'next_billing_date' => $breakdown['next_billing_date'],
            ];
        } else {
            $settlement = $breakdown['refund_amount'] > 0 ? 'refund_due' : ($breakdown['amount_due_today'] > 0 ? 'charge_due' : 'none');
            $payload += [
                'status' => 'active',
                'scheduled_plan' => null,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_code' => $plan->internal_code,
                'price' => (float) $plan->price,
                'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
                'benefits_snapshot' => $planSnapshot['benefit_config'],
                'current_period_start' => $breakdown['new_period_start'],
                'current_period_end' => $breakdown['new_period_end'],
                'next_billing_date' => $breakdown['next_billing_date'],
                'transaction_reference' => 'admin_plan_change_' . $id . '_' . now()->timestamp,
                'transaction_type' => 'admin_plan_change',
                'credit_applied' => min($breakdown['unused_credit'], $breakdown['new_plan_charge']),
                'refund_amount' => $breakdown['refund_amount'],
                'payment_status' => $membership['payment_status'] ?? 'paid',
                'payment_summary' => [
                    'plan_name' => $plan->name,
                    'previous_plan_name' => $membership['plan_name'] ?? null,
                    'billing_frequency' => $plan->billing_cycle ?: 'Monthly',
                    'membership_price' => (float) $plan->price,
                    'unused_credit' => $breakdown['unused_credit'],
                    'days_used' => $breakdown['days_used'],
                    'days_remaining' => $breakdown['days_remaining'],
                    'period_days' => $breakdown['period_days'],
                    'amount_due_today' => $breakdown['amount_due_today'],
                    'refund_amount' => $breakdown['refund_amount'],
                    'settlement' => $settlement,
                    'next_renewal_amount' => $breakdown['next_renewal_amount'],
                    'next_renewal_date' => $breakdown['next_billing_date'],
                ],
            ];
        }

        if ($found['source'] === 'local') {
            /** @var EcommerceMembership $model */
            $model = $found['model'];
            $model->update($validated['timing'] === 'next_cycle'
                ? ['scheduled_plan_id' => $plan->id, 'scheduled_change_at' => $breakdown['effective_date']]
                : [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'price' => (float) $plan->price,
                    'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
                    'status' => 'Active',
                    'next_billing_date' => $breakdown['next_billing_date'],
                    'scheduled_plan_id' => null,
                    'scheduled_change_at' => null,
                ]);
            \App\Services\MembershipActivityLog::admin($request, 'local', (int) $id, $model->user_id, $validated['timing'] === 'immediate' ? 'plan_changed' : 'plan_change_scheduled', [
                'from_plan' => $membership['plan_name'] ?? null,
                'to_plan' => $plan->name,
                'amount' => $breakdown['amount_due_today'] > 0 ? $breakdown['amount_due_today'] : null,
                'details' => ['effective' => $breakdown['effective_date'], 'note' => $validated['details'] ?? null, 'breakdown' => $breakdown],
            ]);
            \App\Services\MembershipNotifier::notify(
                $membership['user']['email'] ?? null,
                $validated['timing'] === 'immediate' ? 'plan_changed' : 'downgrade_scheduled',
                ['from_plan' => $membership['plan_name'] ?? null, 'to_plan' => $plan->name, 'date' => $breakdown['effective_date'] ?? null]
            );

            return response()->json([
                'success' => true,
                'message' => $validated['timing'] === 'immediate'
                    ? "Plan changed to {$plan->name}."
                    : "{$plan->name} scheduled to start on {$breakdown['effective_date']}.",
                'data' => [
                    'membership' => array_merge($model->fresh('user')->toArray(), ['source' => 'local']),
                    'breakdown' => $breakdown,
                ],
            ]);
        }

        $update = $this->centralCall('post', '/v1/internal/admin/memberships/update', $payload);
        if (! $update || ! $update->successful()) {
            $message = $update ? ($update->json('message') ?: 'Membership service rejected the change.') : 'Membership service is unavailable.';
            return response()->json([
                'success' => false,
                'message' => $message . ' Plan was not changed.',
                'errors' => $update ? $update->json('errors') : null,
            ], $update && $update->status() === 422 ? 422 : 502);
        }

        // Keep the local mirror in step for immediate changes.
        if ($validated['timing'] === 'immediate') {
            try {
                $email = strtolower((string) ($membership['user']['email'] ?? ''));
                $localUser = $email !== '' ? \App\Models\User::whereRaw('LOWER(email) = ?', [$email])->first() : null;
                if ($localUser) {
                    EcommerceMembership::where('user_id', $localUser->id)->update([
                        'plan_name' => $plan->name,
                        'price' => (float) $plan->price,
                        'billing_cycle' => $plan->billing_cycle ?: 'Monthly',
                        'next_billing_date' => $breakdown['next_billing_date'],
                        'status' => 'Active',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Local membership mirror sync after plan change failed: ' . $e->getMessage());
            }
        }

        \App\Services\MembershipNotifier::notify(
            $membership['user']['email'] ?? null,
            $validated['timing'] === 'immediate' ? 'plan_changed' : 'downgrade_scheduled',
            ['from_plan' => $membership['plan_name'] ?? null, 'to_plan' => $plan->name, 'date' => $breakdown['effective_date'] ?? null]
        );

        return response()->json([
            'success' => true,
            'message' => $validated['timing'] === 'immediate'
                ? "Plan changed to {$plan->name}."
                : "{$plan->name} scheduled to start on {$breakdown['effective_date']}.",
            'data' => [
                'membership' => $update->json('data'),
                'breakdown' => $breakdown,
            ],
        ]);
    }

    /**
     * Admin upgrade of a Central membership to a higher plan.
     * POST /admin/memberships/{id}/upgrade   (preview=1 returns the quote without saving)
     *
     * The quote is recomputed here (MembershipUpgradeService) so the saved numbers never depend on
     * the browser. If the admin's on-screen total differs from the server's, nothing is saved.
     */
    public function adminUpgrade(Request $request, $id)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer',
            'billing_cycle' => 'required|string|in:monthly,annual',
            'timing' => 'required|string|in:prorated,next_cycle',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'discount_type' => 'nullable|string|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_recurring' => 'nullable|boolean',
            'discount_reason' => 'nullable|string|max:300',
            'payment_method' => 'required|string|in:card_on_file,wallet,payment_link',
            'expected_total_today' => 'nullable|numeric|min:0',
            'client_today' => 'nullable|date_format:Y-m-d',
            'preview' => 'nullable|boolean',
        ]);

        if (($validated['discount_type'] ?? null) === 'percentage' && (float) ($validated['discount_value'] ?? 0) > 100) {
            return response()->json(['success' => false, 'message' => 'Percentage discount cannot exceed 100%.'], 422);
        }

        $today = MembershipProrationService::today();
        if (! empty($validated['client_today'])) {
            $clientToday = MembershipProrationService::parseDate($validated['client_today']);
            if ($clientToday && abs(MembershipProrationService::daysBetween($today, $clientToday)) <= 1) {
                $today = $clientToday;
            }
        }

        $plan = EcommerceSubscriptionPlan::find($validated['plan_id']);
        if (! $plan || ! in_array(strtolower((string) ($plan->status ?? 'active')), ['active', 'featured', 'published'], true)) {
            return response()->json(['success' => false, 'message' => 'Selected plan is not available.'], 422);
        }

        // Central or local (Core-only) membership; the popup sends `source` because ids are not shared.
        $found = $this->findAdminMembership($request, $id);
        if (! $found) {
            return response()->json(['success' => false, 'message' => 'Membership not found.'], 404);
        }
        $membership = $found['row'];

        $memberType = strtolower((string) ($membership['account_type'] ?? 'personal'));
        $planType = strtolower((string) ($plan->account_type ?: 'personal'));
        if ($memberType !== $planType) {
            return response()->json(['success' => false, 'message' => "A {$planType} plan cannot be applied to a {$memberType} membership."], 422);
        }

        $status = strtolower(str_replace(' ', '_', (string) ($membership['status'] ?? '')));
        if (! in_array($status, ['active', 'trialing'], true)) {
            return response()->json(['success' => false, 'message' => "Membership is {$status}. Reactivate it before upgrading."], 422);
        }

        $currentPrice = (float) ($membership['price'] ?? 0);
        $currentCycle = MembershipProrationService::cycleMonths($membership['billing_cycle'] ?? 'Monthly') === 12 ? 'annual' : 'monthly';
        $currentMonthly = $currentCycle === 'annual' ? $currentPrice / 12 : $currentPrice;
        $prices = \App\Services\MembershipUpgradeService::planPrices((float) $plan->price, $plan->billing_cycle, $plan->annual_discount);

        // Compare by catalogue price when the current plan is known, otherwise by what the member pays.
        // A stored plan_id is trusted only if that plan's name/code still matches (ids can be reused after reseeding).
        $memberPlanName = strtolower(trim((string) ($membership['plan_name'] ?? '')));
        $memberPlanCode = strtolower(trim((string) ($membership['plan_code'] ?? '')));
        $currentPlan = ! empty($membership['plan_id']) ? EcommerceSubscriptionPlan::find($membership['plan_id']) : null;
        if ($currentPlan && strtolower((string) $currentPlan->name) !== $memberPlanName
            && ($memberPlanCode === '' || strtolower((string) $currentPlan->internal_code) !== $memberPlanCode)) {
            $currentPlan = null;
        }
        $currentPlan ??= $memberPlanName !== '' ? EcommerceSubscriptionPlan::whereRaw('LOWER(name) = ?', [$memberPlanName])->first() : null;
        if ($currentPlan) {
            $currentMonthly = \App\Services\MembershipUpgradeService::planPrices((float) $currentPlan->price, $currentPlan->billing_cycle, $currentPlan->annual_discount)['monthly'];
        }
        if ($currentPlan && (int) $currentPlan->id === (int) $plan->id) {
            return response()->json(['success' => false, 'message' => 'The customer is already on this plan.'], 422);
        }
        if ($prices['monthly'] <= $currentMonthly + 0.001) {
            return response()->json(['success' => false, 'message' => "{$plan->name} is not higher than the current plan. Use Change Plan to move to a lower plan."], 422);
        }

        $period = MembershipProrationService::resolveCurrentPeriod(
            $membership['current_period_start'] ?? null,
            $membership['current_period_end'] ?? null,
            $membership['next_billing_date'] ?? null,
            $membership['billing_cycle'] ?? 'Monthly',
            1,
            $today
        );

        $discount = ! empty($validated['discount_type']) && isset($validated['discount_value'])
            ? ['type' => $validated['discount_type'], 'value' => (float) $validated['discount_value'], 'recurring' => (bool) ($validated['discount_recurring'] ?? false)]
            : null;
        $targetCycle = $validated['billing_cycle'];
        $targetPrice = $targetCycle === 'annual' ? $prices['annual'] : $prices['monthly'];
        $trialDays = (int) ($validated['trial_days'] ?? 0);

        $quote = \App\Services\MembershipUpgradeService::quote(
            // A free trial was not paid for, so it leaves no unused time to credit.
            $today, $status === 'trialing' ? 0.0 : $currentPrice, $currentCycle, $period, $targetPrice, $targetCycle, $validated['timing'], $trialDays, $discount
        );

        if ($request->boolean('preview')) {
            return response()->json(['success' => true, 'data' => ['breakdown' => $quote]]);
        }

        if (isset($validated['expected_total_today']) && abs((float) $validated['expected_total_today'] - $quote['total_today']) > 0.01) {
            return response()->json([
                'success' => false,
                'message' => 'The amount changed since the quote was shown (now ' . number_format($quote['total_today'], 2) . '). Please review and confirm again.',
                'data' => ['breakdown' => $quote],
            ], 409);
        }

        $adminLabel = $this->adminLabel($request);
        $email = $membership['user']['email'] ?? null;
        $reference = 'admin_upgrade_' . $id . '_' . $plan->id . '_' . $today->format('Ymd') . '_' . now()->timestamp;
        $method = $validated['payment_method'];

        // Collect today's amount.
        $paymentStatus = $membership['payment_status'] ?? 'paid';
        $settlement = 'none';
        if ($quote['total_today'] > 0) {
            if ($method === 'wallet') {
                if (! $email) {
                    return response()->json(['success' => false, 'message' => 'Customer email is missing; wallet cannot be charged.'], 422);
                }
                $debit = $this->centralCall('post', '/v1/internal/admin/wallet/adjust', [
                    'email' => $email,
                    'type' => 'debit',
                    'amount' => round($quote['total_today'], 2),
                    'description' => "Membership upgrade: " . ($membership['plan_name'] ?? 'Membership') . " → {$plan->name} (prorated)",
                    'reference_id' => $reference,
                ]);
                if (! $debit || ! $debit->successful()) {
                    $msg = $debit ? ($debit->json('message') ?: 'Wallet charge failed.') : 'Wallet service is unavailable.';
                    return response()->json(['success' => false, 'message' => $msg . ' Plan was not upgraded.'], 422);
                }
                $paymentStatus = 'paid';
                $settlement = 'paid_from_wallet';
            } elseif ($method === 'card_on_file') {
                // Charge the member's saved card (default card, else the one used at signup).
                $cards = app(\App\Services\MembershipCardService::class);
                $localUser = $email ? \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first() : null;
                $signupCard = is_array($membership['metadata']['payment_card'] ?? null) ? $membership['metadata']['payment_card'] : [];
                $card = $cards->resolveForRenewal($localUser, $signupCard);
                if (! $card) {
                    return response()->json(['success' => false, 'message' => 'This customer has no card on file. Choose wallet or send a payment link. Plan was not upgraded.'], 422);
                }
                $charge = $cards->charge(
                    $card,
                    round($quote['total_today'], 2),
                    'USD',
                    'Membership upgrade: ' . ($membership['plan_name'] ?? 'Membership') . " → {$plan->name}",
                    ['purpose' => 'membership_admin_upgrade', 'membership_id' => $id, 'source' => $found['source']],
                    $reference
                );
                if (! $charge['paid']) {
                    return response()->json(['success' => false, 'message' => ($charge['reason'] ?: 'The card was declined.') . ' Plan was not upgraded.'], 422);
                }
                $reference = (string) ($charge['reference'] ?: $reference);
                $paymentStatus = 'paid';
                $settlement = 'paid_by_card';
            } else {
                // Payment link: the amount stays due until the customer pays it.
                $paymentStatus = 'payment_pending';
                $settlement = 'payment_link_requested';
            }
        }

        // Credit larger than the charge: return the difference to the customer's wallet.
        if ($quote['carryover_credit'] > 0 && $validated['timing'] === 'prorated' && $trialDays === 0) {
            $credited = $email ? $this->centralCall('post', '/v1/internal/admin/wallet/adjust', [
                'email' => $email,
                'type' => 'credit',
                'amount' => round($quote['carryover_credit'], 2),
                'description' => "Membership upgrade credit: unused {$quote['days_remaining']} of {$quote['period_days']} days",
                'reference_id' => $reference . '_credit',
            ]) : null;
            $settlement = ($credited && $credited->successful()) ? 'credit_to_wallet' : 'credit_due';
        }

        $paymentLabel = ['card_on_file' => 'card', 'wallet' => 'wallet', 'payment_link' => 'payment_link'][$method];
        $planSnapshot = [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->internal_code,
            'price' => $quote['renewal_amount'],
            'list_price' => $targetPrice,
            'billing_cycle' => $targetCycle === 'annual' ? 'Annual' : 'Monthly',
            'account_type' => $planType,
            'coverage_type' => $plan->coverage_type,
            'applicable_site' => $plan->applicable_site,
            'covered_sites' => $plan->covered_sites,
            'benefit_config' => $plan->benefit_config ?: $plan->features,
            'trial_days' => $trialDays,
        ];
        $upgradeRecord = [
            'by' => $adminLabel,
            'at' => now()->toIso8601String(),
            'from_plan' => $membership['plan_name'] ?? null,
            'to_plan' => $plan->name,
            'payment_method' => $method,
            'settlement' => $settlement,
            'discount' => $discount ? $discount + ['amount' => $quote['discount_amount'], 'reason' => $validated['discount_reason'] ?? null] : null,
            'breakdown' => $quote,
        ];
        $metadata = array_merge(is_array($membership['metadata'] ?? null) ? $membership['metadata'] : [], ['last_upgrade' => $upgradeRecord]);

        $reason = "Upgrade by {$adminLabel}: " . ($membership['plan_name'] ?? 'Unknown') . " → {$plan->name} ({$validated['timing']}, {$targetCycle})"
            . ($trialDays > 0 ? ", {$trialDays}-day trial" : '')
            . ($discount ? ", discount {$discount['value']}" . ($discount['type'] === 'percentage' ? '%' : ' USD') : '')
            . (! empty($validated['discount_reason']) ? ". Reason: {$validated['discount_reason']}" : '');

        $payload = ['membership_id' => (int) $id, 'admin_authorization_reason' => $reason, 'metadata' => $metadata];

        if ($validated['timing'] === 'next_cycle') {
            $payload += [
                'status' => 'pending_upgrade',
                'scheduled_plan' => $planSnapshot + ['effective_date' => $quote['effective_date'], 'first_charge_date' => $quote['first_charge_date'], 'first_charge_amount' => $quote['first_charge_amount']],
                'current_period_start' => $quote['period_start'],
                'current_period_end' => $quote['period_end'],
                'next_billing_date' => $quote['period_end'],
            ];
        } else {
            $payload += [
                'status' => $trialDays > 0 ? 'trialing' : 'active',
                'scheduled_plan' => null,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_code' => $plan->internal_code,
                'price' => $quote['renewal_amount'],
                'billing_cycle' => $planSnapshot['billing_cycle'],
                'benefits_snapshot' => $planSnapshot['benefit_config'],
                'current_period_start' => $quote['new_period_start'],
                'current_period_end' => $quote['new_period_end'],
                'next_billing_date' => $quote['next_billing_date'],
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentLabel,
                'payment_summary' => [
                    'plan_name' => $plan->name,
                    'previous_plan_name' => $membership['plan_name'] ?? null,
                    'billing_frequency' => $planSnapshot['billing_cycle'],
                    'membership_price' => $quote['renewal_amount'],
                    'new_plan_charge' => $quote['new_charge'],
                    'unused_credit' => $quote['unused_credit'],
                    'discounts' => $quote['discount_amount'],
                    'days_used' => $quote['days_used'],
                    'days_remaining' => $quote['days_remaining'],
                    'period_days' => $quote['period_days'],
                    'amount_due_today' => $quote['total_today'],
                    'carryover_credit' => $quote['carryover_credit'],
                    'trial_ends_on' => $quote['trial_ends_on'],
                    'first_charge_date' => $quote['first_charge_date'],
                    'first_charge_amount' => $quote['first_charge_amount'],
                    'settlement' => $settlement,
                    'next_renewal_amount' => $quote['renewal_amount'],
                    'next_renewal_date' => $quote['next_billing_date'],
                ],
            ];
            if ($trialDays === 0) {
                // Ledger entry for today's prorated settlement: gross − discount − credit = amount due today.
                $payload += [
                    'transaction_reference' => $reference,
                    'transaction_type' => 'admin_upgrade',
                    'gross_amount' => $quote['new_charge'],
                    'discount_amount' => $quote['discount_amount'],
                    'credit_applied' => round(min($quote['unused_credit'], max(0, $quote['new_charge'] - $quote['discount_amount'])), 2),
                    'payment_processor' => $method === 'wallet' ? 'wallet' : null,
                ];
            }
        }

        if ($found['source'] === 'local') {
            /** @var EcommerceMembership $model */
            $model = $found['model'];
            $model->update($validated['timing'] === 'next_cycle'
                ? ['scheduled_plan_id' => $plan->id, 'scheduled_change_at' => $quote['effective_date']]
                : [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'price' => $quote['renewal_amount'],
                    'billing_cycle' => $planSnapshot['billing_cycle'],
                    'status' => $trialDays > 0 ? 'Trialing' : 'Active',
                    'next_billing_date' => $quote['next_billing_date'],
                    'scheduled_plan_id' => null,
                    'scheduled_change_at' => null,
                ]);
            \App\Services\MembershipActivityLog::admin($request, 'local', (int) $id, $model->user_id, $validated['timing'] === 'next_cycle' ? 'upgrade_scheduled' : 'upgrade', [
                'from_plan' => $membership['plan_name'] ?? null,
                'to_plan' => $plan->name,
                'amount' => $quote['total_today'] > 0 ? $quote['total_today'] : null,
                'payment_method' => $paymentLabel,
                'details' => ['effective' => $quote['effective_date'], 'settlement' => $settlement, 'reference' => $reference, 'breakdown' => $quote],
            ]);
            \App\Services\MembershipNotifier::notify(
                $membership['user']['email'] ?? null,
                $validated['timing'] === 'prorated' ? 'upgrade' : 'downgrade_scheduled',
                ['from_plan' => $membership['plan_name'] ?? null, 'to_plan' => $plan->name, 'date' => $quote['effective_date'] ?? null, 'amount' => $quote['total_today'] ?? null]
            );

            return response()->json([
                'success' => true,
                'message' => $validated['timing'] === 'prorated'
                    ? "Upgraded to {$plan->name}."
                    : "{$plan->name} scheduled to start on {$quote['effective_date']}.",
                'data' => ['membership' => array_merge($model->fresh('user')->toArray(), ['source' => 'local']), 'breakdown' => $quote, 'settlement' => $settlement],
            ]);
        }

        $update = $this->centralCall('post', '/v1/internal/admin/memberships/update', $payload);
        if (! $update || ! $update->successful()) {
            if ($settlement === 'paid_by_card') {
                // The card was charged but the upgrade did not save: give the money back.
                $refunded = app(\App\Services\MembershipCardService::class)->refund($reference, round($quote['total_today'], 2));
                if (! $refunded) {
                    Log::error('Admin upgrade: card charged, upgrade not saved, automatic refund failed; manual refund needed.', ['membership_id' => $id, 'reference' => $reference, 'amount' => $quote['total_today']]);
                }
            }
            if ($method === 'wallet' && $settlement === 'paid_from_wallet' && $email) {
                // Undo the wallet charge so the customer is never charged for an upgrade that did not save.
                $this->centralCall('post', '/v1/internal/admin/wallet/adjust', [
                    'email' => $email, 'type' => 'refund', 'amount' => round($quote['total_today'], 2),
                    'description' => 'Reversal: membership upgrade was not saved', 'reference_id' => $reference . '_reversal',
                ]);
            }
            $message = $update ? ($update->json('message') ?: 'Membership service rejected the upgrade.') : 'Membership service is unavailable.';
            return response()->json([
                'success' => false,
                'message' => $message . ' Plan was not upgraded.',
                'errors' => $update ? $update->json('errors') : null,
            ], $update && $update->status() === 422 ? 422 : 502);
        }

        if ($validated['timing'] === 'prorated') {
            try {
                $localUser = $email ? \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first() : null;
                if ($localUser) {
                    EcommerceMembership::where('user_id', $localUser->id)->update([
                        'plan_name' => $plan->name,
                        'price' => $quote['renewal_amount'],
                        'billing_cycle' => $planSnapshot['billing_cycle'],
                        'next_billing_date' => $quote['next_billing_date'],
                        'status' => 'Active',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Local membership mirror sync after upgrade failed: ' . $e->getMessage());
            }
        }

        \App\Services\MembershipNotifier::notify(
            $membership['user']['email'] ?? null,
            $validated['timing'] === 'prorated' ? 'upgrade' : 'downgrade_scheduled',
            ['from_plan' => $membership['plan_name'] ?? null, 'to_plan' => $plan->name, 'date' => $quote['effective_date'] ?? null, 'amount' => $quote['total_today'] ?? null]
        );

        return response()->json([
            'success' => true,
            'message' => $validated['timing'] === 'prorated'
                ? "Upgraded to {$plan->name}."
                : "{$plan->name} scheduled to start on {$quote['effective_date']}.",
            'data' => ['membership' => $update->json('data'), 'breakdown' => $quote, 'settlement' => $settlement],
        ]);
    }

    /** The customer's earlier memberships (Central + local), used for trial eligibility. */
    private function membershipHistory(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }
        $rows = EcommerceMembership::where('user_id', $user->id)->get()->map->toArray()->all();

        $token = $request->bearerToken();
        $response = $token
            ? $this->centralCall('get', '/user/memberships', [], $token)
            : $this->centralCall('get', '/v1/internal/admin/memberships');
        if ($response && $response->successful() && is_array($response->json('data'))) {
            $email = strtolower((string) $user->email);
            foreach ($response->json('data') as $row) {
                if ($token || strtolower((string) ($row['user']['email'] ?? '')) === $email) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private function membershipPayload(Request $request): array
    {
        if ($request->filled('plan_id')) {
            $plan = EcommerceSubscriptionPlan::whereIn('status', ['Active', 'Featured'])
                ->findOrFail($request->integer('plan_id'));

            return [
                'plan_name' => $plan->name,
                'status' => 'Active',
                'price' => $plan->price,
                'billing_cycle' => $plan->billing_cycle,
                'next_billing_date' => $request->input('next_billing_date'),
                'payment_status' => $request->input('payment_status', 'paid'),
                'payment_method' => $request->input('payment_method', 'card'),
                'transaction_reference' => $request->input('transaction_reference', 'membership_' . $plan->id . '_' . now()->timestamp),
                'plan_id' => $plan->id,
                'plan_code' => $plan->internal_code,
                'account_type' => $plan->account_type,
                'coverage_type' => $plan->coverage_type,
                'applicable_site' => $plan->applicable_site,
                'covered_sites' => $plan->covered_sites,
                'benefits_snapshot' => $plan->benefit_config ?: $plan->features,
                'payment_summary' => $request->input('payment_summary'),
                'terms_accepted' => $request->boolean('terms_accepted'),
                'pin' => $request->input('pin'),
                'currency' => $plan->currency ?? 'USD',
            ];
        }

        return $request->only([
            'membership_id',
            'plan_id',
            'plan_name',
            'plan_code',
            'account_type',
            'coverage_type',
            'applicable_site',
            'covered_sites',
            'status',
            'price',
            'billing_cycle',
            'next_billing_date',
            'current_period_start',
            'current_period_end',
            'final_access_at',
            'cancel_at_period_end',
            'scheduled_plan',
            'benefits_snapshot',
            'payment_summary',
            'payment_status',
            'payment_method',
            'payment_processor',
            'transaction_reference',
            'transaction_type',
            'discount_amount',
            'tax_amount',
            'credit_applied',
            'refund_amount',
            'currency',
            'terms_accepted',
            'pin',
            'authorization_reference',
            'timezone',
            'metadata',
        ]);
    }
}
