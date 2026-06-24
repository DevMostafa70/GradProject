<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    use HasFactory;

    protected $table = 'candidates';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company_job_id',
        'invitation_token',
        'status',
        'invited_at',
        'started_at',
        'session_expires_at',
        'completed_at',
        'final_score',
        'answers',
    ];

    protected $casts = [
        'answers' => 'array',
        'invited_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'final_score' => 'decimal:2',
    ];

    // ==================== العلاقات ====================

    public function job()
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function interview()
    {
        return $this->hasOne(Interview::class, 'candidate_id');
    }

    // ==================== دوال مساعدة ====================

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
        return $this->final_score;
    }
}
