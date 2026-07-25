<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidate extends Model
{
    use HasFactory;

    protected $table = 'candidates';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company_job_id',
        'email_invitation_id',
        'invitation_token',
        'status',
        'max_resume_count',
        'invited_at',
        'started_at',
        'session_expires_at',
        'expires_at',
        'completed_at',
        'final_score',
        'answers',
        'import_metadata',
    ];

    protected $hidden = [
        'invitation_token',
    ];

    protected $casts = [
        'answers' => 'array',
        'import_metadata' => 'array',
        'invited_at' => 'datetime',
        'started_at' => 'datetime',
        'session_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'final_score' => 'decimal:2',
        'max_resume_count' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function currentInvitation(): BelongsTo
    {
        return $this->belongsTo(EmailInvitation::class, 'email_invitation_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(EmailInvitation::class);
    }

    public function interview(): HasOne
    {
        return $this->hasOne(Interview::class, 'candidate_id')->latestOfMany();
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'candidate_id');
    }

    public function jobCandidate(): HasOne
    {
        return $this->hasOne(CompanyJobCandidate::class, 'candidate_id')
            ->whereColumn('company_job_id', 'candidates.company_job_id');
    }

    public function identityVerification(): HasOne
    {
        return $this->hasOne(CandidateIdentityVerification::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getAverageScoreAttribute(): ?float
    {
        return $this->final_score !== null ? (float) $this->final_score : null;
    }
}
