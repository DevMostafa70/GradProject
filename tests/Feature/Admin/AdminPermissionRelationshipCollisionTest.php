<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminPermissionRelationshipCollisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_permissions_are_a_spatie_relation_not_a_json_attribute(): void
    {
        $this->assertFalse(Schema::hasColumn('admins', 'permissions'));
        $this->assertTrue(Schema::hasColumn('admins', 'legacy_permissions'));

        $permission = Permission::findOrCreate('admin.dashboard.view', 'admin');

        $admin = Admin::create([
            'name' => 'Permission Test Admin',
            'email' => 'permission-test@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'legacy_permissions' => [$permission->name],
            'is_active' => true,
        ]);

        $admin->syncPermissions([$permission->name]);
        $admin->refresh()->load('permissions');

        $this->assertInstanceOf(Collection::class, $admin->permissions);
        $this->assertTrue($admin->hasDirectPermission($permission));
        $this->assertSame([$permission->name], $admin->getAdminPermissionNames());
    }
}
