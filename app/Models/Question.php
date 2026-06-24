<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'question_text',
        'type',
        'expected_skills',
        'evaluation_criteria',
        'order',
        'status',
        'answered_at',
        'evaluated_at',
    ];

    protected $casts = [
        'expected_skills' => 'array',
        'evaluation_criteria' => 'array',
        'answered_at' => 'datetime',
        'evaluated_at' => 'datetime',
    ];

    const TYPE_TECHNICAL = 'technical';
    const TYPE_BEHAVIORAL = 'behavioral';
    const TYPE_SITUATIONAL = 'situational';
    const TYPE_GENERAL = 'general';

    const STATUS_PENDING = 'pending';
    const STATUS_ANSWERED = 'answered';
    const STATUS_PROCESSING = 'processing';
    const STATUS_EVALUATED = 'evaluated';

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
}
