<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EcommerceGiftCardOrder;
use App\Models\EcommerceGiftCard;
use App\Models\User;
use App\Support\GiftCardMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GiftCardOrderController extends Controller
{
    /**
     * Place a gift card order.
     */
    public function store(Request $request)
    {
        $request->validate([
            'buyer_name' => 'nullable|string|max:255',
            'buyer_email' => 'nullable|email|max:255',
            'recipient_name' => 'required|string|max:255',
            'recipient_email' => 'required_if:delivery_method,digital|nullable|email|max:255',
            'personal_message' => 'nullable|string',
            'giftcard_amount' => 'required|numeric|min:0.01',
            'delivery_date' => 'nullable|date',
            'delivery_method' => 'nullable|string|in:digital,physical',
            'recipient_phone' => 'nullable|string|max:50',
            'address_line1' => 'required_if:delivery_method,physical|nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required_if:delivery_method,physical|nullable|string|max:255',
            'state' => 'required_if:delivery_method,physical|nullable|string|max:255',
            'zip_code' => 'required_if:delivery_method,physical|nullable|string|max:50',
            'country' => 'required_if:delivery_method,physical|nullable|string|max:255',
            'card_purpose' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        // Admin → Gift Cards Settings: module on, card type on, preset / custom amount range, daily limit.
        $ruleError = \App\Services\GiftCardPurchaseRules::check(
            (string) ($request->delivery_method ?: 'digital'),
            (float) $request->giftcard_amount,
            $user,
            $request->buyer_email ?: $user?->email
        );
        if ($ruleError) {
            return response()->json(['success' => false, 'message' => $ruleError, 'errors' => ['giftcard_amount' => [$ruleError]]], 422);
        }

        $buyerName = $request->buyer_name ?: ($user?->name ?: 'Guest');
        $buyerEmail = $request->buyer_email ?: ($user?->email ?: 'guest@example.com');
        $recipientEmail = $request->recipient_email ?: $buyerEmail;

        do {
            $orderNumber = 'GCO-' . mt_rand(100000, 999999) . '-' . time();
        } while (EcommerceGiftCardOrder::where('order_number', $orderNumber)->exists());

        $order = EcommerceGiftCardOrder::create([
            'order_number' => $orderNumber,
            'customer_id' => $user?->id,
            'buyer_name' => $buyerName,
            'buyer_email' => $buyerEmail,
            'recipient_name' => $request->recipient_name,
            'recipient_email' => $recipientEmail,
            'personal_message' => $request->personal_message,
            'giftcard_amount' => $request->giftcard_amount,
            'payment_status' => 'pending',
            'order_status' => 'Payment Pending',
            'delivery_date' => $request->delivery_date,
            'delivery_method' => $request->delivery_method ?: 'digital',
            'recipient_phone' => $request->recipient_phone,
            'address_line1' => $request->address_line1,
            'address_line2' => $request->address_line2,
            'city' => $request->city,
            'state' => $request->state,
            'zip_code' => $request->zip_code,
            'country' => $request->country,
            'card_purpose' => $request->card_purpose,
            'buyer_phone' => $user?->phone,
            'buyer_address' => is_string($user?->address) ? $user->address : null,
            'buyer_business_name' => $user?->business_name,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000) ?: null,
        ]);

        $flag = \App\Services\GiftCardPanelService::detectFlag($order);
        if ($flag) {
            $order->update(['flagged_reason' => $flag]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gift card order placed successfully.',
            'data' => $order
        ], 201);
    }

    /**
     * Show a gift card order.
     */
    public function show($id)
    {
        $order = EcommerceGiftCardOrder::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => strtolower(str_replace(' ', '_', $order->order_status)),
                'order_status' => $order->order_status,
                'payment_status' => $order->payment_status,
                'currency' => 'USD',
                'total_amount' => (float) $order->giftcard_amount,
                'giftcard_amount' => (float) $order->giftcard_amount,
                'recipient_name' => $order->recipient_name,
                'recipient_email' => $order->recipient_email,
                'personal_message' => $order->personal_message,
                'buyer_name' => $order->buyer_name,
                'buyer_email' => $order->buyer_email,
                'delivery_method' => $order->delivery_method,
                'recipient_phone' => $order->recipient_phone,
                'address_line1' => $order->address_line1,
                'address_line2' => $order->address_line2,
                'city' => $order->city,
                'state' => $order->state,
                'zip_code' => $order->zip_code,
                'country' => $order->country,
                'card_purpose' => $order->card_purpose,
                'created_at' => optional($order->created_at)->toIso8601String(),
            ]
        ]);
    }

    /**
     * Pay for a gift card order: from the Mecarvi Cash wallet, or by card through Stripe.
     * The processor's payment reference is saved on the order so a decline or reversal can
     * refund it automatically (GiftCardPaymentRefundService).
     */
    public function pay(Request $request)
    {
        $request->validate([
            'gift_card_order_id' => 'required|exists:ecommerce_gift_card_orders,id',
            'payment_method' => 'required|string|in:wallet,stripe,card',
            'payment_token' => 'nullable|string',
            'saved_payment_method_id' => 'nullable|integer',
            'stripe_payment_method' => 'nullable|string|max:255',
            'payment_method_id' => 'nullable|string|max:255',
        ]);

        $paymentMethod = strtolower($request->payment_method);
        $user = $request->user();

        // Only one payment attempt per order at a time (no double debit / double charge).
        $lock = \Illuminate\Support\Facades\Cache::lock('gift_card_order_pay_' . $request->gift_card_order_id, 30);
        if (! $lock->get()) {
            return response()->json(['success' => false, 'message' => 'This order is already being paid. Please wait a moment.'], 409);
        }

        try {
            return $this->payLocked($request, $paymentMethod, $user);
        } finally {
            $lock->release();
        }
    }

    private function payLocked(Request $request, string $paymentMethod, ?User $user)
    {
        $order = EcommerceGiftCardOrder::findOrFail($request->gift_card_order_id);

        if ($order->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already paid.'
            ], 400);
        }
        if ($order->customer_id && $user && (int) $order->customer_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'This gift card order belongs to another account.'], 403);
        }

        $paymentReference = null;
        $cardBrand = null;
        $cardLast4 = null;

        if ($paymentMethod === 'wallet') {
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User must be authenticated to pay with wallet.'
                ], 401);
            }

            // Fetch LIVE balance from the central auth server
            // Uses same pattern as EcommerceWalletTransactionController::summary()
            $centralUrl = rtrim(config('services.central_auth.url'), '/');
            $token = $request->bearerToken();
            $liveWalletBalance = 0.00;

            if ($token) {
                try {
                    $walletRes = \Illuminate\Support\Facades\Http::acceptJson()
                        ->withToken($token)
                        ->timeout(5)
                        ->get($centralUrl . '/user/wallet');

                    if ($walletRes->successful()) {
                        $data = $walletRes->json('data');
                        // Central API returns data.wallet.balance when wallet key exists,
                        // or data.balance in the flat format — handle both
                        if (isset($data['wallet']['balance'])) {
                            $liveWalletBalance = (float) $data['wallet']['balance'];
                        } elseif (isset($data['balance'])) {
                            $liveWalletBalance = (float) $data['balance'];
                        }

                        // Sync local cache so it stays up-to-date
                        $user->wallet_balance = $liveWalletBalance;
                        $user->save();
                    } else {
                        \Log::warning('GiftCardOrderController: Central wallet API returned ' . $walletRes->status() . ': ' . $walletRes->body());
                        // Fall back to local cached value
                        $liveWalletBalance = (float) $user->wallet_balance;
                    }
                } catch (\Throwable $e) {
                    \Log::warning('GiftCardOrderController: Failed to fetch live wallet balance: ' . $e->getMessage());
                    $liveWalletBalance = (float) $user->wallet_balance;
                }
            } else {
                $liveWalletBalance = (float) $user->wallet_balance;
            }

            if ($walletError = \App\Services\MecarviCashService::validateWalletSpend($user, (float) $order->giftcard_amount, $liveWalletBalance)) {
                return response()->json(['success' => false, 'message' => $walletError], 422);
            }

            if ($liveWalletBalance < (float) $order->giftcard_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance. You have $' . number_format($liveWalletBalance, 2) . ' but need $' . number_format($order->giftcard_amount, 2) . '.'
                ], 400);
            }

            $deducted = \App\Services\WalletService::adjustWallet(
                $user->id,
                (float) $order->giftcard_amount,
                'debit',
                "Payment for Gift Card Order #{$order->order_number}",
                (string) $order->id
            );

            if (!$deducted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to adjust wallet balance. Please try again.'
                ], 500);
            }

            $paymentReference = 'WALLET-GCO-' . $order->id;
        } else {
            $charged = $this->chargeCard($request, $user, $order);
            if ($charged instanceof \Illuminate\Http\JsonResponse) {
                return $charged;
            }
            $paymentReference = $charged['reference'];
            $cardBrand = $charged['brand'];
            $cardLast4 = $charged['last4'];
        }


        $order->update([
            'payment_status' => 'paid',
            'order_status' => 'Pending Gift Card Issue',
            'payment_method' => $paymentMethod === 'wallet' ? 'wallet' : 'stripe',
            'card_brand' => $cardBrand,
            'card_last4' => $cardLast4,
            // The processor's payment id (Stripe pi_…, or the wallet debit reference): refunds use it.
            'transaction_id' => $paymentReference,
            'paid_at' => now(),
        ]);

        // Award pending loyalty points for the order (will activate upon admin issue)
        \App\Services\LoyaltyService::awardPointsForGiftCard(
            $order->customer_id,
            (float) $order->giftcard_amount,
            $order->id,
            $order->order_number,
            'pending'
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment processed successfully. Your gift card order has been submitted to admin for issuance.',
            'data' => $order->fresh(),
        ]);
    }

    /**
     * Charge a card for a gift card order through the store's Stripe account: a saved card
     * (saved_payment_method_id) or one confirmed with Stripe.js (stripe_payment_method). In
     * local/testing with no Stripe keys only the 4242 test card is accepted and no money moves.
     *
     * @return array{reference: string, brand: ?string, last4: ?string}|\Illuminate\Http\JsonResponse
     */
    private function chargeCard(Request $request, ?User $user, EcommerceGiftCardOrder $order)
    {
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Please sign in to pay by card.'], 401);
        }

        $cards = app(\App\Services\MembershipCardService::class);
        $input = $request->only(['saved_payment_method_id', 'stripe_payment_method', 'payment_method_id', 'payment_token', 'card_exp_month', 'card_exp_year', 'cardholder_name']);

        try {
            $card = $cards->resolveForPurchase($user, $input);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'This card could not be used.',
                'errors' => $e->errors(),
            ], 422);
        }

        // One key per order: a retried or double-submitted payment is never charged twice.
        $charge = $cards->charge(
            $card,
            (float) $order->giftcard_amount,
            'USD',
            "Gift Card Order #{$order->order_number}",
            ['purpose' => 'gift_card_order', 'gift_card_order_id' => $order->id, 'user_id' => $user->id],
            'gift_card_order_' . $order->id
        );

        if (! $charge['paid']) {
            return response()->json([
                'success' => false,
                'message' => $charge['reason'] ?: 'Your card was declined.',
                'decline_code' => $charge['details']['decline_code'] ?? null,
            ], 402);
        }

        return [
            'reference' => (string) $charge['reference'],
            'brand' => $card['brand'] ?: 'Card',
            'last4' => $card['last4'] ?: null,
        ];
    }

    /**
     * List all gift card orders (Admin).
     */
    public function index(Request $request)
    {
        $query = EcommerceGiftCardOrder::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('buyer_name', 'like', "%{$search}%")
                  ->orWhere('buyer_email', 'like', "%{$search}%")
                  ->orWhere('recipient_name', 'like', "%{$search}%")
                  ->orWhere('recipient_email', 'like', "%{$search}%")
                  ->orWhere('order_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('order_status')) {
            $query->where('order_status', $request->order_status);
        }

        $orders = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Issue the gift card for a paid order (Admin).
     */
    public function issue(Request $request, $id)
    {
        $order = EcommerceGiftCardOrder::findOrFail($id);

        try {
            $giftCard = \App\Services\GiftCardIssueService::issue($order, $request->user(), $request->ip(), true);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gift card issued successfully.',
            'data' => [
                'order' => $order->fresh(),
                'gift_card' => $giftCard,
            ],
        ]);
    }

    /**
     * List authenticated customer's gift card orders.
     */
    public function customerOrders(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $email = strtolower(trim((string) $user->email));
        $orders = EcommerceGiftCardOrder::where(function ($q) use ($user, $email) {
            $q->where('customer_id', $user->id);
            if ($email) {
                $q->orWhereRaw('LOWER(buyer_email) = ?', [$email]);
            }
        })->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}

