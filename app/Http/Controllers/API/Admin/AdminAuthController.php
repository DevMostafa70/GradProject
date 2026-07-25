<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::where('email', $credentials['email'])->first();

        if (! $admin || ! Hash::check($credentials['password'], $admin->password)) {
            app(ActivityLogService::class)->failed('auth', 'login', 'محاولة تسجيل دخول فاشلة', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
                'reason' => 'Invalid credentials',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $admin->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact super admin.',
            ], 403);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $admin->tokens()->delete();
        $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

        app(ActivityLogService::class)->success('auth', 'login', "قام '{$admin->name}' بتسجيل الدخول", [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'ip' => $request->ip(),
        ]);

        $account = $this->serializeAdmin($admin);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                // account is the canonical key. admin is retained for backward compatibility.
                'account' => $account,
                'admin' => $account,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user();

        if (! $admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeAdmin($admin),
        ]);
    }

    private function serializeAdmin(Admin $admin): array
    {
        $permissions = $admin->getPermissionsByRole();

        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'is_active' => $admin->is_active,
            'roles' => [$admin->getRoleName()],
            'permissions' => $permissions,
            'all_permissions' => $permissions,
            'permission_template_id' => $admin->permission_template_id,
            'permission_template' => $admin->permissionTemplate?->only(['id', 'name', 'is_active']),
            'is_super_admin' => $admin->isSuperAdmin(),
            'last_login_at' => $admin->last_login_at,
            'created_at' => $admin->created_at,
        ];
    }
}
