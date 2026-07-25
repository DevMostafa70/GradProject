<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CompanyJobCandidate extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SHORTLISTED = 'shortlisted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_HIRED = 'hired';

    protected $table = 'company_job_candidates';

    protected $fillable = [
        'company_job_id',
        'candidate_id',
        'interview_id',
        'email_invitation_id',
        'status',
        'identity_status',
        'final_score',
        'source',
        'company_notes',
        'invited_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'final_score' => 'decimal:2',
        'invited_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(EmailInvitation::class, 'email_invitation_id');
    }

    public function identityVerification(): HasOne
    {
        return $this->hasOne(CandidateIdentityVerification::class, 'company_job_candidate_id');
    }

    public function updateStatus(string $status, ?string $notes = null): void
    {
        $this->update([
            'status' => $status,
            'company_notes' => $notes ?? $this->company_notes,
        ]);
    }

    public function markAsStarted(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    public function markAsCompleted(float $score): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'final_score' => $score,
            'completed_at' => $this->completed_at ?? now(),
        ]);
    }
}
