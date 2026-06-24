<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'bio',
        'avatar',
        'last_login_at',
        'is_verified',
        'verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
    ];

    // ==================== العلاقات الأساسية ====================

    public function interviews()
    {
        return $this->hasMany(Interview::class, 'user_id');
    }

    public function activeInterviews()
    {
        return $this->interviews()->whereIn('status', ['in_progress', 'pending']);
    }

    public function completedInterviews()
    {
        return $this->interviews()->where('status', 'completed_with_report');
    }

    public function answers()
    {
        return $this->hasManyThrough(
            Answer::class,
            Interview::class,
            'user_id',
            'interview_id',
            'id',
            'id'
        );
    }

    public function resumes()
    {
        return $this->hasMany(Resume::class, 'user_id');
    }

    // ==================== التحقق من الأدوار ====================

    public function isRegularUser(): bool
    {
        return $this->role === 'user';
    }

    public function isCandidate(): bool
    {
        return $this->role === 'candidate';
    }

    public function isCompany(): bool
    {
        return $this->role === 'company';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isVerified(): bool
    {
        return $this->is_verified ?? false;
    }

    // ==================== علاقات إضافية ====================

    public function adminLogs()
    {
        return $this->hasMany(AdminLog::class, 'admin_id');
    }
}
