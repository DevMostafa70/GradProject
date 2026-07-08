<?php

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

        // ✅ جلب صلاحيات المستخدم
        $userPermissions = $this->getUserPermissions($user);

        // ✅ التحقق من الصلاحيات
        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions)) {
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
            'your_permissions' => $userPermissions,
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
            if ($user->isCompanyEmployee()) {
                return 'company';
            }
            return 'user';
        }

        return 'web';
    }

    /**
     * جلب صلاحيات المستخدم
     */
    private function getUserPermissions($user): array
    {
        // ✅ إذا كان المستخدم Admin
        if ($user instanceof \App\Models\Admin) {
            // ✅ استخدام getPermissionsByRole() مباشرة
            return $user->getPermissionsByRole();
        }

        // ✅ إذا كان المستخدم Company
        if ($user instanceof \App\Models\Company) {
            try {
                $permissions = $user->getAllPermissions();
                if ($permissions && $permissions->isNotEmpty()) {
                    return $permissions->pluck('name')->toArray();
                }
            } catch (\Exception $e) {
                return [];
            }
            return [];
        }

        // ✅ إذا كان المستخدم User
        if ($user instanceof \App\Models\User) {
            try {
                $permissions = $user->getAllPermissions();
                if ($permissions && $permissions->isNotEmpty()) {
                    return $permissions->pluck('name')->toArray();
                }
            } catch (\Exception $e) {
                return [];
            }
            return [];
        }

        return [];
    }
}
