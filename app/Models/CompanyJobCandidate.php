<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyJobCandidate extends Model
{
    use HasFactory;

    protected $table = 'company_job_candidates';

    protected $fillable = [
        'company_job_id',
        'candidate_id',
        'interview_id',
        'status',
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

    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_SHORTLISTED = 'shortlisted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_HIRED = 'hired';

    public function job(): BelongsTo
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
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
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(float $score): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'final_score' => $score,
            'completed_at' => now(),
        ]);
    }
}
