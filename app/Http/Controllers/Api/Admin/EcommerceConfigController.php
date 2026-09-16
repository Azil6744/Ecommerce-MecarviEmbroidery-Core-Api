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
    public function getLoyalty()
    {
        try {
            $settings = SiteSetting::firstOrCreate([]);
            $loyalty = $settings->loyalty_settings ? json_decode($settings->loyalty_settings, true) : null;

            // Default values if empty
            if (!$loyalty) {
                $loyalty = [
                    'enabled' => false,
                    'points_per_dollar' => '1',
                    'points_to_dollar_ratio' => '0.01',
                    'minimum_redeem_points' => '100',
                    'max_redeem_percent' => '20',
                    'expiry_days' => '365',
                    'earn_points_on_gift_cards' => false
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $loyalty
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
            
            $validated = $request->validate([
                'enabled' => 'required|boolean',
                'points_per_dollar' => 'required|string',
                'points_to_dollar_ratio' => 'required|string',
                'minimum_redeem_points' => 'required|string',
                'max_redeem_percent' => 'required|string',
                'expiry_days' => 'required|string',
                // New optional configurations
                'program_name' => 'nullable|string',
                'program_description' => 'nullable|string',
                'calculation_method' => 'nullable|string',
                'eligible_items' => 'nullable|array',
                'excluded_categories' => 'nullable|array',
                'signup_bonus' => 'nullable|string',
                'first_order_bonus' => 'nullable|string',
                'review_bonus' => 'nullable|string',
                'referral_bonus' => 'nullable|string',
                'birthday_bonus' => 'nullable|string',
                'membership_bonus' => 'nullable|string',
                'min_order_amount' => 'nullable|string',
                'allow_partial_redemption' => 'nullable|boolean',
                'allow_with_coupons' => 'nullable|boolean',
                'allow_with_gift_cards' => 'nullable|boolean',
                'earn_points_on_gift_cards' => 'nullable|boolean',
                'enable_expiration' => 'nullable|boolean',
                'expiration_method' => 'nullable|string',
                'expiration_reminder_days' => 'nullable|string',
                'availability_rule' => 'nullable|string',
                'remove_on_cancelled' => 'nullable|boolean',
                'reverse_on_refunded' => 'nullable|boolean',
                'auto_recalculate_partial' => 'nullable|boolean',
                'max_earn_per_month' => 'nullable|string',
                'max_redeem_per_month' => 'nullable|string',
                'fraud_protection' => 'nullable|boolean',
            ]);

            // Sync legacy columns in site_settings for checkout code compatibility
            $settings->loyalty_points_earned_per_unit_price = 1.0;
            $settings->loyalty_points_earned_points = (int)($validated['points_per_dollar'] ?: 2);

            $settings->loyalty_settings = json_encode($validated);
            $settings->save();

            return response()->json([
                'success' => true,
                'data' => $validated,
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

            $user = \App\Models\User::findOrFail($validated['user_id']);
            $pointsChange = (int) $validated['points'];
            if (in_array(strtolower($validated['transaction_type']), ['manual_removed', 'subtract', 'deduct', 'redeemed', 'expired', 'reversed'])) {
                $pointsChange = -abs($pointsChange);
            } else {
                $pointsChange = abs($pointsChange);
            }

            // Central Auth update
            $centralUrl = rtrim(config('services.central_auth.url'), '/');
            $secret = (string) config('services.internal_notifications.secret');
            $totalPoints = null;
            $txData = null;

            try {
                $response = \Illuminate\Support\Facades\Http::acceptJson()
                    ->withHeaders(['X-Internal-Notification-Secret' => $secret])
                    ->timeout(5)
                    ->post($centralUrl . '/v1/internal/admin/loyalty/adjust', [
                        'email' => $user->email,
                        'points' => $pointsChange,
                        'transaction_type' => $validated['transaction_type'],
                        'reason' => $validated['reason'],
                        'reference_type' => $validated['reference_type'] ?? 'Manual Adjustment',
                        'reference_id' => $validated['reference_id'] ?? null,
                    ]);

                if ($response->successful()) {
                    $totalPoints = $response->json('total_points');
                    $txData = $response->json('data');
                }
            } catch (\Throwable $centralEx) {
                \Illuminate\Support\Facades\Log::warning('Central loyalty adjust notice: ' . $centralEx->getMessage());
            }

            // Update local user points balance
            $currentLocal = (int) ($user->loyalty_points ?? 0);
            $newBalance = $totalPoints !== null ? (int)$totalPoints : max(0, $currentLocal + $pointsChange);
            $user->loyalty_points = $newBalance;
            $user->save();

            // Store local transaction record if table exists
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('ecommerce_loyalty_transactions')) {
                    $authUser = $request->user();
                    \App\Models\EcommerceLoyaltyTransaction::create([
                        'user_id' => $user->id,
                        'transaction_type' => $pointsChange >= 0 ? 'manual_added' : 'manual_removed',
                        'points' => $pointsChange,
                        'dollar_value' => number_format(abs($pointsChange) * 0.01, 2, '.', ''),
                        'status' => 'completed',
                        'reason' => $validated['reason'],
                        'reason_details' => $validated['reason_details'] ?? null,
                        'notes' => $validated['notes'] ?? null,
                        'reference_type' => $validated['reference_type'] ?? 'Manual',
                        'reference_id' => $validated['reference_id'] ?? null,
                        'admin_id' => $authUser?->id,
                    ]);
                }
            } catch (\Throwable $localTxEx) {
                \Illuminate\Support\Facades\Log::warning('Local loyalty txn record notice: ' . $localTxEx->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer points adjusted successfully!',
                'data' => [
                    'loyalty_points' => $newBalance,
                    'transaction' => $txData
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust points',
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
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to save packaging config', 'error' => $e->getMessage()], 500);
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
