<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyEmployeePermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AssignRolesToExistingUsersSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::where('name', 'super_admin')->where('guard_name', 'admin')->first();
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'admin')->first();
        $companyOwnerRole = Role::where('name', 'company_owner')->where('guard_name', 'company')->first();
        $regularUserRole = Role::where('name', 'regular_user')->where('guard_name', 'user')->first();
        $employeeFallbackRole = Role::where('name', 'company_employee')->where('guard_name', 'user')->first();

        if ($superAdminRole) {
            Admin::where('role', 'super_admin')->each(
                fn (Admin $admin) => $admin->syncRoles([$superAdminRole])
            );
        }

        if ($adminRole) {
            Admin::where('role', '!=', 'super_admin')->each(
                fn (Admin $admin) => $admin->syncRoles([$adminRole])
            );
        }

        if ($companyOwnerRole) {
            Company::query()->each(
                fn (Company $company) => $company->syncRoles([$companyOwnerRole])
            );
        }

        if ($regularUserRole) {
            User::query()
                ->where(function ($query): void {
                    $query->where('is_company_employee', false)
                        ->orWhereNull('company_id');
                })
                ->each(fn (User $user) => $user->syncRoles([$regularUserRole]));
        }

        if ($employeeFallbackRole) {
            User::query()
                ->where('is_company_employee', true)
                ->whereNotNull('company_id')
                ->each(function (User $employee) use ($employeeFallbackRole): void {
                    $currentEmployeeRoles = $employee->roles
                        ->where('guard_name', 'user')
                        ->whereIn('name', CompanyEmployeePermissionCatalog::ROLES)
                        ->values();

                    $employee->syncRoles(
                        $currentEmployeeRoles->isNotEmpty()
                            ? $currentEmployeeRoles
                            : [$employeeFallbackRole]
                    );
                });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('Existing accounts were assigned guard-compatible roles.');
    }
}
