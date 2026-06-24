<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Answer extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'question_id',
        'transcription',
        'audio_file_path',
        'duration_seconds',
        'status',
        'submitted_at',
        'processed_at',
        'processing_metadata',
    ];

    protected $casts = [
        'processing_metadata' => 'array',
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_EVALUATED = 'evaluated';
    const STATUS_FAILED = 'failed';

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class);
    }

    public function audioAnalysis(): HasOne
    {
        return $this->hasOne(AudioAnalysis::class);
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isEvaluated(): bool
    {
        return $this->status === self::STATUS_EVALUATED;
    }
}
