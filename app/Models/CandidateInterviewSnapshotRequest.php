<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateInterviewSnapshotRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_MISSED = 'missed';

    protected $fillable = [
        'interview_id',
        'question_id',
        'request_token_hash',
        'status',
        'due_at',
        'issued_at',
        'expires_at',
        'captured_at',
        'metadata',
    ];

    protected $hidden = [
        'request_token_hash',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'captured_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
