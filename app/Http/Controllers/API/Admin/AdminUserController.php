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

    /**
     * Create a new admin (إنشاء أدمن جديد) - مستقلاً عن users
     */
    public function store(CreateAdminRequest $request): JsonResponse
    {
        Log::info('Current admin:', [
            'id' => auth()->id(),
            'role' => auth()->user()->role ?? 'no user'
        ]);

        try {
            // ✅ إنشاء الأدمن مباشرة في جدول admins (بدون users)
            $admin = Admin::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'role' => 'admin',
                'permissions' => $request->permissions ?? null,
                'is_active' => true,
                'last_login_at' => null,
            ]);

            // تسجيل النشاط
            \App\Models\AdminLog::log('create_admin', 'admin', $admin->id, [
                'admin_name' => $request->name,
                'admin_email' => $request->email,
                'created_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Admin created successfully',
                'data' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                    'permissions' => $admin->permissions,
                    'is_active' => $admin->is_active,
                    'created_at' => $admin->created_at,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin: ' . $e->getMessage(),
            ], 500);
        }
    }

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

    /**
     * Get all admins list (قائمة الأدمن)
     */
    public function adminsList(Request $request): JsonResponse
    {
        $admins = Admin::orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $admins->getCollection()->transform(function ($admin) {
            return [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active ?? true,
                'permissions' => $admin->permissions,
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
     * Show specific admin (عرض أدمن معين)
     */
    public function showAdmin(Admin $admin): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active ?? true,
                'permissions' => $admin->permissions,
                'last_login_at' => $admin->last_login_at,
                'created_at' => $admin->created_at,
                'updated_at' => $admin->updated_at,
            ],
        ]);
    }

    /**
     * Delete admin (حذف أدمن)
     */
    public function destroyAdmin(Admin $admin): JsonResponse
    {
        try {
            $adminName = $admin->name;
            $adminEmail = $admin->email;

            // تسجيل النشاط
            \App\Models\AdminLog::log('delete_admin', 'admin', $admin->id, [
                'admin_name' => $adminName,
                'admin_email' => $adminEmail,
            ]);

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
     */
    public function suspendAdmin(Admin $admin, Request $request): JsonResponse
    {
        try {
            $adminName = $admin->name;

            // تحديث حالة الأدمن في جدول admins (تعطيل)
            $admin->update([
                'is_active' => false,
            ]);

            // تسجيل النشاط
            \App\Models\AdminLog::log('suspend_admin', 'admin', $admin->id, [
                'admin_name' => $adminName,
                'reason' => $request->reason,
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
