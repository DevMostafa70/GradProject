<?php
// database/seeders/RolesAndPermissionsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ============================================================
        // 1. Admin Permissions (guard_name = admin)
        // ============================================================
        $adminPermissions = [
            'admin.dashboard.view',
            'admin.users.view',
            'admin.users.create',
            'admin.users.update',
            'admin.users.delete',
            'admin.users.suspend',
            'admin.companies.view',
            'admin.companies.approve',
            'admin.companies.reject',
            'admin.companies.suspend',
            'admin.companies.update',
            'admin.jobs.view',
            'admin.jobs.manage',
            'admin.skills.view',
            'admin.skills.create',
            'admin.skills.update',
            'admin.skills.delete',
            'admin.categories.view',
            'admin.categories.create',
            'admin.categories.update',
            'admin.categories.delete',
            'admin.notifications.view',
            'admin.notifications.send',
            'admin.activity_logs.view',
            'admin.activity_logs.clean',
            'admin.backups.view',
            'admin.backups.create',
            'admin.backups.download',
            'admin.plans.view',
            'admin.plans.manage',
            'admin.billing.view',
            'admin.billing.manage',
            'admin.roles.view',
            'admin.roles.create',
            'admin.roles.update',
            'admin.roles.delete',
            'admin.permissions.view',
            'admin.permissions.create',
            'admin.permissions.update',
            'admin.permissions.delete',
            'admin.settings.manage',
        ];

        // ============================================================
        // 2. Company Permissions (guard_name = company) - للـ Company Owner
        // ============================================================
        $companyPermissionsForOwner = [
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

        // ============================================================
        // 3. Company Employee Permissions (guard_name = user) - للموظفين
        // ============================================================
        $companyPermissionsForEmployee = [
            'company.dashboard.view',
            'company.profile.view',
            'company.jobs.update',   
            'company.jobs.delete',
            'company.jobs.close',
            'company.jobs.create',
            'company.jobs.view',
            'company.candidates.view',
            'company.candidates.invite',
            'company.candidates.update',
            'company.interviews.view',
            'company.results.view',
            'company.question_bank.view',
            'company.question_bank.create',
            'company.question_bank.update',
            'company.notifications.view',
        ];

        // ============================================================
        // 4. User Permissions (guard_name = user)
        // ============================================================
        $userPermissions = [
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

        // ============================================================
        // 5. Create All Permissions
        // ============================================================

        // Admin Permissions
        foreach ($adminPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'admin',
            ]);
        }

        // Company Permissions (guard = company)
        foreach ($companyPermissionsForOwner as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'company',
            ]);
        }

        // Company Employee Permissions (guard = user) - ✅ مهم جداً
        foreach ($companyPermissionsForEmployee as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'user',
            ]);
        }

        // User Permissions
        foreach ($userPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'user',
            ]);
        }

        $this->command->info('✅ All permissions created');

        // ============================================================
        // 6. Create Roles
        // ============================================================

        // Admin Roles
        $superAdminRole = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'admin',
        ]);
        $superAdminRole->syncPermissions($adminPermissions);
        $this->command->info('✅ super_admin role created');

        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'admin',
        ]);
        $adminRole->syncPermissions($adminPermissions);
        $this->command->info('✅ admin role created');

        // Company Roles
        $companyOwnerRole = Role::firstOrCreate([
            'name' => 'company_owner',
            'guard_name' => 'company',
        ]);
        $companyOwnerRole->syncPermissions($companyPermissionsForOwner);
        $this->command->info('✅ company_owner role created');

        // Company Employee Roles (guard = company)
        $hrRole = Role::firstOrCreate([
            'name' => 'company_hr',
            'guard_name' => 'company',
        ]);
        $hrRole->syncPermissions([
            'company.dashboard.view',
            'company.candidates.view',
            'company.candidates.invite',
            'company.candidates.update',
            'company.interviews.view',
            'company.results.view',
            'company.notifications.view',
        ]);
        $this->command->info('✅ company_hr role created');

        $interviewerRole = Role::firstOrCreate([
            'name' => 'company_interviewer',
            'guard_name' => 'company',
        ]);
        $interviewerRole->syncPermissions([
            'company.dashboard.view',
            'company.jobs.view',
            'company.candidates.view',
            'company.interviews.view',
            'company.results.view',
            'company.notifications.view',
        ]);
        $this->command->info('✅ company_interviewer role created');

        // User Role
        $regularUserRole = Role::firstOrCreate([
            'name' => 'regular_user',
            'guard_name' => 'user',
        ]);
        $regularUserRole->syncPermissions($userPermissions);
        $this->command->info('✅ regular_user role created');

        $this->command->info('🎉 Roles and Permissions seeded successfully!');
    }
}
