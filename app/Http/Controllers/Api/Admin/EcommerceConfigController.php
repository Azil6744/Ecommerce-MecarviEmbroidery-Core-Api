<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;

class EcommerceConfigController extends Controller
{
    /**
     * Get Loyalty settings.
     */
    /**
     * Get Loyalty settings.
     */
    public function getLoyalty()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $loyalty = $settings->loyalty_settings ? json_decode($settings->loyalty_settings, true) : [];

            $defaultTiers = [
                [
                    'id' => 'bronze',
                    'name' => 'Bronze',
                    'min_points' => 0,
                    'max_points' => 999,
                    'bonus_percent' => 5,
                    'perk_badge' => '',
                    'color' => 'orange',
                    'icon' => 'star',
                ],
                [
                    'id' => 'silver',
                    'name' => 'Silver',
                    'min_points' => 1000,
                    'max_points' => 4999,
                    'bonus_percent' => 10,
                    'perk_badge' => '',
                    'color' => 'slate',
                    'icon' => 'star',
                ],
                [
                    'id' => 'gold',
                    'name' => 'Gold',
                    'min_points' => 5000,
                    'max_points' => 9999,
                    'bonus_percent' => 15,
                    'perk_badge' => 'Priority Support',
                    'color' => 'amber',
                    'icon' => 'star',
                ],
                [
                    'id' => 'platinum',
                    'name' => 'Platinum',
                    'min_points' => 10000,
                    'max_points' => null,
                    'bonus_percent' => 20,
                    'perk_badge' => 'Exclusive Offers',
                    'color' => 'purple',
                    'icon' => 'star',
                ],
            ];

            // Complete defaults
            $defaults = [
                'enabled' => true,
                'points_per_dollar' => '1',
                'points_to_dollar_ratio' => '0.01',
                'minimum_redeem_points' => '100',
                'max_redeem_percent' => '100.00',
                'expiry_days' => '365',
                'min_order_amount' => '1.00',
                'max_earn_per_month' => '10,000',
                'birthday_bonus' => '300',
                'review_bonus' => '120',
                'first_order_bonus' => '50',
                'membership_bonus' => '1,000',
                'referral_bonus' => '500',
                'other_bonus' => '',
                'allow_partial_redemption' => true,
                'allow_redemption_on_shipping' => true,
                'allow_redemption_on_taxes' => false,
                'allow_redemption_on_discounts' => false,
                'enable_expiration' => true,
                'expiration_method' => 'from_earning_date',
                'expiration_reminder_days' => '30_days_before',
                'show_balance_on_store' => true,
                'show_earning_on_product' => true,
                'allow_points_transfer' => true,
                'include_tax_in_calculation' => true,
                'include_shipping_in_calculation' => true,
                'notify_members_on_earn' => true,
                'earn_points_on_gift_cards' => false,
                'terms_and_conditions' => 'By participating in the loyalty program, members agree to earn and redeem points based on the rules and policies set by the store. Points have no cash value and are non-transferable.',
                'tiers' => $defaultTiers,
            ];

            $merged = array_merge($defaults, is_array($loyalty) ? $loyalty : []);

            if (empty($merged['tiers']) || !is_array($merged['tiers'])) {
                $merged['tiers'] = $defaultTiers;
            }

            return response()->json([
                'success' => true,
                'data' => $merged
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loyalty config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Loyalty settings.
     */
    public function saveLoyalty(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            
            $payload = $request->all();

            $validated = $request->validate([
                'enabled' => 'required|boolean',
                'points_per_dollar' => 'nullable',
                'points_to_dollar_ratio' => 'nullable',
                'minimum_redeem_points' => 'nullable',
                'max_redeem_percent' => 'nullable',
                'expiry_days' => 'nullable',
                'min_order_amount' => 'nullable',
                'max_earn_per_month' => 'nullable',
                'birthday_bonus' => 'nullable',
                'review_bonus' => 'nullable',
                'first_order_bonus' => 'nullable',
                'membership_bonus' => 'nullable',
                'referral_bonus' => 'nullable',
                'other_bonus' => 'nullable',
                'allow_partial_redemption' => 'nullable|boolean',
                'allow_redemption_on_shipping' => 'nullable|boolean',
                'allow_redemption_on_taxes' => 'nullable|boolean',
                'allow_redemption_on_discounts' => 'nullable|boolean',
                'enable_expiration' => 'nullable|boolean',
                'expiration_method' => 'nullable|string',
                'expiration_reminder_days' => 'nullable|string',
                'show_balance_on_store' => 'nullable|boolean',
                'show_earning_on_product' => 'nullable|boolean',
                'allow_points_transfer' => 'nullable|boolean',
                'include_tax_in_calculation' => 'nullable|boolean',
                'include_shipping_in_calculation' => 'nullable|boolean',
                'notify_members_on_earn' => 'nullable|boolean',
                'earn_points_on_gift_cards' => 'nullable|boolean',
                'terms_and_conditions' => 'nullable|string',
                'tiers' => 'nullable|array',
            ]);

            // Merge full payload to prevent any dropped custom attributes
            $finalConfig = array_merge($payload, $validated);

            // Sync legacy columns in site_settings for checkout code compatibility
            $ptsRate = (float) ($finalConfig['points_per_dollar'] ?? 1);
            if ($ptsRate <= 0) {
                $ptsRate = 1.0;
            }
            $settings->loyalty_points_earned_per_unit_price = 1.0;
            $settings->loyalty_points_earned_points = $ptsRate >= 1 ? (int) round($ptsRate) : 1;

            $settings->loyalty_settings = json_encode($finalConfig);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $finalConfig,
                'message' => 'Loyalty configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save loyalty config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform manual customer points adjustment.
     */
    public function adjustPoints(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'points' => 'required|integer',
                'transaction_type' => 'required|string',
                'reason' => 'required|string|max:1000',
                'reason_details' => 'nullable|string|max:1000',
                'notes' => 'nullable|string|max:1000',
                'reference_type' => 'nullable|string|max:100',
                'reference_id' => 'nullable|string|max:100',
                'reference_date' => 'nullable|string|max:100',
                'expiration_date' => 'nullable|string|max:100',
            ]);

            $user = \App\Models\User::find($validated['user_id']);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer user not found.'
                ], 404);
            }

            $admin = $request->user();
            $success = \App\Services\LoyaltyService::adjustPoints(
                (int)$user->id,
                (int)$validated['points'],
                (string)$validated['transaction_type'],
                (string)$validated['reason'],
                null,
                'completed',
                $validated['reason_details'] ?? null,
                $validated['notes'] ?? null,
                $admin?->id
            );

            // Fresh user state
            $user->refresh();

            // Record audit log
            try {
                \App\Models\UserAdminChange::create([
                    'user_id' => $user->id,
                    'admin_id' => $admin?->id,
                    'actor_name' => $admin ? $admin->name : 'Administrator',
                    'actor_role' => $admin && $admin->role ? ucfirst($admin->role) : 'Administrator',
                    'title' => 'Loyalty Points Adjusted',
                    'description' => "Admin adjusted points: {$validated['points']} pts ({$validated['transaction_type']}). Reason: {$validated['reason']}",
                    'changed_fields' => 'Loyalty Points',
                    'before_value' => (string) ($user->loyalty_points - (in_array(strtolower($validated['transaction_type']), ['manual_removed', 'subtract', 'deduct', 'redeemed', 'expired', 'reversed']) ? -abs($validated['points']) : abs($validated['points']))),
                    'after_value' => (string) $user->loyalty_points,
                ]);
            } catch (\Throwable $auditEx) {
                \Illuminate\Support\Facades\Log::warning('Audit change log notice: ' . $auditEx->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer loyalty points adjusted successfully!',
                'data' => [
                    'loyalty_points' => (int) $user->loyalty_points,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust points: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get loyalty point transactions history.
     */
    public function getTransactions(Request $request)
    {
        try {
            $centralUrl = rtrim(config('services.central_auth.url'), '/');
            $secret = (string) config('services.internal_notifications.secret');
            $transactions = collect();

            try {
                $response = \Illuminate\Support\Facades\Http::acceptJson()
                    ->withHeaders(['X-Internal-Notification-Secret' => $secret])
                    ->timeout(5)
                    ->get($centralUrl . '/v1/internal/admin/loyalty/transactions');

                if ($response->successful() && is_array($response->json('data'))) {
                    $transactions = collect($response->json('data'));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Central loyalty transactions notice: ' . $e->getMessage());
            }

            // If empty, also pull from local table if exists
            if ($transactions->isEmpty() && \Illuminate\Support\Facades\Schema::hasTable('ecommerce_loyalty_transactions')) {
                $localTx = \App\Models\EcommerceLoyaltyTransaction::with(['user', 'order', 'admin'])->latest()->get();
                $transactions = $localTx->map(function ($t) {
                    return [
                        'id' => $t->id,
                        'user_id' => $t->user_id,
                        'order_id' => $t->order_id,
                        'transaction_type' => $t->transaction_type,
                        'points' => (int) $t->points,
                        'dollar_value' => (string) $t->dollar_value,
                        'status' => $t->status,
                        'reason' => $t->reason,
                        'reason_details' => $t->reason_details,
                        'notes' => $t->notes,
                        'reference_type' => $t->reference_type,
                        'reference_id' => $t->reference_id,
                        'created_at' => optional($t->created_at)->toIso8601String() ?? now()->toIso8601String(),
                        'user' => $t->user ? [
                            'id' => $t->user->id,
                            'name' => $t->user->name,
                            'email' => $t->user->email,
                            'loyalty_points' => (int) $t->user->loyalty_points,
                        ] : null,
                        'order' => $t->order ? [
                            'id' => $t->order->id,
                            'order_number' => $t->order->order_number,
                            'total_amount' => (string) $t->order->total_amount,
                        ] : null,
                    ];
                });
            }

            // Filter by user_id if provided
            if ($request->has('user_id') && $request->user_id) {
                $user = \App\Models\User::find($request->query('user_id'));
                if ($user) {
                    $transactions = $transactions->filter(fn($t) => (isset($t['user_id']) && (int)$t['user_id'] === (int)$user->id) || (isset($t['user']['email']) && strtolower($t['user']['email']) === strtolower($user->email)))->values();
                }
            }

            return response()->json([
                'success' => true,
                'data' => $transactions->values()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transactions list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Charity settings.
     */
    public function getCharity()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $charity = $settings->charity_settings ? json_decode($settings->charity_settings, true) : null;

            $defaultCategories = ['Children', 'Education', 'Disaster Relief', 'Environment', 'Health', 'Animals'];
            $defaultAssistanceOptions = [
                'Rental Assistance',
                'Shelter / Housing',
                'Utility Assistance',
                'Clothing Assistance',
                'Food Support',
                'Job Training',
                'Transportation',
                'Mental Health Support',
                'Healthcare Support',
                'Elderly Care',
                'Education Support',
                'Childcare Support',
                'Disaster Relief'
            ];

            if (!$charity) {
                $charity = [
                    'enabled' => true,
                    'charity_name' => 'Mecarvi Foundation',
                    'charity_description' => 'Mecarvi Foundation provides direct financial assistance to individuals and communities through education, health support and community development initiatives.',
                    'suggested_amounts' => '1,5,10,25,50',
                    'allow_custom_amount' => true,
                    'categories' => $defaultCategories,
                    'assistance_options' => $defaultAssistanceOptions
                ];
            } else {
                if (!isset($charity['categories'])) {
                    $charity['categories'] = $defaultCategories;
                }
                if (!isset($charity['assistance_options'])) {
                    $charity['assistance_options'] = $defaultAssistanceOptions;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $charity
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch charity config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Charity settings.
     */
    public function saveCharity(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            
            $validated = $request->validate([
                'enabled' => 'required|boolean',
                'charity_name' => 'required|string|max:255',
                'charity_description' => 'required|string',
                'suggested_amounts' => 'required|string',
                'allow_custom_amount' => 'required|boolean',
                'categories' => 'nullable|array',
                'categories.*' => 'string',
                'assistance_options' => 'nullable|array',
                'assistance_options.*' => 'string'
            ]);

            // Sync legacy columns in site_settings for checkout code compatibility
            $settings->charity_name = $validated['charity_name'];
            $settings->charity_donation_enabled = $validated['enabled'];
            $suggested = explode(',', $validated['suggested_amounts']);
            $settings->charity_default_amount = (float)($suggested[0] ?? 1.00);

            $settings->charity_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Charity configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save charity config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Tips settings.
     */
    public function getTips()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $tips = $settings->tips_settings ? json_decode($settings->tips_settings, true) : null;

            if (!$tips) {
                $tips = [
                    'enabled' => false,
                    'suggested_percentages' => '10,15,20',
                    'allow_custom' => true,
                    'max_custom_percent' => '30'
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $tips
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tips config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Tips settings.
     */
    public function saveTips(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            
            $validated = $request->validate([
                'enabled' => 'required|boolean',
                'suggested_percentages' => 'required|string',
                'allow_custom' => 'required|boolean',
                'max_custom_percent' => 'required|string'
            ]);

            $settings->tips_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Tips configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save tips config',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Tax Configuration Settings.
     */
    public function getTaxSettings()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $taxSettings = $settings->tax_settings ? json_decode($settings->tax_settings, true) : null;

            if (!$taxSettings) {
                $taxSettings = [
                    'tax_calculation_source' => 'manual',
                    'enable_taxes' => (bool)($settings->tax_enabled ?? true),
                    'tax_calculation_based_on' => 'shipping_address',
                    'display_prices' => 'excluding_tax',
                    'calculate_tax_after_discounts' => true,
                    'tax_label_at_checkout' => 'Sales Tax',
                    'allow_tax_exemption' => true,
                    'auto_apply_non_tax_exempt' => true,
                    'tax_on_shipping' => true,
                    'apply_tax_to_gift_cards' => false,
                    'opensalestax_connected' => false,
                    'opensalestax_url' => '',
                    'opensalestax_api_key' => '',
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $taxSettings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tax settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Public Tax Configuration and Active Rates for Checkout.
     */
    public function getPublicTaxConfig()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $taxSettings = $settings->tax_settings ? json_decode($settings->tax_settings, true) : null;

            if (!$taxSettings) {
                $taxSettings = [
                    'tax_calculation_source' => 'manual',
                    'enable_taxes' => (bool)($settings->tax_enabled ?? true),
                    'tax_calculation_based_on' => 'shipping_address',
                    'display_prices' => 'excluding_tax',
                    'calculate_tax_after_discounts' => true,
                    'tax_label_at_checkout' => 'Sales Tax',
                    'allow_tax_exemption' => true,
                    'auto_apply_non_tax_exempt' => true,
                    'tax_on_shipping' => true,
                    'apply_tax_to_gift_cards' => false,
                    'opensalestax_connected' => false,
                ];
            }

            $rates = \App\Models\TaxRate::where('is_active', true)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'settings' => $taxSettings,
                    'default_tax_rate' => (float)($settings->tax_rate ?? 0),
                    'rates' => $rates,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch public tax configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Tax Configuration Settings.
     */
    public function saveTaxSettings(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);

            $validated = $request->validate([
                'tax_calculation_source' => 'nullable|string',
                'enable_taxes' => 'required|boolean',
                'tax_calculation_based_on' => 'nullable|string',
                'display_prices' => 'nullable|string',
                'calculate_tax_after_discounts' => 'nullable|boolean',
                'tax_label_at_checkout' => 'nullable|string|max:255',
                'allow_tax_exemption' => 'nullable|boolean',
                'auto_apply_non_tax_exempt' => 'nullable|boolean',
                'tax_on_shipping' => 'nullable|boolean',
                'apply_tax_to_gift_cards' => 'nullable|boolean',
                'opensalestax_connected' => 'nullable|boolean',
                'opensalestax_url' => 'nullable|string',
                'opensalestax_api_key' => 'nullable|string',
            ]);

            // Sync legacy boolean tax_enabled on site_settings
            $settings->tax_enabled = $validated['enable_taxes'];
            $settings->tax_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Tax configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save tax settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPackaging()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $packaging = $settings->packaging_settings ? json_decode($settings->packaging_settings, true) : null;

            // Handle old format or empty value
            if (!$packaging || !isset($packaging['styles']) || empty($packaging['styles'])) {
                $packaging = [
                    'styles' => [
                        [
                            'id' => 1,
                            'name' => 'Standard Packaging',
                            'description' => 'Your order is carefully packed in our standard packaging to keep items secure and protected during transit.',
                            'price' => '0.00',
                            'includedInPrice' => true,
                            'displayOrder' => '1',
                            'status' => true,
                            'isRecommended' => false,
                            'image' => '/images/packaging/standard.png',
                        ],
                        [
                            'id' => 2,
                            'name' => 'Premium Packaging',
                            'description' => 'Enhance your order with a polished finishing touch. Includes coordinating tissue paper, decorative ribbon, and our upgraded-style box.',
                            'price' => '6.99',
                            'includedInPrice' => false,
                            'displayOrder' => '2',
                            'status' => true,
                            'isPopular' => true,
                            'isRecommended' => true,
                            'image' => '/images/packaging/premium.png',
                        ],
                        [
                            'id' => 3,
                            'name' => 'Luxury Packaging',
                            'description' => 'An elegant presentation designed to make every unboxing feel memorable. Includes premium gift box, tissue paper, decorative ribbon and bow.',
                            'price' => '12.99',
                            'includedInPrice' => false,
                            'displayOrder' => '3',
                            'status' => true,
                            'image' => '/images/packaging/luxury.png',
                        ],
                    ],
                    'additional_options' => [
                        [
                            'id' => 1,
                            'name' => 'Custom Greetings Card',
                            'description' => 'Include a personalized greeting card with your order.',
                            'price' => '$0.99',
                            'displayOrder' => '1',
                            'status' => true,
                            'image' => '/images/packaging/greeting_card.png',
                        ],
                        [
                            'id' => 2,
                            'name' => 'Extra Protection',
                            'description' => 'Add an extra layer of protective packaging to help keep your items secure and protected during transit.',
                            'price' => '$1.49',
                            'displayOrder' => '2',
                            'status' => true,
                            'image' => '/images/packaging/bubble_wrap.png',
                        ],
                        [
                            'id' => 3,
                            'name' => 'Package Insurance',
                            'description' => 'Add coverage to help protect your order against loss, theft or damage while in transit.',
                            'price' => '$5.99',
                            'displayOrder' => '3',
                            'status' => true,
                            'image' => '/images/packaging/insurance.png',
                        ],
                    ],
                ];
            }

            return response()->json(['success' => true, 'data' => $packaging]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch packaging config', 'error' => $e->getMessage()], 500);
        }
    }

    public function savePackaging(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            
            $input = $request->all();
            if (isset($input['styles']) && is_array($input['styles'])) {
                foreach ($input['styles'] as $k => $s) {
                    if (isset($s['price'])) {
                        $cleanPrice = preg_replace('/[^0-9.]/', '', (string) $s['price']);
                        $input['styles'][$k]['price'] = $cleanPrice !== '' ? (float) $cleanPrice : 0.00;
                    }
                    if (isset($s['includedInPrice'])) {
                        $input['styles'][$k]['includedInPrice'] = (bool) $s['includedInPrice'];
                    }
                    if (isset($s['status'])) {
                        $input['styles'][$k]['status'] = (bool) $s['status'];
                    }
                }
            }
            if (isset($input['additional_options']) && is_array($input['additional_options'])) {
                foreach ($input['additional_options'] as $k => $opt) {
                    if (isset($opt['price'])) {
                        $cleanPrice = preg_replace('/[^0-9.]/', '', (string) $opt['price']);
                        $input['additional_options'][$k]['price'] = '$' . number_format((float) ($cleanPrice !== '' ? $cleanPrice : 0), 2, '.', '');
                    }
                    if (isset($opt['status'])) {
                        $input['additional_options'][$k]['status'] = (bool) $opt['status'];
                    }
                }
            }
            $request->merge($input);

            $validated = $request->validate([
                'styles' => 'required|array',
                'styles.*.id' => 'required',
                'styles.*.name' => 'required|string|max:255',
                'styles.*.description' => 'required|string|max:500',
                'styles.*.price' => 'required|numeric|min:0',
                'styles.*.includedInPrice' => 'required|boolean',
                'styles.*.displayOrder' => 'required|string|max:50',
                'styles.*.status' => 'required|boolean',
                'styles.*.isRecommended' => 'nullable|boolean',
                'styles.*.image' => 'nullable|string',

                'additional_options' => 'required|array',
                'additional_options.*.id' => 'required',
                'additional_options.*.name' => 'required|string|max:255',
                'additional_options.*.description' => 'required|string|max:500',
                'additional_options.*.price' => 'required|string|max:50',
                'additional_options.*.displayOrder' => 'required|string|max:50',
                'additional_options.*.status' => 'required|boolean',
                'additional_options.*.image' => 'nullable|string',
            ]);

            // Save Base64 Images as files for styles
            foreach ($validated['styles'] as $key => $style) {
                if (isset($style['image']) && str_starts_with($style['image'], 'data:image/')) {
                    $validated['styles'][$key]['image'] = $this->saveBase64Image($style['image'], $style['name']);
                }
            }

            // Save Base64 Images as files for additional options
            foreach ($validated['additional_options'] as $key => $opt) {
                if (isset($opt['image']) && str_starts_with($opt['image'], 'data:image/')) {
                    $validated['additional_options'][$key]['image'] = $this->saveBase64Image($opt['image'], $opt['name']);
                }
            }

            $settings->packaging_settings = json_encode($validated);
            $settings->save();

            return response()->json(['success' => true, 'data' => $validated, 'message' => 'Packaging configuration saved successfully']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to save packaging config', 'error' => $e->getMessage()], 500);
        }
    }

    public function getTurnaround()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $turnaround = $settings->turnaround_settings ? json_decode($settings->turnaround_settings, true) : null;

            if (!$turnaround || !is_array($turnaround) || empty($turnaround)) {
                $turnaround = [
                    [
                        'id' => 1,
                        'name' => 'Rush Production',
                        'description' => 'Order ready within 3 hours for store pickup or delivery.',
                        'production_time_value' => '3',
                        'production_time_unit' => 'Hours',
                        'estimated_days' => '3 hours',
                        'additional_fee' => 20.00,
                        'availability' => 'All Days',
                        'cutoff_time' => '6:00 PM',
                        'status' => true,
                        'is_default' => true,
                        'color_code' => '#ec4899',
                        'icon_type' => 'zap',
                        'priority' => 1,
                    ],
                    [
                        'id' => 2,
                        'name' => 'Express Production',
                        'description' => 'Order ready within 6 hours for store pickup or delivery.',
                        'production_time_value' => '6',
                        'production_time_unit' => 'Hours',
                        'estimated_days' => '6 hours',
                        'additional_fee' => 15.00,
                        'availability' => 'All Days',
                        'cutoff_time' => '6:00 PM',
                        'status' => true,
                        'is_default' => false,
                        'color_code' => '#f97316',
                        'icon_type' => 'clock',
                        'priority' => 2,
                    ],
                    [
                        'id' => 3,
                        'name' => 'Standard Production',
                        'description' => 'Order ready within 2 – 3 business days for store pickup or delivery.',
                        'production_time_value' => '2 – 3',
                        'production_time_unit' => 'Business Days',
                        'estimated_days' => '2 – 3 business days',
                        'additional_fee' => 0.00,
                        'availability' => 'All Days',
                        'cutoff_time' => '6:00 PM',
                        'status' => true,
                        'is_default' => false,
                        'color_code' => '#2563eb',
                        'icon_type' => 'calendar',
                        'priority' => 3,
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $turnaround
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch turnaround configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function saveTurnaround(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);

            $items = $request->input('turnaround_times', $request->all());
            if (isset($items['turnaround_times'])) {
                $items = $items['turnaround_times'];
            }

            if (!is_array($items)) {
                $items = [];
            }

            $settings->turnaround_settings = json_encode($items);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $items,
                'message' => 'Turnaround times configuration saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save turnaround configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDeliverySettings()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $delivery = $settings->delivery_settings ? json_decode($settings->delivery_settings, true) : null;

            if (!$delivery || !is_array($delivery)) {
                $delivery = [
                    'max_delivery_radius' => 25,
                    'radius_unit' => 'miles',
                    'allow_custom_upcharges' => true,
                    'mileage_tiers' => [
                        ['id' => 'tier_1', 'min_miles' => 0, 'max_miles' => 5, 'price' => 15.00, 'label' => '0 to 5 miles'],
                        ['id' => 'tier_2', 'min_miles' => 6, 'max_miles' => 10, 'price' => 20.00, 'label' => '6 to 10 miles'],
                        ['id' => 'tier_3', 'min_miles' => 11, 'max_miles' => 15, 'price' => 25.00, 'label' => '11 to 15 miles'],
                        ['id' => 'tier_4', 'min_miles' => 16, 'max_miles' => 20, 'price' => 30.00, 'label' => '16 to 20 miles'],
                        ['id' => 'tier_5', 'min_miles' => 21, 'max_miles' => 25, 'price' => 35.00, 'label' => '21 to 25 miles'],
                    ]
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $delivery
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivery settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function saveDeliverySettings(Request $request)
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $validated = $request->validate([
                'max_delivery_radius' => 'required|numeric|min:1',
                'radius_unit' => 'nullable|string',
                'mileage_tiers' => 'required|array|min:1',
                'mileage_tiers.*.min_miles' => 'required|numeric|min:0',
                'mileage_tiers.*.max_miles' => 'required|numeric|min:0',
                'mileage_tiers.*.price' => 'required|numeric|min:0',
                'mileage_tiers.*.label' => 'nullable|string',
                'allow_custom_upcharges' => 'nullable|boolean',
            ]);

            $settings->delivery_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Delivery settings saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save delivery settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function saveBase64Image($base64Data, $name)
    {
        if (empty($base64Data)) {
            return '';
        }

        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $data = substr($base64Data, strpos($base64Data, ',') + 1);
            $type = strtolower($type[1]); // png, jpg, jpeg, gif

            if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) {
                return '';
            }

            $data = base64_decode($data);

            if ($data === false) {
                return '';
            }

            $fileName = uniqid() . '_' . \Illuminate\Support\Str::slug($name) . '.' . $type;
            $dirPath = storage_path('app/public/packaging');

            if (!\Illuminate\Support\Facades\File::exists($dirPath)) {
                \Illuminate\Support\Facades\File::makeDirectory($dirPath, 0755, true);
            }

            \Illuminate\Support\Facades\File::put($dirPath . '/' . $fileName, $data);

            return '/storage/packaging/' . $fileName;
        }

        return $base64Data; // Return as-is if it's already a URL/path
    }
}

