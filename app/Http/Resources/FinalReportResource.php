<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinalReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,

            // Scores
            'overall_score' => $this->overall_score,
            'adjusted_score' => $this->adjusted_score,
            'technical_score' => $this->technical_score,
            'communication_score' => $this->communication_score,
            'problem_solving_score' => $this->problem_solving_score,

            // Analysis
            'executive_summary' => $this->executive_summary,
            'strengths_analysis' => $this->strengths_analysis,
            'improvement_areas' => $this->improvement_areas,
            'hiring_recommendation' => $this->hiring_recommendation,

            // Detailed breakdowns
            'skill_breakdown' => $this->skill_breakdown,
            'question_evaluations' => $this->question_evaluations,

            // Cheating information
            'cheating' => [
                'severity_score' => $this->cheating_severity_score,
                'total_violations' => $this->total_violations,
                'summary' => $this->violation_summary,
            ],

            'generated_at' => $this->generated_at->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
