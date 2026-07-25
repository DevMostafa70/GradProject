<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $assignablePermissions = [
        'company.dashboard.view',
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
        'company.usage.view',
        'company.notifications.view',
    ];

    /** @var array<string, array<int, string>> */
    private array $dependencies = [
        'company.jobs.update' => ['company.jobs.view'],
        'company.jobs.delete' => ['company.jobs.view'],
        'company.jobs.close' => ['company.jobs.view'],
        'company.candidates.update' => ['company.candidates.view'],
        'company.results.export' => ['company.results.view'],
        'company.question_bank.create' => ['company.question_bank.view'],
        'company.question_bank.update' => ['company.question_bank.view'],
        'company.question_bank.delete' => ['company.question_bank.view'],
    ];

    /** @var array<string, array<int, string>> */
    private array $rolePermissions = [
        'company_hr' => [
            'company.dashboard.view',
            'company.candidates.view',
            'company.candidates.invite',
            'company.candidates.update',
            'company.interviews.view',
            'company.results.view',
            'company.notifications.view',
        ],
        'company_interviewer' => [
            'company.dashboard.view',
            'company.jobs.view',
            'company.candidates.view',
            'company.interviews.view',
            'company.results.view',
            'company.notifications.view',
        ],
        'company_recruiter' => [
            'company.dashboard.view',
            'company.jobs.view',
            'company.candidates.view',
            'company.candidates.invite',
            'company.candidates.update',
            'company.interviews.view',
            'company.results.view',
            'company.notifications.view',
        ],
        'company_question_bank_manager' => [
            'company.dashboard.view',
            'company.jobs.view',
            'company.question_bank.view',
            'company.question_bank.create',
            'company.question_bank.update',
            'company.question_bank.delete',
            'company.notifications.view',
        ],
        'company_viewer' => [
            'company.dashboard.view',
            'company.jobs.view',
            'company.candidates.view',
            'company.interviews.view',
            'company.results.view',
            'company.question_bank.view',
            'company.notifications.view',
        ],
        'company_employee' => [],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('model_has_permissions')
            || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $now = now();

        foreach ($this->assignablePermissions as $permissionName) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permissionName, 'guard_name' => 'user'],
                ['updated_at' => $now, 'created_at' => $now]
            );
        }

        foreach (array_keys($this->rolePermissions) as $roleName) {
            DB::table('roles')->updateOrInsert(
                ['name' => $roleName, 'guard_name' => 'user'],
                ['updated_at' => $now, 'created_at' => $now]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'user')
            ->whereIn('name', $this->assignablePermissions)
            ->pluck('id', 'name');

        $roleIds = DB::table('roles')
            ->where('guard_name', 'user')
            ->whereIn('name', array_keys($this->rolePermissions))
            ->pluck('id', 'name');

        foreach ($this->rolePermissions as $roleName => $permissions) {
            $roleId = $roleIds[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }

            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();

            foreach ($permissions as $permissionName) {
                $permissionId = $permissionIds[$permissionName] ?? null;
                if ($permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        // Repair orphaned legacy rows first. An employee without a company
        // cannot authenticate as a company employee and must not retain
        // company access.
        $orphanEmployeeIds = DB::table('users')
            ->where('is_company_employee', true)
            ->whereNull('company_id')
            ->pluck('id');

        if ($orphanEmployeeIds->isNotEmpty()) {
            $allCompanyPermissionIds = DB::table('permissions')
                ->where('name', 'like', 'company.%')
                ->pluck('id');
            $allEmployeeRoleIds = DB::table('roles')
                ->whereIn('name', array_keys($this->rolePermissions))
                ->pluck('id');

            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->whereIn('model_id', $orphanEmployeeIds)
                ->whereIn('permission_id', $allCompanyPermissionIds)
                ->delete();

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $orphanEmployeeIds)
                ->whereIn('role_id', $allEmployeeRoleIds)
                ->delete();

            DB::table('users')
                ->whereIn('id', $orphanEmployeeIds)
                ->update([
                    'is_company_employee' => false,
                    'company_permission_template_id' => null,
                ]);

            $regularUserRoleId = DB::table('roles')
                ->where('name', 'regular_user')
                ->where('guard_name', 'user')
                ->value('id');

            if ($regularUserRoleId) {
                foreach ($orphanEmployeeIds as $orphanEmployeeId) {
                    DB::table('model_has_roles')->insertOrIgnore([
                        'role_id' => $regularUserRoleId,
                        'model_type' => User::class,
                        'model_id' => $orphanEmployeeId,
                    ]);
                }
            }
        }

        $employeeIds = DB::table('users')
            ->where('is_company_employee', true)
            ->whereNotNull('company_id')
            ->pluck('id');

        foreach ($employeeIds as $employeeId) {
            $employeeTemplateId = DB::table('users')
                ->where('id', $employeeId)
                ->value('company_permission_template_id');

            $currentPermissionNames = collect();

            if ($employeeTemplateId && Schema::hasTable('company_permission_templates')) {
                $templatePermissions = DB::table('company_permission_templates')
                    ->where('id', $employeeTemplateId)
                    ->whereNull('deleted_at')
                    ->value('permissions');
                $decodedTemplatePermissions = json_decode((string) $templatePermissions, true);

                if (is_array($decodedTemplatePermissions)) {
                    $currentPermissionNames = collect($decodedTemplatePermissions);
                }
            }

            // Custom-access employees, or employees linked to a missing legacy
            // template, retain their existing access by permission name.
            if ($currentPermissionNames->isEmpty()) {
                $currentPermissionNames = DB::table('model_has_permissions as mhp')
                    ->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
                    ->where('mhp.model_type', User::class)
                    ->where('mhp.model_id', $employeeId)
                    ->where('p.name', 'like', 'company.%')
                    ->pluck('p.name');
            }

            $currentPermissionNames = collect(
                $this->normalizePermissions($currentPermissionNames->all())
            );

            $allCompanyPermissionIds = DB::table('permissions')
                ->where('name', 'like', 'company.%')
                ->pluck('id');

            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->where('model_id', $employeeId)
                ->whereIn('permission_id', $allCompanyPermissionIds)
                ->delete();

            foreach ($currentPermissionNames as $permissionName) {
                $permissionId = $permissionIds[$permissionName] ?? null;
                if ($permissionId) {
                    DB::table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'model_type' => User::class,
                        'model_id' => $employeeId,
                    ]);
                }
            }

            $currentRoleName = DB::table('model_has_roles as mhr')
                ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('mhr.model_type', User::class)
                ->where('mhr.model_id', $employeeId)
                ->whereIn('r.name', array_keys($this->rolePermissions))
                ->orderByRaw("case when r.guard_name = 'user' then 0 else 1 end")
                ->value('r.name');

            $currentRoleName = $currentRoleName ?: 'company_employee';
            $targetRoleId = $roleIds[$currentRoleName] ?? $roleIds['company_employee'] ?? null;

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $employeeId)
                ->delete();

            if ($targetRoleId) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $targetRoleId,
                    'model_type' => User::class,
                    'model_id' => $employeeId,
                ]);
            }
        }

        // Remove obsolete employee-role definitions under the company guard.
        // Company owners use only company_owner; employee roles belong to User.
        $legacyEmployeeRoleIds = DB::table('roles')
            ->where('guard_name', 'company')
            ->whereIn('name', array_keys($this->rolePermissions))
            ->pluck('id');

        if ($legacyEmployeeRoleIds->isNotEmpty()) {
            DB::table('model_has_roles')->whereIn('role_id', $legacyEmployeeRoleIds)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $legacyEmployeeRoleIds)->delete();
            DB::table('roles')->whereIn('id', $legacyEmployeeRoleIds)->delete();
        }

        if (Schema::hasTable('company_permission_templates')) {
            DB::table('company_permission_templates')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'permissions'])
                ->each(function (object $template): void {
                    $permissions = json_decode((string) $template->permissions, true);
                    $permissions = $this->normalizePermissions(
                        is_array($permissions) ? $permissions : []
                    );

                    DB::table('company_permission_templates')
                        ->where('id', $template->id)
                        ->update(['permissions' => json_encode($permissions)]);
                });
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'current_employees')) {
            DB::table('companies')->orderBy('id')->get(['id'])->each(function (object $company): void {
                $employeeCount = DB::table('users')
                    ->where('company_id', $company->id)
                    ->where('is_company_employee', true)
                    ->count();

                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['current_employees' => 1 + $employeeCount]);
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<int, mixed> $permissions
     *  @return array<int, string>
     */
    private function normalizePermissions(array $permissions): array
    {
        $normalized = array_values(array_intersect(
            array_values(array_unique(array_filter(
                $permissions,
                static fn (mixed $permission): bool => is_string($permission) && $permission !== ''
            ))),
            $this->assignablePermissions
        ));

        do {
            $before = count($normalized);
            foreach ($normalized as $permission) {
                foreach ($this->dependencies[$permission] ?? [] as $dependency) {
                    if (! in_array($dependency, $normalized, true)) {
                        $normalized[] = $dependency;
                    }
                }
            }
        } while (count($normalized) !== $before);

        return array_values(array_intersect($this->assignablePermissions, $normalized));
    }

    public function down(): void
    {
        // Data normalization is intentionally irreversible.
    }
};
