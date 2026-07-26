<?php

namespace App\Models;

use App\Enums\BillingStatus;
use App\Services\CompanyEmployeeAccessService;
use App\Notifications\Auth\NervuPasswordResetNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Cashier\Billable;
use Spatie\Permission\Traits\HasRoles;

class Company extends Authenticatable
{
    use Billable, HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'company';

    protected $table = 'companies';

    protected $fillable = [
        'company_name',
        'email',
        'password',
        'logo',
        'industry',
        'website',
        'description',
        'phone',
        'address',
        'status',
        'admin_notes',
        'approved_at',
        'approved_by',
        'current_employees',

        // Billing
        'selected_plan_id',
        'billing_status',
        'billing_grace_ends_at',
        'billing_locked_at',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'current_employees' => 'integer',

        // Billing
        'billing_status' => BillingStatus::class,
        'billing_grace_ends_at' => 'datetime',
        'billing_locked_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'selected_plan_id' => 'integer',
    ];


    public function isCompanyOwner(): bool
    {
        return true;
    }

    // ==================== العلاقات ====================

    public function jobs()
    {
        return $this->hasMany(CompanyJob::class, 'company_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function selectedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'selected_plan_id');
    }

    public function usageCounters(): HasMany
    {
        return $this->hasMany(CompanyUsageCounter::class, 'company_id');
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class, 'company_id');
    }

    public function stripeWebhookEvents(): HasMany
    {
        return $this->hasMany(StripeWebhookEvent::class, 'company_id');
    }

    // ✅ NEW: Employees (users who belong to this company as employees)
    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'company_id')
            ->where('is_company_employee', true);
    }

    // ✅ NEW: All users associated with this company (including owner if in users table)
    public function companyUsers(): HasMany
    {
        return $this->hasMany(User::class, 'company_id');
    }

    // ✅ NEW: Permission templates for this company
    public function permissionTemplates(): HasMany
    {
        return $this->hasMany(CompanyPermissionTemplate::class, 'company_id');
    }

    // ==================== دوال مساعدة ====================

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    public function stripeName(): ?string
    {
        return $this->company_name;
    }

    public function stripeEmail(): ?string
    {
        return $this->email;
    }

    /**
     * الحصول على الخطة المختارة مع معلوماتها
     */
    public function getSelectedPlan(): ?Plan
    {
        return $this->selectedPlan;
    }

    /**
     * التحقق من وجود خطة مختارة
     */
    public function hasSelectedPlan(): bool
    {
        return $this->selected_plan_id !== null;
    }

    /**
     * الحصول على حد معين من الخطة المختارة
     */
    public function getPlanLimit(string $key): ?int
    {
        if (!$this->selectedPlan) {
            return null;
        }

        return $this->selectedPlan->limit($key);
    }

    /**
     * الحصول على ميزة معينة من الخطة المختارة
     */
    public function hasPlanFeature(string $feature): bool
    {
        if (!$this->selectedPlan) {
            return false;
        }

        return $this->selectedPlan->hasFeature($feature);
    }

    // ============================================================
    // ✅ NEW: Employee Management Methods
    // ============================================================

    /**
     * Get the maximum number of employees allowed based on the selected plan
     */
    public function getMaxEmployees(): int
    {
        if (!$this->selectedPlan) {
            return 1; // Default if no plan selected
        }

        return $this->selectedPlan->getMaxEmployees();
    }

    /**
     * Get the current number of employees (including owner)
     */
    public function getCurrentEmployees(): int
    {
        return $this->current_employees ?? 1;
    }

    /**
     * Check if the company can add a new employee
     */
    public function canAddEmployee(): bool
    {
        $currentCount = $this->getCurrentEmployees();
        $maxEmployees = $this->getMaxEmployees();

        return $currentCount < $maxEmployees;
    }

    /**
     * Get the number of remaining employee slots
     */
    public function getRemainingEmployeeSlots(): int
    {
        $currentCount = $this->getCurrentEmployees();
        $maxEmployees = $this->getMaxEmployees();

        return max(0, $maxEmployees - $currentCount);
    }

    /**
     * Increment the employee count
     */
    public function incrementEmployeeCount(): void
    {
        $this->increment('current_employees');
    }

    /**
     * Decrement the employee count
     */
    public function decrementEmployeeCount(): void
    {
        $this->decrement('current_employees');
    }

    /**
     * Recalculate the stored count from the actual employee records.
     * The owner is included in plan limits, hence the +1.
     */
    public function syncEmployeeCount(): int
    {
        $count = 1 + $this->employees()->count();
        $this->forceFill(['current_employees' => $count])->saveQuietly();
        $this->current_employees = $count;

        return $count;
    }

    /**
     * Get employee limit information as array
     */
    public function getEmployeeLimitInfo(): array
    {
        return [
            'max_employees' => $this->getMaxEmployees(),
            'current_employees' => $this->getCurrentEmployees(),
            'remaining_slots' => $this->getRemainingEmployeeSlots(),
            'can_add' => $this->canAddEmployee(),
            'plan_name' => $this->selectedPlan?->name ?? 'No Plan',
            'plan_slug' => $this->selectedPlan?->slug ?? 'none',
        ];
    }

    /**
     * Check if employee limit is reached
     */
    public function isEmployeeLimitReached(): bool
    {
        return !$this->canAddEmployee();
    }

    /**
     * Get all employees with their permissions
     */
    public function getEmployeesWithPermissions(): \Illuminate\Support\Collection
    {
        return $this->employees()->with('roles', 'permissions')->get()->map(function ($employee) {
            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'is_active' => $employee->is_active,
                'roles' => app(CompanyEmployeeAccessService::class)->roleNames($employee),
                'permissions' => app(CompanyEmployeeAccessService::class)->permissionNames($employee),
                'created_at' => $employee->created_at,
            ];
        });
    }

    // ============================================================
    // ✅ NEW: Sync employee permissions with template
    // ============================================================

    /**
     * Assign permissions to an employee from a template
     */
    public function assignEmployeePermissions(User $employee, CompanyPermissionTemplate $template): void
    {
        if ((int) $employee->company_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Employee does not belong to this company');
        }

        if ((int) $template->company_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Permission template does not belong to this company');
        }

        $employee->update([
            'is_company_employee' => true,
            'company_permission_template_id' => $template->id,
        ]);

        $accessService = app(CompanyEmployeeAccessService::class);
        if ($accessService->roleNames($employee) === []) {
            $accessService->syncRole($employee, 'company_employee');
        }
        $accessService->syncPermissions($employee, (array) $template->permissions);
        $this->syncEmployeeCount();
    }

    /**
     * Remove employee from company
     */
    public function removeEmployee(User $employee): void
    {
        if ((int) $employee->company_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Employee does not belong to this company');
        }

        $employee->tokens()->delete();
        app(CompanyEmployeeAccessService::class)->clear($employee);
        $employee->update([
            'company_id' => null,
            'company_permission_template_id' => null,
            'is_company_employee' => false,
            'is_active' => false,
        ]);

        $this->syncEmployeeCount();
    }

    // ==================== حالة الشركة ====================

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function hasPaidAccess(): bool
    {
        if ($this->billing_status instanceof BillingStatus) {
            return $this->billing_status->allowsPaidAccess();
        }

        return in_array($this->billing_status, ['active', 'trialing'], true);
    }

    /**
     * التحقق من إمكانية الوصول الكامل للشركة
     * (موافقة + اشتراك فعال)
     */
    public function hasFullAccess(): bool
    {
        return $this->isApproved() && $this->hasPaidAccess() && $this->hasSelectedPlan();
    }

    /**
     * التحقق من أن الشركة في فترة السماح (grace period)
     */
    public function isInGracePeriod(): bool
    {
        if (!$this->billing_grace_ends_at) {
            return false;
        }

        return now()->lt($this->billing_grace_ends_at);
    }

    /**
     * التحقق من أن الشركة مقيدة (restricted)
     */
    public function isRestricted(): bool
    {
        return $this->billing_status === BillingStatus::Restricted;
    }

    // ==================== إجراءات ====================

    public function approve(?string $notes = null, ?int $adminId = null): void
    {
        $this->update([
            'status' => 'approved',
            'admin_notes' => $notes,
            'approved_at' => now(),
            'approved_by' => $adminId,
        ]);
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'admin_notes' => $reason,
        ]);
    }

    public function suspend(?string $reason = null): void
    {
        $this->update([
            'status' => 'suspended',
            'admin_notes' => $reason,
        ]);
    }

    public function activate(?string $notes = null): void
    {
        $this->update([
            'status' => 'approved',
            'admin_notes' => $notes,
        ]);
    }

    /**
     * بدء فترة السماح (grace period)
     */
    public function startGracePeriod(int $days = 3): void
    {
        $this->update([
            'billing_status' => BillingStatus::PastDue,
            'billing_grace_ends_at' => now()->addDays($days),
        ]);
    }

    /**
     * تقييد الوصول بسبب انتهاء فترة السماح
     */
    public function restrictAccess(): void
    {
        $this->update([
            'billing_status' => BillingStatus::Restricted,
            'billing_locked_at' => now(),
        ]);
    }

    /**
     * تفعيل الاشتراك بعد الدفع الناجح
     */
    public function activateSubscription(?Plan $plan = null): void
    {
        $data = [
            'billing_status' => BillingStatus::Active,
            'billing_locked_at' => null,
            'billing_grace_ends_at' => null,
        ];

        if ($plan) {
            $data['selected_plan_id'] = $plan->id;
        }

        $this->update($data);
    }

    /**
     * إلغاء الاشتراك
     */
    public function cancelSubscription(): void
    {
        $this->update([
            'billing_status' => BillingStatus::Cancelled,
        ]);
    }
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new NervuPasswordResetNotification(
            (string) $token,
            NervuPasswordResetNotification::accountTypeFor($this),
        ));
    }

}
