<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Question extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'interview_id',
        'question_text',
        'time_allocation_seconds',
        'type',
        'expected_skills',
        'evaluation_criteria',
        'order',
        'status',
        'answered_at',
        'evaluated_at',
        'job_id',
        'source',
    ];

    protected $casts = [
        'expected_skills' => 'array',
        'evaluation_criteria' => 'array',
        'question_text' => 'array',
        'time_allocation_seconds' => 'integer',
        'answered_at' => 'datetime',
        'evaluated_at' => 'datetime',
    ];

    public const TYPE_TECHNICAL = 'technical';
    public const TYPE_BEHAVIORAL = 'behavioral';
    public const TYPE_SITUATIONAL = 'situational';
    public const TYPE_GENERAL = 'general';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_EVALUATED = 'evaluated';

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function answer(): HasOne
    {
        return $this->hasOne(Answer::class);
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class);
    }

    public function isAnswered(): bool
    {
        return !is_null($this->answer);
    }

    public function isEvaluated(): bool
    {
        return $this->status === self::STATUS_EVALUATED;
    }

    public function textForLocale(?string $locale = null): string
    {
        $locale = strtolower(substr((string) ($locale ?: app()->getLocale()), 0, 2));
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $value = $this->getAttribute('question_text');

        if (is_array($value)) {
            return trim((string) ($value[$locale] ?? $value['en'] ?? $value['ar'] ?? reset($value) ?: ''));
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return trim((string) ($decoded[$locale] ?? $decoded['en'] ?? $decoded['ar'] ?? reset($decoded) ?: ''));
            }

            return trim($value);
        }

        return '';
    }
}
