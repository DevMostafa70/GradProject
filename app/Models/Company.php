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
}
