<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\User;

class RolePermissionController extends Controller
{
    /**
     * Check if user is super admin (by column or Spatie role).
     */
    private function isSuperAdmin($user): bool
    {
        if (!$user) return false;
        if ($user->role === 'super_admin') {
            return true;
        }
        try {
            return $user->hasRole('super_admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check permission with super_admin bypass and graceful fallback.
     */
    private function checkPermission(Request $request, string $permission): mixed
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if ($this->isSuperAdmin($user)) {
            return null; // allowed
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

    // ─── ROLES ───────────────────────────────────────────────

    /**
     * List all roles with their permissions, user details, and KPI statistics.
     */
    public function indexRoles(Request $request)
    {
        try {
            $denied = $this->checkPermission($request, 'view-roles');
            if ($denied) return $denied;

            // Fetch all roles with permissions
            $roles = Role::with('permissions')
                ->where('name', '!=', 'customer')
                ->get()
                ->map(function ($role) {
                    // Get assigned staff users
                    $assignedUsers = User::whereHas('roles', function ($q) use ($role) {
                        $q->where('roles.id', $role->id);
                    })
                    ->orWhere('role', $role->name)
                    ->get(['id', 'name', 'email', 'avatar', 'staff_id', 'department', 'job_title', 'status']);

                    // Count unique modules this role has access to
                    $moduleAccessCount = $role->permissions
                        ->pluck('module_id')
                        ->filter()
                        ->unique()
                        ->count();

                    return [
                        'id'                  => $role->id,
                        'name'                => $role->name,
                        'display_name'        => ucwords(str_replace('_', ' ', $role->name)),
                        'guard_name'          => $role->guard_name,
                        'description'         => $role->description ?? '',
                        'status'              => (bool) ($role->status ?? true),
                        'color'               => $role->color ?? 'blue',
                        'permissions'         => $role->permissions->pluck('name')->toArray(),
                        'permissions_count'   => $role->permissions->count(),
                        'modules_count'       => $moduleAccessCount,
                        'users_count'         => $assignedUsers->count(),
                        'users'               => $assignedUsers,
                        'created_at'          => $role->created_at,
                        'updated_at'          => $role->updated_at,
                    ];
                });

            // Calculate KPI Stats
            $totalRoles = $roles->count();
            $activeRoles = $roles->where('status', true)->count();
            $inactiveRoles = $roles->where('status', false)->count();

            return response()->json([
                'success' => true,
                'message' => 'Roles retrieved successfully',
                'data'    => [
                    'roles' => $roles->values(),
                    'stats' => [
                        'total_roles'    => $totalRoles,
                        'active_roles'   => $activeRoles,
                        'inactive_roles' => $inactiveRoles,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve roles.',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Get a single role's complete details.
     */
    public function showRole(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'view-roles');
            if ($denied) return $denied;

            $role = is_numeric($id) ? Role::with('permissions')->find($id) : null;
            if (!$role) {
                $role = Role::with('permissions')->where('name', $id)->first();
            }
            if (!$role) {
                return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
            }

            $assignedUsers = User::whereHas('roles', function ($q) use ($role) {
                $q->where('roles.id', $role->id);
            })
            ->orWhere('role', $role->name)
            ->get(['id', 'name', 'email', 'avatar', 'staff_id', 'department', 'job_title', 'status']);

            return response()->json([
                'success' => true,
                'data'    => [
                    'role' => [
                        'id'                => $role->id,
                        'name'              => $role->name,
                        'display_name'      => ucwords(str_replace('_', ' ', $role->name)),
                        'description'       => $role->description,
                        'status'            => (bool) ($role->status ?? true),
                        'color'             => $role->color,
                        'permissions'       => $role->permissions->pluck('name')->toArray(),
                        'permissions_count' => $role->permissions->count(),
                        'users'             => $assignedUsers,
                        'created_at'        => $role->created_at,
                        'updated_at'        => $role->updated_at,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new custom role with permissions.
     */
    public function storeRole(Request $request)
    {
        try {
            $denied = $this->checkPermission($request, 'add-edit-roles');
            if ($denied) return $denied;

            $validated = $request->validate([
                'name'          => ['required', 'string', 'max:255', 'unique:roles,name'],
                'description'   => ['nullable', 'string', 'max:500'],
                'status'        => ['sometimes', 'boolean'],
                'color'         => ['nullable', 'string', 'max:50'],
                'permissions'   => ['sometimes', 'array'],
                'permissions.*' => ['string'],
            ]);

            $role = new Role();
            $role->name = strtolower(trim(str_replace(' ', '_', $validated['name'])));
            $role->guard_name = 'web';
            $role->description = $validated['description'] ?? '';
            $role->status = $validated['status'] ?? true;
            $role->color = $validated['color'] ?? 'blue';
            $role->save();

            if (!empty($validated['permissions'])) {
                $rawPerms = (array) $validated['permissions'];
                $normalized = [];
                foreach ($rawPerms as $p) {
                    $normalized[] = $p;
                    if (str_contains($p, ':')) {
                        $parts = explode(':', $p);
                        $normalized[] = end($parts);
                    }
                }
                $toSync = [];
                foreach (array_unique($normalized) as $pName) {
                    if (!empty($pName)) {
                        Permission::firstOrCreate(['name' => $pName, 'guard_name' => 'web']);
                        $toSync[] = $pName;
                    }
                }
                $role->syncPermissions($toSync);
            }

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data'    => [
                    'role' => [
                        'id'                => $role->id,
                        'name'              => $role->name,
                        'description'       => $role->description,
                        'status'            => (bool) $role->status,
                        'color'             => $role->color,
                        'permissions'       => $role->permissions->pluck('name')->toArray(),
                        'permissions_count' => $role->permissions->count(),
                    ],
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role creation failed.',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Update a role's name, description, status, and permissions.
     */
    public function updateRole(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'add-edit-roles');
            if ($denied) return $denied;

            $role = is_numeric($id) ? Role::find($id) : null;
            if (!$role) {
                $role = Role::where('name', $id)->first();
            }
            if (!$role && $request->filled('name')) {
                $formattedName = strtolower(trim(str_replace(' ', '_', $request->input('name'))));
                $role = Role::where('name', $formattedName)->first();
            }
            if (!$role) {
                $role = new Role();
                $role->name = strtolower(trim(str_replace(' ', '_', $request->input('name', (string)$id))));
                $role->guard_name = 'web';
                $role->description = $request->input('description', '');
                $role->status = $request->input('status', true);
                $role->color = $request->input('color', 'blue');
                $role->save();
            }

            $validated = $request->validate([
                'name'          => ['sometimes', 'required', 'string', 'max:255', 'unique:roles,name,' . $role->id],
                'description'   => ['nullable', 'string', 'max:500'],
                'status'        => ['sometimes', 'boolean'],
                'color'         => ['nullable', 'string', 'max:50'],
                'permissions'   => ['sometimes', 'array'],
                'permissions.*' => ['string'],
            ]);

            // Protect changing system names for built-in super_admin
            if (isset($validated['name']) && $role->name !== 'super_admin') {
                $role->name = strtolower(trim(str_replace(' ', '_', $validated['name'])));
            }

            if (array_key_exists('description', $validated)) {
                $role->description = $validated['description'];
            }
            if (array_key_exists('status', $validated)) {
                $role->status = (bool) $validated['status'];
            }
            if (array_key_exists('color', $validated)) {
                $role->color = $validated['color'];
            }

            $role->save();

            if (isset($validated['permissions'])) {
                $rawPerms = (array) $validated['permissions'];
                $normalized = [];
                foreach ($rawPerms as $p) {
                    $normalized[] = $p;
                    if (str_contains($p, ':')) {
                        $parts = explode(':', $p);
                        $normalized[] = end($parts);
                    }
                }
                $toSync = [];
                foreach (array_unique($normalized) as $pName) {
                    if (!empty($pName)) {
                        Permission::firstOrCreate(['name' => $pName, 'guard_name' => 'web']);
                        $toSync[] = $pName;
                    }
                }
                $role->syncPermissions($toSync);
            }

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data'    => [
                    'role' => [
                        'id'                => $role->id,
                        'name'              => $role->name,
                        'description'       => $role->description,
                        'status'            => (bool) $role->status,
                        'color'             => $role->color,
                        'permissions'       => $role->permissions->pluck('name')->toArray(),
                        'permissions_count' => $role->permissions->count(),
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role update failed.',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Toggle a role's status (active/inactive).
     */
    public function toggleRoleStatus(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'add-edit-roles');
            if ($denied) return $denied;

            $role = is_numeric($id) ? Role::find($id) : null;
            if (!$role) {
                $role = Role::where('name', $id)->first();
            }
            if (!$role) {
                return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
            }

            if ($role->name === 'super_admin') {
                return response()->json(['success' => false, 'message' => 'Cannot deactivate Super Admin role.'], 403);
            }

            $role->status = !$role->status;
            $role->save();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            return response()->json([
                'success' => true,
                'message' => 'Role status updated to ' . ($role->status ? 'Active' : 'Inactive'),
                'data'    => [
                    'id'     => $role->id,
                    'status' => (bool) $role->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a custom role (protect core roles).
     */
    public function destroyRole(Request $request, $id)
    {
        try {
            $denied = $this->checkPermission($request, 'delete-roles');
            if ($denied) return $denied;

            $role = is_numeric($id) ? Role::find($id) : null;
            if (!$role) {
                $role = Role::where('name', $id)->first();
            }
            if (!$role) {
                return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
            }

            $protected = ['super_admin', 'admin', 'editor', 'customer', 'staff', 'viewer', 'manager'];
            if (in_array($role->name, $protected)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete protected system role "' . $role->name . '".',
                ], 403);
            }

            $role->delete();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Role deletion failed.',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    // ─── PERMISSIONS & MATRIX ────────────────────────────────

    /**
     * List all 25 modules with sub-permissions and access status per role for the matrix table.
     */
    public function indexPermissions(Request $request)
    {
        try {
            $denied = $this->checkPermission($request, 'view-roles');
            if ($denied) return $denied;

            // Fetch all permissions grouped by module_id
            $permissions = Permission::all();
            $roles = Role::with('permissions')
                ->where('name', '!=', 'customer')
                ->get();

            // Create a lookup map: [role_name => [perm_name => true]]
            $rolePermMap = [];
            foreach ($roles as $r) {
                $rolePermMap[$r->name] = $r->permissions->pluck('name')->flip()->toArray();
            }

            // Group permissions by module_id
            $modulesOrder = [
                'staff-management'           => 'Staff Management',
                'user-management'            => 'User Management',
                'products-management'        => 'Products Management',
                'product-moderation'         => 'Product Moderation',
                'fulfillment-management'     => 'Fulfillment Management',
                'orders-management'          => 'Orders Management',
                'quotation-management'       => 'Quotation Management',
                'sales-crm-management'       => 'Sales & CRM Management',
                'support-management'         => 'Support Management',
                'marketing-management'       => 'Marketing Management',
                'membership-management'      => 'Membership Management',
                'loyalty-management'         => 'Loyalty Management',
                'gift-cards-management'      => 'Gift Cards Management',
                'affiliates-management'      => 'Affiliates Management',
                'content-management'         => 'Content Management',
                'voucher-management'         => 'Voucher Management',
                'financing-management'       => 'Financing Management',
                'accounting-management'      => 'Accounting Management',
                'vendors-management'         => 'Vendors Management',
                'asset-management'           => 'Asset Management',
                'file-management'            => 'File Management',
                'hr-management'              => 'HR Management',
                'donations-management'       => 'Donations Management',
                'knowledge-base-management'  => 'Knowledge Base Management',
                'blog-management'            => 'Blog Management',
                'workspace-management'       => 'Workspace Management',
                'reports-management'         => 'Reports Management',
                'settings'                   => 'Settings',
            ];

            $modulesList = [];

            foreach ($modulesOrder as $modId => $modName) {
                $modPerms = $permissions->filter(function ($p) use ($modId) {
                    $pMod = $p->module_id;
                    // Legacy module ID aliases mapping
                    if ($modId === 'staff-management') {
                        return $pMod === 'staff-management' || ($pMod === 'user-management' && in_array($p->name, [
                            'view-users', 'add-edit-users', 'activate-suspend-users', 'delete-users',
                            'reset-password-security', 'view-roles', 'add-edit-roles', 'delete-roles',
                            'assign-remove-roles', 'manage-permission-matrix', 'view-staff-activity-log'
                        ]));
                    }
                    if ($modId === 'user-management') {
                        return $pMod === 'user-management' && !in_array($p->name, [
                            'view-users', 'add-edit-users', 'activate-suspend-users', 'delete-users',
                            'reset-password-security', 'view-roles', 'add-edit-roles', 'delete-roles',
                            'assign-remove-roles', 'manage-permission-matrix', 'view-staff-activity-log'
                        ]) || in_array($pMod, ['customer-management', 'business-management']);
                    }
                    if ($modId === 'sales-crm-management') {
                        return in_array($pMod, ['sales-crm-management', 'contracts-proposals']);
                    }
                    if ($modId === 'content-management') {
                        return in_array($pMod, ['content-management', 'cms']);
                    }
                    return $pMod === $modId;
                })->values();

                $subPermissions = [];
                // Calculate aggregated module access for each role (true if role has at least 1 permission in module)
                $moduleAccess = [];
                foreach ($roles as $r) {
                    $moduleAccess[$r->name] = false;
                }

                foreach ($modPerms as $p) {
                    $pAccess = [];
                    foreach ($roles as $r) {
                        $roleKey = $r->name;
                        $hasIt = isset($rolePermMap[$roleKey][$p->name]);
                        $pAccess[$roleKey] = $hasIt;
                        if ($hasIt) {
                            $moduleAccess[$roleKey] = true;
                        }
                    }

                    $subPermissions[] = [
                        'id'          => $p->name,
                        'name'        => $p->display_name ?: $p->name,
                        'description' => $p->description ?: '',
                        'access'      => $pAccess,
                    ];
                }

                $modulesList[] = [
                    'id'             => $modId,
                    'name'           => $modName,
                    'access'         => $moduleAccess,
                    'subPermissions' => $subPermissions,
                ];
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'modules'     => $modulesList,
                    'roles'       => $roles->map(fn($r) => [
                        'id'    => $r->id,
                        'name'  => $r->name,
                        'color' => $r->color,
                    ]),
                    'last_updated' => now()->format('M d, Y • h:i A'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permissions matrix.',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Update/toggle a single permission in the matrix or bulk update.
     */
    public function updatePermissionMatrix(Request $request)
    {
        try {
            $denied = $this->checkPermission($request, 'manage-permission-matrix');
            if ($denied) return $denied;

            $validated = $request->validate([
                'role_name'          => ['required', 'string', 'exists:roles,name'],
                'permission_name'    => ['sometimes', 'nullable', 'string', 'exists:permissions,name'],
                'permission_names'   => ['sometimes', 'nullable', 'array'],
                'permission_names.*' => ['string', 'exists:permissions,name'],
                'module_id'          => ['sometimes', 'nullable', 'string'],
                'enabled'            => ['required', 'boolean'],
            ]);

            $role = Role::where('name', $validated['role_name'])->firstOrFail();

            if ($role->name === 'super_admin' && !$validated['enabled']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove permissions from Super Admin.',
                ], 403);
            }

            $permsToUpdate = [];
            if (!empty($validated['permission_names'])) {
                $permsToUpdate = $validated['permission_names'];
            } elseif (!empty($validated['permission_name'])) {
                $permsToUpdate = [$validated['permission_name']];
            } elseif (!empty($validated['module_id'])) {
                $targetMod = $validated['module_id'];
                if ($targetMod === 'sales-crm-management') {
                    $permsToUpdate = Permission::whereIn('module_id', ['sales-crm-management', 'contracts-proposals'])->pluck('name')->toArray();
                } elseif ($targetMod === 'content-management') {
                    $permsToUpdate = Permission::whereIn('module_id', ['content-management', 'cms'])->pluck('name')->toArray();
                } elseif ($targetMod === 'user-management') {
                    $permsToUpdate = Permission::whereIn('module_id', ['user-management', 'customer-management', 'business-management'])
                        ->whereNotIn('name', [
                            'view-users', 'add-edit-users', 'activate-suspend-users', 'delete-users',
                            'reset-password-security', 'view-roles', 'add-edit-roles', 'delete-roles',
                            'assign-remove-roles', 'manage-permission-matrix', 'view-staff-activity-log'
                        ])->pluck('name')->toArray();
                } elseif ($targetMod === 'staff-management') {
                    $permsToUpdate = Permission::where(function($q) {
                        $q->where('module_id', 'staff-management')
                          ->orWhere(function($sub) {
                              $sub->where('module_id', 'user-management')
                                  ->whereIn('name', [
                                      'view-users', 'add-edit-users', 'activate-suspend-users', 'delete-users',
                                      'reset-password-security', 'view-roles', 'add-edit-roles', 'delete-roles',
                                      'assign-remove-roles', 'manage-permission-matrix', 'view-staff-activity-log'
                                  ]);
                          });
                    })->pluck('name')->toArray();
                } else {
                    $permsToUpdate = Permission::where('module_id', $targetMod)->pluck('name')->toArray();
                }
            }

            if (empty($permsToUpdate)) {
                return response()->json(['success' => false, 'message' => 'No valid permissions specified to update.'], 422);
            }

            foreach ($permsToUpdate as $pName) {
                if ($validated['enabled']) {
                    $role->givePermissionTo($pName);
                } else {
                    $role->revokePermissionTo($pName);
                }
            }

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            return response()->json([
                'success' => true,
                'message' => 'Permissions updated for role ' . $role->name,
                'data'    => [
                    'role'        => $role->name,
                    'permissions' => $permsToUpdate,
                    'enabled'     => $validated['enabled'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
