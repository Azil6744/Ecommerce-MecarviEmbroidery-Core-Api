<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Cache;

class CentralAuthTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $mode = 'required')
    {
        $token = $this->extractBearerToken($request);
        $isOptional = strtolower($mode) === 'optional';

        if (! $token) {
            if ($isOptional) {
                return $next($request);
            }

            if (config('app.env') === 'local') {
                $devAdmin = User::whereIn('role', ['super_admin', 'admin', 'editor'])->first();
                if (! $devAdmin) {
                    $devAdmin = User::firstOrCreate(
                        ['email' => 'admin@mecarvi.com'],
                        [
                            'name' => 'Krista Calliste',
                            'username' => 'admin',
                            'password' => bcrypt('password'),
                            'role' => 'admin',
                        ]
                    );
                }
                if ($devAdmin) {
                    if (! $devAdmin->hasRole(['super_admin', 'admin', 'editor'])) {
                        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
                        $devAdmin->assignRole('admin');
                    }
                    $this->authenticateRequestAs($request, $devAdmin);
                    $request->attributes->set('central_auth_user', [
                        'id' => $devAdmin->id,
                        'name' => $devAdmin->name,
                        'email' => $devAdmin->email,
                        'role' => $devAdmin->role,
                    ]);
                    $request->attributes->set('central_auth_token', 'local-dev-token');
                    return $next($request);
                }
            }

            \Log::info('CentralAuthTokenMiddleware: No token found in request', [
                'authorization_header_present' => $request->headers->has('Authorization'),
                'x_central_auth_token_present' => $request->headers->has('X-Central-Auth-Token'),
                'http_authorization' => $request->server('HTTP_AUTHORIZATION') ? 'present' : 'missing',
                'redirect_http_authorization' => $request->server('REDIRECT_HTTP_AUTHORIZATION') ? 'present' : 'missing',
            ]);
            return response()->json([
                'message' => 'Unauthenticated. Please provide a valid central auth token.',
            ], 401);
        }

        \Log::info('CentralAuthTokenMiddleware: Token found in request');

        try {
            $centralUser = $this->validateAgainstCentralAuth($token);

            if (! $centralUser) {
                $localUser = $this->validateAgainstLocalSanctum($token);
                if ($localUser) {
                    if ($localUser->banned_at !== null) {
                        \Log::warning('CentralAuthTokenMiddleware: Banned user (local Sanctum) attempted access: ' . $localUser->email);
                        return response()->json([
                            'message' => 'Your account has been banned on this website.',
                        ], 403);
                    }

                    $this->authenticateRequestAs($request, $localUser);
                    $request->attributes->set('central_auth_user', [
                        'id' => $localUser->id,
                        'email' => $localUser->email,
                        'role' => $localUser->role,
                    ]);
                    $request->attributes->set('central_auth_token', $token);
                    \Log::info('CentralAuthTokenMiddleware: Token valid as local Sanctum token for ' . $localUser->email);

                    return $next($request);
                }

                if (config('app.env') === 'local' || app()->environment('local')) {
                    $devAdmin = User::whereIn('role', ['super_admin', 'admin', 'editor'])->first();
                    if (! $devAdmin) {
                        $devAdmin = User::firstOrCreate(
                            ['email' => 'admin@mecarvi.com'],
                            [
                                'name' => 'Krista Calliste',
                                'username' => 'admin',
                                'password' => bcrypt('password'),
                                'role' => 'admin',
                            ]
                        );
                    }
                    if ($devAdmin) {
                        if (! $devAdmin->hasRole(['super_admin', 'admin', 'editor'])) {
                            \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
                            $devAdmin->assignRole('admin');
                        }
                        $this->authenticateRequestAs($request, $devAdmin);
                        $request->attributes->set('central_auth_user', [
                            'id' => $devAdmin->id,
                            'name' => $devAdmin->name,
                            'email' => $devAdmin->email,
                            'role' => $devAdmin->role,
                        ]);
                        $request->attributes->set('central_auth_token', $token ?: 'local-dev-token');
                        \Log::info('CentralAuthTokenMiddleware: Falling back to local dev admin');
                        return $next($request);
                    }
                }

                return response()->json([
                    'message' => 'Unauthenticated. Central auth token is invalid or could not be verified.',
                ], 401);
            }

            \Log::info('CentralAuthTokenMiddleware: Token valid for ' . $centralUser['email']);
            $request->attributes->set('central_auth_user', $centralUser);
            $request->attributes->set('central_auth_token', $token);

            $email = strtolower(trim((string) $centralUser['email']));
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $user) {
                \Log::info('CentralAuthTokenMiddleware: Creating new local user for ' . $email);
                $userRole = $this->localRole($centralUser['role'] ?? null, $centralUser['account_type'] ?? null);
                $user = User::create([
                    'name' => $centralUser['name'] ?? 'User',
                    'email' => $email,
                    'username' => $this->availableUsername($centralUser['username'] ?? Str::before($email, '@')),
                    'password' => bcrypt(Str::random(16)),
                    'role' => $userRole,
                    'business_name' => $centralUser['business_name'] ?? null,
                    'business_type' => $centralUser['business_type'] ?? null,
                    'tax_id' => $centralUser['tax_id'] ?? null,
                    'address' => $centralUser['address'] ?? null,
                ]);

                // Assign Spatie Role for newly created user
                $roleName = $user->role;
                if ($roleName && in_array($roleName, ['super_admin', 'admin', 'editor', 'customer', 'seller', 'business'], true)) {
                    \Spatie\Permission\Models\Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
                    $user->assignRole($roleName);
                }
            } else {
                $this->syncLocalUserProfile($user, $centralUser);

                // Sync role if it changed or if Spatie role is missing
                $centralRoleName = $this->localRole($centralUser['role'] ?? null, $centralUser['account_type'] ?? null);
                if ($user->role !== $centralRoleName) {
                    $privilegedRoles = ['super_admin', 'admin'];
                    $isLocalPrivileged = in_array($user->role, $privilegedRoles, true);
                    $isCentralStandard = in_array($centralRoleName, ['customer', 'editor', 'seller', 'business'], true);

                    if (! ($isLocalPrivileged && $isCentralStandard)) {
                        $user->role = $centralRoleName;
                        $user->save();
                    }
                }

                if ($centralRoleName && !$user->hasRole($centralRoleName)) {
                    \Spatie\Permission\Models\Role::firstOrCreate(['name' => $centralRoleName, 'guard_name' => 'web']);
                    $user->assignRole($centralRoleName);
                }
            }

            if ($user && $user->banned_at !== null) {
                \Log::warning('CentralAuthTokenMiddleware: Banned user attempted access: ' . $user->email);
                return response()->json([
                    'message' => 'Your account has been banned on this website.',
                ], 403);
            }

            $this->linkGuestDataForUser($user);

            $this->authenticateRequestAs($request, $user);
            $this->linkSiteUserInCentralAuth($token, $user);
            \Log::info('CentralAuthTokenMiddleware: User authenticated for request');
        } catch (\Exception $e) {
            \Log::error('CentralAuthTokenMiddleware: Exception during validation: ' . $e->getMessage());

            if (config('app.env') === 'local' || app()->environment('local')) {
                $devAdmin = User::whereIn('role', ['super_admin', 'admin', 'editor'])->first();
                if ($devAdmin) {
                    if (! $devAdmin->hasRole(['super_admin', 'admin', 'editor'])) {
                        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
                        $devAdmin->assignRole('admin');
                    }
                    $this->authenticateRequestAs($request, $devAdmin);
                    $request->attributes->set('central_auth_user', [
                        'id' => $devAdmin->id,
                        'name' => $devAdmin->name,
                        'email' => $devAdmin->email,
                        'role' => $devAdmin->role,
                    ]);
                    $request->attributes->set('central_auth_token', $token ?: 'local-dev-token');
                    return $next($request);
                }
            }

            return response()->json([
                'message' => 'Unable to validate central auth token.',
            ], 401);
        }

        return $next($request);
    }

    private function authenticateRequestAs(Request $request, User $user): void
    {
        Auth::setUser($user);
        Auth::guard('sanctum')->setUser($user);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });
    }

    private function extractBearerToken(Request $request): ?string
    {
        $candidates = [
            $request->bearerToken(),
            $this->parseBearerValue($request->header('Authorization')),
            $this->parseBearerValue($request->server('HTTP_AUTHORIZATION')),
            $this->parseBearerValue($request->server('REDIRECT_HTTP_AUTHORIZATION')),
            $this->parseBearerValue($request->server('Authorization')),
            $request->header('X-Central-Auth-Token'),
            $request->header('X-Auth-Token'),
            $request->query('token'),
            $request->query('auth_token'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function validateAgainstLocalSanctum(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable instanceof User) {
            return null;
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        $expiration = config('sanctum.expiration');
        if ($expiration && $accessToken->created_at && $accessToken->created_at->lte(now()->subMinutes((int) $expiration))) {
            return null;
        }

        return $accessToken->tokenable;
    }

    private function syncLocalUserProfile(User $user, array $centralUser): void
    {
        $payload = [];

        foreach (['name', 'phone', 'address', 'business_name', 'business_type', 'tax_id'] as $field) {
            if (Schema::hasColumn($user->getTable(), $field) && array_key_exists($field, $centralUser) && !empty($centralUser[$field])) {
                $payload[$field] = $centralUser[$field];
            }
        }

        if (! empty($centralUser['username'])
            && Schema::hasColumn($user->getTable(), 'username')
            && $centralUser['username'] !== $user->username
            && ! User::where('username', $centralUser['username'])->whereKeyNot($user->id)->exists()
        ) {
            $payload['username'] = $centralUser['username'];
        }

        if ($payload) {
            $user->update($payload);
        }
    }

    private function availableUsername(string $username): string
    {
        $base = Str::slug($username, '_') ?: 'user';
        $candidate = $base;
        $suffix = 1;

        while (User::where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = $base . '_' . $suffix;
        }

        return $candidate;
    }

    private function localRole(?string $centralRole, ?string $accountType = null): string
    {
        $role = strtolower(trim((string) $centralRole));
        $type = strtolower(trim((string) $accountType));

        if ($type === 'business' || in_array($role, ['business', 'business_owner', 'business_user', 'company'], true)) {
            return 'business';
        }

        return in_array($role, ['super_admin', 'admin', 'editor', 'customer', 'seller', 'business'], true)
            ? $role
            : 'customer';
    }

    private function linkSiteUserInCentralAuth(string $token, User $user): void
    {
        // Avoid synchronous loopback deadlock in local/testing environments with single-threaded PHP web server
        if (in_array(config('app.env'), ['local', 'testing'])) {
            return;
        }

        $siteSlug = trim((string) config('services.mccarvy_site.slug', ''));
        if ($siteSlug === '') {
            return;
        }

        foreach ($this->centralAuthBaseUrls() as $centralAuthUrl) {
            try {
                $response = Http::withoutVerifying()->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'X-Central-Auth-Token' => $token,
                    'Accept' => 'application/json',
                ])->timeout(5)->post($centralAuthUrl . '/user/link-site', [
                    'site_slug' => $siteSlug,
                    'site_user_id' => $user->id,
                ]);

                if ($response->successful()) {
                    return;
                }
            } catch (\Throwable $e) {
                \Log::warning('CentralAuthTokenMiddleware: Failed linking site user', [
                    'site_slug' => $siteSlug,
                    'user_id' => $user->id,
                    'url' => $centralAuthUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function parseBearerValue(?string $headerValue): ?string
    {
        if (! $headerValue) {
            return null;
        }

        if (preg_match('/Bearer\s+(.+)/i', $headerValue, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);
        return $token !== '' ? $token : null;
    }

    private function validateAgainstCentralAuth(string $token): ?array
    {
        $cacheKey = 'central_auth_token:' . hash('sha256', $token);
        $cacheDuration = (int) config('services.central_auth.token_cache_seconds', 300);

        if ($cacheDuration > 0 && Cache::has($cacheKey)) {
            $cachedVal = Cache::get($cacheKey);
            if (is_array($cachedVal)) {
                \Log::info('CentralAuthTokenMiddleware: Using cached token validation result');
                return $cachedVal;
            }
            if ($cachedVal === 'invalid') {
                \Log::warning('CentralAuthTokenMiddleware: Using cached rejection for token');
                return null;
            }
        }

        foreach ($this->centralAuthBaseUrls() as $centralAuthUrl) {
            \Log::info('CentralAuthTokenMiddleware: Validating token against ' . $centralAuthUrl);

            try {
                $client = Http::withoutVerifying()->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])->timeout(10);

                $validateResponse = $client->post($centralAuthUrl . '/auth/validate-token', [
                    'token' => $token,
                    'site_slug' => config('services.mccarvy_site.slug', 'embroidery'),
                ]);
                $validatedUser = $this->extractValidatedUser($validateResponse);
                if ($validatedUser) {
                    if ($cacheDuration > 0) {
                        Cache::put($cacheKey, $validatedUser, $cacheDuration);
                    }
                    return $validatedUser;
                }

                $meResponse = $client->get($centralAuthUrl . '/auth/me');
                $meUser = $this->extractMeUser($meResponse);
                if ($meUser) {
                    if ($cacheDuration > 0) {
                        Cache::put($cacheKey, $meUser, $cacheDuration);
                    }
                    return $meUser;
                }

                \Log::warning('CentralAuthTokenMiddleware: Central auth rejected token', [
                    'url' => $centralAuthUrl,
                    'validate_status' => $validateResponse->status(),
                    'validate_body' => $validateResponse->json(),
                    'me_status' => $meResponse->status(),
                    'me_body' => $meResponse->json(),
                ]);
            } catch (\Throwable $e) {
                \Log::warning('CentralAuthTokenMiddleware: Central auth request failed', [
                    'url' => $centralAuthUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cache rejection for 60 seconds (or less if configured) to avoid spamming Central Auth
        if ($cacheDuration > 0) {
            Cache::put($cacheKey, 'invalid', min($cacheDuration, 60));
        }

        return null;
    }

    private function centralAuthBaseUrls(): array
    {
        $candidates = [
            config('services.central_auth.internal_url'),
            config('services.central_auth.url'),
            config('services.central_auth.api_url'),
            'https://auth-api.mecarvi.com/api',
            'http://127.0.0.1:8001/api',
            'http://127.0.0.1:8002/api',
            'http://localhost:8001/api',
            'http://localhost:8002/api',
        ];

        return array_values(array_unique(array_filter(array_map(function (?string $url) {
            if (! $url) {
                return null;
            }

            $normalized = rtrim($url, '/');
            return str_ends_with($normalized, '/api') ? $normalized : $normalized . '/api';
        }, $candidates))));
    }

    private function extractValidatedUser(Response $response): ?array
    {
        if (! $response->successful() || ! $response->json('valid')) {
            return null;
        }

        $payload = $response->json();
        if (empty($payload['email'])) {
            return null;
        }

        return $payload;
    }

    private function extractMeUser(Response $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (empty($payload['email'])) {
            return null;
        }

        return $payload;
    }

    private function linkGuestDataForUser(User $user): void
    {
        try {
            $email = strtolower(trim((string) $user->email));
            if ($email === '') {
                return;
            }

            // Link guest orders
            if (class_exists(\App\Models\EcommerceOrder::class)) {
                \App\Models\EcommerceOrder::whereNull('user_id')
                    ->whereRaw('LOWER(customer_email) = ?', [$email])
                    ->update(['user_id' => $user->id]);
            }

            // Link guest quotations
            if (class_exists(\App\Models\EcommerceQuotation::class)) {
                \App\Models\EcommerceQuotation::whereNull('user_id')
                    ->where(function ($query) use ($email) {
                        $query->whereRaw('LOWER(customer_email) = ?', [$email])
                              ->orWhereRaw('LOWER(contact_email) = ?', [$email]);
                    })
                    ->update(['user_id' => $user->id]);
            }

            // Link guest tickets
            if (class_exists(\App\Models\EcommerceTicket::class)) {
                \App\Models\EcommerceTicket::whereNull('user_id')
                    ->whereRaw('LOWER(contact_email) = ?', [$email])
                    ->update(['user_id' => $user->id]);
            }

            // Link guest disputes
            if (class_exists(\App\Models\EcommerceDispute::class)) {
                \App\Models\EcommerceDispute::whereNull('user_id')
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->update(['user_id' => $user->id]);
            }

            // Link order verifications
            if (class_exists(\App\Models\EcommerceOrderVerification::class)) {
                \App\Models\EcommerceOrderVerification::whereNull('user_id')
                    ->whereHas('order', function ($q) use ($user) {
                        $q->where('user_id', $user->id);
                    })
                    ->update(['user_id' => $user->id]);
            }
            
            // Link guest/pending referrals
            if (class_exists(\App\Models\EcommerceReferral::class)) {
                $pendingReferrals = \App\Models\EcommerceReferral::whereNull('referred_id')
                    ->whereRaw('LOWER(referred_email) = ?', [$email])
                    ->get();

                foreach ($pendingReferrals as $referral) {
                    $referral->update(['referred_id' => $user->id]);

                    if ($referral->reward_amount_referee > 0) {
                        \App\Services\WalletService::adjustWallet(
                            $user->id,
                            (float)$referral->reward_amount_referee,
                            'Affiliate Earned',
                            'Welcome reward for joining via referral code'
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::error('CentralAuthTokenMiddleware: Error linking guest data: ' . $e->getMessage());
        }
    }
}
