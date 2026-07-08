<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminRequest;
use App\Models\User;
use App\Models\Candidate;
use App\Models\Admin;
use App\Models\Company;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;
use App\Models\PermissionTemplate;

class AdminUserController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    /**
     * Get all regular users with pagination (جلب جميع المستخدمين العاديين)
     */
    public function index(Request $request): JsonResponse
    {
        // جلب المستخدمين العاديين فقط (role = 'user')
        $users = User::where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $users->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'avatar' => $user->avatar,
                'bio' => $user->bio,
                'created_at' => $user->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Get candidates for a specific company (جلب مرشحي شركة معينة)
     * GET /api/admin/companies/{company}/candidates
     */
    public function companyCandidates(Company $company, Request $request): JsonResponse
    {
        // جلب جميع الوظائف التابعة للشركة
        $jobIds = $company->jobs()->pluck('id');

        if ($jobIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'company' => [
                        'id' => $company->id,
                        'company_name' => $company->company_name,
                    ],
                    'candidates' => [],
                    'total' => 0,
                ],
            ]);
        }

        // جلب المرشحين المرتبطين بهذه الوظائف
        $candidates = Candidate::with('job')
            ->whereIn('company_job_id', $jobIds)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $candidates->getCollection()->transform(function ($candidate) {
            return [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'company_job_id' => $candidate->company_job_id,
                'job_title' => $candidate->job->title ?? null,
                'status' => $candidate->status,
                'invitation_token' => $candidate->invitation_token,
                'invited_at' => $candidate->invited_at,
                'started_at' => $candidate->started_at,
                'completed_at' => $candidate->completed_at,
                'final_score' => $candidate->final_score,
                'created_at' => $candidate->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'company' => [
                    'id' => $company->id,
                    'company_name' => $company->company_name,
                    'email' => $company->email,
                    'industry' => $company->industry,
                ],
                'candidates' => $candidates,
            ],
        ]);
    }

    /**
     * Get all candidates only (جلب جميع مرشحي الشركات) - مستقل بدون user
     */
    public function candidatesList(Request $request): JsonResponse
    {
        $candidates = Candidate::with('job.company')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $candidates->getCollection()->transform(function ($candidate) {
            return [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'company_job_id' => $candidate->company_job_id,
                'job_title' => $candidate->job->title ?? null,
                'company_name' => $candidate->job->company->company_name ?? null,
                'status' => $candidate->status,
                'invitation_token' => $candidate->invitation_token,
                'invited_at' => $candidate->invited_at,
                'started_at' => $candidate->started_at,
                'completed_at' => $candidate->completed_at,
                'final_score' => $candidate->final_score,
                'created_at' => $candidate->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $candidates,
        ]);
    }

    /**
     * Show specific candidate (عرض مرشح محدد)
     */
    public function showCandidate(Candidate $candidate): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'company_job_id' => $candidate->company_job_id,
                'job_title' => $candidate->job->title ?? null,
                'status' => $candidate->status,
                'invitation_token' => $candidate->invitation_token,
                'invited_at' => $candidate->invited_at,
                'started_at' => $candidate->started_at,
                'completed_at' => $candidate->completed_at,
                'final_score' => $candidate->final_score,
                'created_at' => $candidate->created_at,
            ],
        ]);
    }

    /**
     * Get user details (عرض مستخدم عادي محدد)
     */
    public function show(User $user): JsonResponse
    {
        // التحقق من أن المستخدم عادي
        if ($user->role !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'هذا المستخدم ليس مستخدماً عادياً.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'avatar' => $user->avatar,
                'bio' => $user->bio,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    // ============================================================
    // ✅ ADMIN MANAGEMENT (محدث مع Spatie)
    // ============================================================

/**
 * Create a new admin
 * POST /api/admin/admins
 */
public function store(CreateAdminRequest $request): JsonResponse
{
    Log::info('Creating new admin:', [
        'email' => $request->email,
        'role' => $request->role ?? 'admin',
        'template_id' => $request->template_id,
        'permissions' => $request->permissions ?? 'default',
    ]);

    try {
        // ✅ 1. إنشاء الأدمن
        $admin = Admin::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => $request->role ?? 'admin',
            'is_active' => true,
            'last_login_at' => null,
        ]);

        $roleName = $request->role ?? 'admin';
        $role = Role::where('name', $roleName)->where('guard_name', 'admin')->first();

        if ($role) {
            $admin->assignRole($role);
        }

        // ✅ 2. تعيين الصلاحيات (من قالب أو مخصصة)
        $permissionsToAssign = [];

        // ✅ 2.1: إذا تم إرسال template_id، استخدم صلاحيات القالب
        if ($request->has('template_id') && $request->template_id) {
            $template = PermissionTemplate::find($request->template_id);

            if ($template) {
                $permissionsToAssign = $template->permissions;
                Log::info('Using template permissions', [
                    'template_id' => $template->id,
                    'template_name' => $template->name,
                    'permissions_count' => count($permissionsToAssign),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Template not found',
                ], 404);
            }
        }
        // ✅ 2.2: إذا تم إرسال صلاحيات مخصصة، استخدمها
        elseif ($request->has('permissions') && is_array($request->permissions) && !empty($request->permissions)) {
            $permissionsToAssign = $request->permissions;
            Log::info('Using custom permissions', [
                'permissions_count' => count($permissionsToAssign),
            ]);
        }
        // ✅ 2.3: إذا لم يتم إرسال أي شيء، استخدم الصلاحيات الافتراضية حسب الدور
        else {
            if ($roleName === 'admin') {
                $permissionsToAssign = [
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
            } elseif ($roleName === 'super_admin') {
                $permissionsToAssign = Permission::where('guard_name', 'admin')->pluck('name')->toArray();
            }
        }

        // ✅ 3. تعيين الصلاحيات للأدمن
        if (!empty($permissionsToAssign)) {
            // ✅ التأكد من وجود الصلاحيات في قاعدة البيانات
            foreach ($permissionsToAssign as $permName) {
                Permission::firstOrCreate([
                    'name' => $permName,
                    'guard_name' => 'admin',
                ]);
            }

            $admin->syncPermissions($permissionsToAssign);
            Log::info('Permissions assigned to admin', [
                'admin_id' => $admin->id,
                'permissions_count' => count($permissionsToAssign),
            ]);
        }

        // ✅ 4. تسجيل النشاط
        \App\Models\AdminLog::log('create_admin', 'admin', $admin->id, [
            'admin_name' => $request->name,
            'admin_email' => $request->email,
            'role' => $roleName,
            'template_id' => $request->template_id,
            'permissions' => $permissionsToAssign,
            'created_by' => auth()->user()->name ?? 'System',
        ]);

        $admin->load('roles', 'permissions');

        // ✅ 5. جلب الصلاحيات من قاعدة البيانات
        $permissions = DB::table('model_has_permissions')
            ->where('model_id', $admin->id)
            ->where('model_type', 'App\\Models\\Admin')
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.guard_name', 'admin')
            ->pluck('permissions.name');

        return response()->json([
            'success' => true,
            'message' => 'Admin created successfully',
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active,
                'roles' => $admin->getRoleNames(),
                'permissions' => $permissions,
                'permissions_count' => $permissions->count(),
                'template_used' => $request->template_id ? PermissionTemplate::find($request->template_id)?->name : null,
                'created_at' => $admin->created_at,
            ],
        ], 201);

    } catch (\Exception $e) {
        Log::error('Failed to create admin: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to create admin: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * Get all admins list with roles and permissions (قائمة الأدمن)
     * GET /api/admin/admins
     */
    public function adminsList(Request $request): JsonResponse
    {
        $admins = Admin::with('roles', 'permissions')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $admins->getCollection()->transform(function ($admin) {
            return [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active,
                'roles' => $admin->getRoleNames(),
                'permissions' => $admin->getAllPermissions()->pluck('name'),
                'last_login_at' => $admin->last_login_at,
                'created_at' => $admin->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $admins,
        ]);
    }

    /**
     * Show specific admin with roles and permissions (عرض أدمن معين)
     * GET /api/admin/admins/{admin}
     */
    public function showAdmin(Admin $admin): JsonResponse
    {
        $admin->load('roles', 'permissions');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active,
                'roles' => $admin->getRoleNames(),
                'permissions' => $admin->getAllPermissions()->pluck('name'),
                'last_login_at' => $admin->last_login_at,
                'created_at' => $admin->created_at,
                'updated_at' => $admin->updated_at,
            ],
        ]);
    }

    /**
     * Delete admin (حذف أدمن)
     * DELETE /api/admin/admins/{admin}
     */
    public function destroyAdmin(Admin $admin): JsonResponse
    {
        try {
            $adminName = $admin->name;
            $adminEmail = $admin->email;

            // ✅ منع حذف الـ Super Admin الوحيد
            if ($admin->isSuperAdmin()) {
                $superAdminCount = Admin::where('role', 'super_admin')->count();
                if ($superAdminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete the only Super Admin',
                    ], 400);
                }
            }

            // تسجيل النشاط
            \App\Models\AdminLog::log('delete_admin', 'admin', $admin->id, [
                'admin_name' => $adminName,
                'admin_email' => $adminEmail,
                'deleted_by' => auth()->user()->name ?? 'System',
            ]);

            // ✅ حذف العلاقات أولاً
            $admin->syncRoles([]);
            $admin->syncPermissions([]);

            // حذف سجل الأدمن
            $admin->delete();

            return response()->json([
                'success' => true,
                'message' => "Admin '{$adminName}' deleted successfully",
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete admin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Suspend admin (تعليق أدمن)
     * POST /api/admin/admins/{admin}/suspend
     */
    public function suspendAdmin(Admin $admin, Request $request): JsonResponse
    {
        try {
            $adminName = $admin->name;

            // ✅ منع تعليق الـ Super Admin الوحيد
            if ($admin->isSuperAdmin()) {
                $superAdminCount = Admin::where('role', 'super_admin')->count();
                if ($superAdminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot suspend the only Super Admin',
                    ], 400);
                }
            }

            // تحديث حالة الأدمن في جدول admins (تعطيل)
            $admin->update([
                'is_active' => false,
            ]);

            // تسجيل النشاط
            \App\Models\AdminLog::log('suspend_admin', 'admin', $admin->id, [
                'admin_name' => $adminName,
                'reason' => $request->reason,
                'suspended_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Admin '{$adminName}' has been suspended successfully",
                'data' => [
                    'is_active' => false,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to suspend admin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate admin (تفعيل أدمن)
     * POST /api/admin/admins/{admin}/activate
     */
    public function activateAdmin(Admin $admin, Request $request): JsonResponse
    {
        try {
            $adminName = $admin->name;

            // تحديث حالة الأدمن في جدول admins (تفعيل)
            $admin->update([
                'is_active' => true,
            ]);

            // تسجيل النشاط
            \App\Models\AdminLog::log('activate_admin', 'admin', $admin->id, [
                'admin_name' => $adminName,
                'notes' => $request->notes,
                'activated_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Admin '{$adminName}' has been activated successfully",
                'data' => [
                    'is_active' => true,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate admin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign role to admin (تعيين دور لأدمن)
     * POST /api/admin/admins/{admin}/roles
     */
    public function assignRole(Request $request, Admin $admin): JsonResponse
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        try {
            $roleName = $request->role;
            $role = Role::where('name', $roleName)->where('guard_name', 'admin')->first();

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role not found for guard admin',
                ], 404);
            }

            // ✅ منع تعيين دور super_admin لأدمن عادي
            if ($roleName === 'super_admin' && !auth()->user()->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only Super Admin can assign super_admin role',
                ], 403);
            }

            $admin->assignRole($role);

            \App\Models\AdminLog::log('assign_role', 'admin', $admin->id, [
                'admin_name' => $admin->name,
                'role' => $roleName,
                'assigned_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Role '{$roleName}' assigned successfully",
                'data' => [
                    'admin_id' => $admin->id,
                    'roles' => $admin->getRoleNames(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove role from admin (إزالة دور من أدمن)
     * DELETE /api/admin/admins/{admin}/roles/{role}
     */
    public function removeRole(Admin $admin, string $role): JsonResponse
    {
        try {
            // ✅ منع إزالة دور super_admin من آخر أدمن
            if ($role === 'super_admin' && $admin->isSuperAdmin()) {
                $superAdminCount = Admin::role('super_admin')->count();
                if ($superAdminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot remove super_admin role from the only Super Admin',
                    ], 400);
                }
            }

            $admin->removeRole($role);

            \App\Models\AdminLog::log('remove_role', 'admin', $admin->id, [
                'admin_name' => $admin->name,
                'role' => $role,
                'removed_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Role '{$role}' removed successfully",
                'data' => [
                    'admin_id' => $admin->id,
                    'roles' => $admin->getRoleNames(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync permissions for admin (مزامنة صلاحيات أدمن)
     * POST /api/admin/admins/{admin}/permissions
     */
    public function syncPermissions(Request $request, Admin $admin): JsonResponse
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        try {
            $permissions = Permission::whereIn('name', $request->permissions)
                ->where('guard_name', 'admin')
                ->get();

            if ($permissions->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid permissions found for guard admin',
                ], 404);
            }

            $admin->syncPermissions($permissions);

            \App\Models\AdminLog::log('sync_permissions', 'admin', $admin->id, [
                'admin_name' => $admin->name,
                'permissions' => $request->permissions,
                'synced_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permissions synced successfully',
                'data' => [
                    'admin_id' => $admin->id,
                    'permissions' => $admin->getAllPermissions()->pluck('name'),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync permissions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get permissions for admin (جلب صلاحيات أدمن)
     * GET /api/admin/admins/{admin}/permissions
     */
    public function getPermissions(Admin $admin): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'admin_id' => $admin->id,
                'roles' => $admin->getRoleNames(),
                'permissions' => $admin->getAllPermissions()->pluck('name'),
                'direct_permissions' => $admin->permissions()->pluck('name'),
            ],
        ]);
    }

    // ============================================================
    // ✅ Roles Management
    // ============================================================

    /**
     * Get all roles (جلب جميع الأدوار)
     * GET /api/admin/roles
     */
    public function getAllRoles(Request $request): JsonResponse
    {
        $roles = Role::where('guard_name', 'admin')
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('display_name', 'LIKE', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'permissions_count' => $role->permissions()->count(),
                    'users_count' => $role->users()->count(),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            }),
            'meta' => [
                'current_page' => $roles->currentPage(),
                'total' => $roles->total(),
                'per_page' => $roles->perPage(),
            ],
        ]);
    }

    /**
     * Create a new role (إنشاء دور جديد)
     * POST /api/admin/roles
     */
    public function createRole(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:roles,name',
            'display_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        try {
            $role = Role::create([
                'name' => $request->name,
                'guard_name' => 'admin',
                'display_name' => $request->display_name,
                'description' => $request->description,
            ]);

            if ($request->has('permissions') && is_array($request->permissions)) {
                $permissions = Permission::whereIn('name', $request->permissions)
                    ->where('guard_name', 'admin')
                    ->get();
                $role->syncPermissions($permissions);
            }

            \App\Models\AdminLog::log('create_role', 'role', $role->id, [
                'role_name' => $request->name,
                'display_name' => $request->display_name,
                'permissions' => $request->permissions ?? [],
                'created_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'permissions' => $role->permissions()->pluck('name'),
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a role (تحديث دور)
     * PUT /api/admin/roles/{role}
     */
    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:roles,name,' . $role->id,
            'display_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        try {
            // منع تعديل الأدوار الأساسية
            $protectedRoles = ['super_admin', 'admin'];
            if (in_array($role->name, $protectedRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot modify protected role: {$role->name}",
                ], 400);
            }

            $oldName = $role->name;

            $role->update([
                'name' => $request->name,
                'display_name' => $request->display_name,
                'description' => $request->description,
            ]);

            \App\Models\AdminLog::log('update_role', 'role', $role->id, [
                'old_name' => $oldName,
                'new_name' => $request->name,
                'updated_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a role (حذف دور)
     * DELETE /api/admin/roles/{role}
     */
    public function deleteRole(Role $role): JsonResponse
    {
        try {
            // منع حذف الأدوار الأساسية
            $protectedRoles = ['super_admin', 'admin'];
            if (in_array($role->name, $protectedRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete protected role: {$role->name}",
                ], 400);
            }

            $roleName = $role->name;

            // حذف العلاقات أولاً
            $role->syncPermissions([]);
            $role->users()->detach();
            $role->delete();

            \App\Models\AdminLog::log('delete_role', 'role', $role->id, [
                'role_name' => $roleName,
                'deleted_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Role '{$roleName}' deleted successfully",
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get role permissions (جلب صلاحيات دور)
     * GET /api/admin/roles/{role}/permissions
     */
    public function getRolePermissions(Role $role): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                ],
                'permissions' => $role->permissions()->pluck('name'),
            ],
        ]);
    }

    /**
     * Sync permissions for a role (مزامنة صلاحيات دور)
     * POST /api/admin/roles/{role}/permissions
     */
    public function syncRolePermissions(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        try {
            // منع تعديل صلاحيات الأدوار الأساسية
            $protectedRoles = ['super_admin'];
            if (in_array($role->name, $protectedRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot modify permissions of protected role: {$role->name}",
                ], 400);
            }

            $permissions = Permission::whereIn('name', $request->permissions)
                ->where('guard_name', 'admin')
                ->get();

            $role->syncPermissions($permissions);

            \App\Models\AdminLog::log('sync_role_permissions', 'role', $role->id, [
                'role_name' => $role->name,
                'permissions' => $request->permissions,
                'updated_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role permissions synced successfully',
                'data' => [
                    'role' => $role->name,
                    'permissions' => $role->permissions()->pluck('name'),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync role permissions: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ============================================================
    // ✅ Permissions Management
    // ============================================================

    /**
     * Get all permissions (grouped by prefix)
     * GET /api/admin/permissions
     */
    public function getAllPermissions(Request $request): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'admin')
            ->orderBy('name')
            ->get();

        // Group permissions by prefix
        $grouped = [];
        foreach ($permissions as $permission) {
            $parts = explode('.', $permission->name);
            $group = $parts[0] ?? 'other';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
                'created_at' => $permission->created_at,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'groups' => $grouped,
                'total' => $permissions->count(),
            ],
        ]);
    }

    /**
     * Create a new permission (إنشاء صلاحية جديدة)
     * POST /api/admin/permissions
     */
    public function createPermission(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:permissions,name',
            'group' => 'nullable|string',
        ]);

        try {
            $permission = Permission::create([
                'name' => $request->name,
                'guard_name' => 'admin',
                'group' => $request->group,
            ]);

            \App\Models\AdminLog::log('create_permission', 'permission', $permission->id, [
                'permission_name' => $request->name,
                'group' => $request->group,
                'created_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission created successfully',
                'data' => $permission,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create permission: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a permission (تحديث صلاحية)
     * PUT /api/admin/permissions/{permission}
     */
    public function updatePermission(Request $request, Permission $permission): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:permissions,name,' . $permission->id,
            'group' => 'nullable|string',
        ]);

        try {
            $oldName = $permission->name;

            $permission->update([
                'name' => $request->name,
                'group' => $request->group,
            ]);

            \App\Models\AdminLog::log('update_permission', 'permission', $permission->id, [
                'old_name' => $oldName,
                'new_name' => $request->name,
                'updated_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission updated successfully',
                'data' => $permission,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update permission: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a permission (حذف صلاحية)
     * DELETE /api/admin/permissions/{permission}
     */
    public function deletePermission(Permission $permission): JsonResponse
    {
        try {
            // التحقق من أن الصلاحية غير مستخدمة
            $isUsed = $permission->roles()->exists() || $permission->users()->exists();

            if ($isUsed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete permission that is assigned to roles or users',
                ], 400);
            }

            $permissionName = $permission->name;

            $permission->delete();

            \App\Models\AdminLog::log('delete_permission', 'permission', $permission->id, [
                'permission_name' => $permissionName,
                'deleted_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Permission '{$permissionName}' deleted successfully",
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete permission: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ============================================================
    // ✅ User Management (Regular Users)
    // ============================================================

    /**
     * Suspend a regular user (تعليق مستخدم عادي)
     */
    public function suspendUser(User $user, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string',
        ]);

        // التحقق من أن المستخدم عادي (وليس شركة أو أدمن)
        if ($user->role !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'يمكنك فقط تعليق المستخدمين العاديين.',
            ], 400);
        }

        $user->update([
            'is_active' => false,
        ]);

        \App\Models\AdminLog::log('suspend_user', 'user', $user->id, [
            'user_name' => $user->name,
            'user_email' => $user->email,
            'reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User suspended successfully',
            'data' => [
                'user_id' => $user->id,
                'is_active' => false,
            ],
        ]);
    }

    /**
     * Activate a regular user (تفعيل مستخدم عادي)
     */
    public function activateUser(User $user, Request $request): JsonResponse
    {
        // التحقق من أن المستخدم عادي (وليس شركة أو أدمن)
        if ($user->role !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'يمكنك فقط تفعيل المستخدمين العاديين.',
            ], 400);
        }

        $user->update([
            'is_active' => true,
        ]);

        \App\Models\AdminLog::log('activate_user', 'user', $user->id, [
            'user_name' => $user->name,
            'user_email' => $user->email,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User activated successfully',
            'data' => [
                'user_id' => $user->id,
                'is_active' => true,
            ],
        ]);
    }

    /**
     * Delete a regular user (حذف مستخدم عادي)
     */
    public function deleteUser(User $user): JsonResponse
    {
        // التحقق من أن المستخدم عادي (وليس شركة أو أدمن)
        if ($user->role !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'يمكنك فقط حذف المستخدمين العاديين.',
            ], 400);
        }

        try {
            $userName = $user->name;
            $userId = $user->id;

            \App\Models\AdminLog::log('delete_user', 'user', $userId, [
                'user_name' => $userName,
                'user_email' => $user->email,
            ]);

            // حذف المقابلات المرتبطة أولاً (إذا وجدت)
            $user->interviews()->delete();

            // حذف السير الذاتية المرتبطة
            $user->resumes()->delete();

            // حذف المستخدم
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => "User '{$userName}' deleted successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ============================================================
    // ✅ Candidate Management
    // ============================================================

    /**
     * Suspend a candidate only (تعليق مرشح فقط) - باستخدام candidates.id
     */
    public function suspendCandidate($candidate, Request $request): JsonResponse
    {
        $candidateModel = Candidate::find($candidate);

        if (!$candidateModel) {
            return response()->json([
                'success' => false,
                'message' => 'Candidate not found',
            ], 404);
        }

        $request->validate([
            'reason' => 'nullable|string',
        ]);

        // تحديث حالة المرشح في جدول candidates
        $candidateModel->update([
            'status' => 'suspended',
        ]);

        \App\Models\AdminLog::log('suspend_candidate', 'candidate', $candidateModel->id, [
            'candidate_name' => $candidateModel->name,
            'candidate_email' => $candidateModel->email,
            'reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Candidate suspended successfully',
            'data' => [
                'candidate_id' => $candidateModel->id,
                'candidate_status' => 'suspended',
            ],
        ]);
    }

    /**
     * Activate a candidate only (تفعيل مرشح فقط) - باستخدام candidates.id
     */
    public function activateCandidate($candidate, Request $request): JsonResponse
    {
        $candidateModel = Candidate::find($candidate);

        if (!$candidateModel) {
            return response()->json([
                'success' => false,
                'message' => 'Candidate not found',
            ], 404);
        }

        // تحديث حالة المرشح في جدول candidates
        $candidateModel->update([
            'status' => 'active',
        ]);

        \App\Models\AdminLog::log('activate_candidate', 'candidate', $candidateModel->id, [
            'candidate_name' => $candidateModel->name,
            'candidate_email' => $candidateModel->email,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Candidate activated successfully',
            'data' => [
                'candidate_id' => $candidateModel->id,
                'candidate_status' => 'active',
            ],
        ]);
    }

    /**
     * Delete a candidate only (حذف مرشح فقط) - باستخدام candidates.id
     */
    public function deleteCandidate($candidate): JsonResponse
    {
        $candidateModel = Candidate::find($candidate);

        if (!$candidateModel) {
            return response()->json([
                'success' => false,
                'message' => 'Candidate not found',
            ], 404);
        }

        try {
            $candidateName = $candidateModel->name;
            $candidateId = $candidateModel->id;

            \App\Models\AdminLog::log('delete_candidate', 'candidate', $candidateId, [
                'candidate_name' => $candidateName,
                'candidate_email' => $candidateModel->email,
            ]);

            // حذف المرشح
            $candidateModel->delete();

            return response()->json([
                'success' => true,
                'message' => "Candidate '{$candidateName}' deleted successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete candidate: ' . $e->getMessage(),
            ], 500);
        }
    }




}
