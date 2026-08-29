<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserAuthController extends Controller
{
/**
 * Register a new regular user
 * POST /api/user/register
 */
public function register(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users,email',
        'password' => 'required|string|min:6|confirmed',
        'locale' => 'nullable|in:en,ar',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    // ✅ منع تسجيل مستخدم عادي ببريد موظف
    $existingEmployee = User::where('email', $request->email)
        ->where('is_company_employee', true)
        ->exists();

    if ($existingEmployee) {
        return response()->json([
            'success' => false,
            'message' => 'This email is already registered as a company employee.',
        ], 422);
    }

    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'role' => 'user',
        'is_active' => true,
        'locale' => $request->locale ?? 'en',
        'is_company_employee' => false,
        'company_id' => null,
    ]);

    $token = $user->createToken('user-token')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Registration successful',
        'data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'locale' => $user->locale,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ],
    ], 201);
}

/**
 * Login regular user
 * POST /api/user/login
 */
public function login(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    // ✅ 1. البحث عن المستخدم أولاً (بدون استثناء)
    $user = User::where('email', $request->email)->first();

    // ❌ إذا لم يتم العثور على المستخدم
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'No account found with this email address.',
            'error_code' => 'USER_NOT_FOUND',
        ], 401);
    }

    // ❌ إذا كانت كلمة المرور غير صحيحة
    if (!Hash::check($request->password, $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Incorrect password. Please try again.',
            'error_code' => 'INVALID_PASSWORD',
        ], 401);
    }

    // ✅ 2. التحقق من أن المستخدم ليس موظفاً في شركة
    if ($user->isCompanyEmployee()) {
        return response()->json([
            'success' => false,
            'message' => 'This account is a company employee. Please use the company login page.',
            'error_code' => 'COMPANY_EMPLOYEE',
        ], 403);
    }

    // ✅ 3. التحقق من أن المستخدم عادي (role = user)
    if ($user->role !== 'user') {
        return response()->json([
            'success' => false,
            'message' => 'Invalid account type. Please use the correct login page.',
            'error_code' => 'INVALID_ACCOUNT_TYPE',
        ], 403);
    }

    if (!$user->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'Your account has been suspended. Please contact support.',
            'error_code' => 'ACCOUNT_SUSPENDED',
        ], 403);
    }

    $user->tokens()->delete();
    $token = $user->createToken('user-token')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar' => $this->publicUploadUrl($user->avatar),
                'bio' => $user->bio,
                'roles' => $user->getRoleNames(),
                'all_permissions' => $user->getAllPermissions()->pluck('name'),
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ],
    ]);
}

    /**
     * Get user profile
     * GET /api/user/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar' => $this->publicUploadUrl($user->avatar),
                'bio' => $user->bio,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Update user profile
     * PUT /api/user/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string',
            'locale' => 'sometimes|in:en,ar',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('name')) {
            $user->name = $request->name;
        }
        if ($request->has('bio')) {
            $user->bio = $request->bio;
        }
        if ($request->has('avatar')) {
            $user->avatar = $request->avatar;
        }
    if ($request->has('locale')) {
    $user->locale = $request->locale;
}
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $user,
        ]);
    }

    /**
     * Update user password
     * PUT /api/user/password
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect',
            ], 401);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully',
        ]);
    }

    /**
     * Upload avatar
     * POST /api/user/avatar
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpg,jpeg,png,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = time() . '_' . $file->getClientOriginalName();
            $publicDisk = (string) config('uploads.public_disk', 'public');
            $path = $file->storeAs('avatars', $filename, $publicDisk);
            $user->avatar = $path;
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Avatar uploaded successfully',
            'data' => ['avatar_url' => $this->publicUploadUrl($user->avatar)],
        ]);
    }

    /**
     * Logout user
     * POST /api/user/logout
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
     * Get all notifications for the authenticated user
     * GET /api/user/notifications
     */
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $notifications = $user->notifications()
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $notifications->getCollection()->map(function ($notification) {
                $data = $notification->data;

                if (is_string($data)) {
                    $data = json_decode($data, true) ?: [];
                }

                return [
                    'id' => $notification->id,
                    'title' => $data['title'] ?? null,
                    'message' => $data['message'] ?? null,
                    'sender' => $data['sender'] ?? 'admin',
                    'sender_name' => $data['sender_name'] ?? 'Admin',
                    'read_at' => $notification->read_at,
                    'created_at' => $notification->created_at,
                    'is_read' => $notification->read_at !== null,
                ];
            })->values(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Mark a notification as read
     * PUT /api/user/notifications/{id}/read
     */
    public function markNotificationAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     * PUT /api/user/notifications/read-all
     */
    public function markAllNotificationsAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $updated = $user->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'data' => [
                'updated_count' => $updated,
                'unread_count' => 0,
            ],
        ]);
    }


    /**
 * Delete all notifications for the authenticated user
 * DELETE /api/user/notifications
 */
public function deleteAllNotifications(Request $request): JsonResponse
{
    $user = $request->user();
    $deleted = $user->notifications()->delete();

    return response()->json([
        'success' => true,
        'message' => "Deleted {$deleted} notifications",
        'data' => [
            'deleted_count' => $deleted,
        ],
    ]);
}

/**
 * Delete a specific notification
 * DELETE /api/user/notifications/{id}
 */
public function deleteNotification(Request $request, string $id): JsonResponse
{
    $user = $request->user();
    $notification = $user->notifications()->find($id);

    if (!$notification) {
        return response()->json([
            'success' => false,
            'message' => 'Notification not found',
        ], 404);
    }

    $notification->delete();

    return response()->json([
        'success' => true,
        'message' => 'Notification deleted successfully',
    ]);
}

private function publicUploadUrl(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }

    $storagePath = preg_replace('#^/?storage/#', '', $path) ?? $path;

    return Storage::disk((string) config('uploads.public_disk', 'public'))
        ->url(ltrim($storagePath, '/'));
}
}
