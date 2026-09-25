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

            // Fetch local transactions for metrics and fallback
            $localTx = EcommerceWalletTransaction::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $credits = (float) $localTx->filter(fn ($t) => in_array(strtolower($t->type ?? ''), [
                'credit', 'deposit', 'refund', 'affiliate earned', 'affiliate_earned'
            ]))->sum('amount');

            $debits = (float) $localTx->filter(fn ($t) => !in_array(strtolower($t->type ?? ''), [
                'credit', 'deposit', 'refund', 'affiliate earned', 'affiliate_earned'
            ]))->sum(fn ($t) => abs((float)$t->amount));

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'available_balance' => $balance,
                    'usable_balance' => $balance,
                    'credits' => $credits,
                    'debits' => $debits,
                    'transactions_count' => $localTx->count(),
                    'last_updated' => now()->toIso8601String(),
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
            ],
        ]);
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

        if (!$user && $request->filled('email')) {
            $email = strtolower(trim($request->input('email')));
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if (!$user) {
                $user = User::create([
                    'name' => $request->input('name') ?: explode('@', $email)[0],
                    'email' => $email,
                    'password' => bcrypt(str()->random(24)),
                    'role' => 'customer',
                    'wallet_balance' => 0.00,
                ]);
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please log in or provide an email to add funds to your wallet.',
            ], 401);
        }

        $method = $request->input('payment_method', 'card');
        $cardLast4 = $request->input('card_last4');
        $cardBrand = $request->input('card_brand', 'Card');
        $refId = $request->input('reference_id') ?: ('TOPUP-' . strtoupper(uniqid()));

        $desc = $request->input('description');
        if (!$desc) {
            if ($method === 'card') {
                $desc = 'Added Funds via ' . $cardBrand . ($cardLast4 ? " **** {$cardLast4}" : '');
            } elseif ($method === 'paypal') {
                $desc = 'Added Funds via PayPal';
            } else {
                $desc = 'Added Funds to Wallet';
            }
        }

        $success = WalletService::adjustWallet($user->id, $amount, 'deposit', $desc, $refId);

        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to credit funds to wallet. Please try again.',
            ], 500);
        }

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
                    ->timeout(3)
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

