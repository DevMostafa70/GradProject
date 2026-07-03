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

        // 🔹 NEW
        'audio_deleted_at'
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


        // ==================== Audio Privacy ====================

    /**
     * Check if audio file exists in storage
     */
    public function audioFileExists(): bool
    {
        if (empty($this->audio_file_path)) {
            return false;
        }

        return \Illuminate\Support\Facades\Storage::disk(
            config('interview_ai.audio.storage_disk', 'public')
        )->exists($this->audio_file_path);
    }

    /**
     * Check if audio file has been deleted
     */
    public function isAudioDeleted(): bool
    {
        return $this->audio_deleted_at !== null;
    }

    /**
     * Delete the audio file from storage
     */
    public function deleteAudioFile(): bool
    {
        if (empty($this->audio_file_path)) {
            return false;
        }

        $disk = config('interview_ai.audio.storage_disk', 'public');

        if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($this->audio_file_path)) {
            \Illuminate\Support\Facades\Storage::disk($disk)->delete($this->audio_file_path);

            $this->audio_deleted_at = now();
            $this->save();

            \Illuminate\Support\Facades\Log::info('Audio file deleted for privacy', [
                'answer_id' => $this->id,
                'interview_id' => $this->interview_id,
                'file_path' => $this->audio_file_path,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get the audio file path with full storage URL
     */
    public function getAudioUrlAttribute(): ?string
    {
        if (empty($this->audio_file_path) || $this->isAudioDeleted()) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk(
            config('interview_ai.audio.storage_disk', 'public')
        )->url($this->audio_file_path);
    }

    /**
     * Check if audio is still available (not deleted and file exists)
     */
    public function isAudioAvailable(): bool
    {
        return !$this->isAudioDeleted() && $this->audioFileExists();
    }

    /**
     * Get retention days from config
     */
    public static function getRetentionDays(): ?int
    {
        $days = config('interview_ai.audio.retention_days');
        return $days !== null ? (int) $days : null;
    }

    /**
     * Scope for answers with audio files that should be deleted
     */
    public function scopeShouldDeleteAudio($query)
    {
        $retentionDays = self::getRetentionDays();

        if ($retentionDays === null) {
            return $query->whereRaw('1 = 0'); // No deletion if retention is disabled
        }

        if ($retentionDays === 0) {
            // Delete immediately after processing
            return $query->where('status', self::STATUS_EVALUATED)
                ->whereNull('audio_deleted_at')
                ->whereNotNull('audio_file_path');
        }

        // Delete after retention period
        return $query->where('status', self::STATUS_EVALUATED)
            ->whereNull('audio_deleted_at')
            ->whereNotNull('audio_file_path')
            ->where('processed_at', '<=', now()->subDays($retentionDays));
    }

    /**
     * Get answers with audio files that are eligible for deletion
     */
    public static function getAnswersForAudioCleanup(): \Illuminate\Database\Eloquent\Collection
    {
        return self::shouldDeleteAudio()->get();
    }

    /**
     * Clean up audio files for all eligible answers
     */
    public static function cleanupAudioFiles(): array
    {
        $answers = self::getAnswersForAudioCleanup();
        $deletedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($answers as $answer) {
            try {
                if ($answer->deleteAudioFile()) {
                    $deletedCount++;
                } else {
                    $failedCount++;
                }
            } catch (\Exception $e) {
                $failedCount++;
                $errors[] = [
                    'answer_id' => $answer->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        \Illuminate\Support\Facades\Log::info('Audio cleanup completed', [
            'deleted' => $deletedCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ]);

        return [
            'deleted' => $deletedCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ];
    }
}
