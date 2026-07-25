<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CandidateIdentityVerification extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REQUIRES_RESUBMISSION = 'requires_resubmission';

    public const LIVENESS_PENDING = 'pending';
    public const LIVENESS_IN_PROGRESS = 'in_progress';
    public const LIVENESS_PASSED = 'passed';
    public const LIVENESS_FAILED = 'failed';

    protected $fillable = [
        'company_job_id',
        'candidate_id',
        'company_job_candidate_id',
        'interview_id',
        'status',
        'document_type',
        'liveness_status',
        'liveness_score',
        'reviewer_type',
        'reviewer_id',
        'review_notes',
        'rejection_reason',
        'submitted_at',
        'reviewed_at',
        'evidence_deleted_at',
        'resubmission_requested_at',
        'metadata',
    ];

    protected $casts = [
        'liveness_score' => 'decimal:2',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'evidence_deleted_at' => 'datetime',
        'resubmission_requested_at' => 'datetime',
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

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(CandidateIdentityEvidence::class, 'verification_id');
    }

    public function livenessChallenges(): HasMany
    {
        return $this->hasMany(CandidateLivenessChallenge::class, 'verification_id')
            ->orderBy('sequence');
    }

    public function documentFront(): HasOne
    {
        return $this->hasOne(CandidateIdentityEvidence::class, 'verification_id')
            ->where('type', CandidateIdentityEvidence::TYPE_DOCUMENT_FRONT);
    }

    public function documentBack(): HasOne
    {
        return $this->hasOne(CandidateIdentityEvidence::class, 'verification_id')
            ->where('type', CandidateIdentityEvidence::TYPE_DOCUMENT_BACK);
    }

    public function selfie(): HasOne
    {
        return $this->hasOne(CandidateIdentityEvidence::class, 'verification_id')
            ->where('type', CandidateIdentityEvidence::TYPE_SELFIE);
    }

    public function hasRequiredEvidence(CompanyJob $job): bool
    {
        if (!$job->identity_verification_required) {
            return true;
        }

        if (!$job->identity_document_required) {
            return true;
        }

        $hasFront = $this->evidences()
            ->where('type', CandidateIdentityEvidence::TYPE_DOCUMENT_FRONT)
            ->exists();

        if (!$hasFront) {
            return false;
        }

        if (
            $this->requiresDocumentBack()
            && !$this->evidences()
                ->where('type', CandidateIdentityEvidence::TYPE_DOCUMENT_BACK)
                ->exists()
        ) {
            return false;
        }

        /*
         * Selfie and active liveness challenges are intentionally not required.
         * Identity is reviewed manually by comparing the submitted document
         * with random snapshots captured during the interview.
         */
        return true;
    }

    public function requiresDocumentBack(): bool
    {
        return in_array($this->document_type, ['national_id', 'driver_license'], true);
    }

    public function refreshSubmissionStatus(CompanyJob $job): void
    {
        if (!$this->hasRequiredEvidence($job)) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_UNDER_REVIEW,
            /*
             * Kept as "passed" for backward compatibility with existing
             * database columns and old reports. No active liveness test runs.
             */
            'liveness_status' => self::LIVENESS_PASSED,
            'liveness_score' => null,
            'submitted_at' => $this->submitted_at ?? now(),
            'metadata' => array_merge($this->metadata ?? [], [
                'verification_method' => 'document_and_interview_snapshots',
                'selfie_required' => false,
                'liveness_required' => false,
            ]),
        ])->save();
    }
}
