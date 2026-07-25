<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EmailInvitation extends Model
{
    use HasFactory;

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';

    public const LIFECYCLE_CREATED = 'created';
    public const LIFECYCLE_OPENED = 'opened';
    public const LIFECYCLE_CLAIMED = 'claimed';
    public const LIFECYCLE_IN_PROGRESS = 'in_progress';
    public const LIFECYCLE_COMPLETED = 'completed';
    public const LIFECYCLE_EXPIRED = 'expired';
    public const LIFECYCLE_CANCELLED = 'cancelled';

    protected $fillable = [
        'email',
        'name',
        'company_job_id',
        'candidate_id',
        'company_job_candidate_id',
        'token_hash',
        'token_ciphertext',
        'status',
        'lifecycle_status',
        'send_attempts',
        'failure_reason',
        'sent_at',
        'expires_at',
        'last_sent_at',
        'opened_at',
        'claimed_at',
        'completed_at',
        'cancelled_at',
        'metadata',
    ];

    protected $hidden = [
        'token_hash',
        'token_ciphertext',
    ];

    protected $casts = [
        'send_attempts' => 'integer',
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function jobCandidate(): BelongsTo
    {
        return $this->belongsTo(CompanyJobCandidate::class, 'company_job_candidate_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function isCancelled(): bool
    {
        return $this->lifecycle_status === self::LIFECYCLE_CANCELLED || $this->cancelled_at !== null;
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    public function canBeClaimed(): bool
    {
        return $this->status === self::DELIVERY_SENT
            && !$this->isCancelled()
            && !$this->isExpired()
            && !$this->isClaimed();
    }

    public function markAsSent(Carbon $sentAt, Carbon $expiresAt): void
    {
        $this->forceFill([
            'status' => self::DELIVERY_SENT,
            'sent_at' => $sentAt,
            'last_sent_at' => $sentAt,
            'expires_at' => $expiresAt,
            'failure_reason' => null,
            'token_ciphertext' => null,
        ])->save();

        $this->candidate?->forceFill([
            'invited_at' => $sentAt,
            'expires_at' => $expiresAt,
            'session_expires_at' => $expiresAt,
        ])->save();
    }

    public function markAsFailed(?string $reason = null): void
    {
        $this->forceFill([
            'status' => self::DELIVERY_FAILED,
            'failure_reason' => $reason,
        ])->save();
    }

    public function markOpened(): void
    {
        if ($this->opened_at !== null) {
            return;
        }

        $this->forceFill([
            'opened_at' => now(),
            'lifecycle_status' => self::LIFECYCLE_OPENED,
        ])->save();
    }

    public function markClaimed(): void
    {
        $this->forceFill([
            'claimed_at' => now(),
            'lifecycle_status' => self::LIFECYCLE_CLAIMED,
        ])->save();
    }

    public function cancel(): void
    {
        $this->forceFill([
            'cancelled_at' => now(),
            'lifecycle_status' => self::LIFECYCLE_CANCELLED,
            'token_hash' => null,
            'token_ciphertext' => null,
        ])->save();
    }
}
