<?php

namespace App\Services;

use App\Models\User;
use App\Models\EcommerceWalletTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WalletService
{
    /**
     * Adjust user's wallet balance centrally and log locally.
     *
     * @param int $userId
     * @param float $amount Absolute value of the amount
     * @param string $type Transaction type ('credit', 'debit', 'refund', 'affiliate_earned', 'affiliate_deduction', etc.)
     * @param string $description
     * @param string|null $referenceId
     * @return bool
     */
    public static function adjustWallet(int $userId, float $amount, string $type, string $description, ?string $referenceId = null): bool
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                Log::warning("WalletService: User ID {$userId} not found.");
                return false;
            }

            $typeLower = strtolower($type);
            $isCredit = in_array($typeLower, ['credit', 'deposit', 'refund', 'affiliate earned', 'affiliate_earned', 'affiliate credit', 'affiliate_credit', 'refund credit', 'refund_credit']);
            $centralType = $isCredit ? 'credit' : 'debit';

            $newBalance = null;

            // Check if central_auth DB connection is available in local environment
            if (config('app.env') === 'local' && config('database.connections.central_auth')) {
                try {
                    $newBalance = self::adjustCentralWalletDirectly($user->email, $type, $amount, $description, $referenceId);
                } catch (\Throwable $e) {
                    Log::warning("WalletService: Direct central DB adjustment skipped: " . $e->getMessage());
                }
            }

            // HTTP API call to Central Auth if URL is configured
            if ($newBalance === null && config('services.central_auth.url')) {
                try {
                    $centralUrl = rtrim(config('services.central_auth.url'), '/');
                    $secret = (string) config('services.internal_notifications.secret');

                    $response = Http::acceptJson()
                        ->withHeaders(['X-Internal-Notification-Secret' => $secret])
                        ->timeout(3)
                        ->post($centralUrl . '/v1/internal/admin/wallet/adjust', [
                            'email' => $user->email,
                            'type' => $centralType,
                            'amount' => abs($amount),
                            'description' => $description,
                            'reference_id' => $referenceId,
                        ]);

                    if ($response->successful()) {
                        $tx = $response->json('data');
                        $newBalance = (float) ($tx['balance_after'] ?? 0.00);
                    }
                } catch (\Throwable $e) {
                    Log::warning("WalletService: Central HTTP API call skipped: " . $e->getMessage());
                }
            }

            // Fallback: Adjust local user's wallet_balance directly if central auth is unreachable or unconfigured
            if ($newBalance === null) {
                $currentBalance = (float) ($user->wallet_balance ?? 0.00);
                if (!$isCredit && $currentBalance < $amount) {
                    Log::warning("WalletService: Insufficient balance for user ID {$userId}. Available: {$currentBalance}, Required: {$amount}");
                    return false;
                }
                $newBalance = $isCredit ? ($currentBalance + abs($amount)) : ($currentBalance - abs($amount));
            }

            // Update local user's wallet_balance
            $user->wallet_balance = $newBalance;
            $user->save();

            // Log local transaction
            EcommerceWalletTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $isCredit ? abs($amount) : -abs($amount),
                'balance_after' => $newBalance,
                'description' => $description,
                'status' => 'Completed',
                'reference_id' => $referenceId,
            ]);

            try {
                if ($user->email) {
                    $emailService = app(\App\Services\EmailNotificationService::class);
                    $payload = [
                        'customer_name' => $user->name,
                        'customer_email' => $user->email,
                        'amount' => '$' . number_format(abs($amount), 2),
                        'new_balance' => '$' . number_format($newBalance, 2),
                        'site_name' => config('app.name', 'Mecarvi Embroidery'),
                    ];

                    if ($isCredit) {
                        $emailService->sendEvent('customer_add_balance', $payload, $user->email);
                        $emailService->sendEvent('wallet_deposit', $payload, $user->email);
                    } else {
                        $emailService->sendEvent('customer_sub_balance', $payload, $user->email);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('WalletService email notification failed: ' . $e->getMessage());
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("WalletService: Exception in adjustWallet: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Directly adjust user's wallet balance in the central DB (for local/testing deadlock bypass).
     */
    private static function adjustCentralWalletDirectly(string $email, string $type, float $amount, string $description, ?string $referenceId = null): ?float
    {
        try {
            return \Illuminate\Support\Facades\DB::connection('central_auth')->transaction(function () use ($email, $type, $amount, $description, $referenceId) {
                $user = \Illuminate\Support\Facades\DB::connection('central_auth')->table('users')
                    ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
                    ->first();
                if (!$user) {
                    Log::warning("adjustCentralWalletDirectly: Central user not found for {$email}");
                    return null;
                }

                $wallet = \Illuminate\Support\Facades\DB::connection('central_auth')->table('central_wallets')
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    $walletId = \Illuminate\Support\Facades\DB::connection('central_auth')->table('central_wallets')->insertGetId([
                        'user_id' => $user->id,
                        'balance' => 0.00,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], 'id');
                    $wallet = (object)[
                        'id' => $walletId,
                        'balance' => 0.00,
                    ];
                }

                $isCredit = in_array(strtolower($type), ['credit', 'deposit', 'refund', 'affiliate earned', 'affiliate_earned', 'affiliate credit', 'affiliate_credit', 'refund credit', 'refund_credit']);
                $newBalance = (float)$wallet->balance;
                if ($isCredit) {
                    $newBalance += $amount;
                } else {
                    if ($newBalance < $amount) {
                        throw new \Exception('Insufficient wallet balance.');
                    }
                    $newBalance -= $amount;
                }

                \Illuminate\Support\Facades\DB::connection('central_auth')->table('central_wallets')
                    ->where('id', $wallet->id)
                    ->update([
                        'balance' => $newBalance,
                        'updated_at' => now(),
                    ]);

                \Illuminate\Support\Facades\DB::connection('central_auth')->table('central_wallet_transactions')->insert([
                    'central_wallet_id' => $wallet->id,
                    'type' => $isCredit ? 'credit' : 'debit',
                    'amount' => $amount,
                    'balance_after' => $newBalance,
                    'description' => $description,
                    'status' => 'completed',
                    'reference_id' => $referenceId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $newBalance;
            });
        } catch (\Throwable $e) {
            Log::error("adjustCentralWalletDirectly failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user's live wallet balance from Central Auth DB / API or local fallback.
     */
    public static function getWalletBalance(User $user, ?string $token = null): float
    {
        try {
            if (config('database.connections.central_auth')) {
                $cUser = \Illuminate\Support\Facades\DB::connection('central_auth')
                    ->table('users')
                    ->whereRaw('LOWER(email) = ?', [strtolower(trim($user->email))])
                    ->first();
                if ($cUser) {
                    $cWallet = \Illuminate\Support\Facades\DB::connection('central_auth')
                        ->table('central_wallets')
                        ->where('user_id', $cUser->id)
                        ->first();
                    if ($cWallet) {
                        $bal = (float) $cWallet->balance;
                        if ((float)$user->wallet_balance !== $bal) {
                            $user->wallet_balance = $bal;
                            $user->save();
                        }
                        return $bal;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("WalletService::getWalletBalance central DB lookup failed: " . $e->getMessage());
        }

        $defaultCentral = app()->environment('production') ? 'https://mecarvi.com/auth-api' : 'http://localhost:8001/api';
        $centralUrl = rtrim(config('services.central_auth.url', env('CENTRAL_AUTH_URL', $defaultCentral)), '/');
        if ($token) {
            try {
                $response = Http::acceptJson()
                    ->withToken($token)
                    ->timeout(3)
                    ->get($centralUrl . '/user/wallet');
                if ($response->successful()) {
                    $bal = (float) $response->json('data.balance');
                    if ((float)$user->wallet_balance !== $bal) {
                        $user->wallet_balance = $bal;
                        $user->save();
                    }
                    return $bal;
                }
            } catch (\Throwable $e) {
                Log::warning("WalletService::getWalletBalance HTTP API lookup failed: " . $e->getMessage());
            }
        }

        return (float) ($user->wallet_balance ?? 0.00);
    }
}
