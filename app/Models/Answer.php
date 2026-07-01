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

        // 🔹 NEW
        'idempotency_key',
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


        // ==================== Duplicate Prevention ====================

    /**
     * Check if an answer already exists for a question
     */
    public static function existsForQuestion(int $interviewId, int $questionId): bool
    {
        return self::where('interview_id', $interviewId)
            ->where('question_id', $questionId)
            ->exists();
    }

    /**
     * Find answer by idempotency key
     */
    public static function findByIdempotencyKey(string $key): ?self
    {
        return self::where('idempotency_key', $key)->first();
    }

    /**
     * Create a new answer with idempotency protection
     */
    public static function createWithIdempotency(array $data, string $idempotencyKey): ?self
    {
        // Check if answer already exists with this idempotency key
        $existing = self::findByIdempotencyKey($idempotencyKey);
        if ($existing) {
            return $existing;
        }

        // Check if answer already exists for this question
        if (self::existsForQuestion($data['interview_id'], $data['question_id'])) {
            return null;
        }

        // Create new answer
        $data['idempotency_key'] = $idempotencyKey;
        return self::create($data);
    }
}
