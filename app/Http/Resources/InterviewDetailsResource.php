<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'experience_level' => $this->experience_level,
            'difficulty' => $this->difficulty,
            'skills' => $this->skills,
            'number_of_questions' => $this->number_of_questions,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,

            // Final Report
            'report' => $this->whenLoaded('finalReport', function () {
                return [
                    'overall_score' => $this->finalReport
                        ? round($this->finalReport->overall_score * 10, 2)
                        : null,
                    'adjusted_score' => $this->finalReport
                        ? round($this->finalReport->adjusted_score * 10, 2)
                        : null,
                    'technical_score' => $this->finalReport
                        ? round($this->finalReport->technical_score * 10, 2)
                        : null,
                    'communication_score' => $this->finalReport
                        ? round($this->finalReport->communication_score * 10, 2)
                        : null,
                    'problem_solving_score' => $this->finalReport
                        ? round($this->finalReport->problem_solving_score * 10, 2)
                        : null,
                    'executive_summary' => $this->finalReport?->executive_summary,
                    'strengths_analysis' => $this->finalReport?->strengths_analysis,
                    'improvement_areas' => $this->finalReport?->improvement_areas,
                    'hiring_recommendation' => $this->finalReport?->hiring_recommendation,
                    'cheating_severity_score' => $this->finalReport?->cheating_severity_score,
                ];
            }),

            // Questions with Answers and Evaluations
            'questions' => $this->whenLoaded('questions', function () {
                return $this->questions->map(function ($question) {
                    $answer = $this->answers->where('question_id', $question->id)->first();
                    $evaluation = $answer?->evaluation;

                    return [
                        'id' => $question->id,
                        'text' => $question->question_text,
                        'type' => $question->type,
                        'order' => $question->order,
                        'expected_skills' => $question->expected_skills,
                        'answer' => $answer ? [
                            'transcript' => $answer->transcription,
                            'duration_seconds' => $answer->duration_seconds,
                            'submitted_at' => $answer->submitted_at,
                        ] : null,
                        'evaluation' => $evaluation ? [
                            'score' => round($evaluation->score * 10, 2),
                            'clarity_score' => $evaluation->clarity_score
                                ? round($evaluation->clarity_score * 10, 2)
                                : null,
                            'relevance_score' => $evaluation->relevance_score
                                ? round($evaluation->relevance_score * 10, 2)
                                : null,
                            'depth_score' => $evaluation->depth_score
                                ? round($evaluation->depth_score * 10, 2)
                                : null,
                            'confidence_score' => $evaluation->confidence_score
                                ? round($evaluation->confidence_score * 10, 2)
                                : null,
                            'strengths' => $evaluation->strengths,
                            'weaknesses' => $evaluation->weaknesses,
                            'detailed_feedback' => $evaluation->detailed_feedback,
                        ] : null,
                    ];
                });
            }),

            // Anti-Cheat Summary
            'anti_cheat' => [
                'total_violations' => $this->antiCheatLogs->count(),
                'severity_score' => $this->calculateCheatingSeverityScore(),
                'violations_by_type' => $this->antiCheatLogs
                    ->groupBy('violation_type')
                    ->map(function ($logs) {
                        return [
                            'count' => $logs->count(),
                            'total_duration' => $logs->sum('duration_seconds'),
                            'avg_confidence' => round($logs->avg('confidence_score'), 2),
                        ];
                    }),
            ],
        ];
    }
}
