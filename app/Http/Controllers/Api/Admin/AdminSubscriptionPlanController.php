<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceSubscriptionPlan;
use App\Services\PlanAvailabilityService;
use App\Models\EcommerceMembership;
use App\Services\MembershipPlanPolicy;
use App\Services\MembershipProrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AdminSubscriptionPlanController extends Controller
{
    /**
     * Display a listing of the subscription plans.
     */
    public function index()
    {
        $plans = EcommerceSubscriptionPlan::orderBy('sort_order')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $plans
        ]);
    }

    /**
     * Display active subscription plans for customer-facing pages.
     */
    public function publicIndex(Request $request)
    {
        $site = trim((string) ($request->input('site') ?: $request->input('website') ?: ''));
        $accountType = trim((string) ($request->input('account_type') ?: $request->input('audience') ?: $request->input('type') ?: ''));

        $targetTypes = [];
        if ($accountType !== '') {
            $accLower = strtolower($accountType);
            if (in_array($accLower, ['business', 'commercial', 'company', 'biz'], true)) {
                $targetTypes = ['business', 'commercial'];
            } elseif (in_array($accLower, ['personal', 'customer', 'user', 'individual', 'consumer'], true)) {
                $targetTypes = ['personal', 'customer', 'individual'];
            } else {
                $targetTypes = [$accLower];
            }
        }

        $query = EcommerceSubscriptionPlan::query();

        $query->where(function ($q) {
            $q->whereNull('status')
              ->orWhereRaw('LOWER(status) IN (?, ?, ?)', ['active', 'featured', 'published']);
        });
        // Availability dates, visibility and availability status are applied below by
        // PlanAvailabilityService (a future start date lists the plan as "coming soon").

        if (!empty($targetTypes)) {
            $query->where(function ($q) use ($targetTypes) {
                foreach ($targetTypes as $idx => $t) {
                    if ($idx === 0) {
                        $q->whereRaw('LOWER(account_type) = ?', [$t]);
                    } else {
                        $q->orWhereRaw('LOWER(account_type) = ?', [$t]);
                    }
                }
            });
        }

        if ($site !== '') {
            $siteSlug = strtolower($site);
            $query->where(function ($q) use ($siteSlug) {
                $q->whereNull('coverage_type')
                  ->orWhereRaw('LOWER(coverage_type) IN (?, ?, ?, ?)', ['universal', 'all', 'all-sites', 'all_sites'])
                  ->orWhere(function ($sub) use ($siteSlug) {
                      $sub->where(function ($siteQ) use ($siteSlug) {
                          $siteQ->whereRaw('LOWER(applicable_site) = ?', [$siteSlug])
                                ->orWhereRaw('LOWER(applicable_site) LIKE ?', ['%' . $siteSlug . '%'])
                                ->orWhereRaw('LOWER(CAST(covered_sites AS TEXT)) LIKE ?', ['%' . $siteSlug . '%']);
                      });
                  });
            });
        }

        $plans = $query->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        if ($plans->isEmpty()) {
            $fallbackQuery = EcommerceSubscriptionPlan::where(function ($q) {
                $q->whereNull('status')->orWhereRaw('LOWER(status) IN (?, ?, ?)', ['active', 'featured', 'published']);
            });
            if (!empty($targetTypes)) {
                $fallbackQuery->where(function ($q) use ($targetTypes) {
                    foreach ($targetTypes as $idx => $t) {
                        if ($idx === 0) {
                            $q->whereRaw('LOWER(account_type) = ?', [$t]);
                        } else {
                            $q->orWhereRaw('LOWER(account_type) = ?', [$t]);
                        }
                    }
                });
            }
            $plans = $fallbackQuery->orderBy('sort_order')->orderBy('price')->get();
        }

        // Plan Visibility / Availability Status / Availability Dates from the plan editor.
        // "Hidden / Direct link only" plans are returned only when asked for by id or slug.
        $directRef = strtolower(trim((string) ($request->input('plan') ?: $request->input('plan_id') ?: $request->input('slug') ?: '')));
        $plans = $plans->filter(function ($plan) use ($directRef) {
            if (PlanAvailabilityService::isListed($plan)) {
                return true;
            }
            return $directRef !== ''
                && PlanAvailabilityService::isReachable($plan)
                && in_array($directRef, [(string) $plan->id, strtolower((string) $plan->internal_code)], true);
        })->values();

        $viewer = $request->user();

        $formatted = $plans->map(function ($plan) use ($viewer, $request) {
            $features = $plan->features;
            if (is_string($features)) {
                $decoded = json_decode($features, true);
                if (is_array($decoded)) {
                    $features = $decoded;
                } else {
                    $features = array_filter(array_map('trim', explode("\n", $features)));
                }
            }

            if ((empty($features) || !is_array($features)) && !empty($plan->benefit_config['benefits_table']) && is_array($plan->benefit_config['benefits_table'])) {
                $features = collect($plan->benefit_config['benefits_table'])
                    ->filter(fn ($b) => ($b['status'] ?? true))
                    ->map(function ($b) {
                        $title = trim((string) ($b['title'] ?? ''));
                        $details = trim((string) ($b['details'] ?? ''));
                        return $details !== '' ? "{$title}: {$details}" : $title;
                    })
                    ->filter(fn ($s) => $s !== '')
                    ->values()
                    ->all();
            }

            $priceNum = (float) $plan->price;
            $tierRank = 1;
            if ($priceNum <= 0) {
                $tierRank = 0;
            } elseif ($priceNum < 30) {
                $tierRank = 1;
            } elseif ($priceNum < 70) {
                $tierRank = 2;
            } else {
                $tierRank = 3;
            }

            $planData = $plan->toArray();
            $planData['features'] = is_array($features) ? array_values($features) : [];
            $planData['tier_rank'] = $tierRank;
            $planData['formatted_price'] = '$' . number_format($priceNum, 2);
            // Pricing & Billing rules the panels use for checkout (payment methods, trial, first charge, renewal).
            $policy = MembershipPlanPolicy::for($plan);
            $planData['billing_policy'] = $policy;
            $planData['subscribe_preview'] = MembershipPlanPolicy::schedule($policy, $policy['trial_enabled']);
            $planData['benefits'] = collect($plan->benefit_config['benefits_table'] ?? [])
                ->filter(fn ($b) => is_array($b) && ($b['status'] ?? true))
                ->values()
                ->all();

            return array_merge($planData, PlanAvailabilityService::presentFor($plan, $viewer, $request));
        });

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    /**
     * Store a newly created subscription plan in storage.
     */
    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $plan = EcommerceSubscriptionPlan::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan created successfully.',
            'data' => $plan
        ], 201);
    }

    /**
     * Display the specified subscription plan.
     */
    public function show(string $id)
    {
        $plan = EcommerceSubscriptionPlan::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $plan
        ]);
    }

    /**
     * Update the specified subscription plan in storage.
     */
    public function update(Request $request, string $id)
    {
        $plan = EcommerceSubscriptionPlan::findOrFail($id);

        $validated = $this->validated($request, true, $plan->id);

        $plan->update($validated);
        $synced = $this->syncMembersToPlan($plan->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan updated successfully.' . ($synced > 0 ? " {$synced} member(s) updated to the new billing settings." : ''),
            'data' => $plan->fresh(),
            'members_synced' => $synced,
        ]);
    }

    /**
     * Remove the specified subscription plan from storage.
     */
    public function destroy(string $id)
    {
        $plan = EcommerceSubscriptionPlan::findOrFail($id);

        // members_limit is the plan's member cap, not its subscriber count, so it must not
        // block deletion. Only block when members are actually still active on this plan.
        if (Schema::hasColumn('ecommerce_memberships', 'plan_id')) {
            $activeMembers = DB::table('ecommerce_memberships')
                ->where('plan_id', $plan->id)
                ->whereRaw('LOWER(status) IN (?, ?, ?)', ['active', 'trialing', 'paused'])
                ->count();

            if ($activeMembers > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "This plan still has {$activeMembers} active member(s). Set it to Inactive or Retired instead of deleting it.",
                ], 409);
            }
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan deleted successfully.'
        ]);
    }

    /**
     * Upload the side/banner image shown on the plan card.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ]);

        $path = $request->file('image')->store('membership-banners', 'public');

        return response()->json([
            'success' => true,
            'data' => [
                'url' => Storage::disk('public')->url($path),
                'path' => $path,
            ],
        ]);
    }

    /**
     * Existing members follow the plan's billing settings (renewal price, auto-renew, failed-payment rules).
     * Their own choices are kept: a member who turned auto-renew off stays off, a locked signup price stays locked.
     */
    private function syncMembersToPlan(EcommerceSubscriptionPlan $plan): int
    {
        $policy = MembershipPlanPolicy::for($plan);
        $count = 0;

        try {
            if (! $policy['auto_renew']) {
                EcommerceMembership::where('plan_id', $plan->id)->update(['auto_renew' => false]);
            }

            $url = rtrim((string) config('services.central_auth.url'), '/');
            if ($url === '') {
                return 0;
            }
            $http = Http::acceptJson()->timeout(8)
                ->withHeaders(['X-Internal-Notification-Secret' => (string) config('services.internal_notifications.secret')]);
            $response = $http->get($url . '/v1/internal/admin/memberships');
            if (! $response->successful()) {
                return 0;
            }

            $live = ['active', 'trialing', 'payment_pending', 'past_due', 'grace_period', 'pending_upgrade', 'pending_downgrade', 'pending_cancellation', 'paused'];
            foreach ((array) $response->json('data') as $row) {
                if ((int) ($row['plan_id'] ?? 0) !== (int) $plan->id || ! in_array(strtolower((string) ($row['status'] ?? '')), $live, true)) {
                    continue;
                }
                $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
                $next = MembershipPlanPolicy::refreshMembershipMetadata($policy, $metadata, (float) ($row['price'] ?? 0));
                if ($next == $metadata) {
                    continue;
                }
                $update = $http->post($url . '/v1/internal/admin/memberships/update', [
                    'membership_id' => (int) $row['id'],
                    'actor_type' => 'system',
                    'metadata' => $next,
                    'payment_summary' => array_merge(is_array($row['payment_summary'] ?? null) ? $row['payment_summary'] : [], [
                        'next_renewal_amount' => $next['renewal_price'],
                    ]),
                    'admin_authorization_reason' => "Plan \"{$plan->name}\" billing settings changed by admin.",
                ]);
                if ($update->successful()) {
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Plan member sync failed: ' . $e->getMessage());
        }

        return $count;
    }

    private function validated(Request $request, bool $partial = false, ?int $planId = null): array
    {
        $required = $partial ? 'sometimes|required' : 'required';
        $uniqueCode = 'nullable|string|max:100|unique:ecommerce_subscription_plans,internal_code';
        if ($planId) {
            $uniqueCode .= ',' . $planId;
        }

        $validated = $request->validate([
            'name' => "{$required}|string|max:255",
            'internal_code' => $uniqueCode,
            'description' => 'nullable|string|max:4000',
            'account_type' => 'nullable|string|in:personal,business',
            'coverage_type' => 'nullable|string|in:individual_site,universal',
            'applicable_site' => 'nullable|string|max:255|required_if:coverage_type,individual_site',
            'covered_sites' => 'nullable|array',
            'covered_sites.*' => 'nullable|string|max:255',
            'include_future_sites' => 'nullable|boolean',
            'price' => "{$required}|numeric|min:0",
            'billing_cycle' => 'nullable|string|in:Daily,Weekly,Monthly,Quarterly,Six Months,Semi-Annually,semi-annually,monthly,quarterly,annually,Yearly,Annually,Custom',
            'billing_interval_count' => 'nullable|integer|min:1|max:120',
            'setup_fee' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'tax_treatment' => 'nullable|string|max:100',
            'trial_available' => 'nullable|boolean',
            'trial_duration_days' => 'nullable|integer|min:0|max:730',
            'trial_amount' => 'nullable|numeric|min:0',
            'introductory_price' => 'nullable|numeric|min:0',
            'introductory_duration_days' => 'nullable|integer|min:0|max:730',
            'annual_discount' => 'nullable|numeric|min:0',
            'members_limit' => 'nullable|integer|min:0',
            'tier' => 'nullable|string|max:100',
            'features' => 'nullable|array',
            'features.*' => 'nullable|string|max:255',
            'benefit_config' => 'nullable|array',
            'upgrade_rules' => 'nullable|array',
            'downgrade_rules' => 'nullable|array',
            'cancellation_rules' => 'nullable|array',
            'failed_payment_settings' => 'nullable|array',
            'availability_rules' => 'nullable|array',
            'terms' => 'nullable|string',
            'renewal_disclosure' => 'nullable|string',
            'refund_policy' => 'nullable|string',
            'cancellation_policy' => 'nullable|string',
            'privacy_notice' => 'nullable|string',
            'requires_agreement' => 'nullable|boolean',
            'badge' => 'nullable|string|max:255',
            'image_url' => 'nullable|string|max:2048',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:Active,Draft,Featured,Inactive,Scheduled,Archived,Retired',
            'effective_date' => 'nullable|date',
            'retirement_date' => 'nullable|date|after_or_equal:effective_date',
        ]);

        $this->assertBillingSettings($validated);

        foreach (['features', 'covered_sites'] as $arrayField) {
            if (array_key_exists($arrayField, $validated)) {
                $validated[$arrayField] = collect($validated[$arrayField] ?? [])
                    ->filter(fn ($value) => filled($value))
                    ->values()
                    ->all();
            }
        }

        // Keep the image_url column in step with the banner picked in the plan editor.
        $bannerInput = $request->input('banner_image', data_get($validated, 'benefit_config.banner_image'));
        if (is_string($bannerInput) && ! array_key_exists('image_url', $validated)
            && ($request->has('banner_image') || $request->has('benefit_config.banner_image'))) {
            $bannerInput = trim($bannerInput);
            $validated['image_url'] = ($bannerInput !== '' && ! str_starts_with($bannerInput, 'data:') && strlen($bannerInput) <= 2048)
                ? $bannerInput
                : null;
        }

        $defaults = [
            'account_type' => 'personal',
            'coverage_type' => 'individual_site',
            'include_future_sites' => false,
            'billing_cycle' => 'Monthly',
            'billing_interval_count' => 1,
            'setup_fee' => 0,
            'currency' => 'USD',
            'trial_available' => false,
            'trial_duration_days' => 0,
            'trial_amount' => 0,
            'introductory_duration_days' => 0,
            'annual_discount' => 0,
            'members_limit' => 0,
            'sort_order' => 0,
            'requires_agreement' => true,
            'status' => 'Active',
        ];

        return $partial ? $validated : array_merge($defaults, $validated);
    }

    /** Reject Pricing & Billing combinations that cannot work. */
    private function assertBillingSettings(array $v): void
    {
        $cfg = $v['benefit_config'] ?? null;
        if (! is_array($cfg)) {
            return;
        }
        $errors = [];

        if (array_key_exists('payment_methods', $cfg) && empty(array_filter((array) $cfg['payment_methods'], fn ($m) => MembershipPlanPolicy::paymentMethod((string) $m)))) {
            $errors['benefit_config.payment_methods'] = ['Select at least one accepted payment method.'];
        }
        if (($cfg['first_payment_timing'] ?? '') === 'on_fixed_date') {
            $date = MembershipProrationService::parseDate($cfg['fixed_first_payment_date'] ?? null);
            if (! $date || $date->year < 2000) {
                $errors['benefit_config.fixed_first_payment_date'] = ['Choose the fixed first payment date.'];
            }
        }
        if (($cfg['first_payment_timing'] ?? '') === 'after_trial' && ! ($v['trial_available'] ?? false)) {
            $errors['benefit_config.first_payment_timing'] = ['"After Trial" needs the trial period to be enabled.'];
        }
        if (isset($cfg['custom_billing_day']) && $cfg['custom_billing_day'] !== ''
            && ((int) $cfg['custom_billing_day'] < 1 || (int) $cfg['custom_billing_day'] > 28)) {
            $errors['benefit_config.custom_billing_day'] = ['Custom billing day must be between 1 and 28.'];
        }
        if (isset($cfg['team_seats']) && (! is_numeric($cfg['team_seats']) || (int) $cfg['team_seats'] < 1 || (int) $cfg['team_seats'] > 500)) {
            $errors['benefit_config.team_seats'] = ['Team seats must be between 1 and 500.'];
        }
        if (str_contains(strtolower((string) ($cfg['renewal_price_behavior'] ?? '')), 'custom')
            && (! isset($cfg['custom_renewal_price']) || ! is_numeric($cfg['custom_renewal_price']) || (float) $cfg['custom_renewal_price'] < 0)) {
            $errors['benefit_config.custom_renewal_price'] = ['Enter the custom renewal price.'];
        }
        if (($v['trial_available'] ?? false) && (int) ($v['trial_duration_days'] ?? 0) < 1) {
            $errors['trial_duration_days'] = ['Trial duration must be at least 1 day when the trial is enabled.'];
        }
        $grace = data_get($v, 'failed_payment_settings.grace_period_days');
        if ($grace !== null && ((int) $grace < 0 || (int) $grace > 90)) {
            $errors['failed_payment_settings.grace_period_days'] = ['Grace period must be between 0 and 90 days.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
