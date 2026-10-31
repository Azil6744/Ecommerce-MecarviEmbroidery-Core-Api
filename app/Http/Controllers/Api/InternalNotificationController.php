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

        $referralCode = strtoupper(trim($validated['referral_code']));
        $newUserEmail = strtolower(trim($validated['email']));

        // Look up the affiliate by code (case-insensitive, must be Active)
        $affiliate = \App\Models\EcommerceAffiliate::whereRaw('UPPER(affiliate_code) = ?', [$referralCode])
            ->where('status', 'Active')
            ->first();

        if (! $affiliate || ! $affiliate->user) {
            return response()->json([
                'success' => false,
                'message' => 'Affiliate code not found or inactive.',
            ], 404);
        }

        $referrer = $affiliate->user;

        $affiliateSettings = \App\Services\AffiliateCommissionService::settings();
        if (! \App\Services\AffiliateCommissionService::flag($affiliateSettings, 'program_active')) {
            return response()->json(['success' => false, 'message' => 'Affiliate program is not active.'], 403);
        }

        // Self-referral guard, including +alias and Gmail dot tricks.
        $normalizeEmail = function (string $e): string {
            [$local, $domain] = array_pad(explode('@', strtolower(trim($e)), 2), 2, '');
            $local = explode('+', $local)[0];
            if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
                $local = str_replace('.', '', $local);
            }
            return $local . '@' . $domain;
        };
        if ($normalizeEmail((string) $referrer->email) === $normalizeEmail($newUserEmail)) {
            return response()->json(['success' => false, 'message' => 'Self referrals are not allowed.'], 422);
        }

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

        if ($newUser && (int) $newUser->id === (int) $referrer->id) {
            return response()->json(['success' => false, 'message' => 'Self referrals are not allowed.'], 422);
        }

        // Signup rewards (both sides) come from the dynamic affiliate settings.
        $rewardReferrer = \App\Services\AffiliateCommissionService::flag($affiliateSettings, 'earn_sign_referrer')
            ? (float) $affiliateSettings['referral_reward_referrer'] : 0.00;
        $rewardReferee = \App\Services\AffiliateCommissionService::flag($affiliateSettings, 'earn_sign_referee')
            ? (float) $affiliateSettings['referral_reward_referee'] : 0.00;

        // Fraud & security: a flagged signup is still recorded, but earns no signup reward.
        $ip = $request->input('ip_address') ?: $request->ip();
        $fraud = null;
        if (\App\Services\AffiliateCommissionService::flag($affiliateSettings, 'require_unique_ip') && $ip
            && \App\Models\EcommerceReferral::where('referrer_id', $referrer->id)->where('ip_address', $ip)->exists()) {
            $fraud = 'Multiple referrals from the same IP address';
        } elseif (\App\Services\AffiliateCommissionService::flag($affiliateSettings, 'monitor_suspicious')
            && \App\Models\EcommerceReferral::where('referrer_id', $referrer->id)->where('created_at', '>=', now()->subDay())->count() >= 20) {
            $fraud = 'Suspicious activity: too many referrals in 24 hours';
        }
        if ($fraud) {
            \Illuminate\Support\Facades\Log::warning("Affiliate signup fraud check: {$fraud} (referrer {$referrer->id}, email {$newUserEmail})");
            $rewardReferrer = 0.00;
            $rewardReferee = 0.00;
        }

        // A user can only ever be referred once globally, by the first valid referrer.
        $alreadyReferred = \App\Models\EcommerceReferral::where(function ($q) use ($newUser, $newUserEmail) {
            $q->where('referred_email', $newUserEmail);
            if ($newUser) {
                $q->orWhere('referred_id', $newUser->id);
            }
        })->exists();

        if ($alreadyReferred) {
            return response()->json([
                'success' => true,
                'message' => 'User was already referred.',
            ]);
        }

        // Create the referral under a lock.
        $referral = \Illuminate\Support\Facades\DB::transaction(function () use ($referrer, $newUser, $newUserEmail, $ip) {
            $existing = \App\Models\EcommerceReferral::where(function ($q) use ($newUser, $newUserEmail) {
                $q->where('referred_email', $newUserEmail);
                if ($newUser) {
                    $q->orWhere('referred_id', $newUser->id);
                }
            })
            ->lockForUpdate()
            ->exists();

            if ($existing) {
                return null;
            }

            return \App\Models\EcommerceReferral::create([
                'referrer_id'            => $referrer->id,
                'referred_id'            => $newUser?->id,
                'referred_email'         => $newUserEmail,
                'reward_amount_referrer' => 0,
                'reward_amount_referee'  => 0,
                'ip_address'             => $ip,
            ]);
        });

        if (! $referral) {
            return response()->json([
                'success' => true,
                'message' => 'Referral already processed.',
            ]);
        }

        $affiliate->increment('total_referrals');

        // Credit referrer wallet. Stats and the stored reward only reflect money actually credited.
        $creditedReferrer = 0.00;
        if ($rewardReferrer > 0) {
            $ok = \App\Services\WalletService::adjustWallet(
                $referrer->id,
                $rewardReferrer,
                'Affiliate Earned',
                'Referral signup reward for inviting ' . $validated['name'],
                'referral-signup-' . $referral->id . '-referrer'
            );
            if ($ok) {
                $creditedReferrer = $rewardReferrer;
                $affiliate->increment('total_earnings', $rewardReferrer);
            } else {
                \Illuminate\Support\Facades\Log::error("Referral {$referral->id}: referrer signup credit failed.");
            }
        }

        // Credit new-user wallet now if they exist locally; otherwise it is credited on first login
        // (CentralAuthTokenMiddleware) using the planned amount stored on the referral.
        $storedReferee = $rewardReferee;
        if ($rewardReferee > 0 && $newUser) {
            $ok = \App\Services\WalletService::adjustWallet(
                $newUser->id,
                $rewardReferee,
                'Affiliate Earned',
                'Welcome reward for joining via referral code ' . $referralCode,
                'referral-signup-' . $referral->id . '-referee'
            );
            if (! $ok) {
                \Illuminate\Support\Facades\Log::error("Referral {$referral->id}: referee welcome credit failed.");
                $storedReferee = 0.00;
            }
        }

        $referral->update([
            'reward_amount_referrer' => $creditedReferrer,
            'reward_amount_referee'  => $storedReferee,
        ]);

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
