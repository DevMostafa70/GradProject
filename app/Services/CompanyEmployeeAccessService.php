<?php

namespace App\Services;

use App\Models\User;
use App\Support\CompanyEmployeePermissionCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class CompanyEmployeeAccessService
{
    public const GUARD = 'user';

    /**
     * @param array<int, string> $permissionNames
     * @return array<int, string>
     */
    public function syncPermissions(User $employee, array $permissionNames): array
    {
        $this->assertCompanyEmployee($employee);

        $requested = array_values(array_unique(array_filter(
            $permissionNames,
            static fn (mixed $name): bool => is_string($name) && $name !== ''
        )));

        $notAssignable = array_values(array_diff(
            $requested,
            CompanyEmployeePermissionCatalog::ASSIGNABLE
        ));

        if ($notAssignable !== []) {
            throw new InvalidArgumentException(
                'These permissions cannot be assigned to a company employee: ' . implode(', ', $notAssignable)
            );
        }

        $requested = CompanyEmployeePermissionCatalog::sanitize($requested);

        $permissions = Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', $requested)
            ->get();

        $foundNames = $permissions->pluck('name')->values()->all();
        $missingNames = array_values(array_diff($requested, $foundNames));

        if ($missingNames !== []) {
            throw new InvalidArgumentException(
                'Missing user-guard company permissions. Run RolesAndPermissionsSeeder: ' . implode(', ', $missingNames)
            );
        }

        // The User model uses the user guard, so Spatie's normal API is now safe.
        $employee->syncPermissions($permissions);
        $this->forgetCache($employee);

        return $foundNames;
    }

    public function syncRole(User $employee, string $roleName): string
    {
        $this->assertCompanyEmployee($employee);

        if (! in_array($roleName, CompanyEmployeePermissionCatalog::ROLES, true)) {
            throw new InvalidArgumentException("Invalid company employee role: {$roleName}");
        }

        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', self::GUARD)
            ->first();

        if (! $role) {
            throw new InvalidArgumentException(
                "Missing user-guard role '{$roleName}'. Run RolesAndPermissionsSeeder."
            );
        }

        $employee->syncRoles([$role]);
        $this->forgetCache($employee);

        return $role->name;
    }


    /** @return array<int, string> */
    public function permissionNames(User $employee): array
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $modelKey = $columnNames['model_morph_key'] ?? 'model_id';
        $modelType = $employee->getMorphClass();
        $permissionsTable = $tableNames['permissions'] ?? 'permissions';
        $rolesTable = $tableNames['roles'] ?? 'roles';
        $modelPermissionsTable = $tableNames['model_has_permissions'] ?? 'model_has_permissions';
        $modelRolesTable = $tableNames['model_has_roles'] ?? 'model_has_roles';
        $rolePermissionsTable = $tableNames['role_has_permissions'] ?? 'role_has_permissions';

        $direct = DB::table("{$modelPermissionsTable} as mhp")
            ->join("{$permissionsTable} as p", 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_type', $modelType)
            ->where("mhp.{$modelKey}", $employee->getKey())
            ->where('p.guard_name', self::GUARD)
            ->pluck('p.name');

        $viaRoles = DB::table("{$modelRolesTable} as mhr")
            ->join("{$rolesTable} as r", 'r.id', '=', 'mhr.role_id')
            ->join("{$rolePermissionsTable} as rhp", 'rhp.role_id', '=', 'r.id')
            ->join("{$permissionsTable} as p", 'p.id', '=', 'rhp.permission_id')
            ->where('mhr.model_type', $modelType)
            ->where("mhr.{$modelKey}", $employee->getKey())
            ->where('r.guard_name', self::GUARD)
            ->where('p.guard_name', self::GUARD)
            ->pluck('p.name');

        return $direct
            ->merge($viaRoles)
            ->unique()
            ->intersect(CompanyEmployeePermissionCatalog::ASSIGNABLE)
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function roleNames(User $employee): array
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $modelKey = $columnNames['model_morph_key'] ?? 'model_id';
        $modelType = $employee->getMorphClass();
        $rolesTable = $tableNames['roles'] ?? 'roles';
        $modelRolesTable = $tableNames['model_has_roles'] ?? 'model_has_roles';

        return DB::table("{$modelRolesTable} as mhr")
            ->join("{$rolesTable} as r", 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $modelType)
            ->where("mhr.{$modelKey}", $employee->getKey())
            ->where('r.guard_name', self::GUARD)
            ->whereIn('r.name', CompanyEmployeePermissionCatalog::ROLES)
            ->orderBy('r.name')
            ->pluck('r.name')
            ->values()
            ->all();
    }

    public function clear(User $employee): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $modelKey = $columnNames['model_morph_key'] ?? 'model_id';
        $modelType = $employee->getMorphClass();

        DB::table($tableNames['model_has_permissions'] ?? 'model_has_permissions')
            ->where('model_type', $modelType)
            ->where($modelKey, $employee->getKey())
            ->delete();

        DB::table($tableNames['model_has_roles'] ?? 'model_has_roles')
            ->where('model_type', $modelType)
            ->where($modelKey, $employee->getKey())
            ->delete();

        $this->forgetCache($employee);
    }

    private function assertCompanyEmployee(User $employee): void
    {
        if (! $employee->isCompanyEmployee()) {
            throw new InvalidArgumentException('The selected account is not an active company employee.');
        }
    }

    private function forgetCache(User $employee): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $employee->unsetRelation('permissions');
        $employee->unsetRelation('roles');
    }
}
