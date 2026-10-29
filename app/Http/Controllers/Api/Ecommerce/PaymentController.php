<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Omnipay\Omnipay;
use App\Models\EcommerceOrder;
use App\Models\EcommerceGiftCardOrder;
use App\Models\PaymentGateway;

class PaymentController extends Controller
{
    private $stripeGateway;
    private $paypalGateway;

    public function __construct()
    {
        if (! class_exists(Omnipay::class)) {
            return;
        }

        // Check Stripe Database Gateway first, then fallback to services.stripe config
        $stripeModel = PaymentGateway::where('provider', 'stripe')->first();
        $stripeSecret = ($stripeModel && $stripeModel->is_active && filled($stripeModel->secret_key))
            ? $stripeModel->secret_key
            : config('services.stripe.secret');

        if (filled($stripeSecret)) {
            $this->stripeGateway = Omnipay::create('Stripe');
            $this->stripeGateway->setApiKey($stripeSecret);
        }

        // Check PayPal Database Gateway first, then fallback to services.paypal config
        $paypalModel = PaymentGateway::where('provider', 'paypal')->first();
        $paypalClientId = ($paypalModel && $paypalModel->is_active && filled($paypalModel->public_key))
            ? $paypalModel->public_key
            : config('services.paypal.client_id');
        $paypalSecret = ($paypalModel && $paypalModel->is_active && filled($paypalModel->secret_key))
            ? $paypalModel->secret_key
            : config('services.paypal.secret');
        $paypalMode = $paypalModel
            ? ($paypalModel->is_test_mode ? 'sandbox' : 'live')
            : config('services.paypal.mode', 'sandbox');

        if (filled($paypalClientId) && filled($paypalSecret)) {
            $this->paypalGateway = Omnipay::create('PayPal_Rest');
            $this->paypalGateway->setClientId($paypalClientId);
            $this->paypalGateway->setSecret($paypalSecret);
            $this->paypalGateway->setTestMode($paypalMode === 'sandbox');
        }
    }

    public function showOrder($order)
    {
        if (request()->query('type') === 'gift_card') {
            $query = EcommerceGiftCardOrder::query();
            if (is_numeric($order)) {
                $query->where(function ($q) use ($order) {
                    $q->where('id', $order)->orWhere('order_number', $order);
                });
            } else {
                $query->where('order_number', $order);
            }
            $item = $query->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $item->id,
                    'order_number' => $item->order_number,
                    'status' => strtolower(str_replace(' ', '_', $item->order_status)),
                    'payment_status' => $item->payment_status,
                    'payment_method' => 'card',
                    'currency' => 'USD',
                    'total_amount' => (float) $item->giftcard_amount,
                    'subtotal' => (float) $item->giftcard_amount,
                    'shipping_amount' => 0.0,
                    'discount_amount' => 0.0,
                    'tax_amount' => 0.0,
                    'shipping_address' => null,
                    'billing_address' => null,
                    'shipping_method' => 'Email',
                    'loyalty_points_earned' => 0,
                    'estimated_delivery_at' => null,
                    'created_at' => optional($item->created_at)->toIso8601String(),
                    'items' => [
                        [
                            'id' => $item->id,
                            'name' => 'Gift Card Purchase',
                            'product' => [
                                'name' => 'Gift Card Purchase',
                                'price' => (float) $item->giftcard_amount,
                                'thumbnail' => '/assets/images/logo.webp'
                            ],
                            'quantity' => 1,
                            'unit_price' => (float) $item->giftcard_amount,
                            'total_price' => (float) $item->giftcard_amount,
                        ]
                    ],
                ],
            ]);
        }

        $query = EcommerceOrder::with(['items.product']);

        if (is_numeric($order)) {
            $query->where(function ($q) use ($order) {
                $q->where('id', $order)->orWhere('order_number', $order);
            });
        } else {
            $query->where('order_number', $order);
        }

        $item = $query->firstOrFail();
        $subtotal = (float) ($item->subtotal ?: $item->items->sum('total_price'));

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $item->id,
                'order_number' => $item->order_number,
                'status' => strtolower((string) $item->status),
                'payment_status' => $item->payment_status,
                'payment_method' => $item->payment_method,
                'currency' => $item->currency ?? 'USD',
                'total_amount' => (float) $item->total_amount,
                'subtotal' => round($subtotal, 2),
                'shipping_amount' => (float) ($item->shipping_amount ?? 0),
                'discount_amount' => (float) ($item->discount_amount ?? 0),
                'customer_name' => $item->customer_name,
                'customer_email' => $item->customer_email,
                'customer_phone' => $item->customer_phone,
                'shipping_address' => $item->shipping_address,
                'billing_address' => $item->billing_address,
                'shipping_method' => $item->shipping_method,
                'loyalty_points_earned' => (int) ($item->loyalty_points_earned ?? 0),
                'tip_amount' => (float) ($item->tip_amount ?? 0),
                'estimated_delivery_at' => optional($item->estimated_delivery_at)->toIso8601String(),
                'created_at' => optional($item->created_at)->toIso8601String(),
                'items' => $item->items,
            ],
        ]);
    }

    /**
     * Process a payment using token or redirect.
     */
    public function process(Request $request)
    {
        $isGiftCard = $request->input('type') === 'gift_card';

        $request->validate([
            'order_id' => 'required',
            'payment_method' => 'required|in:stripe,paypal,wallet',
            'payment_token' => 'required_if:payment_method,stripe|string',
        ]);

        if ($isGiftCard) {
            $order = EcommerceGiftCardOrder::findOrFail($request->order_id);

            if ($order->payment_status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already paid.'
                ], 400);
            }
        } else {
            $order = EcommerceOrder::findOrFail($request->order_id);

            if ($order->status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already paid.'
                ], 400);
            }
        }

        if ($request->payment_method === 'stripe') {
            if ($this->shouldUseStripeTestMode()) {
                return $this->processStripeTestPayment($order, $request->payment_token, $isGiftCard);
            }

            if (! $this->stripeGateway) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe payment gateway is not configured.',
                ], 503);
            }

            return $this->processStripe($order, $request->payment_token, $isGiftCard);
        } else if ($request->payment_method === 'paypal') {
            if (! $this->paypalGateway) {
                return response()->json([
                    'success' => false,
                    'message' => 'PayPal payment gateway is not configured.',
                ], 503);
            }

            return $this->processPayPal($order, $isGiftCard);
        } else if ($request->payment_method === 'wallet') {
            return $this->processWallet($request, $order, $isGiftCard);
        }
    }

    /**
     * Process Stripe Payment
     */
    private function processStripe($order, $token, bool $isGiftCard = false)
    {
        try {
            $amount = $isGiftCard ? $order->giftcard_amount : $order->total_amount;
            $response = $this->stripeGateway->purchase([
                'amount' => $amount,
                'currency' => env('CASHIER_CURRENCY', 'USD'),
                'token' => $token,
                'description' => ($isGiftCard ? 'Gift Card Order #' : 'Order #') . $order->order_number,
                'metadata' => [
                    'order_id' => $order->id,
                    'type' => $isGiftCard ? 'gift_card' : 'order',
                ]
            ])->send();

            if ($response->isSuccessful()) {
                if ($isGiftCard) {
                    $order->payment_status = 'paid';
                    $order->order_status = 'Pending Gift Card Issue';
                    $order->payment_method = 'stripe';
                } else {
                    $order->status = 'paid';
                    $order->payment_status = 'paid';
                    $order->payment_method = 'stripe';
                }
                $order->save();

                if ($isGiftCard) {
                    \App\Services\LoyaltyService::awardPointsForGiftCard(
                        $order->customer_id,
                        (float) $order->giftcard_amount,
                        $order->id,
                        $order->order_number,
                        'pending'
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment successful.',
                    'transaction_reference' => $response->getTransactionReference(),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $response->getMessage()
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function shouldUseStripeTestMode(): bool
    {
        return app()->environment(['local', 'testing']) && blank(config('services.stripe.secret'));
    }

    private function processStripeTestPayment($order, string $token, bool $isGiftCard = false)
    {
        $allowedPrefixes = ['test_card_4242424242424242', 'tok_visa', 'test_card_success'];
        $isApprovedToken = collect($allowedPrefixes)->contains(fn ($prefix) => str_starts_with($token, $prefix));

        if (! $isApprovedToken) {
            return response()->json([
                'success' => false,
                'message' => 'Local Stripe test mode only accepts the 4242 4242 4242 4242 test card.',
            ], 400);
        }

        if ($isGiftCard) {
            $order->payment_status = 'paid';
            $order->order_status = 'Pending Gift Card Issue';
            $order->payment_method = 'stripe';
            $order->save();

            \App\Services\LoyaltyService::awardPointsForGiftCard(
                $order->customer_id,
                (float) $order->giftcard_amount,
                $order->id,
                $order->order_number,
                'pending'
            );
        } else {
            $order->status = 'paid';
            $order->payment_status = 'paid';
            $order->payment_method = 'stripe';
            $order->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Test payment successful.',
            'transaction_reference' => 'test_stripe_' . $order->id . '_' . now()->timestamp,
            'test_mode' => true,
        ]);
    }

    /**
     * Process PayPal Payment
     */
    private function processPayPal($order, bool $isGiftCard = false)
    {
        try {
            $origin = request()->header('Origin') ?: (request()->header('Referer') ? parse_url(request()->header('Referer'), PHP_URL_SCHEME) . '://' . parse_url(request()->header('Referer'), PHP_URL_HOST) : null);
            $frontendUrl = env('FRONTEND_URL') ?: ($origin ?: (config('app.frontend_url') ?: (app()->environment('production') ? 'https://mecarviembroidery.com' : 'http://localhost:3000')));
            $frontendUrl = rtrim($frontendUrl, '/');
            $amount = $isGiftCard ? $order->giftcard_amount : $order->total_amount;
            $typeParam = $isGiftCard ? '&type=gift_card' : '';

            $response = $this->paypalGateway->purchase([
                'amount' => $amount,
                'currency' => env('CASHIER_CURRENCY', 'USD'),
                'description' => ($isGiftCard ? 'Gift Card Order #' : 'Order #') . $order->order_number,
                'returnUrl' => $frontendUrl . '/checkout/payment/success?order_id=' . $order->id . $typeParam,
                'cancelUrl' => $frontendUrl . '/checkout/payment/cancel?order_id=' . $order->id . $typeParam,
            ])->send();

            if ($response->isRedirect()) {
                return response()->json([
                    'success' => true,
                    'redirect_url' => $response->getRedirectUrl(),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $response->getMessage()
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle PayPal Success
     */
    public function paypalSuccess(Request $request)
    {
        $paymentId = $request->query('paymentId');
        $payerId = $request->query('PayerID');
        $orderId = $request->query('order_id');
        $isGiftCard = $request->query('type') === 'gift_card';

        if (!$paymentId || !$payerId || !$orderId) {
            return response()->json(['success' => false, 'message' => 'Invalid PayPal callback parameters'], 400);
        }

        $order = $isGiftCard ? EcommerceGiftCardOrder::findOrFail($orderId) : EcommerceOrder::findOrFail($orderId);

        try {
            $response = $this->paypalGateway->completePurchase([
                'payer_id' => $payerId,
                'transactionReference' => $paymentId,
            ])->send();

            if ($response->isSuccessful()) {
                if ($isGiftCard) {
                    $order->payment_status = 'paid';
                    $order->order_status = 'Pending Gift Card Issue';
                    $order->payment_method = 'paypal';
                } else {
                    $order->status = 'paid';
                    $order->payment_status = 'paid';
                    $order->payment_method = 'paypal';
                }
                $order->save();

                if ($isGiftCard) {
                    \App\Services\LoyaltyService::awardPointsForGiftCard(
                        $order->customer_id,
                        (float) $order->giftcard_amount,
                        $order->id,
                        $order->order_number,
                        'pending'
                    );
                }

                return response()->json([
                     'success' => true,
                     'message' => 'PayPal payment successful.'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $response->getMessage()
                ], 400);
            }
        } catch (\Exception $e) {
             return response()->json([
                 'success' => false,
                 'message' => $e->getMessage()
              ], 500);
        }
    }

    /**
     * Handle PayPal Cancel
     */
    public function paypalCancel(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'User cancelled the PayPal payment.',
        ]);
    }

    /**
     * Process Wallet Payment
     */
    private function processWallet(Request $request, $order, bool $isGiftCard = false)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required to pay with wallet.'
            ], 401);
        }

        $token = $request->header('X-Central-Auth-Token') ?? $request->bearerToken();
        $walletBalance = \App\Services\WalletService::getWalletBalance($user, $token);

        $amount = $isGiftCard ? $order->giftcard_amount : $order->total_amount;

        if ($walletBalance < $amount) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient wallet balance. You need $" . number_format($amount, 2) . " but only have $" . number_format($walletBalance, 2) . "."
            ], 400);
        }

        $debitSuccess = \App\Services\WalletService::adjustWallet(
            $user->id,
            $amount,
            'debit',
            "Payment for order #{$order->order_number}",
            $order->order_number
        );

        if (!$debitSuccess) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deduct payment from your wallet. Please try again.'
            ], 500);
        }

        if ($isGiftCard) {
            $order->payment_status = 'paid';
            $order->order_status = 'Pending Gift Card Issue';
        } else {
            $order->status = 'paid';
            $order->payment_status = 'paid';
        }
        $order->payment_method = 'wallet';
        $order->save();

        if ($isGiftCard) {
            \App\Services\LoyaltyService::awardPointsForGiftCard(
                $order->customer_id,
                (float) $order->giftcard_amount,
                $order->id,
                $order->order_number,
                'pending'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment successful via Wallet.',
            'transaction_reference' => 'wallet_' . $order->order_number,
        ]);
    }
}
