<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

    // البحث عن الأدمن مباشرة في جدول admins
    $admin = Admin::where('email', $request->email)->first();

    // التحقق من وجود الأدمن وصحة كلمة المرور
    if (!$admin || !Hash::check($request->password, $admin->password)) {
        //  تسجيل محاولة فاشلة
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
        //  تسجيل محاولة دخول لحساب معلق
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

    // تسجيل وقت آخر دخول
    $admin->update(['last_login_at' => now()]);

    // حذف التوكنات القديمة
    $admin->tokens()->delete();

    // إنشاء توكن جديد
    $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

    //  تسجيل نجاح تسجيل الدخول
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
                'permissions' => $admin->permissions,
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

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'permissions' => $admin->permissions,
                'last_login_at' => $admin->last_login_at,
                'created_at' => $admin->created_at,
            ],
        ]);
    }
}
