<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminCustomerDirectoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin Customer Management + Business Management directories.
 *
 * GET   /admin/customer-directory?audience=customer|business&search&status&verification&flag&from&to&page&per_page
 * POST  /admin/business-users                    create a business account
 * PATCH /admin/customer-directory/{id}/security  phone verification, password/recovery requirements, risk level
 */
class AdminCustomerDirectoryController extends Controller
{
    public function __construct(private AdminCustomerDirectoryService $service)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'audience' => ['nullable', 'in:customer,business'],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:all,active,deactivated,banned'],
            'verification' => ['nullable', 'in:all,verified,unverified,email_unverified,phone_unverified'],
            'flag' => ['nullable', 'in:' . implode(',', AdminCustomerDirectoryService::FLAGS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->directory($data),
        ]);
    }

    public function storeBusiness(Request $request)
    {
        try {
            $data = $request->validate([
                'business_name' => ['required', 'string', 'max:255'],
                'owner_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'phone' => ['nullable', 'string', 'max:100'],
                'address' => ['nullable', 'string', 'max:500'],
                'job_title' => ['nullable', 'string', 'max:100'],
                'business_type' => ['nullable', 'string', 'max:100'],
                'password' => ['nullable', 'string', 'min:8'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        $user = User::create([
            'name' => $data['owner_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'business_name' => $data['business_name'],
            'business_type' => $data['business_type'] ?? null,
            'job_title' => $data['job_title'] ?? 'Owner',
            'role' => 'business',
            'password' => Hash::make($data['password'] ?? Str::random(24)),
            // No password was shared with the owner, so they must set one on first sign-in.
            'password_update_required_at' => empty($data['password']) ? Carbon::now() : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Business created successfully.',
            'data' => ['id' => $user->id],
        ], 201);
    }

    public function updateSecurity(Request $request, $id)
    {
        $data = $request->validate([
            'phone_verified' => ['sometimes', 'boolean'],
            'password_update_required' => ['sometimes', 'boolean'],
            'account_recovery_required' => ['sometimes', 'boolean'],
            'risk_level' => ['sometimes', 'nullable', 'in:low,medium,high'],
            'risk_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }

        $map = [
            'phone_verified' => 'phone_verified_at',
            'password_update_required' => 'password_update_required_at',
            'account_recovery_required' => 'account_recovery_required_at',
        ];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $user->{$column} = $data[$input] ? ($user->{$column} ?: Carbon::now()) : null;
            }
        }
        if (array_key_exists('risk_level', $data)) {
            $user->risk_level = $data['risk_level'];
        }
        if (array_key_exists('risk_reason', $data)) {
            $user->risk_reason = $data['risk_reason'];
        }
        $user->save();

        return response()->json(['success' => true, 'message' => 'Account security updated.']);
    }
}
