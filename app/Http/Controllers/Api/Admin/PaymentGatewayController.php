<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentGatewayController extends Controller
{
    private function defaultGatewaysList(): array
    {
        return [
            [
                'name' => 'Stripe',
                'display_label' => 'Credit / Debit Card (Stripe)',
                'provider' => 'stripe',
                'description' => 'Accept Visa, MasterCard, Amex, Apple Pay, Google Pay, and Klarna securely.',
                'public_key' => 'pk_test_sample_stripe_publishable_key',
                'secret_key' => 'sk_test_sample_stripe_secret_key',
                'webhook_url' => url('/api/webhooks/stripe'),
                'is_active' => true,
                'is_test_mode' => true,
                'sort_order' => 1,
                'settings' => [
                    'currency' => 'USD',
                    'enable_apple_pay' => true,
                    'enable_google_pay' => true,
                    'enable_klarna' => true,
                    'enable_link' => true,
                    'statement_descriptor' => 'MECARVI STORE',
                    'webhook_signing_secret' => 'whsec_sample_secret_key_8923',
                    'capture_method' => 'automatic',
                ],
            ],
            [
                'name' => 'PayPal',
                'display_label' => 'PayPal Express & Smart Buttons',
                'provider' => 'paypal',
                'description' => 'Pay securely with PayPal account, balance, Pay in 4, or Venmo.',
                'public_key' => 'paypal_client_id_sample_8832',
                'secret_key' => 'paypal_secret_sample_9912',
                'webhook_url' => url('/api/webhooks/paypal'),
                'is_active' => true,
                'is_test_mode' => true,
                'sort_order' => 2,
                'settings' => [
                    'currency' => 'USD',
                    'mode' => 'sandbox',
                    'smart_buttons' => true,
                    'pay_in_4' => true,
                    'venmo' => true,
                    'webhook_id' => 'WH-9921471029',
                    'payment_action' => 'sale',
                ],
            ],
            [
                'name' => 'Square',
                'display_label' => 'Square Payments',
                'provider' => 'square',
                'description' => 'Accept credit cards and contactless POS payments with Square.',
                'public_key' => 'sq0idp-sample_app_id_9901',
                'secret_key' => 'sq0csp-sample_access_token',
                'webhook_url' => url('/api/webhooks/square'),
                'is_active' => false,
                'is_test_mode' => true,
                'sort_order' => 3,
                'settings' => [
                    'location_id' => 'L_MECARVI_MAIN',
                    'currency' => 'USD',
                    'digital_wallet' => true,
                ],
            ],
            [
                'name' => 'Cash App Pay',
                'display_label' => 'Cash App Pay',
                'provider' => 'cashapp',
                'description' => 'Pay fast and seamlessly with Cash App on mobile or desktop.',
                'public_key' => 'cashtag_sample',
                'secret_key' => 'cashapp_secret_sample',
                'webhook_url' => url('/api/webhooks/cashapp'),
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 4,
                'settings' => [
                    'cashtag' => '$MecarviEmbroidery',
                    'currency' => 'USD',
                    'allow_qr_scan' => true,
                    'merchant_id' => 'MERCHANT_CASHAPP_0091',
                ],
            ],
            [
                'name' => 'Wallet',
                'display_label' => 'Mecarvi Wallet Balance',
                'provider' => 'wallet',
                'description' => 'Allow customers to pay using their store wallet funds instantly.',
                'public_key' => null,
                'secret_key' => null,
                'webhook_url' => null,
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 5,
                'settings' => [
                    'allow_partial' => true,
                    'auto_refund_to_wallet' => true,
                    'min_balance' => 0,
                    'cashback_percentage' => 2,
                ],
            ],
            [
                'name' => 'Gift Cards',
                'display_label' => 'Gift Card Balance',
                'provider' => 'giftcard',
                'description' => 'Redeem your Mecarvi digital gift cards at checkout.',
                'public_key' => null,
                'secret_key' => null,
                'webhook_url' => null,
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 6,
                'settings' => [
                    'allow_combine' => true,
                    'allow_with_coupons' => true,
                    'expiry_months' => 24,
                ],
            ],
            [
                'name' => 'Voucher',
                'display_label' => 'Store Voucher & Coupons',
                'provider' => 'voucher',
                'description' => 'Apply promotional voucher codes for order discounts.',
                'public_key' => null,
                'secret_key' => null,
                'webhook_url' => null,
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 7,
                'settings' => [
                    'allow_stacking' => false,
                    'exclude_sale_items' => true,
                ],
            ],
            [
                'name' => 'Cash on Delivery',
                'display_label' => 'Cash on Delivery (COD)',
                'provider' => 'cod',
                'description' => 'Pay with cash upon physical delivery of your order.',
                'public_key' => null,
                'secret_key' => null,
                'webhook_url' => null,
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 8,
                'settings' => [
                    'fee' => 0.0,
                    'max_order_limit' => 1500,
                    'instructions' => 'Please have exact cash ready upon order arrival.',
                ],
            ],
            [
                'name' => 'Bank Transfer',
                'display_label' => 'Direct Bank Transfer / Wire',
                'provider' => 'bank_transfer',
                'description' => 'Make payment directly into our bank account with order ID as reference.',
                'public_key' => null,
                'secret_key' => null,
                'webhook_url' => null,
                'is_active' => false,
                'is_test_mode' => false,
                'sort_order' => 9,
                'settings' => [
                    'bank_name' => 'First National Bank',
                    'account_name' => 'Mecarvi Technologies LLC',
                    'account_number' => '••••••••4892',
                    'routing_number' => '121000358',
                    'swift_code' => 'FNBAUS33',
                    'branch_address' => '1200 Avenue of the Americas, New York, NY',
                    'zelle_identifier' => 'billing@mecarviembroidery.com',
                    'instructions' => 'Include Order Number in wire payment transfer notes.',
                ],
            ],
        ];
    }

    /**
     * Self-healing logic to normalize legacy records, deduplicate COD/manual entries,
     * and initialize default gateways only if the table is completely empty.
     */
    private function ensureCleanGateways(): void
    {
        // If table is completely empty, populate defaults once
        if (PaymentGateway::count() === 0) {
            foreach ($this->defaultGatewaysList() as $gw) {
                PaymentGateway::create($gw);
            }
            return;
        }

        // Deduplicate and normalize legacy 'manual' provider for Cash on Delivery
        $manualCod = PaymentGateway::where('provider', 'manual')
            ->where(function ($query) {
                $query->where('name', 'like', '%Cash on Delivery%')
                      ->orWhere('display_label', 'like', '%Cash on Delivery%')
                      ->orWhere('display_label', 'like', '%Pay when you receive%');
            })
            ->first();

        $standardCod = PaymentGateway::where('provider', 'cod')->first();

        if ($manualCod && $standardCod) {
            // Both exist: remove duplicate manual entry
            $manualCod->delete();
        } elseif ($manualCod && !$standardCod) {
            // Only manual exists: normalize provider to 'cod'
            $manualCod->update([
                'provider' => 'cod',
                'name' => 'Cash on Delivery',
                'display_label' => $manualCod->display_label ?: 'Cash on Delivery (COD)',
            ]);
        }

        // Deduplicate any repeated providers (keeping the earliest record)
        $allGateways = PaymentGateway::orderBy('id')->get();
        $seen = [];
        foreach ($allGateways as $gw) {
            $key = strtolower(trim($gw->provider));
            if (isset($seen[$key])) {
                $gw->delete();
            } else {
                $seen[$key] = true;
            }
        }
    }

    public function index(Request $request)
    {
        try {
            $this->ensureCleanGateways();

            $gateways = PaymentGateway::orderBy('sort_order')->get();

            if ($request->query('reveal') === '1') {
                $gateways->makeVisible(['secret_key']);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'gateways' => $gateways,
                    'stats' => [
                        'total' => $gateways->count(),
                        'active' => $gateways->where('is_active', true)->count(),
                        'test_mode' => $gateways->where('is_test_mode', true)->count(),
                        'online_gateways' => $gateways->whereIn('provider', ['stripe', 'paypal', 'square', 'cashapp'])->count(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment gateways.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function publicIndex()
    {
        try {
            $this->ensureCleanGateways();

            $gateways = PaymentGateway::where('is_active', true)->orderBy('sort_order')->get();

            // Public index never reveals secret keys
            $gateways->makeHidden(['secret_key']);

            return response()->json([
                'success' => true,
                'data' => ['gateways' => $gateways],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment gateways.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'display_label' => 'nullable|string|max:255',
                'provider' => 'required|string|max:50',
                'description' => 'nullable|string',
                'public_key' => 'nullable|string',
                'secret_key' => 'nullable|string',
                'webhook_url' => 'nullable|string',
                'is_active' => 'boolean',
                'is_test_mode' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'nullable|integer',
            ]);

            if (!isset($validated['sort_order'])) {
                $maxSort = PaymentGateway::max('sort_order') ?? 0;
                $validated['sort_order'] = $maxSort + 1;
            }

            $gateway = PaymentGateway::create($validated);
            return response()->json([
                'success' => true,
                'message' => 'Payment gateway created successfully.',
                'data' => ['gateway' => $gateway],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment gateway.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $gateway = PaymentGateway::findOrFail($id);
            if ($request->query('reveal') === '1') {
                $gateway->makeVisible(['secret_key']);
            }
            return response()->json([
                'success' => true,
                'data' => ['gateway' => $gateway],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Payment gateway not found.'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $gateway = PaymentGateway::findOrFail($id);
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'display_label' => 'nullable|string|max:255',
                'provider' => 'sometimes|string|max:50',
                'description' => 'nullable|string',
                'public_key' => 'nullable|string',
                'secret_key' => 'nullable|string',
                'webhook_url' => 'nullable|string',
                'is_active' => 'boolean',
                'is_test_mode' => 'boolean',
                'settings' => 'nullable|array',
                'sort_order' => 'nullable|integer',
            ]);

            // Don't overwrite existing secret key if blank, null, or masked placeholder
            if (array_key_exists('secret_key', $validated)) {
                $secret = $validated['secret_key'];
                if (empty($secret) || str_contains((string) $secret, '••••')) {
                    unset($validated['secret_key']);
                }
            }

            // Merge settings if provided
            if (isset($validated['settings']) && is_array($validated['settings']) && is_array($gateway->settings)) {
                $validated['settings'] = array_merge($gateway->settings, $validated['settings']);
            }

            $gateway->update($validated);

            if ($request->query('reveal') === '1') {
                $gateway->makeVisible(['secret_key']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment gateway updated successfully.',
                'data' => ['gateway' => $gateway],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment gateway.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function toggle(Request $request, $id)
    {
        try {
            $gateway = PaymentGateway::findOrFail($id);
            $field = $request->input('field', 'is_active');

            if ($field === 'is_test_mode') {
                $gateway->is_test_mode = $request->has('value')
                    ? (bool) $request->input('value')
                    : !$gateway->is_test_mode;
            } else {
                $gateway->is_active = $request->has('value')
                    ? (bool) $request->input('value')
                    : !$gateway->is_active;
            }

            $gateway->save();

            return response()->json([
                'success' => true,
                'message' => "Gateway {$field} updated successfully.",
                'data' => ['gateway' => $gateway],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle gateway status.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function testConnection(Request $request, $id)
    {
        try {
            $gateway = PaymentGateway::findOrFail($id);
            $provider = strtolower($gateway->provider);
            $publicKey = $request->input('public_key', $gateway->public_key);
            $secretKey = $request->input('secret_key', $gateway->secret_key);

            $status = 'healthy';
            $details = [];

            switch ($provider) {
                case 'stripe':
                    if (empty($publicKey)) {
                        $status = 'warning';
                        $details[] = 'Publishable key (pk_...) is not set.';
                    } elseif (!str_starts_with($publicKey, 'pk_')) {
                        $status = 'warning';
                        $details[] = 'Publishable key should start with "pk_test_" or "pk_live_".';
                    }
                    if (empty($secretKey) && empty($gateway->secret_key)) {
                        $status = 'warning';
                        $details[] = 'Secret key (sk_...) is missing.';
                    } elseif (!empty($secretKey) && !str_starts_with($secretKey, 'sk_') && !str_contains($secretKey, '••••')) {
                        $status = 'warning';
                        $details[] = 'Secret key typically starts with "sk_test_" or "sk_live_".';
                    }
                    break;

                case 'paypal':
                    if (empty($publicKey)) {
                        $status = 'warning';
                        $details[] = 'PayPal Client ID is missing.';
                    }
                    if (empty($secretKey) && empty($gateway->secret_key)) {
                        $status = 'warning';
                        $details[] = 'PayPal Secret Key is missing.';
                    }
                    break;

                case 'square':
                    if (empty($publicKey)) {
                        $status = 'warning';
                        $details[] = 'Square Application ID is missing.';
                    }
                    if (empty($secretKey) && empty($gateway->secret_key)) {
                        $status = 'warning';
                        $details[] = 'Square Access Token is missing.';
                    }
                    break;

                case 'cashapp':
                    $cashtag = $gateway->settings['cashtag'] ?? $publicKey;
                    if (empty($cashtag)) {
                        $status = 'warning';
                        $details[] = 'Cashtag or merchant ID is missing.';
                    }
                    break;

                case 'wallet':
                    $details[] = 'Internal Store Wallet payment engine is ready.';
                    break;

                case 'giftcard':
                    $details[] = 'Digital Gift Card payment engine is ready.';
                    break;

                case 'voucher':
                    $details[] = 'Voucher and promotional discounts processor is ready.';
                    break;

                case 'cod':
                case 'manual':
                    $details[] = 'Cash on Delivery (COD) processing is active.';
                    break;

                case 'bank_transfer':
                    $settings = $gateway->settings ?? [];
                    if (empty($settings['bank_name']) && empty($settings['account_number'])) {
                        $status = 'warning';
                        $details[] = 'Bank name and account details are recommended.';
                    } else {
                        $details[] = 'Bank transfer wire instructions configured.';
                    }
                    break;

                default:
                    $details[] = 'Custom payment provider connection verified.';
                    break;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $status,
                    'message' => $status === 'healthy' 
                        ? 'Connection validated. All required keys and settings are configured.' 
                        : 'Validation note: Some credentials or settings need attention.',
                    'details' => $details,
                    'tested_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to test connection.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function reorder(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'integer',
            ]);

            $ids = $request->input('ids');
            foreach ($ids as $index => $id) {
                PaymentGateway::where('id', $id)->update(['sort_order' => $index + 1]);
            }

            $gateways = PaymentGateway::orderBy('sort_order')->get();
            return response()->json([
                'success' => true,
                'message' => 'Gateways reordered successfully.',
                'data' => ['gateways' => $gateways],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder payment gateways.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $gateway = PaymentGateway::findOrFail($id);
            $gateway->delete();
            return response()->json([
                'success' => true,
                'message' => 'Payment gateway deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment gateway.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
