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
        ]);

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
     * Pay for the gift card order (mock payment).
     */
    public function pay(Request $request)
    {
        $request->validate([
            'gift_card_order_id' => 'required|exists:ecommerce_gift_card_orders,id',
            'payment_method' => 'required|string',
            'payment_token' => 'required|string',
        ]);

        $order = EcommerceGiftCardOrder::findOrFail($request->gift_card_order_id);

        if ($order->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already paid.'
            ], 400);
        }

        $paymentMethod = strtolower($request->payment_method);
        $user = $request->user();

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
        }

        $token = $request->payment_token;
        if (str_starts_with($token, 'test_card_') || str_starts_with($token, 'tok_')) {
            $order->update([
                'payment_status' => 'paid',
                'order_status' => 'Pending Gift Card Issue',
                'payment_method' => $paymentMethod,
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
                'data' => $order
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid payment token.'
        ], 400);
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

        if ($order->payment_status !== 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Order must be paid before issuing the gift card.'
            ], 400);
        }

        if ($order->order_status === 'Gift Card Issued' || $order->order_status === 'Gift Card Delivered') {
            return response()->json([
                'success' => false,
                'message' => 'Gift card has already been issued for this order.'
            ], 400);
        }

        return DB::transaction(function () use ($order, $request) {
            do {
                $code = '';
                for ($i = 0; $i < 15; $i++) {
                    $code .= random_int(0, 9);
                }
            } while (EcommerceGiftCard::where('code', $code)->exists());

            $recipientUser = User::where('email', $order->recipient_email)->first();
            $expiresAt = now()->addYear();

            $giftCard = EcommerceGiftCard::create([
                'user_id' => $recipientUser?->id,
                'order_id' => $order->id,
                'code' => $code,
                'recipient_name' => $order->recipient_name,
                'recipient_email' => $order->recipient_email,
                'sender_name' => $order->buyer_name,
                'initial_balance' => $order->giftcard_amount,
                'current_balance' => $order->giftcard_amount,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'delivery_type' => 'Email',
                'message' => $order->personal_message,
                'purchased_at' => now(),
                'buyer_user_id' => $order->customer_id,
                'buyer_name' => $order->buyer_name,
                'buyer_email' => $order->buyer_email,
                'owner_email' => $order->recipient_email,
                'issue_type' => 'Purchased',
                'issued_by_admin_id' => $request->user()?->id,
            ]);

            $giftCard->transactions()->create([
                'transaction_type' => 'Issue',
                'amount' => $order->giftcard_amount,
                'notes' => 'Gift card issued for order ' . $order->order_number,
                'created_by' => $request->user()?->id,
            ]);

            $giftCard->activityLogs()->create([
                'action' => 'Issued',
                'admin_id' => $request->user()?->id,
                'user_id' => $order->customer_id,
                'old_value' => null,
                'new_value' => json_encode($giftCard->only(['id', 'code', 'initial_balance', 'recipient_email'])),
                'ip_address' => $request->ip(),
            ]);

            $order->update([
                'order_status' => 'Gift Card Issued'
            ]);

            $emailSent = GiftCardMailer::sendIssued($order->recipient_email, [
                'code' => $code,
                'balance' => $order->giftcard_amount,
                'message' => $order->personal_message,
                'recipient_name' => $order->recipient_name,
                'sender_name' => $order->buyer_name,
                'expires_at' => $expiresAt->toDateString(),
            ]);

            if ($emailSent) {
                $order->update(['order_status' => 'Gift Card Delivered']);
                $giftCard->update(['status' => 'delivered']);
            } else {
                $order->update(['order_status' => 'Delivery Failed']);
                $giftCard->update(['status' => 'Issued — Delivery Failed']);
            }

            // Send email to sender (buyer)
            GiftCardMailer::sendPurchasedToBuyer($order->buyer_email, [
                'code' => $code,
                'balance' => $order->giftcard_amount,
                'message' => $order->personal_message,
                'recipient_name' => $order->recipient_name,
                'new_owner_email' => $order->recipient_email,
                'sender_name' => $order->buyer_name,
                'expires_at' => $expiresAt->toDateString(),
            ]);

            // Transition points for gift card orders from pending to available
            if ($order->customer_id) {
                $newStatus = $order->order_status;
                if ($newStatus === 'Gift Card Issued' || $newStatus === 'Gift Card Delivered') {
                    $pendingTxn = \App\Models\EcommerceLoyaltyTransaction::where('reference_type', 'gift_card_order')
                        ->where('reference_id', (string) $order->id)
                        ->where('transaction_type', 'earned')
                        ->where('status', 'pending')
                        ->first();
                    if ($pendingTxn) {
                        $points = $pendingTxn->points;
                        $pendingTxn->delete();
                        \App\Services\LoyaltyService::adjustPoints(
                            $order->customer_id,
                            $points,
                            'earned',
                            "Points earned for Gift Card Order #{$order->order_number}",
                            null,
                            'available'
                        );
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Gift card issued successfully.',
                'data' => [
                    'order' => $order->fresh(),
                    'gift_card' => $giftCard->fresh()
                ]
            ]);
        });
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

