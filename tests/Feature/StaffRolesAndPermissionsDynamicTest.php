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

    public function test_admin_role_does_not_get_wildcard_and_respects_matrix_permissions(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $perm1 = Permission::firstOrCreate(['name' => 'view-products', 'guard_name' => 'web']);
        $perm2 = Permission::firstOrCreate(['name' => 'view-orders', 'guard_name' => 'web']);
        $adminRole->givePermissionTo([$perm1, $perm2]);

        $adminUser = User::factory()->create(['status' => 'active', 'role' => 'admin']);
        $adminUser->assignRole('admin');

        $headers = $this->authHeadersFor($adminUser);
        $res = $this->getJson('/api/v1/auth/me', $headers);

        $res->assertStatus(200);
        $this->assertFalse($res->json('data.user.is_super_admin'));
        $this->assertNotContains('*', $res->json('data.user.permissions'));
        $this->assertContains('view-products', $res->json('data.user.permissions'));
        $this->assertContains('view-orders', $res->json('data.user.permissions'));

        // Revoke view-orders from admin role via matrix
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdminUser = User::factory()->create(['status' => 'active', 'role' => 'super_admin']);
        $superAdminUser->assignRole('super_admin');
        $superHeaders = $this->authHeadersFor($superAdminUser);

        $revokeRes = $this->postJson('/api/v1/permissions/matrix', [
            'role_name' => 'admin',
            'permission_name' => 'view-orders',
            'enabled' => false,
        ], $superHeaders);
        $revokeRes->assertStatus(200);

        $resAfter = $this->getJson('/api/v1/auth/me', $headers);
        $resAfter->assertStatus(200);
        $this->assertContains('view-products', $resAfter->json('data.user.permissions'));
        $this->assertNotContains('view-orders', $resAfter->json('data.user.permissions'));
    }

    public function test_permission_matrix_module_bulk_update(): void
    {
        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdminUser = User::factory()->create(['status' => 'active', 'role' => 'super_admin']);
        $superAdminUser->assignRole('super_admin');
        $superHeaders = $this->authHeadersFor($superAdminUser);

        $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);
        $p1 = Permission::create(['name' => 'view-custom-mod', 'module_id' => 'custom-mod', 'guard_name' => 'web']);
        $p2 = Permission::create(['name' => 'manage-custom-mod', 'module_id' => 'custom-mod', 'guard_name' => 'web']);

        // Enable module
        $res = $this->postJson('/api/v1/permissions/matrix', [
            'role_name' => 'manager',
            'module_id' => 'custom-mod',
            'enabled' => true,
        ], $superHeaders);
        $res->assertStatus(200);
        $this->assertTrue($role->fresh()->hasPermissionTo('view-custom-mod'));
        $this->assertTrue($role->fresh()->hasPermissionTo('manage-custom-mod'));

        // Disable module
        $res2 = $this->postJson('/api/v1/permissions/matrix', [
            'role_name' => 'manager',
            'module_id' => 'custom-mod',
            'enabled' => false,
        ], $superHeaders);
        $res2->assertStatus(200);
        $this->assertFalse($role->fresh()->hasPermissionTo('view-custom-mod'));
        $this->assertFalse($role->fresh()->hasPermissionTo('manage-custom-mod'));
    }

    public function test_editor_role_only_sees_assigned_permissions_and_reflects_immediate_updates(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdminUser = User::factory()->create(['status' => 'active', 'role' => 'super_admin']);
        $superAdminUser->assignRole('super_admin');
        $superHeaders = $this->authHeadersFor($superAdminUser);

        $editorRole = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        $viewProd = Permission::firstOrCreate(['name' => 'view-products', 'guard_name' => 'web']);
        $manageProd = Permission::firstOrCreate(['name' => 'manage-products', 'guard_name' => 'web']);
        $editorRole->syncPermissions([$viewProd, $manageProd]);

        $editorUser = User::factory()->create(['status' => 'active', 'role' => 'editor']);
        $editorUser->assignRole('editor');
        $editorHeaders = $this->authHeadersFor($editorUser);

        // Editor /auth/me must return only editor permissions and is_super_admin must be false
        $meRes = $this->getJson('/api/v1/auth/me', $editorHeaders);
        $meRes->assertStatus(200);
        $this->assertFalse($meRes->json('data.user.is_super_admin'));
        $this->assertEquals('editor', $meRes->json('data.user.role'));
        $this->assertNotContains('*', $meRes->json('data.user.permissions'));
        $this->assertContains('view-products', $meRes->json('data.user.permissions'));
        $this->assertContains('manage-products', $meRes->json('data.user.permissions'));
        $this->assertNotContains('view-orders', $meRes->json('data.user.permissions'));
        $this->assertNotContains('view-users', $meRes->json('data.user.permissions'));

        // Admin updates editor user status/role via staff-users endpoint
        $updateRes = $this->putJson("/api/v1/staff-users/{$editorUser->id}", [
            'name' => 'Updated Editor Name',
            'job_title' => 'Senior Editor',
            'role' => 'editor',
            'status' => 'active',
        ], $superHeaders);
        $updateRes->assertStatus(200);

        // Verify updated staff profile reflects on /auth/me immediately
        $meResUpdated = $this->getJson('/api/v1/auth/me', $editorHeaders);
        $meResUpdated->assertStatus(200);
        $this->assertEquals('Updated Editor Name', $meResUpdated->json('data.user.name'));
        $this->assertEquals('Senior Editor', $meResUpdated->json('data.user.job_title'));
        $this->assertEquals('editor', $meResUpdated->json('data.user.role'));
    }
}
