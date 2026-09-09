<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Create a new user (Admin only).
     *
     * Allows admin users to create users with specific roles.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $authUser = $request->user();
            $isSuperAdmin = in_array($authUser->role, ['super_admin', 'admin']);
            try { $isSuperAdmin = $isSuperAdmin || $authUser->hasAnyRole(['super_admin', 'admin']); } catch (\Exception $e) {}

            if (!$isSuperAdmin) {
                try {
                    if (!$authUser->hasPermissionTo('create users')) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. Insufficient permissions.'], 403);
                    }
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Permission not configured.'], 403);
                }
            }

            // Validate the incoming request data
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => ['required', 'string', Password::defaults()],
                'roles' => ['sometimes', 'array'],
                'roles.*' => ['string', 'exists:roles,name'],
            ]);

            // Create the new user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Assign roles if provided
            if (isset($validated['roles']) && is_array($validated['roles'])) {
                $user->assignRole($validated['roles']);
            }

            // Return success response with user data
            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                        'email_verified_at' => $user->email_verified_at,
                        'created_at' => $user->created_at,
                    ],
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
                'message' => 'User creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during user creation.',
            ], 500);
        }
    }

    /**
     * Update an existing user (Admin only).
     *
     * Allows admin users to update user information including roles.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $authUser = $request->user();
            $isSuperAdmin = in_array($authUser->role, ['super_admin', 'admin']);
            try { $isSuperAdmin = $isSuperAdmin || $authUser->hasAnyRole(['super_admin', 'admin']); } catch (\Exception $e) {}

            if (!$isSuperAdmin) {
                try {
                    if (!$authUser->hasPermissionTo('edit users')) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. Insufficient permissions.'], 403);
                    }
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Permission not configured.'], 403);
                }
            }

            // Find the user to update
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            // Validate the incoming request data
            $validated = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,' . $id],
                'password' => ['sometimes', 'nullable', 'string', Password::defaults()],
                'roles' => ['sometimes', 'array'],
                'roles.*' => ['string', 'exists:roles,name'],
            ]);

            // Update user fields
            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }
            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }
            if (isset($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            // Update roles if provided
            if (isset($validated['roles'])) {
                $user->syncRoles($validated['roles']);
            }

            $user->save();

            // Return success response with updated user data
            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                        'email_verified_at' => $user->email_verified_at,
                        'updated_at' => $user->updated_at,
                    ],
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
                'message' => 'User update failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during user update.',
            ], 500);
        }
    }

    /**
     * Delete a user (Admin only).
     *
     * Allows admin users to delete users from the system.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        try {
            $authUser = $request->user();
            $isSuperAdmin = in_array($authUser->role, ['super_admin', 'admin']);
            try { $isSuperAdmin = $isSuperAdmin || $authUser->hasAnyRole(['super_admin', 'admin']); } catch (\Exception $e) {}

            if (!$isSuperAdmin) {
                try {
                    if (!$authUser->hasPermissionTo('delete users')) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. Insufficient permissions.'], 403);
                    }
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Permission not configured.'], 403);
                }
            }

            // Find the user to delete
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            // Prevent deleting yourself
            if ($user->id === $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account.',
                ], 403);
            }

            // Delete the user
            $user->delete();

            // Return success response
            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            // Return general error response
            return response()->json([
                'success' => false,
                'message' => 'User deletion failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during user deletion.',
            ], 500);
        }
    }

    /**
     * Get all users (Admin only).
     *
     * Returns a paginated list of all users with their roles and permissions.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $authUser = $request->user();

            // Allow super_admin and admin by role column OR Spatie role, else check permission
            $isSuperAdmin = in_array($authUser->role, ['super_admin', 'admin']);
            try { $isSuperAdmin = $isSuperAdmin || $authUser->hasAnyRole(['super_admin', 'admin']); } catch (\Exception $e) {}

            if (!$isSuperAdmin) {
                try {
                    if (!$authUser->hasPermissionTo('view users')) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unauthorized. Insufficient permissions.',
                        ], 403);
                    }
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized. Permission not configured.',
                    ], 403);
                }
            }

            $perPage = $request->get('per_page', 50);
            
            $query = User::with(['roles', 'permissions']);

            if ($request->has('search') && $request->search) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('email', 'like', "%{$s}%");
                });
            }

            if ($request->has('role') && $request->role) {
                $role = $request->role;
                $query->where(function ($q) use ($role) {
                    $q->where('role', $role)
                      ->orWhereHas('roles', function ($roleQuery) use ($role) {
                          $roleQuery->where('name', $role);
                      });
                });
            }

            if ($request->has('status') && $request->status) {
                if ($request->status === 'banned') {
                    $query->whereNotNull('banned_at');
                } elseif ($request->status === 'deactivated') {
                    $query->whereNotNull('deactivated_at');
                } elseif ($request->status === 'active') {
                    $query->whereNull('banned_at')->whereNull('deactivated_at');
                }
            }

            $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Transform the users data
            $users->getCollection()->transform(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'loyalty_points' => $user->loyalty_points,
                    'roles' => $user->roles->pluck('name')->toArray(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                    'email_verified_at' => $user->email_verified_at,
                    'banned_at' => $user->banned_at,
                    'deactivated_at' => $user->deactivated_at,
                    'last_login_at' => $user->last_login_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Users retrieved successfully',
                'data' => [
                    'users' => $users,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve users.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Get a specific user (Admin only).
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        try {
            $authUser = $request->user();
            $isSuperAdmin = in_array($authUser->role, ['super_admin', 'admin']);
            try { $isSuperAdmin = $isSuperAdmin || $authUser->hasAnyRole(['super_admin', 'admin']); } catch (\Exception $e) {}

            if (!$isSuperAdmin) {
                try {
                    if (!$authUser->hasPermissionTo('view users')) {
                        return response()->json(['success' => false, 'message' => 'Unauthorized. Insufficient permissions.'], 403);
                    }
                } catch (\Exception $e) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized. Permission not configured.'], 403);
                }
            }

            $user = User::with(['roles', 'permissions'])->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                        'email_verified_at' => $user->email_verified_at,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Assign roles to a user (Admin only).
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignRoles(Request $request, $id)
    {
        try {
            // Check if user has permission to assign roles
            if (!$request->user()->hasPermissionTo('assign roles')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Insufficient permissions.',
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $validated = $request->validate([
                'roles' => ['required', 'array'],
                'roles.*' => ['string', 'exists:roles,name'],
            ]);

            $user->syncRoles($validated['roles']);

            return response()->json([
                'success' => true,
                'message' => 'Roles assigned successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                    ],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign roles.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Remove roles from a user (Admin only).
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeRoles(Request $request, $id)
    {
        try {
            // Check if user has permission to assign roles
            if (!$request->user()->hasPermissionTo('assign roles')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Insufficient permissions.',
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $validated = $request->validate([
                'roles' => ['required', 'array'],
                'roles.*' => ['string', 'exists:roles,name'],
            ]);

            $user->removeRole($validated['roles']);

            return response()->json([
                'success' => true,
                'message' => 'Roles removed successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                    ],
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove roles.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * List customers (users with role=customer).
     */
    public function customers(Request $request)
    {
        try {
            $businessRoles = ['business', 'business_owner', 'business_user', 'business-customer', 'company'];
            $query = User::with(['roles'])
                ->withCount(['orders'])
                ->withSum('orders as total_spent', 'total_amount')
                ->where(function ($q) use ($businessRoles) {
                    $q->where(function ($sub) {
                        $sub->where('role', 'customer')
                            ->orWhereHas('roles', function ($roleQuery) {
                                $roleQuery->where('name', 'customer');
                            });
                    })
                    ->whereNotIn('role', $businessRoles)
                    ->whereDoesntHave('roles', function ($roleQuery) use ($businessRoles) {
                        $roleQuery->whereIn('name', $businessRoles);
                    });
                });

            if ($request->has('search') && $request->search) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('email', 'like', "%{$s}%");
                });
            }

            if ($request->has('status') && $request->status) {
                if ($request->status === 'banned') {
                    $query->whereNotNull('banned_at');
                } elseif ($request->status === 'deactivated') {
                    $query->whereNotNull('deactivated_at');
                } elseif ($request->status === 'active') {
                    $query->whereNull('banned_at')->whereNull('deactivated_at');
                }
            }

            $customers = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            $customers->getCollection()->transform(function ($user) {
                return $this->customerPayload($user);
            });

            return response()->json([
                'success' => true,
                'data' => $customers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function customerStats(Request $request)
    {
        try {
            $businessRoles = ['business', 'business_owner', 'business_user', 'business-customer', 'company'];
            $base = User::where(function ($q) use ($businessRoles) {
                $q->where(function ($sub) {
                    $sub->where('role', 'customer')
                        ->orWhereHas('roles', function ($roleQuery) {
                            $roleQuery->where('name', 'customer');
                        });
                })
                ->whereNotIn('role', $businessRoles)
                ->whereDoesntHave('roles', function ($roleQuery) use ($businessRoles) {
                    $roleQuery->whereIn('name', $businessRoles);
                });
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => (clone $base)->count(),
                    'active' => (clone $base)->whereNull('banned_at')->whereNull('deactivated_at')->count(),
                    'banned' => (clone $base)->whereNotNull('banned_at')->count(),
                    'deactivated' => (clone $base)->whereNotNull('deactivated_at')->count(),
                    'verified' => (clone $base)->whereNotNull('email_verified_at')->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customer stats.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function businessUsers(Request $request)
    {
        try {
            $businessRoles = ['business', 'business_owner', 'business_user', 'business-customer', 'company'];
            $query = User::with(['roles'])
                ->withCount(['orders'])
                ->withSum('orders as total_spent', 'total_amount')
                ->where(function ($q) use ($businessRoles) {
                    $q->whereIn('role', $businessRoles)
                        ->orWhereHas('roles', function ($roleQuery) use ($businessRoles) {
                            $roleQuery->whereIn('name', $businessRoles);
                        })
                        ->orWhereNotNull('business_name');
                });

            $this->applyUserDirectoryFilters($query, $request);

            $businessUsers = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            $businessUsers->getCollection()->transform(function ($user) {
                return $this->businessUserPayload($user);
            });

            return response()->json([
                'success' => true,
                'data' => $businessUsers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch business users.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function sellerStores(Request $request)
    {
        try {
            $query = User::with(['roles'])
                ->withCount(['orders'])
                ->withSum('orders as total_spent', 'total_amount')
                ->where(function ($q) {
                    $q->where('role', 'seller')
                        ->orWhereHas('roles', function ($roleQuery) {
                            $roleQuery->where('name', 'seller');
                        });
                });

            $this->applyUserDirectoryFilters($query, $request);

            $sellers = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            $sellers->getCollection()->transform(function ($user) {
                return $this->sellerStorePayload($user);
            });

            return response()->json([
                'success' => true,
                'data' => $sellers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch seller stores.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function storeCustomer(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'phone' => ['nullable', 'string', 'max:100'],
                'password' => ['required', 'string', Password::defaults()],
                'email_verified' => ['sometimes', 'boolean'],
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => 'customer',
                'email_verified_at' => !empty($validated['email_verified']) ? Carbon::now() : null,
            ]);

            try {
                $user->assignRole('customer');
            } catch (\Exception $e) {
                // The role may not exist in older local databases. The role column still marks the account as a customer.
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully.',
                'data' => ['customer' => $this->customerPayload($user->load('roles')->loadCount('orders'))],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer creation failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function updateCustomerStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => ['required', 'in:active,banned,deactivated'],
            ]);

            $customer = $this->findCustomer($id);

            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
            }

            $customer->banned_at = $validated['status'] === 'banned' ? Carbon::now() : null;
            $customer->deactivated_at = $validated['status'] === 'deactivated' ? Carbon::now() : null;
            $customer->save();

            // Forward status update to Central Auth
            try {
                $this->centralCall('post', '/v1/internal/admin/users/ban-status', [
                    'email' => $customer->email,
                    'site_slug' => config('services.mccarvy_site.slug', 'embroidery'),
                    'status' => $validated['status'],
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to sync ban status to Central Auth for ' . $customer->email . ': ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Customer status updated.',
                'data' => ['customer' => $this->customerPayload($customer->load('roles')->loadCount('orders'))],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer status.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function verifyCustomer(Request $request, $id)
    {
        try {
            $customer = $this->findCustomer($id);

            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
            }

            $customer->email_verified_at = $customer->email_verified_at ?: Carbon::now();
            $customer->save();

            return response()->json([
                'success' => true,
                'message' => 'Customer verified.',
                'data' => ['customer' => $this->customerPayload($customer->load('roles')->loadCount('orders'))],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify customer.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function findCustomer($id): ?User
    {
        return User::where('id', $id)
            ->where(function ($q) {
                $q->where('role', 'customer')
                    ->orWhereHas('roles', function ($roleQuery) {
                        $roleQuery->where('name', 'customer');
                    });
            })
            ->first();
    }

    private function customerPayload(User $user): array
    {
        $ordersCount = $user->orders_count ?? 0;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->toArray() : [],
            'email_verified_at' => $user->email_verified_at,
            'banned_at' => $user->banned_at,
            'deactivated_at' => $user->deactivated_at,
            'last_login_at' => $user->last_login_at,
            'orders_count' => $ordersCount,
            'total_orders' => $ordersCount,
            'total_spent' => (float) ($user->total_spent ?? 0),
            'loyalty_points' => (int) ($user->loyalty_points ?? 0),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    private function applyUserDirectoryFilters($query, Request $request): void
    {
        if ($request->has('search') && $request->search) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        if ($request->has('status') && $request->status) {
            if ($request->status === 'banned') {
                $query->whereNotNull('banned_at');
            } elseif ($request->status === 'deactivated') {
                $query->whereNotNull('deactivated_at');
            } elseif ($request->status === 'active') {
                $query->whereNull('banned_at')->whereNull('deactivated_at');
            }
        }
    }

    private function businessUserPayload(User $user): array
    {
        $bizName = $user->business_name ?: ($user->name ? $user->name . "'s Business" : 'Business Account');

        return array_merge($this->customerPayload($user), [
            'business_name' => $bizName,
            'company_name' => $bizName,
            'store_name' => $bizName,
            'business_type' => $user->business_type ?: 'Business Account',
            'tax_id' => $user->tax_id,
            'address' => $user->address,
            'membership_status' => $user->membership_status ?: 'Business Member',
        ]);
    }

    private function sellerStorePayload(User $user): array
    {
        $payload = $this->customerPayload($user);

        return array_merge($payload, [
            'store_name' => $payload['name'] ? $payload['name'] . "'s Store" : 'Seller Store',
            'business_name' => null,
            'company_name' => null,
            'store_status' => $payload['email_verified_at'] ? 'verified' : 'pending',
        ]);
    }

    private function centralCall(string $method, string $path, array $data = [])
    {
        $centralUrl = rtrim(config('services.central_auth.url'), '/');
        $secret = (string) config('services.internal_notifications.secret');

        $request = \Illuminate\Support\Facades\Http::acceptJson()
            ->withHeaders(['X-Internal-Notification-Secret' => $secret])
            ->timeout(5);

        if (strtolower($method) === 'post') {
            return $request->post($centralUrl . $path, $data);
        }

        return $request->get($centralUrl . $path, $data);
    }
}
