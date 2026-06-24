<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id',
        'section',
        'action',
        'description',
        'status',
        'ip_address',
        'user_agent',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    // ==================== العلاقات ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'user_id');
    }

    // ==================== دوال مساعدة ====================

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'success' => '<span class="badge bg-success">✅ نجاح</span>',
            'failed' => '<span class="badge bg-danger">❌ فشل</span>',
            'warning' => '<span class="badge bg-warning">⚠️ تحذير</span>',
            default => '<span class="badge bg-secondary">غير معروف</span>',
        };
    }

    public function getSectionLabelAttribute(): string
    {
        return match ($this->section) {
            'auth' => 'المصادقة',
            'users' => 'المستخدمين',
            'candidates' => 'المرشحين',
            'companies' => 'الشركات',
            'admins' => 'الأدمن',
            'interviews' => 'المقابلات',
            'jobs' => 'الوظائف',
            'resumes' => 'السير الذاتية',
            'skills' => 'المهارات',
            'categories' => 'الفئات',
            'broadcast' => 'الإشعارات',
            default => $this->section,
        };
    }

    public function getActionLabelAttribute(): string
    {
        $labels = [
            'login' => 'تسجيل دخول',
            'logout' => 'تسجيل خروج',
            'register' => 'تسجيل حساب',
            'create' => 'إنشاء',
            'update' => 'تحديث',
            'delete' => 'حذف',
            'approve' => 'موافقة',
            'reject' => 'رفض',
            'suspend' => 'تعليق',
            'activate' => 'تفعيل',
            'start_interview' => 'بدأ مقابلة',
            'submit_answer' => 'أرسل إجابة',
            'complete_interview' => 'أكمل مقابلة',
            'upload_resume' => 'رفع سيرة ذاتية',
            'analyze_resume' => 'تحليل سيرة ذاتية',
        ];

        return $labels[$this->action] ?? $this->action;
    }
}
