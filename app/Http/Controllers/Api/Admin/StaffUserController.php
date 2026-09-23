<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StaffUserController extends Controller
{
    /**
     * Check if user is super admin (by column or Spatie role).
     */
    private function isSuperAdmin($user): bool
    {
        if (!$user) return false;
        if (in_array($user->role, ['super_admin', 'admin'])) {
            return true;
        }
        try {
            return $user->hasAnyRole(['super_admin', 'admin']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check permission with super_admin bypass.
     */
    private function checkPermission(Request $request, string $permission): mixed
    {
        $user = $request->user();
        if ($this->isSuperAdmin($user)) {
            return null;
        }
        try {
            if (!$user->hasPermissionTo($permission)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized. Insufficient permissions.'], 403);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Permission not configured.'], 403);
        }
        return null;
    }

    /**
     * List all staff members with KPI statistics and filters.
     */
    public function index(Request $request)
    {
        try {
            $denied = $this->checkPermission($request, 'view-users');
            if ($denied) return $denied;

            // Base query for staff/admin users (exclude pure customers unless they have a staff role)
            $query = User::with('roles')
                ->where('role', '!=', 'customer')
                ->orWhereHas('roles', function ($q) {
                    $q->where('name', '!=', 'customer');
                });

            // Calculate KPI Stats across all staff
            $allStaff = (clone $query)->get();
            $totalStaff = $allStaff->count();
            $activeStaff = $allStaff->where('status', 'active')->count();
            $inactiveStaff = $allStaff->where('status', 'inactive')->count();
            $pendingStaff = $allStaff->where('status', 'pending')->count();

            // Search filter
            if ($request->filled('search')) {
                $s = trim($request->search);
                $query->where(function ($q) use ($s) {
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('email', 'like', "%{$s}%")
                      ->orWhere('staff_id', 'like', "%{$s}%")
                      ->orWhere('job_title', 'like', "%{$s}%")
                      ->orWhere('department', 'like', "%{$s}%")
                      ->orWhere('phone', 'like', "%{$s}%");
                });
            }

            // Role filter
            if ($request->filled('role') && $request->role !== 'all') {
                $role = strtolower(trim(str_replace(' ', '_', $request->role)));
                $query->where(function ($q) use ($role) {
                    $q->where('role', $role)
                      ->orWhereHas('roles', function ($rq) use ($role) {
                          $rq->where('name', $role);
                      });
                });
            }

            // Department filter
            if ($request->filled('department') && $request->department !== 'all') {
                $query->where('department', $request->department);
            }

            // Status filter
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', strtolower($request->status));
            }

            // Sorting
            $sortBy = $request->get('sort', 'newest');
            if ($sortBy === 'newest') {
                $query->orderBy('created_at', 'desc');
            } elseif ($sortBy === 'oldest') {
                $query->orderBy('created_at', 'asc');
            } elseif ($sortBy === 'name_asc') {
                $query->orderBy('name', 'asc');
            } elseif ($sortBy === 'name_desc') {
                $query->orderBy('name', 'desc');
            } else {
                $query->orderBy('id', 'asc');
            }

            $perPage = $request->get('per_page', 50);
            $staff = $query->paginate($perPage);

            $staff->getCollection()->transform(function ($user) {
                // Primary role name
                $primaryRole = $user->roles->first()?->name ?? $user->role ?? 'staff';

                return [
                    'id'                 => $user->id,
                    'name'               => $user->name,
                    'email'              => $user->email,
                    'staff_id'           => $user->staff_id ?: 'STF-' . str_pad($user->id, 4, '0', STR_PAD_LEFT),
                    'department'         => $user->department ?: 'Operations',
                    'job_title'          => $user->job_title ?: 'Staff Member',
                    'role'               => $primaryRole,
                    'role_display'       => ucwords(str_replace('_', ' ', $primaryRole)),
                    'status'             => $user->status ?: 'active',
                    'phone'              => $user->phone ?: '',
                    'avatar'             => $user->avatar ?: '',
                    'two_factor_enabled' => (bool) $user->two_factor_enabled,
                    'access_expires_at'  => $user->access_expires_at?->format('Y-m-d') ?: null,
                    'created_at'         => $user->created_at?->format('M d, Y') ?: '',
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'staff' => $staff,
                    'stats' => [
                        'total_staff'    => $totalStaff,
                        'active_staff'   => $activeStaff,
                        'inactive_staff' => $inactiveStaff,
                        'pending_staff'  => $pendingStaff,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff members.',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Create a new staff member.
     */
    public function store(Request $request)
    {
        try {
            $denied = $this->checkPermission($request, 'add-edit-users');
            if ($denied) return $denied;

            $validated = $request->validate([
                'first_name'         => ['sometimes', 'string', 'max:120'],
                'last_name'          => ['sometimes', 'string', 'max:120'],
                'name'               => ['sometimes', 'string', 'max:255'],
                'email'              => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'phone'              => ['nullable', 'string', 'max:50'],
                'job_title'          => ['nullable', 'string', 'max:150'],
                'department'         => ['nullable', 'string', 'max:100'],
                'staff_id'           => ['nullable', 'string', 'max:50', 'unique:users,staff_id'],
                'role'               => ['required', 'string'],
                'status'             => ['sometimes', 'string', 'in:active,inactive,pending'],
                'two_factor_enabled' => ['sometimes', 'boolean'],
                'access_expires_at'  => ['nullable', 'date'],
                'password'           => ['sometimes', 'string', 'min:6'],
                'avatar'             => ['nullable', 'string'],
            ]);

            // Determine display name
            $fullName = $validated['name'] ?? trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));
            if (empty($fullName)) {
                $fullName = explode('@', $validated['email'])[0];
            }

            // Determine staff ID
            $staffId = $validated['staff_id'] ?? null;
            if (!$staffId) {
                $lastId = User::max('id') + 1;
                $staffId = 'STF-' . str_pad($lastId, 4, '0', STR_PAD_LEFT);
            }

            $roleName = strtolower(trim(str_replace(' ', '_', $validated['role'])));

            $user = User::create([
                'name'               => $fullName,
                'email'              => $validated['email'],
                'password'           => Hash::make($validated['password'] ?? 'Password123!'),
                'phone'              => $validated['phone'] ?? null,
                'job_title'          => $validated['job_title'] ?? 'Staff Member',
                'department'         => $validated['department'] ?? 'Operations',
                'staff_id'           => $staffId,
                'role'               => $roleName,
                'status'             => $validated['status'] ?? 'active',
                'two_factor_enabled' => $validated['two_factor_enabled'] ?? false,
                'access_expires_at'  => $validated['access_expires_at'] ?? null,
                'avatar'             => $validated['avatar'] ?? null,
            ]);

            // Assign Spatie Role
            $roleObj = Role::where('name', $roleName)->first();
            if ($roleObj) {
                $user->assignRole($roleObj);
            }

            return response()->json([
                'success' => true,
                'message' => 'Staff member created successfully.',
                'data'    => [
                    'user' => [
                        'id'                 => $user->id,
                        'name'               => $user->name,
                        'email'              => $user->email,
                        'staff_id'           => $user->staff_id,
                        'department'         => $user->department,
                        'job_title'          => $user->job_title,
                        'role'               => $user->role,
                        'status'             => $user->status,
                        'phone'              => $user->phone,
                        'two_factor_enabled' => (bool) $user->two_factor_enabled,
                        'access_expires_at'  => $user->access_expires_at,
                        'created_at'         => $user->created_at?->format('M d, Y'),
                    ],
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Staff member creation failed.',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Get single staff member details.
     */
    public function show(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'view-users');
            if ($denied) return $denied;

            $user = User::with('roles.permissions')->find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Staff member not found.'], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'user' => [
                        'id'                 => $user->id,
                        'name'               => $user->name,
                        'email'              => $user->email,
                        'staff_id'           => $user->staff_id,
                        'department'         => $user->department,
                        'job_title'          => $user->job_title,
                        'role'               => $user->role,
                        'status'             => $user->status,
                        'phone'              => $user->phone,
                        'two_factor_enabled' => (bool) $user->two_factor_enabled,
                        'access_expires_at'  => $user->access_expires_at,
                        'permissions'        => $user->getAllPermissions()->pluck('name')->toArray(),
                        'created_at'         => $user->created_at?->format('M d, Y'),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing staff member.
     */
    public function update(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'add-edit-users');
            if ($denied) return $denied;

            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Staff member not found.'], 404);
            }

            $validated = $request->validate([
                'name'               => ['sometimes', 'string', 'max:255'],
                'email'              => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $id],
                'phone'              => ['nullable', 'string', 'max:50'],
                'job_title'          => ['nullable', 'string', 'max:150'],
                'department'         => ['nullable', 'string', 'max:100'],
                'staff_id'           => ['nullable', 'string', 'max:50', 'unique:users,staff_id,' . $id],
                'role'               => ['sometimes', 'string'],
                'status'             => ['sometimes', 'string', 'in:active,inactive,pending'],
                'two_factor_enabled' => ['sometimes', 'boolean'],
                'access_expires_at'  => ['nullable', 'date'],
                'password'           => ['sometimes', 'nullable', 'string', 'min:6'],
            ]);

            if (isset($validated['name'])) $user->name = $validated['name'];
            if (isset($validated['email'])) $user->email = $validated['email'];
            if (isset($validated['phone'])) $user->phone = $validated['phone'];
            if (isset($validated['job_title'])) $user->job_title = $validated['job_title'];
            if (isset($validated['department'])) $user->department = $validated['department'];
            if (isset($validated['staff_id'])) $user->staff_id = $validated['staff_id'];
            if (isset($validated['status'])) $user->status = $validated['status'];
            if (isset($validated['two_factor_enabled'])) $user->two_factor_enabled = $validated['two_factor_enabled'];
            if (array_key_exists('access_expires_at', $validated)) $user->access_expires_at = $validated['access_expires_at'];

            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            if (isset($validated['role'])) {
                $roleName = strtolower(trim(str_replace(' ', '_', $validated['role'])));
                $user->role = $roleName;
                $user->syncRoles([$roleName]);
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Staff member updated successfully.',
                'data'    => ['user' => $user],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a staff member.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'delete-users');
            if ($denied) return $denied;

            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Staff member not found.'], 404);
            }

            // Prevent self-deletion
            if ($request->user() && $request->user()->id === $user->id) {
                return response()->json(['success' => false, 'message' => 'Cannot delete your own account.'], 403);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Staff member removed successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Quick toggle staff status (active, inactive, pending).
     */
    public function toggleStatus(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'activate-suspend-users');
            if ($denied) return $denied;

            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Staff member not found.'], 404);
            }

            $newStatus = $request->get('status');
            if (!$newStatus) {
                $newStatus = ($user->status === 'active') ? 'inactive' : 'active';
            }

            $user->status = $newStatus;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Staff status changed to ' . ucfirst($newStatus),
                'data'    => ['id' => $user->id, 'status' => $user->status],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reset staff member's password or trigger security reset.
     */
    public function resetPassword(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'reset-password-security');
            if ($denied) return $denied;

            $user = User::find($id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Staff member not found.'], 404);
            }

            $tempPassword = Str::random(10);
            $user->password = Hash::make($tempPassword);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully.',
                'data'    => ['temp_password' => $tempPassword],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
