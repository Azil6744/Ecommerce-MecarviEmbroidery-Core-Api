<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EcommerceWalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EcommerceWalletTransactionController extends Controller
{
    /**
     * Get wallet summary including live balance, credits, debits, and counts.
     */
    public function summary(Request $request)
    {
        $token = $request->bearerToken();
        $user = $request->user();

        // If no authenticated user from middleware, check if email was passed
        if (!$user && $request->filled('email')) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($request->input('email')))])->first();
        }

        if ($user) {
            $balance = WalletService::getWalletBalance($user, $token);
            $account = \App\Services\MecarviCashService::account($user);

            // Compute wallet metrics in SQL instead of loading every transaction into memory
            // Wallet rows only: store credit is a separate balance with its own ledger rows.
            $agg = EcommerceWalletTransaction::where('user_id', $user->id)
                ->where(fn ($q) => $q->where('balance_type', 'wallet')->orWhereNull('balance_type'))
                ->selectRaw("
                    COUNT(*) as transactions_count,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(type, '')) IN ('credit','deposit','refund','affiliate earned','affiliate_earned') THEN amount ELSE 0 END), 0) as credits,
                    COALESCE(SUM(CASE WHEN LOWER(COALESCE(type, '')) NOT IN ('credit','deposit','refund','affiliate earned','affiliate_earned') THEN ABS(amount) ELSE 0 END), 0) as debits
                ")
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'available_balance' => $balance,
                    'usable_balance' => $balance,
                    'credits' => (float) ($agg->credits ?? 0),
                    'debits' => (float) ($agg->debits ?? 0),
                    'transactions_count' => (int) ($agg->transactions_count ?? 0),
                    'last_updated' => now()->toIso8601String(),
                    'wallet_status' => $account->status,
                    // Store credit issued by admins (Mecarvi Cash). Separate from the wallet balance.
                    'store_credit_balance' => round((float) $account->store_credit_balance, 2),
                    'store_credit' => round((float) $account->store_credit_balance, 2),
                    'settings' => \App\Services\MecarviCashService::publicSettings(),
                ],
            ]);
        }

        // Unauthenticated or guest without email
        return response()->json([
            'success' => true,
            'data' => [
                'balance' => 0.00,
                'available_balance' => 0.00,
                'usable_balance' => 0.00,
                'credits' => 0.00,
                'debits' => 0.00,
                'transactions_count' => 0,
                'last_updated' => null,
                'store_credit_balance' => 0.00,
                'settings' => \App\Services\MecarviCashService::publicSettings(),
            ],
        ]);
    }

    /** Public Mecarvi Cash settings (no auth) so UIs can hide wallet options the admin turned off. */
    public function publicSettings()
    {
        return response()->json(['success' => true, 'data' => \App\Services\MecarviCashService::publicSettings()]);
    }

    /**
     * Add funds directly to wallet (top-up via card, paypal, etc.)
     */
    public function addFunds(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.50',
        ]);

        $amount = round((float) $request->input('amount'), 2);
        $user = $request->user();

        // Top-ups charge a real payment method and save cards to the account, so a signed-in customer is required.
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please log in to add funds to your wallet.',
            ], 401);
        }

        $method = strtolower((string) $request->input('payment_method', 'card'));
        $methodKey = match ($method) {
            'card', 'credit_card', 'debit_card', 'stripe', 'saved_card' => 'card',
            'cashapp', 'cash_app', 'cash_app_pay' => 'cashapp',
            default => $method,
        };

        if ($limitError = \App\Services\MecarviCashService::validateCustomerTopUp($user, $amount, $methodKey)) {
            return response()->json(['success' => false, 'message' => $limitError], 422);
        }

        // Take the money first; the wallet is only credited once the payment has gone through.
        $payment = match ($methodKey) {
            'card' => $this->chargeCardForTopUp($request, $user, $amount),
            'paypal' => $this->chargePayPalForTopUp($request, $user, $amount),
            'cashapp' => response()->json(['success' => false, 'message' => 'Cash App top-ups are not available yet. Please use a card or PayPal.'], 422),
            default => response()->json(['success' => false, 'message' => 'This payment method is not allowed for wallet top-ups.'], 422),
        };
        if ($payment instanceof \Illuminate\Http\JsonResponse) {
            return $payment; // error, or the PayPal approval redirect
        }

        $refId = $payment['reference'];
        $desc = $payment['description'];

        $success = WalletService::adjustWallet($user->id, $amount, 'deposit', $desc, $refId, true, [
            'category' => 'topup',
            'reason' => 'Customer Top-Up',
        ]);

        if (!$success) {
            $refunded = $this->refundTopUpPayment($payment, $amount);
            Log::error('Wallet top-up was paid but the wallet credit failed.', ['user_id' => $user->id, 'reference' => $refId, 'refunded' => $refunded]);

            return response()->json([
                'success' => false,
                'message' => $refunded
                    ? 'We could not add the funds to your wallet, so your payment was refunded. Please try again.'
                    : 'Your payment went through but the wallet could not be updated. Our team has been notified and will fix this.',
            ], 500);
        }

        // Activity row: also resets the declined-funding counter (MecarviCashService::recordFundingDecline).
        \App\Services\MecarviCashService::log($user, 'topup', 'Wallet Top-up', sprintf('$%s added by customer (%s).', number_format($amount, 2), $desc), $refId, $user);

        $user->refresh();
        $newBalance = (float) ($user->wallet_balance ?? 0.00);

        return response()->json([
            'success' => true,
            'message' => 'Funds added successfully!',
            'data' => [
                'amount' => $amount,
                'balance' => $newBalance,
                'available_balance' => $newBalance,
                'usable_balance' => $newBalance,
                'reference_id' => $refId,
                'description' => $desc,
            ],
        ]);
    }

    /**
     * Charge a card for a top-up: a saved card (saved_payment_method_id), a card confirmed with Stripe
     * (stripe_payment_method / payment_method_id), or the 4242 card in local test mode.
     *
     * @return array{method: string, reference: string, description: string}|\Illuminate\Http\JsonResponse
     */
    private function chargeCardForTopUp(Request $request, User $user, float $amount)
    {
        $cards = app(\App\Services\MembershipCardService::class);
        $cardInput = $request->only(['saved_payment_method_id', 'stripe_payment_method', 'payment_method_id', 'payment_token', 'card_exp_month', 'card_exp_year', 'cardholder_name']);
        $newCardGiven = filled($cardInput['stripe_payment_method'] ?? null) || filled($cardInput['payment_method_id'] ?? null) || filled($cardInput['payment_token'] ?? null);
        if (! $newCardGiven && ! filled($cardInput['saved_payment_method_id'] ?? null)) {
            return response()->json(['success' => false, 'message' => 'Please enter a card or choose a saved card to add funds.'], 422);
        }

        try {
            $card = $cards->resolveForPurchase($user, $cardInput);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'This card could not be used.';
            // A card that was entered but refused (e.g. not the test card in local test mode) counts as a decline.
            if ($newCardGiven && $cards->mode() === \App\Services\MembershipCardService::MODE_TEST) {
                $message = $this->declineMessage($user, 'card', $message);
            }

            return response()->json(['success' => false, 'message' => $message, 'errors' => $e->errors()], 422);
        }

        $idempotencyKey = 'wallet_topup_' . sha1(implode('|', [
            $user->id,
            $card['payment_method'],
            number_format($amount, 2, '.', ''),
            (string) ($request->input('idempotency_key') ?: now()->format('Y-m-d H:i')),
        ]));
        $charge = $cards->charge($card, $amount, 'USD', 'Mecarvi Cash top-up', ['purpose' => 'wallet_topup', 'user_id' => $user->id], $idempotencyKey);

        if (! $charge['paid']) {
            return response()->json([
                'success' => false,
                'message' => $this->declineMessage($user, 'card', (string) ($charge['reason'] ?: 'Your card was declined.')),
                'decline_code' => $charge['details']['decline_code'] ?? null,
            ], 402);
        }

        return [
            'method' => 'card',
            'reference' => (string) ($charge['reference'] ?: 'TOPUP-' . strtoupper(uniqid())),
            'description' => 'Added Funds via ' . ($card['brand'] ?: 'Card') . ($card['last4'] ? " **** {$card['last4']}" : ''),
        ];
    }

    /**
     * PayPal top-up, two steps: without paypal_payment_id this returns the approval redirect; after
     * PayPal sends the customer back, the same call with paypal_payment_id + paypal_payer_id captures it.
     *
     * @return array{method: string, reference: string, description: string}|\Illuminate\Http\JsonResponse
     */
    private function chargePayPalForTopUp(Request $request, User $user, float $amount)
    {
        $service = app(\App\Services\PayPalMembershipPaymentService::class);
        $purpose = ['purpose' => 'wallet_topup'];
        $paymentId = trim((string) $request->input('paypal_payment_id', ''));
        $payerId = trim((string) $request->input('paypal_payer_id', ''));
        // Only a failed capture of a real pending approval counts as a decline (not an expired/unknown id).
        $pendingApproval = $paymentId !== '' && $service->isPendingApproval($paymentId);

        try {
            if ($paymentId !== '' && $payerId !== '') {
                $result = $service->completeFor($user, $purpose, $amount, $paymentId, $payerId, 'This PayPal approval is for a different payment.');

                return [
                    'method' => 'paypal',
                    'reference' => (string) $result['transaction_reference'],
                    'description' => 'Added Funds via PayPal',
                ];
            }

            $returnUrl = $this->safeReturnUrl($request, (string) $request->input('return_url', ''));
            if (! $returnUrl) {
                return response()->json(['success' => false, 'message' => 'A valid return_url is required to pay with PayPal.'], 422);
            }
            $cancelUrl = $this->safeReturnUrl($request, (string) $request->input('cancel_url', '')) ?: $returnUrl;
            $started = $service->startFor($user, $purpose, $amount, 'Mecarvi Cash top-up', $returnUrl, $cancelUrl);

            return response()->json([
                'success' => true,
                'requires_redirect' => true,
                'redirect_url' => $started['redirect_url'],
                'paypal_payment_id' => $started['payment_id'],
                'message' => 'Continue to PayPal to approve the payment.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'PayPal payment was not completed.';
            if ($pendingApproval) {
                // The customer approved on PayPal but the capture failed: a declined funding attempt.
                $message = $this->declineMessage($user, 'paypal', $message);
            }

            return response()->json(['success' => false, 'message' => $message], 422);
        }
    }

    /** Record a declined funding attempt and add a note when it restricted the wallet. */
    private function declineMessage(User $user, string $method, string $message): string
    {
        $restricted = \App\Services\MecarviCashService::recordFundingDecline($user, $method, $message);

        return $restricted
            ? $message . ' Your wallet has been restricted after repeated declined payments. Please contact support.'
            : $message;
    }

    /** Give back a top-up payment when the wallet could not be credited. */
    private function refundTopUpPayment(array $payment, float $amount): bool
    {
        try {
            return $payment['method'] === 'paypal'
                ? app(\App\Services\PayPalMembershipPaymentService::class)->refund($payment['reference'], $amount)
                : app(\App\Services\MembershipCardService::class)->refund($payment['reference'], $amount, 'requested_by_customer');
        } catch (\Throwable $e) {
            Log::error('Wallet top-up refund failed: ' . $e->getMessage(), ['reference' => $payment['reference']]);

            return false;
        }
    }

    /** Only send customers back to our own sites after PayPal. */
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

    /**
     * Get transaction history for current user.
     */
    public function index(Request $request)
    {
        $token = $request->bearerToken();
        $user = $request->user();

        if (!$user && $request->filled('email')) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($request->input('email')))])->first();
        }

        if (!$user) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Try central auth first if configured
        $centralUrl = rtrim(config('services.central_auth.url'), '/');
        if ($centralUrl && $token) {
            try {
                $response = Http::acceptJson()
                    ->withToken($token)
                    ->connectTimeout(0.5)
                    ->timeout(1)
                    ->get($centralUrl . '/user/wallet/transactions');
                if ($response->successful()) {
                    $data = $response->json('data');
                    $txList = isset($data['transactions']) ? $data['transactions'] : $data;
                    if (is_array($txList) && count($txList) > 0) {
                        return response()->json([
                            'success' => true,
                            'data' => $txList,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Central wallet index fallback: ' . $e->getMessage());
            }
        }

        // Fallback to local transactions
        $transactions = EcommerceWalletTransaction::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Store transaction (credit/debit).
     */
    public function store(Request $request)
    {
        $type = strtolower($request->input('type', 'credit'));
        $isCredit = in_array($type, ['credit', 'deposit', 'refund', 'affiliate earned', 'affiliate_earned']);

        // Delegate deposits directly to addFunds
        if ($isCredit) {
            return $this->addFunds($request);
        }

        $user = $request->user();
        if (!$user && $request->filled('email')) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($request->input('email')))])->first();
        }

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $amount = (float) $request->input('amount', 0);
        $desc = $request->input('description', 'Wallet debit');
        $refId = $request->input('reference_id') ?: ('TX-' . strtoupper(uniqid()));

        if ($walletError = \App\Services\MecarviCashService::validateWalletSpend($user, $amount)) {
            return response()->json(['success' => false, 'message' => $walletError], 422);
        }

        $success = WalletService::adjustWallet($user->id, $amount, 'debit', $desc, $refId);

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient wallet balance or transaction failed.',
            ], 400);
        }

        $user->refresh();
        return response()->json([
            'success' => true,
            'data' => [
                'amount' => $amount,
                'balance' => (float)$user->wallet_balance,
                'reference_id' => $refId,
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        return response()->json(['success' => false, 'message' => 'Method not supported.'], 501);
    }

    public function update(Request $request, $id)
    {
        return response()->json(['success' => false, 'message' => 'Method not supported.'], 501);
    }

    public function destroy(Request $request, $id)
    {
        return response()->json(['success' => false, 'message' => 'Method not supported.'], 501);
    }
}

