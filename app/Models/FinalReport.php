<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'overall_score',
        'adjusted_score',
        'cheating_severity_score',
        'total_violations',
        'violation_summary',
        'skill_breakdown',
        'question_evaluations',
        'executive_summary',
        'strengths_analysis',
        'improvement_areas',
        'hiring_recommendation',
        'technical_score',
        'communication_score',
        'problem_solving_score',
        'ai_raw_response',
        'generated_at',
    ];

    protected $casts = [
        'violation_summary' => 'array',
        'skill_breakdown' => 'array',
        'question_evaluations' => 'array',
        'ai_raw_response' => 'array',
        'overall_score' => 'decimal:2',
        'adjusted_score' => 'decimal:2',
        'cheating_severity_score' => 'decimal:2',
        'technical_score' => 'decimal:2',
        'communication_score' => 'decimal:2',
        'problem_solving_score' => 'decimal:2',
        'generated_at' => 'datetime',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }
}
