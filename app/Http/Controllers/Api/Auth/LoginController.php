<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Handle user login.
     * 
     * Authenticates a user with email and password and returns a Sanctum token.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        try {
            // Validate the incoming request data
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            // Find the user by email with roles and permissions
            $user = User::with(['roles.permissions', 'permissions'])->where('email', $request->email)->first();

            // Check if user exists and password is correct
            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials.',
                ], 401);
            }

            // Check if user is deactivated or suspended
            if (in_array(strtolower($user->status ?? ''), ['inactive', 'suspended'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is deactivated or suspended. Please contact an administrator.',
                ], 403);
            }

            // Check if user's access has expired
            if ($user->access_expires_at && $user->access_expires_at->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account access has expired. Please contact an administrator.',
                ], 403);
            }

            // Determine user primary role and permissions
            $primaryRole = $user->roles->first()?->name ?? $user->role ?? 'customer';
            $isSuperAdmin = in_array(strtolower($primaryRole), ['super_admin', 'admin']) || strtolower($user->role ?? '') === 'super_admin';

            $roleModel = \Spatie\Permission\Models\Role::with('permissions')->where('name', $primaryRole)->first();
            $isRoleActive = $roleModel ? (bool)($roleModel->status ?? true) : true;

            $permissions = [];
            if ($isSuperAdmin) {
                try {
                    $permissions = \Spatie\Permission\Models\Permission::pluck('name')->toArray();
                } catch (\Throwable $e) {
                    $permissions = [];
                }
                if (!in_array('*', $permissions)) {
                    $permissions[] = '*';
                }
            } elseif ($isRoleActive) {
                $rolePerms = $roleModel ? $roleModel->permissions->pluck('name')->toArray() : [];
                $directPerms = [];
                try {
                    $directPerms = $user->permissions ? $user->permissions->pluck('name')->toArray() : [];
                } catch (\Throwable $e) {
                    $directPerms = [];
                }
                $permissions = array_values(array_unique(array_merge($rolePerms, $directPerms)));
            }

            // Generate a Sanctum token for the authenticated user
            $expirationMinutes = (int) config('sanctum.expiration', 360);
            $expiresAt = $expirationMinutes > 0 ? now()->addMinutes($expirationMinutes) : null;
            $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;

            // Return success response with user data and token
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $primaryRole,
                        'role_display' => ucwords(str_replace('_', ' ', $primaryRole)),
                        'status' => $user->status ?: 'active',
                        'is_super_admin' => $isSuperAdmin,
                        'department' => $user->department ?: 'Operations',
                        'job_title' => $user->job_title ?: 'Staff Member',
                        'staff_id' => $user->staff_id ?: ('STF-' . str_pad($user->id, 4, '0', STR_PAD_LEFT)),
                        'phone' => $user->phone ?: '',
                        'avatar' => $user->avatar ?: '',
                        'permissions' => $permissions,
                        'email_verified_at' => $user->email_verified_at,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
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
                'message' => 'Login failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during login.',
            ], 500);
        }
    }

    /**
     * Handle forgot password request.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();
        if ($user) {
            try {
                $token = \Illuminate\Support\Str::random(60);
                \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $user->email],
                    [
                        'token' => \Illuminate\Support\Facades\Hash::make($token),
                        'created_at' => now(),
                    ]
                );

                $resetUrl = url('/reset-password?token=' . $token . '&email=' . urlencode($user->email));

                app(\App\Services\EmailNotificationService::class)->sendEvent('forgot_password', [
                    'customer_name' => $user->name,
                    'customer_email' => $user->email,
                    'reset_link' => $resetUrl,
                    'expiry_minutes' => 60,
                    'site_name' => config('app.name', 'Mecarvi Embroidery'),
                ], $user->email);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Forgot password email failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account exists with that email, a password reset link has been sent.',
        ]);
    }
}
