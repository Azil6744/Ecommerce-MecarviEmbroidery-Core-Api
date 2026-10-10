<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StaffRolesAndPermissionsDynamicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function authHeadersFor(User $user): array
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
    }

    public function test_login_returns_dynamic_permissions_for_active_user(): void
    {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $permission1 = Permission::create(['name' => 'view-products', 'guard_name' => 'web']);
        $permission2 = Permission::create(['name' => 'manage-products', 'guard_name' => 'web']);
        $role->givePermissionTo([$permission1, $permission2]);

        $user = User::factory()->create([
            'email' => 'editor@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
        $user->assignRole('editor');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'editor@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'token',
                'token_type',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'permissions',
                    'is_super_admin',
                    'role_display',
                ],
            ],
        ]);

        $permissions = $response->json('data.user.permissions');
        $this->assertContains('view-products', $permissions);
        $this->assertContains('manage-products', $permissions);
        $this->assertFalse($response->json('data.user.is_super_admin'));
    }

    public function test_inactive_or_suspended_user_cannot_login(): void
    {
        $inactiveUser = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => bcrypt('password123'),
            'status' => 'inactive',
        ]);

        $res1 = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);
        $res1->assertStatus(403);

        $suspendedUser = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => bcrypt('password123'),
            'status' => 'suspended',
        ]);

        $res2 = $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'password123',
        ]);
        $res2->assertStatus(403);
    }

    public function test_user_with_expired_access_cannot_login(): void
    {
        $expiredUser = User::factory()->create([
            'email' => 'expired@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
            'access_expires_at' => Carbon::now()->subDays(2),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'expired@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    public function test_auth_me_returns_empty_permissions_when_role_is_deactivated(): void
    {
        $role = Role::create([
            'name' => 'manager',
            'guard_name' => 'web',
            'status' => true,
        ]);
        $permission = Permission::create(['name' => 'view-orders', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create([
            'status' => 'active',
        ]);
        $user->assignRole('manager');

        $headers = $this->authHeadersFor($user);

        // Role active
        $res = $this->getJson('/api/v1/auth/me', $headers);
        $res->assertStatus(200);
        $this->assertContains('view-orders', $res->json('data.user.permissions'));

        // Toggle role status to false
        $role->status = false;
        $role->save();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Check auth/me again -> permissions should now be empty because role is deactivated
        $resDeactivated = $this->getJson('/api/v1/auth/me', $headers);
        $resDeactivated->assertStatus(200);
        $this->assertEmpty($resDeactivated->json('data.user.permissions'));
    }

    public function test_super_admin_gets_wildcard_permission(): void
    {
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create([
            'status' => 'active',
        ]);
        $user->assignRole('super_admin');

        $headers = $this->authHeadersFor($user);
        $response = $this->getJson('/api/v1/auth/me', $headers);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.user.is_super_admin'));
        $this->assertContains('*', $response->json('data.user.permissions'));
    }

    public function test_permission_matrix_update_endpoint(): void
    {
        $adminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $adminUser = User::factory()->create(['status' => 'active']);
        $adminUser->assignRole('super_admin');

        $testRole = Role::create(['name' => 'staff', 'guard_name' => 'web']);
        $testPerm = Permission::create(['name' => 'view-knowledge-base', 'guard_name' => 'web']);

        $headers = $this->authHeadersFor($adminUser);

        // Grant permission via matrix
        $grantRes = $this->postJson('/api/v1/permissions/matrix', [
            'role_name' => 'staff',
            'permission_name' => 'view-knowledge-base',
            'enabled' => true,
        ], $headers);
        $grantRes->assertStatus(200);

        $this->assertTrue($testRole->fresh()->hasPermissionTo('view-knowledge-base'));

        // Revoke permission via matrix
        $revokeRes = $this->postJson('/api/v1/permissions/matrix', [
            'role_name' => 'staff',
            'permission_name' => 'view-knowledge-base',
            'enabled' => false,
        ], $headers);
        $revokeRes->assertStatus(200);

        $this->assertFalse($testRole->fresh()->hasPermissionTo('view-knowledge-base'));
    }
}
