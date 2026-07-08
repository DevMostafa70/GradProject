<?php
// app/Http/Middleware/CheckPermission.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckPermission
{
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // ✅ تحديد الـ Guard المناسب
        $guard = $this->getUserGuard($user);

        // ✅ التحقق من الصلاحيات حسب الـ Guard
        foreach ($permissions as $permission) {
            // 1. التحقق من الصلاحيات المباشرة
            $hasDirectPermission = DB::table('model_has_permissions')
                ->where('model_id', $user->id)
                ->where('model_type', get_class($user))
                ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
                ->where('permissions.name', $permission)
                ->where('permissions.guard_name', $guard)
                ->exists();

            if ($hasDirectPermission) {
                return $next($request);
            }

            // 2. التحقق من الصلاحيات عبر الأدوار
            $hasRolePermission = DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', get_class($user))
                ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
                ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
                ->where('permissions.name', $permission)
                ->where('permissions.guard_name', $guard)
                ->exists();

            if ($hasRolePermission) {
                return $next($request);
            }
        }

        // ✅ super_admin لديه كل الصلاحيات
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        // ✅ company_owner لديه كل صلاحيات الشركة
        if (method_exists($user, 'isCompanyOwner') && $user->isCompanyOwner()) {
            foreach ($permissions as $permission) {
                if (str_starts_with($permission, 'company.')) {
                    return $next($request);
                }
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. You do not have the required permission.',
            'required_permissions' => $permissions,
            'your_permissions' => $this->getUserPermissions($user, $guard),
        ], 403);
    }

    /**
     * تحديد الـ Guard المناسب للمستخدم
     */
    private function getUserGuard($user): string
    {
        if ($user instanceof \App\Models\Admin) {
            return 'admin';
        }

        if ($user instanceof \App\Models\Company) {
            return 'company';
        }

        if ($user instanceof \App\Models\User) {
            // ✅ الموظف يستخدم guard = user أيضاً
            return 'user';
        }

        return 'web';
    }

    /**
     * جلب صلاحيات المستخدم
     */
    private function getUserPermissions($user, string $guard): array
    {
        try {
            // 1. الصلاحيات المباشرة
            $directPermissions = DB::table('model_has_permissions')
                ->where('model_id', $user->id)
                ->where('model_type', get_class($user))
                ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
                ->where('permissions.guard_name', $guard)
                ->pluck('permissions.name')
                ->toArray();

            // 2. الصلاحيات عبر الأدوار
            $rolePermissions = DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', get_class($user))
                ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
                ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
                ->where('permissions.guard_name', $guard)
                ->pluck('permissions.name')
                ->toArray();

            return array_unique(array_merge($directPermissions, $rolePermissions));
        } catch (\Exception $e) {
            return [];
        }
    }
}
