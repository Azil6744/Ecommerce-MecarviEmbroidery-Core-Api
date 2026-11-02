<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceSubscriptionPlan;
use Illuminate\Http\Request;

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
        })
        ->where(function ($q) {
            $q->whereNull('effective_date')->orWhere('effective_date', '<=', now());
        })
        ->where(function ($q) {
            $q->whereNull('retirement_date')->orWhere('retirement_date', '>', now());
        });

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

        $formatted = $plans->map(function ($plan) {
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

            return $planData;
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

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan updated successfully.',
            'data' => $plan
        ]);
    }

    /**
     * Remove the specified subscription plan from storage.
     */
    public function destroy(string $id)
    {
        $plan = EcommerceSubscriptionPlan::findOrFail($id);
        if ($plan->members_limit > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Plans with subscriber history should be retired or archived instead of deleted.',
            ], 409);
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subscription plan deleted successfully.'
        ]);
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

        foreach (['features', 'covered_sites'] as $arrayField) {
            if (array_key_exists($arrayField, $validated)) {
                $validated[$arrayField] = collect($validated[$arrayField] ?? [])
                    ->filter(fn ($value) => filled($value))
                    ->values()
                    ->all();
            }
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
}
