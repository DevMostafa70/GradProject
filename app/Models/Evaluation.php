<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'answer_id',
        'question_id',
        'interview_id',
        'score',
        'criteria_scores',
        'strengths',
        'weaknesses',
        'detailed_feedback',
        'clarity_score',
        'relevance_score',
        'depth_score',
        'confidence_score',
        'cheating_penalty',
        'ai_raw_response',
    ];

    protected $casts = [
        'criteria_scores' => 'array',
        'ai_raw_response' => 'array',
        'score' => 'decimal:2',
        'cheating_penalty' => 'decimal:2',
        'clarity_score' => 'decimal:2',
        'relevance_score' => 'decimal:2',
        'depth_score' => 'decimal:2',
        'confidence_score' => 'decimal:2',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function getAdjustedScoreAttribute(): float
    {
        return max(0, $this->score - $this->cheating_penalty);
    }
}
