<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Services\CompanyEmployeeAccessService;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $guard_name = 'user';

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
        'company_id',
        'company_permission_template_id',
        'is_company_employee',
        'locale',
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
        'is_company_employee' => 'boolean',
        'company_id' => 'integer',
        'company_permission_template_id' => 'integer',
    ];

    /**
     * All records in the users table use the user guard.
     * Company employees are still User models; only company owners use
     * the separate company guard through the Company model.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->isRegularUser()) {
                $user->assignRole('regular_user');
            }
        });
    }

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

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function companyPermissionTemplate()
    {
        return $this->belongsTo(CompanyPermissionTemplate::class, 'company_permission_template_id');
    }

    public function isRegularUser(): bool
    {
        return $this->role === 'user' && !$this->isCompanyEmployee();
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

    public function isCompanyEmployee(): bool
    {
        return $this->is_company_employee && $this->company_id !== null;
    }

    public function isCompanyOwner(): bool
    {
        // Company owners authenticate through App\Models\Company.
        // A users-table record must never receive owner bypass privileges.
        return false;
    }

    public function getCompanyRole(): ?string
    {
        if (! $this->isCompanyEmployee()) {
            return null;
        }

        return app(CompanyEmployeeAccessService::class)->roleNames($this)[0] ?? null;
    }

    public function getCompanyPermissions(): array
    {
        if (!$this->isCompanyEmployee()) {
            return [];
        }

        return app(CompanyEmployeeAccessService::class)->permissionNames($this);
    }

    public function syncCompanyPermissions(array $permissionNames): void
    {
        app(CompanyEmployeeAccessService::class)->syncPermissions($this, $permissionNames);
    }

    public function hasCompanyPermission(string $permission): bool
    {
        return $this->hasPermissionTo($permission, 'user');
    }

    public function adminLogs()
    {
        return $this->hasMany(AdminLog::class, 'admin_id');
    }
}
