<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;

class InternalNotificationController extends Controller
{
    public function __construct(private readonly EmailNotificationService $emails) {}

    public function userRegistered(Request $request)
    {
        $secret = (string) config('services.internal_notifications.secret');
        $provided = (string) $request->header('X-Internal-Notification-Secret');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
        ]);

        $logs = $this->emails->sendEvent('user_registered', [
            'customer_name' => $validated['name'],
            'customer_email' => $validated['email'],
            'site_name' => $validated['site_name'] ?? config('app.name', 'Mecarvi Embroidery'),
        ], $validated['email']);

        return response()->json([
            'success' => true,
            'data' => ['logs' => $logs],
        ]);
    }

    /**
     * Called by the Central Auth API when a new user registers via an affiliate referral link.
     * Looks up the affiliate by code, creates the referral record, and credits wallets.
     */
    public function referralRegistered(Request $request)
    {
        $secret = (string) config('services.internal_notifications.secret');
        $provided = (string) $request->header('X-Internal-Notification-Secret');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255'],
            'referral_code' => ['required', 'string', 'max:100'],
            'site_name'     => ['nullable', 'string', 'max:255'],
        ]);

        $referralCode = trim($validated['referral_code']);
        $newUserEmail = strtolower(trim($validated['email']));

        // Look up the affiliate by code (must be Active)
        $affiliate = \App\Models\EcommerceAffiliate::where('affiliate_code', $referralCode)
            ->where('status', 'Active')
            ->first();

        if (! $affiliate || ! $affiliate->user) {
            return response()->json([
                'success' => false,
                'message' => 'Affiliate code not found or inactive.',
            ], 404);
        }

        $referrer = $affiliate->user;

        // Find the newly registered user in our local DB.
        // NOTE: The new user registered in Central Auth — they may not yet exist in the Core API DB
        // (they only get synced here on first login). To guarantee the referee wallet credit fires
        // immediately, we create a local mirror user on-the-fly if one doesn't exist yet.
        $newUser = \App\Models\User::whereRaw('LOWER(email) = ?', [$newUserEmail])->first();

        if (! $newUser) {
            // Mirror the user locally so we can credit their wallet right now.
            // On their first login the sync process will update this row (same email = same record).
            try {
                $newUser = \App\Models\User::create([
                    'name'     => $validated['name'],
                    'email'    => $validated['email'],
                    'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                    'role'     => 'customer',
                ]);

                // Assign the Spatie 'customer' role (if it exists)
                try { $newUser->assignRole('customer'); } catch (\Exception $e) {}
            } catch (\Exception $e) {
                // If creation fails (e.g. race condition / duplicate), try fetching again
                $newUser = \App\Models\User::whereRaw('LOWER(email) = ?', [$newUserEmail])->first();
            }
        }

        // Get reward amounts from the dynamic affiliate settings
        $affiliateSettings = \App\Services\AffiliateCommissionService::settings();
        $rewardReferrer = \App\Services\AffiliateCommissionService::flag($affiliateSettings, 'earn_sign_referrer')
            ? (float) $affiliateSettings['referral_reward_referrer'] : 0.00;
        $rewardReferee = \App\Services\AffiliateCommissionService::flag($affiliateSettings, 'earn_sign_referee')
            ? (float) $affiliateSettings['referral_reward_referee'] : 0.00;

        if (! \App\Services\AffiliateCommissionService::flag($affiliateSettings, 'program_active')) {
            return response()->json(['success' => false, 'message' => 'Affiliate program is not active.'], 403);
        }
        if ($newUser && (int) $newUser->id === (int) $referrer->id) {
            return response()->json(['success' => false, 'message' => 'Self referrals are not allowed.'], 422);
        }

        // Avoid duplicate referral rows for the same (referrer, new-user email) pair
        $alreadyExists = \App\Models\EcommerceReferral::where('referrer_id', $referrer->id)
            ->when($newUser, fn($q) => $q->where('referred_id', $newUser->id))
            ->when(! $newUser, fn($q) => $q->where('referred_email', $newUserEmail))
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'success' => true,
                'message' => 'Referral already processed.',
            ]);
        }

        // Create referral log
        \App\Models\EcommerceReferral::create([
            'referrer_id'            => $referrer->id,
            'referred_id'            => $newUser?->id,
            'referred_email'         => $newUserEmail,
            'reward_amount_referrer' => $rewardReferrer,
            'reward_amount_referee'  => $rewardReferee,
            'ip_address'             => $request->input('ip_address') ?: $request->ip(),
        ]);

        // Update affiliate stats
        $affiliate->increment('total_referrals');
        if ($rewardReferrer > 0) {
            $affiliate->increment('total_earnings', $rewardReferrer);
        }

        // Credit referrer wallet
        if ($rewardReferrer > 0) {
            \App\Services\WalletService::adjustWallet(
                $referrer->id,
                $rewardReferrer,
                'Affiliate Earned',
                'Referral signup reward for inviting ' . $validated['name']
            );
        }

        // Credit new-user wallet — now always available since we mirror-created them above
        if ($rewardReferee > 0 && $newUser) {
            \App\Services\WalletService::adjustWallet(
                $newUser->id,
                $rewardReferee,
                'Affiliate Earned',
                'Welcome reward for joining via referral code ' . $referralCode
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Referral processed and rewards credited.',
        ]);
    }

    public function checkEmail(Request $request)
    {
        $secret = (string) config('services.internal_notifications.secret');
        $provided = (string) $request->header('X-Internal-Notification-Secret');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        $exists = \App\Models\User::whereRaw('LOWER(email) = ?', [$email])
            ->whereNotNull('password')
            ->exists();

        return response()->json([
            'success' => true,
            'exists' => $exists,
        ]);
    }

    public function fetchUserCredentials(Request $request)
    {
        $secret = (string) config('services.internal_notifications.secret');
        $provided = (string) $request->header('X-Internal-Notification-Secret');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        $user = \App\Models\User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'password' => $user->password,
                    'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toIso8601String() : null,
                ],
            ],
        ]);
    }

    public function verifyUserId(Request $request)
    {
        $secret = (string) config('services.internal_notifications.secret');
        $provided = (string) $request->header('X-Internal-Notification-Secret');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));
        $user = \App\Models\User::find($validated['user_id']);

        $valid = $user && strtolower(trim($user->email)) === $email;

        return response()->json([
            'success' => true,
            'valid' => $valid,
        ]);
    }
}
