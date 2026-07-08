<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Models\Permission;

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
        'is_company_employee',
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
    ];

    public function getGuardName(): string
    {
        if ($this->isCompanyEmployee()) {
            return 'company';
        }
        return 'user';
    }

    public function setGuardName(): void
    {
        $this->guard_name = $this->getGuardName();
    }

    protected static function booted()
    {
        static::retrieved(function ($user) {
            $user->setGuardName();
        });

        static::saved(function ($user) {
            $user->setGuardName();
        });

        static::created(function ($user) {
            if ($user->role === 'user' && !$user->isCompanyEmployee()) {
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
        return $this->role === 'company' && $this->company_id !== null;
    }

    public function getCompanyRole(): ?string
    {
        if (!$this->company_id) {
            return null;
        }

        try {
            $roles = $this->getRoleNames();
            return $roles->first() ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getCompanyPermissions(): array
    {
        if (!$this->isCompanyEmployee()) {
            return [];
        }

        try {
            return $this->getAllPermissions()->pluck('name')->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function syncCompanyPermissions(array $permissionNames): void
    {
        $permissions = Permission::whereIn('name', $permissionNames)
            ->where('guard_name', 'user')
            ->get();

        if ($permissions->isNotEmpty()) {
            $this->syncPermissions($permissions);
        }
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
