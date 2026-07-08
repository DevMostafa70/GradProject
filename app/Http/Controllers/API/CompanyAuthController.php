<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules;
use Spatie\Permission\Models\Role;

class CompanyAuthController extends Controller
{
    /**
     * Register a new company
     * POST /api/company/register
     */
public function register(Request $request): JsonResponse
{
    $request->validate([
        'company_name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:companies',
        'password' => ['required', 'confirmed', Rules\Password::defaults()],
        'industry' => 'nullable|string|max:255',
        'phone' => 'nullable|string|max:20',
    ]);

    // إنشاء الشركة
    $company = Company::create([
        'company_name' => $request->company_name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'industry' => $request->industry,
        'phone' => $request->phone,
        'status' => 'pending',
        'current_employees' => 1, // ✅ NEW: Owner is the first employee
    ]);

    // ✅ NEW: تعيين دور company_owner تلقائياً
    $ownerRole = Role::where('name', 'company_owner')
        ->where('guard_name', 'company')
        ->first();

    if ($ownerRole) {
        $company->assignRole($ownerRole);
    }

    $token = $company->createToken('company-token')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Company registered successfully. Waiting for admin approval.',
        'data' => [
            'company' => [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'email' => $company->email,
                'status' => $company->status,
                'roles' => $company->getRoleNames(),
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ],
    ], 201);
}

/**
 * Login company or employee
 * POST /api/company/login
 */
public function login(Request $request): JsonResponse
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    // ✅ 1. البحث عن Company Owner أولاً
    $company = Company::where('email', $request->email)->first();

    if ($company && Hash::check($request->password, $company->password)) {
        // ✅ هذا Company Owner
        if ($company->status !== 'approved') {
            $message = $company->status === 'pending'
                ? 'Your company account is pending admin approval. Please wait for confirmation.'
                : 'Your company account has been suspended or rejected. Please contact support.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'error_code' => 'COMPANY_' . strtoupper($company->status),
            ], 403);
        }

        $company->tokens()->delete();
        $token = $company->createToken('company-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'company' => [
                    'id' => $company->id,
                    'company_name' => $company->company_name,
                    'email' => $company->email,
                    'status' => $company->status,
                    'industry' => $company->industry,
                    'phone' => $company->phone,
                    'roles' => $company->getRoleNames(),
                    'all_permissions' => $company->getAllPermissions()->pluck('name'),
                    'is_company_owner' => true,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    // ✅ 2. البحث عن Employee في جدول users
    $employee = User::where('email', $request->email)
        ->where('is_company_employee', true)
        ->first();

    // ❌ إذا لم يتم العثور على الموظف
    if (!$employee) {
        return response()->json([
            'success' => false,
            'message' => 'No account found with this email address.',
            'error_code' => 'USER_NOT_FOUND',
        ], 401);
    }

    // ❌ إذا كانت كلمة المرور غير صحيحة
    if (!Hash::check($request->password, $employee->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Incorrect password. Please try again.',
            'error_code' => 'INVALID_PASSWORD',
        ], 401);
    }

    // ✅ التحقق من أن الموظف مفعل
    if (!$employee->is_active) {
        return response()->json([
            'success' => false,
            'message' => 'Your account has been deactivated. Please contact your company administrator.',
            'error_code' => 'ACCOUNT_DEACTIVATED',
        ], 403);
    }

    // ✅ التحقق من أن الشركة لا تزال نشطة
    $company = $employee->company;
    if ($company && $company->status !== 'approved') {
        return response()->json([
            'success' => false,
            'message' => 'Your company account is not active. Please contact support.',
            'error_code' => 'COMPANY_INACTIVE',
        ], 403);
    }

    $employee->tokens()->delete();
    $token = $employee->createToken('company-token')->plainTextToken;

    // ✅ جلب الصلاحيات من guard = user
    $permissions = $employee->getAllPermissions()->pluck('name');

    return response()->json([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'company' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'status' => $company?->status ?? 'active',
                'roles' => $employee->getRoleNames(),
                'all_permissions' => $permissions,
                'is_company_owner' => false,
                'is_company_employee' => true,
                'company_id' => $employee->company_id,
                'company_name' => $company?->company_name,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ],
    ]);
}

/**
 * جلب صلاحيات الموظف من قاعدة البيانات
 */
private function getEmployeePermissions($user): array
{
    try {
        return DB::table('model_has_permissions')
            ->where('model_id', $user->id)
            ->where('model_type', 'App\\Models\\User')
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.guard_name', 'company')
            ->pluck('permissions.name')
            ->toArray();
    } catch (\Exception $e) {
        return [];
    }
}

/**
 * جلب معلومات الموظف
 */
private function getEmployeeInfo($user): ?array
{
    if (!$user->is_company_employee || !$user->company_id) {
        return null;
    }

    $company = $user->company;

    if (!$company) {
        return null;
    }

    return [
        'company_id' => $company->id,
        'company_name' => $company->company_name,
        'plan' => $company->selectedPlan?->name,
        'max_employees' => $company->getMaxEmployees(),
        'current_employees' => $company->getCurrentEmployees(),
        'remaining_slots' => $company->getRemainingEmployeeSlots(),
        'is_owner' => false,
    ];
}


    /**
     * Logout company
     * POST /api/company/logout
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
     * Get company profile
     * GET /api/company/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $company = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'email' => $company->email,
                'logo' => $company->logo,
                'industry' => $company->industry,
                'website' => $company->website,
                'description' => $company->description,
                'phone' => $company->phone,
                'address' => $company->address,
                'status' => $company->status,
                'created_at' => $company->created_at,
            ],
        ]);
    }

    /**
     * Update company profile
     * PUT /api/company/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $company = $request->user();

        // ✅ التحقق من صحة البيانات (مع دعم _method)
        $request->validate([
            'company_name' => 'sometimes|string|max:255',
            'industry' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255|url',
            'description' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5120',
            '_method' => 'nullable|string|in:PUT', // السماح بـ _method
        ]);

        // ✅ تحديث البيانات النصية
        if ($request->has('company_name')) {
            $company->company_name = $request->company_name;
        }
        if ($request->has('industry')) {
            $company->industry = $request->industry;
        }
        if ($request->has('website')) {
            $company->website = $request->website;
        }
        if ($request->has('description')) {
            $company->description = $request->description;
        }
        if ($request->has('phone')) {
            $company->phone = $request->phone;
        }
        if ($request->has('address')) {
            $company->address = $request->address;
        }

        // ✅ رفع الشعار (Logo)
        if ($request->hasFile('logo')) {
            // حذف الشعار القديم إذا وجد
            if ($company->logo && Storage::disk('public')->exists($company->logo)) {
                Storage::disk('public')->delete($company->logo);
            }

            // حفظ الشعار الجديد
            $file = $request->file('logo');
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs('companies/logos', $filename, 'public');
            $company->logo = $path;
        }

        $company->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'email' => $company->email,
                'logo' => $company->logo ? asset('storage/' . $company->logo) : null,
                'industry' => $company->industry,
                'website' => $company->website,
                'description' => $company->description,
                'phone' => $company->phone,
                'address' => $company->address,
                'status' => $company->status,
                'updated_at' => $company->updated_at,
            ],
        ]);
    }



    /**
     * Get all notifications for the authenticated company
     * GET /api/company/notifications
     */
    public function notifications(Request $request): JsonResponse
    {
        $company = $request->user();

        // ✅ Debug: تحقق من وجود المستخدم
        Log::info('Company notifications request', [
            'company_id' => $company->id,
            'company_class' => get_class($company),
        ]);

        // ✅ جلب الإشعارات باستخدام DatabaseNotification مباشرة
        $notifications = \Illuminate\Notifications\DatabaseNotification::where('notifiable_type', 'App\Models\Company')
            ->where('notifiable_id', $company->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        Log::info('Notifications found: ' . $notifications->count());

        return response()->json([
            'success' => true,
            'data' => $notifications->map(function ($notification) {
                $data = $notification->data;
                if (is_string($data)) {
                    $data = json_decode($data, true);
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
            }),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'unread_count' => $notifications->whereNull('read_at')->count(),
            ],
        ]);
    }

    /**
     * Mark a notification as read
     * PUT /api/company/notifications/{id}/read
     */
    public function markNotificationAsRead(Request $request, string $id): JsonResponse
    {
        $company = $request->user();
        $notification = $company->notifications()->find($id);

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
 * Delete all notifications for the authenticated company
 * DELETE /api/company/notifications
 */
public function deleteAllNotifications(Request $request): JsonResponse
{
    $company = $request->user();
    $deleted = $company->notifications()->delete();

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
 * DELETE /api/company/notifications/{id}
 */
public function deleteNotification(Request $request, string $id): JsonResponse
{
    $company = $request->user();
    $notification = $company->notifications()->find($id);

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
}
