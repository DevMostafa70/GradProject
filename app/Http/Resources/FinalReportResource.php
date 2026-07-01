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
                'risk_level' => $this->cheating_risk_level,
                'risk_label' => $this->getRiskLabel(),
                'risk_color' => $this->getRiskColor(),
                'risk_description' => $this->cheating_risk_description,
                'recommendation' => $this->cheating_recommendation,
                'violations_by_type' => $this->violation_count_by_type,
            ],

            // ============================================================
            // 🔹 NEW: Educational Feedback
            // ============================================================
            'educational' => [
                'summary' => $this->educational_summary,
                'strengths' => $this->key_strengths,
                'weaknesses' => $this->key_weaknesses,
                'improvement_plan' => $this->improvement_plan,
                'learning_resources' => $this->learning_resources,
                'key_takeaways' => $this->key_takeaways,
                'next_steps' => $this->next_steps,
            ],

            'generated_at' => $this->generated_at->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }

    private function getRiskLabel(): string
    {
        $levels = [
            'low' => 'منخفض',
            'medium' => 'متوسط',
            'high' => 'مرتفع',
            'critical' => 'حرج',
        ];
        return $levels[$this->cheating_risk_level] ?? $this->cheating_risk_level;
    }

    private function getRiskColor(): string
    {
        $colors = [
            'low' => '#22c55e',
            'medium' => '#eab308',
            'high' => '#f97316',
            'critical' => '#ef4444',
        ];
        return $colors[$this->cheating_risk_level] ?? '#gray';
    }
}
