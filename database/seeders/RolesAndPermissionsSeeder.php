<?php

namespace Database\Seeders;

use App\Support\AdminPermissionCatalog;
use App\Support\CompanyEmployeePermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $adminPermissions = AdminPermissionCatalog::all();
        $companyOwnerPermissions = [
            'company.dashboard.view',
            'company.profile.view',
            'company.profile.update',
            'company.jobs.view',
            'company.jobs.create',
            'company.jobs.update',
            'company.jobs.delete',
            'company.jobs.close',
            'company.candidates.view',
            'company.candidates.invite',
            'company.candidates.update',
            'company.interviews.view',
            'company.results.view',
            'company.results.export',
            'company.question_bank.view',
            'company.question_bank.create',
            'company.question_bank.update',
            'company.question_bank.delete',
            'company.billing.view',
            'company.billing.select_plan',
            'company.billing.checkout',
            'company.billing.portal',
            'company.usage.view',
            'company.notifications.view',
            'company.employees.view',
            'company.employees.create',
            'company.employees.update',
            'company.employees.delete',
        ];
        $regularUserPermissions = [
            'user.dashboard.view',
            'user.profile.view',
            'user.profile.update',
            'user.interviews.view',
            'user.interviews.start',
            'user.interviews.resume',
            'user.interviews.complete',
            'user.answers.submit',
            'user.results.view',
            'user.resumes.view',
            'user.resumes.upload',
            'user.resumes.delete',
            'user.notifications.view',
        ];

        $this->createPermissions($adminPermissions, 'admin');
        $this->createPermissions($companyOwnerPermissions, 'company');
        // Employees are User models, therefore their company.* permissions use user guard.
        $this->createPermissions(CompanyEmployeePermissionCatalog::ASSIGNABLE, 'user');
        $this->createPermissions($regularUserPermissions, 'user');

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'admin']);
        $superAdmin->syncPermissions($adminPermissions);

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'admin']);
        $admin->syncPermissions([]);

        $owner = Role::firstOrCreate(['name' => 'company_owner', 'guard_name' => 'company']);
        $owner->syncPermissions($companyOwnerPermissions);

        foreach (CompanyEmployeePermissionCatalog::rolePermissions() as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'user',
            ]);
            $role->syncPermissions($permissions);
        }

        $regularUser = Role::firstOrCreate(['name' => 'regular_user', 'guard_name' => 'user']);
        $regularUser->syncPermissions($regularUserPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('Roles and permissions normalized successfully.');
    }

    /** @param array<int, string> $permissions */
    private function createPermissions(array $permissions, string $guard): void
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }
    }
}
