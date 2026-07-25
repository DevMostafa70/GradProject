<?php

namespace Tests\Feature\Company;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyEmployeeAccessService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CompanyEmployeePermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_employee_roles_and_permissions_use_user_guard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $company = Company::create([
            'company_name' => 'Guard Test Company',
            'email' => 'company-guard-test@example.com',
            'password' => Hash::make('password123'),
            'status' => 'approved',
            'current_employees' => 1,
        ]);

        $employee = User::create([
            'name' => 'Employee Guard Test',
            'email' => 'employee-guard-test@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'company_id' => $company->id,
            'is_company_employee' => true,
            'is_active' => true,
        ]);

        $service = app(CompanyEmployeeAccessService::class);
        $service->syncRole($employee, 'company_employee');
        $service->syncPermissions($employee, [
            'company.jobs.view',
            'company.jobs.create',
        ]);

        $this->assertSame(
            'user',
            Role::where('name', 'company_employee')->value('guard_name')
        );
        $this->assertSame(
            'user',
            Permission::where('name', 'company.jobs.view')
                ->where('guard_name', 'user')
                ->value('guard_name')
        );
        $this->assertEqualsCanonicalizing(
            ['company.jobs.create', 'company.jobs.view'],
            $service->permissionNames($employee)
        );
        $this->assertSame(['company_employee'], $service->roleNames($employee));
    }

    public function test_owner_only_permissions_are_not_assignable_to_user_employee(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => 'company.employees.create',
            'guard_name' => 'company',
        ]);
        $this->assertDatabaseMissing('permissions', [
            'name' => 'company.employees.create',
            'guard_name' => 'user',
        ]);
    }
    public function test_legacy_company_guard_pivots_are_replaced_without_guard_mismatch(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $company = Company::create([
            'company_name' => 'Legacy Guard Company',
            'email' => 'legacy-company@example.com',
            'password' => Hash::make('password123'),
            'status' => 'approved',
            'current_employees' => 1,
        ]);

        $employee = User::create([
            'name' => 'Legacy Guard Employee',
            'email' => 'legacy-employee@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'company_id' => $company->id,
            'is_company_employee' => true,
            'is_active' => true,
        ]);

        $legacyPermission = Permission::firstOrCreate([
            'name' => 'company.jobs.view',
            'guard_name' => 'company',
        ]);

        DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
            ->insert([
                'permission_id' => $legacyPermission->id,
                'model_type' => User::class,
                'model_id' => $employee->id,
            ]);

        $service = app(CompanyEmployeeAccessService::class);
        $service->syncRole($employee, 'company_employee');
        $service->syncPermissions($employee, ['company.question_bank.view']);

        $this->assertSame(
            ['company.question_bank.view'],
            $service->permissionNames($employee)
        );
        $this->assertDatabaseMissing('model_has_permissions', [
            'permission_id' => $legacyPermission->id,
            'model_type' => User::class,
            'model_id' => $employee->id,
        ]);
    }

    public function test_users_table_records_never_receive_company_owner_bypass(): void
    {
        $user = new User([
            'role' => 'company',
            'company_id' => 999,
            'is_company_employee' => false,
        ]);

        $this->assertFalse($user->isCompanyOwner());
    }

}
