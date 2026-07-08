<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AdminAuthController extends Controller
{
    /**
     * Login admin
     * POST /api/admin/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            app(\App\Services\ActivityLogService::class)->failed(
                'auth',
                'login',
                'محاولة تسجيل دخول فاشلة',
                [
                    'email' => $request->email,
                    'ip' => $request->ip(),
                    'reason' => 'Invalid credentials',
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (!$admin->is_active) {
            app(\App\Services\ActivityLogService::class)->warning(
                'auth',
                'login',
                "محاولة تسجيل دخول لحساب معلق '{$admin->email}'",
                [
                    'admin_id' => $admin->id,
                    'admin_email' => $admin->email,
                    'ip' => $request->ip(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact super admin.',
            ], 403);
        }

        $admin->update(['last_login_at' => now()]);
        $admin->tokens()->delete();
        $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

        app(\App\Services\ActivityLogService::class)->success(
            'auth',
            'login',
            "قام '{$admin->name}' بتسجيل الدخول",
            [
                'admin_id' => $admin->id,
                'admin_email' => $admin->email,
                'ip' => $request->ip(),
            ]
        );

        // ✅ جلب الأدوار والصلاحيات (تجنب getAllPermissions)
        $roles = $this->getRolesSafe($admin);
        $permissions = $this->getPermissionsSafe($admin);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                    'is_active' => $admin->is_active,
                    'roles' => $roles,
                    'all_permissions' => $permissions,
                    'is_super_admin' => $admin->isSuperAdmin(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Logout admin
     * POST /api/admin/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get admin profile
     * GET /api/admin/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $roles = $this->getRolesSafe($admin);
        $permissions = $this->getPermissionsSafe($admin);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active,
                'roles' => $roles,
                'permissions' => $permissions,
                'is_super_admin' => $admin->isSuperAdmin(),
                'last_login_at' => $admin->last_login_at,
                'created_at' => $admin->created_at,
            ],
        ]);
    }

    // ============================================================
    // ✅ Helper Methods (آمنة تماماً - بدون Spatie)
    // ============================================================

    /**
     * جلب الأدوار بطريقة آمنة (بدون Spatie)
     */
    private function getRolesSafe(Admin $admin): array
    {
        // ✅ استخدام الدور المخزن في جدول admins
        return [$admin->role ?? 'admin'];
    }

    /**
     * جلب الصلاحيات بطريقة آمنة (بدون Spatie - من قاعدة البيانات مباشرة)
     */
    private function getPermissionsSafe(Admin $admin): array
    {
        // ✅ 1. محاولة جلب الصلاحيات من جدول model_has_permissions
        try {
            $permissions = DB::table('model_has_permissions')
                ->where('model_id', $admin->id)
                ->where('model_type', 'App\\Models\\Admin')
                ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
                ->where('permissions.guard_name', 'admin')
                ->pluck('permissions.name')
                ->toArray();

            if (!empty($permissions)) {
                return $permissions;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get permissions from model_has_permissions', [
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);
        }

        // ✅ 2. إذا لم توجد صلاحيات في model_has_permissions، استخدم الصلاحيات حسب الدور
        if ($admin->isSuperAdmin()) {
            return $this->getAllAdminPermissions();
        }

        return $this->getAdminPermissions();
    }

    /**
     * ✅ جميع صلاحيات الأدمن (للـ super_admin)
     */
    private function getAllAdminPermissions(): array
    {
        return [
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
    }

    /**
     * ✅ صلاحيات الأدمن العادي
     */
    private function getAdminPermissions(): array
    {
        return [
            'admin.dashboard.view',
            'admin.users.view',
            'admin.users.create',
            'admin.users.update',
            'admin.companies.view',
            'admin.companies.approve',
            'admin.companies.reject',
            'admin.jobs.view',
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
            'admin.backups.view',
            'admin.backups.create',
            'admin.backups.download',
            'admin.plans.view',
            'admin.billing.view',
        ];
    }
}
