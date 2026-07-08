<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // ✅ جلب أدوار المستخدم
        $userRoles = $this->getUserRoles($user);

        // ✅ جلب صلاحيات المستخدم
        $userPermissions = $this->getUserPermissions($user);

        \Illuminate\Support\Facades\Log::info('CheckRole Middleware', [
            'user_id' => $user->id ?? null,
            'user_type' => get_class($user),
            'user_roles' => $userRoles,
            'user_permissions' => $userPermissions,
            'required_roles' => $roles,
        ]);

        // ✅ 1. التحقق من الأدوار المطلوبة
        $hasRole = false;
        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                $hasRole = true;
                break;
            }
        }

        // ✅ 2. إذا لم يكن لديه دور، تحقق من الصلاحيات
        if (!$hasRole) {
            // ✅ التحقق من وجود أي صلاحية تبدأ بـ 'company.' (للموظفين)
            foreach ($userPermissions as $permission) {
                if (str_starts_with($permission, 'company.')) {
                    $hasRole = true;
                    break;
                }
            }
        }

        // ✅ 3. إذا كان لديه صلاحيات company.*، اسمح له بالدخول
        if (!$hasRole) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You do not have the required role or permissions.',
                'required_roles' => $roles,
                'your_roles' => $userRoles,
                'your_permissions' => $userPermissions,
            ], 403);
        }

        return $next($request);
    }

    /**
     * الحصول على أدوار المستخدم
     */
    private function getUserRoles($user): array
    {
        try {
            if (method_exists($user, 'getRoleNames')) {
                $roles = $user->getRoleNames();
                if ($roles && $roles->isNotEmpty()) {
                    return $roles->toArray();
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('CheckRole: Spatie role fetch failed', [
                'error' => $e->getMessage(),
                'user_type' => get_class($user),
            ]);
        }

        // Fallback
        if ($user instanceof \App\Models\Admin) {
            return [$user->role ?? 'admin'];
        }

        if ($user instanceof \App\Models\Company) {
            return ['company_owner'];
        }

        if ($user instanceof \App\Models\User) {
            if ($user->isCompanyEmployee()) {
                return ['company_employee'];
            }
            return ['regular_user'];
        }

        return ['unknown'];
    }

/**
 * الحصول على صلاحيات المستخدم
 */
private function getUserPermissions($user): array
{
    try {
        // ✅ إذا كان المستخدم Admin
        if ($user instanceof \App\Models\Admin) {
            // ✅ استخدام getPermissionsByRole() بدلاً من getAllPermissions()
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

        // ✅ إذا كان المستخدم User (عادي أو موظف)
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
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning('CheckRole: Failed to get permissions', [
            'error' => $e->getMessage(),
            'user_type' => get_class($user),
        ]);
        return [];
    }
}
}
