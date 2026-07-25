<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateLivenessChallenge extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_STARTED = 'started';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'verification_id',
        'sequence',
        'challenge_type',
        'challenge_payload',
        'status',
        'confidence_score',
        'evidence_id',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'challenge_payload' => 'array',
        'confidence_score' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function verification(): BelongsTo
    {
        return $this->belongsTo(CandidateIdentityVerification::class, 'verification_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(CandidateIdentityEvidence::class, 'evidence_id');
    }
}
