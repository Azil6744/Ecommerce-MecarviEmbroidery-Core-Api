<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Support\PasswordValidationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    /**
     * Handle user registration.
     * 
     * Creates a new user account with the provided information.
     * By default, new users are assigned the 'viewer' role.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            // Validate the incoming request data
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => PasswordValidationRules::rules(),
                'referral_code' => ['sometimes', 'nullable', 'string'],
            ], PasswordValidationRules::messages());

            // Create the new user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'customer', // Default role for new registrations
            ]);

            // Assign the Spatie 'customer' role (if it exists)
            try {
                $user->assignRole('customer');
            } catch (\Exception $e) {
                // Role may not exist yet — skip silently
            }

            // Handle Referral logic
            if ($request->filled('referral_code')) {
                $referralCode = $request->input('referral_code');
                $affiliate = \App\Models\EcommerceAffiliate::where('affiliate_code', $referralCode)
                    ->where('status', 'Active')
                    ->first();

                if ($affiliate && $affiliate->user) {
                    $referrer = $affiliate->user;

                    // Get reward configurations from Site Settings
                    $settings = \App\Models\SiteSetting::first();
                    $rewardReferrer = $settings ? (float)$settings->referral_reward_referrer : 0.00;
                    $rewardReferee = $settings ? (float)$settings->referral_reward_referee : 0.00;

                    // Create referral log
                    \App\Models\EcommerceReferral::create([
                        'referrer_id' => $referrer->id,
                        'referred_id' => $user->id,
                        'reward_amount_referrer' => $rewardReferrer,
                        'reward_amount_referee' => $rewardReferee,
                    ]);

                    // Update affiliate stats
                    $affiliate->increment('total_referrals');
                    if ($rewardReferrer > 0) {
                        $affiliate->increment('total_earnings', $rewardReferrer);
                    }

                    // Credit Referrer Wallet
                    if ($rewardReferrer > 0) {
                        \App\Services\WalletService::adjustWallet(
                            $referrer->id,
                            $rewardReferrer,
                            'Affiliate Earned',
                            'Referral reward for inviting ' . $user->name
                        );
                    }

                    // Credit Referee (New User) Wallet
                    if ($rewardReferee > 0) {
                        \App\Services\WalletService::adjustWallet(
                            $user->id,
                            $rewardReferee,
                            'Affiliate Earned',
                            'Welcome reward for joining via referral code ' . $referralCode
                        );
                    }
                }
            }

            // Award signup bonus loyalty points if enabled
            try {
                $loyaltySettings = \App\Services\LoyaltyService::getSettings();
                if ($loyaltySettings['enabled']) {
                    $signupBonus = (int)($loyaltySettings['signup_bonus'] ?? 0);
                    if ($signupBonus > 0) {
                        // awardBonus() has built-in anti-duplication via reference_type + reference_id
                        \App\Services\LoyaltyService::awardBonus(
                            $user->id,
                            'signup_bonus',
                            $signupBonus,
                            'Welcome bonus for joining Mecarvi Gold Rewards.',
                            'signup',
                            (string) $user->id
                        );
                    }
                }
            } catch (\Throwable $loyaltyEx) {
                \Illuminate\Support\Facades\Log::warning('Signup loyalty bonus notice: ' . $loyaltyEx->getMessage());
            }


            $expirationMinutes = (int) config('sanctum.expiration', 360);
            $expiresAt = $expirationMinutes > 0 ? now()->addMinutes($expirationMinutes) : null;
            $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;

            // Trigger Welcome / Registration Email Notification
            try {
                $emailService = app(\App\Services\EmailNotificationService::class);
                $emailService->sendEvent('user_registered', [
                    'customer_name' => $user->name,
                    'customer_email' => $user->email,
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $user->email);

                if (!empty($rewardReferee) && $rewardReferee > 0) {
                    $emailService->sendEvent('customer_registration_bonus', [
                        'customer_name' => $user->name,
                        'customer_email' => $user->email,
                        'bonus_amount' => '$' . number_format($rewardReferee, 2),
                        'site_name' => config('app.name', 'Mecarvi Embroidery'),
                    ], $user->email);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Registration email notification error: ' . $e->getMessage());
            }

            // Return success response with user data and token
            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'email_verified_at' => $user->email_verified_at,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 201);
        } catch (ValidationException $e) {
            // Return validation errors
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // Return general error response
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during registration.',
            ], 500);
        }
    }
}
