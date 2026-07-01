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
        'cheating_risk_level',        // 🔹 NEW
        'cheating_risk_description',  // 🔹 NEW
        'cheating_recommendation',    // 🔹 NEW
        'violation_count_by_type',    // 🔹 NEW
        'total_violations',
        'violation_summary',
        'skill_breakdown',
        'question_evaluations',
        'executive_summary',
        'strengths_analysis',
        'improvement_areas',
        'hiring_recommendation',

        // 🔹 NEW: Educational Fields
        'educational_summary',
        'key_strengths',
        'key_weaknesses',
        'improvement_plan',
        'learning_resources',
        'key_takeaways',
        'next_steps',

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
        'violation_count_by_type' => 'array',  // 🔹 NEW
        'key_strengths' => 'array',
        'key_weaknesses' => 'array',
        'improvement_plan' => 'array',
        'learning_resources' => 'array',
        'key_takeaways' => 'array',
        'next_steps' => 'array',
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
