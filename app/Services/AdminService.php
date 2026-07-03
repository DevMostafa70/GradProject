<?php

namespace App\Services;

use App\Models\AdminLog;
use App\Models\BroadcastNotification;
use App\Models\JobCategory;
use App\Models\Skill;
use App\Models\User;
use App\Models\Company;
use App\Models\Admin;
use App\Models\CompanyJob;
use App\Models\Interview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;



class AdminService
{
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(): array
    {
        return [
            'total_users' => User::count(),
            'total_companies' => Company::count(),
            'total_jobs' => CompanyJob::count(),
            'total_interviews' => Interview::count(),
            'completed_interviews' => Interview::where('status', 'completed_with_report')->count(),
            'pending_companies' => Company::where('status', 'pending')->count(),
            'active_jobs' => CompanyJob::where('status', 'active')->count(),
            'recent_users' => User::orderBy('created_at', 'desc')->take(5)->get(),
            'recent_companies' => Company::orderBy('created_at', 'desc')->take(5)->get(),
        ];
    }

    /**
     * Get pending company registration requests
     * طلبات تسجيل الشركات التي تنتظر الموافقة
     */
    public function getPendingRequests()
    {
        return Company::where('status', 'pending')
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get recent admin logs
     */
    public function getRecentLogs(int $limit = 50)
    {
        return AdminLog::with('admin')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Suspend a user account
     */
    public function suspendUser(User $user, ?string $reason = null): bool
    {
        $user->update([
            'is_active' => false,
        ]);

        AdminLog::log('suspend_user', 'user', $user->id, [
            'reason' => $reason,
            'user_email' => $user->email,
        ]);

        return true;
    }

    /**
     * Activate a user account
     */
    public function activateUser(User $user): bool
    {
        $user->update([
            'is_active' => true,
        ]);

        AdminLog::log('activate_user', 'user', $user->id, [
            'user_email' => $user->email,
        ]);

        return true;
    }

    public function deleteUser(User $user): bool
    {
        $email = $user->email;
        $role = $user->role;

        // تسجيل النشاط
        AdminLog::log('delete_user', 'user', $user->id, [
            'user_email' => $email,
            'role' => $role,
        ]);

        // حذف السجل المرتبط حسب الدور
        if ($role === 'candidate' && $user->candidate) {
            $user->candidate->delete();
        }

        if ($role === 'company' && $user->company) {
            $user->company->delete();
        }

        // حذف المستخدم
        return $user->delete();
    }

    /**
     * Approve a company registration
     */
    public function approveCompany(Company $company, ?string $notes = null): bool
    {
        // 1. تحديث بيانات الشركة
        $company->update([
            'status' => 'approved',
            'admin_notes' => $notes,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        // 2. تسجيل النشاط في AdminLog (الموجود مسبقاً)
        AdminLog::log('approve_company', 'company', $company->id, [
            'company_name' => $company->company_name,
            'notes' => $notes,
        ]);

        // 3.  تسجيل النشاط في ActivityLog (الجديد)
        app(\App\Services\ActivityLogService::class)->success(
            'companies',
            'approve',
            "تم قبول شركة '{$company->company_name}'",
            [
                'company_id' => $company->id,
                'company_name' => $company->company_name,
                'email' => $company->email,
                'notes' => $notes,
                'approved_by' => auth()->id(),
            ]
        );

        return true;
    }

    /**
     * Reject a company registration
     */
    public function rejectCompany(Company $company, string $reason): bool
    {
        // 1. تحديث بيانات الشركة
        $company->update([
            'status' => 'rejected',
            'admin_notes' => $reason,
        ]);


        // 2. تسجيل النشاط
        AdminLog::log('reject_company', 'company', $company->id, [
            'company_name' => $company->company_name,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Suspend a company account
     */
    public function suspendCompany(Company $company, ?string $reason = null): bool
    {
        // 1. تحديث حالة الشركة
        $company->update([
            'status' => 'suspended',
            'admin_notes' => $reason,
        ]);


        // 2. تسجيل النشاط
        AdminLog::log('suspend_company', 'company', $company->id, [
            'company_name' => $company->company_name,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Activate a suspended company account
     */
    public function activateCompany(Company $company, ?string $notes = null): bool
    {
        // 1. تحديث حالة الشركة
        $company->update([
            'status' => 'approved',
            'admin_notes' => $notes,
        ]);

        // 2. لا حاجة لتفعيل مستخدم (الشركة مستقلة)

        // 3. تسجيل النشاط
        AdminLog::log('activate_company', 'company', $company->id, [
            'company_name' => $company->company_name,
            'notes' => $notes,
        ]);

        return true;
    }
    /**
     * Delete a company permanently
     */
    public function deleteCompany(Company $company): bool
    {
        $companyName = $company->company_name;

        AdminLog::log('delete_company', 'company', $company->id, [
            'company_name' => $companyName,
            'company_email' => $company->email,
        ]);

        return $company->delete();
    }

/**
 * Send broadcast notification to users
 */
public function sendBroadcastNotification(string $title, string $message, string $targetType, bool $sendEmail = false): array
{
    try {
        $users = collect();
        $sentCount = 0;

        switch ($targetType) {
            case 'companies':
                // ✅ جلب الشركات من جدول companies
                $companies = Company::where('status', 'approved')->get();
                $users = $companies;
                break;
            case 'candidates':
                // ✅ جلب المرشحين من جدول users (role = candidate)
                $users = User::where('role', 'candidate')->where('is_active', true)->get();
                break;
            case 'users':
                // ✅ جلب المستخدمين العاديين من جدول users (role = user)
                $users = User::where('role', 'user')->where('is_active', true)->get();
                break;
            default: // 'all'
                // ✅ جلب الجميع: شركات + مرشحين + مستخدمين
                $companies = Company::where('status', 'approved')->get();
                $candidates = User::where('role', 'candidate')->where('is_active', true)->get();
                $users = User::where('role', 'user')->where('is_active', true)->get();
                $users = $companies->concat($candidates)->concat($users);
                break;
        }

        Log::info('Sending broadcast to ' . $users->count() . ' users');

        foreach ($users as $user) {
            try {
                $user->notify(new \App\Notifications\BroadcastNotification($title, $message));
                $sentCount++;
            } catch (\Exception $e) {
                Log::error('Failed to notify user ' . ($user->id ?? 'unknown') . ': ' . $e->getMessage());
            }
        }

        // ✅ تخزين الإشعار في جدول broadcast_notifications
        $broadcast = BroadcastNotification::create([
            'admin_id' => auth()->id(),
            'title' => $title,
            'message' => $message,
            'target_type' => $targetType,
            'sent_via_email' => $sendEmail,
            'sent_at' => now(),
            'sent_count' => $sentCount,
        ]);

        AdminLog::log('send_broadcast', 'broadcast', $broadcast->id, [
            'title' => $title,
            'target_type' => $targetType,
            'sent_count' => $sentCount,
        ]);

        return [
            'sent_count' => $sentCount,
            'broadcast_id' => $broadcast->id,
        ];
    } catch (\Exception $e) {
        Log::error('sendBroadcastNotification failed: ' . $e->getMessage());

        return [
            'sent_count' => 0,
            'broadcast_id' => null,
            'error' => $e->getMessage(),
        ];
    }
}

    /**
     * Get all skills with pagination
     */
    public function getSkills($perPage = 20)
    {
        return Skill::orderBy('name')->paginate($perPage);
    }

    /**
     * Create a new skill
     */
    public function createSkill(array $data): Skill
    {
        $skill = Skill::create($data);

        AdminLog::log('create_skill', 'skill', $skill->id, [
            'skill_name' => $skill->name,
        ]);

        return $skill;
    }

    /**
     * Update a skill
     */
    public function updateSkill(Skill $skill, array $data): Skill
    {
        $oldName = $skill->name;

        $skill->update($data);

        AdminLog::log('update_skill', 'skill', $skill->id, [
            'old_name' => $oldName,
            'new_name' => $skill->name,
        ]);

        return $skill;
    }

    /**
     * Delete a skill
     */
    public function deleteSkill(Skill $skill): bool
    {
        AdminLog::log('delete_skill', 'skill', $skill->id, [
            'skill_name' => $skill->name,
        ]);

        return $skill->delete();
    }

    /**
     * Get all job categories
     */
    public function getJobCategories($perPage = 20)
    {
        return JobCategory::orderBy('sort_order')->paginate($perPage);
    }

    /**
     * Create a new job category
     */
    public function createJobCategory(array $data): JobCategory
    {
        $category = JobCategory::create($data);

        AdminLog::log('create_category', 'category', $category->id, [
            'category_name' => $category->name_ar,
        ]);

        return $category;
    }

    /**
     * Update a job category
     */
    public function updateJobCategory(JobCategory $category, array $data): JobCategory
    {
        $oldName = $category->name_ar;

        $category->update($data);

        AdminLog::log('update_category', 'category', $category->id, [
            'old_name' => $oldName,
            'new_name' => $category->name_ar,
        ]);

        return $category;
    }

    /**
     * Delete a job category
     */
    public function deleteJobCategory(JobCategory $category): bool
    {
        AdminLog::log('delete_category', 'category', $category->id, [
            'category_name' => $category->name_ar,
        ]);

        return $category->delete();
    }

    /**
     * Create a new admin account
     */
    public function createAdmin(array $data): Admin
    {
        // 1. إنشاء المستخدم في جدول users
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'role' => 'admin',
            'is_active' => true,
            'is_verified' => true,
            'email_verified_at' => now(),
        ]);

        // 2. إنشاء سجل الأدمن في جدول admins (مع الاحتفاظ بكل الحقول)
        $admin = Admin::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'role' => 'admin',
            'permissions' => $data['permissions'] ?? null,
            'last_login_at' => null,
        ]);

        // 3. تسجيل النشاط
        AdminLog::log('create_admin', 'admin', $admin->id, [
            'admin_name' => $data['name'],
            'admin_email' => $data['email'],
            'created_by' => auth()->user()->name ?? 'System',
        ]);

        return $admin;
    }


    /**
 * الحصول على نوع الـ Notifiable الصحيح
 */
private function getNotifiableType($user): string
{
    // ✅ تحديد النوع الصحيح
    if ($user instanceof \App\Models\Company) {
        return 'App\Models\Company';
    }

    if ($user instanceof \App\Models\User) {
        return 'App\Models\User';
    }

    if ($user instanceof \App\Models\Admin) {
        return 'App\Models\Admin';
    }

    // Fallback: استخدم get_class
    return get_class($user);
}
}
