<?php

namespace App\Models;

use App\Enums\BillingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Cashier\Billable;

class Company extends Authenticatable
{
    use Billable, HasApiTokens, HasFactory, Notifiable;
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

        // Billing
        'billing_status' => BillingStatus::class,
        'billing_grace_ends_at' => 'datetime',
        'billing_locked_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'selected_plan_id' => 'integer',
    ];

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
}
