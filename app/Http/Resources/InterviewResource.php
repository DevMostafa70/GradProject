<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'position' => $this->position,
            'experience_level' => $this->experience_level,
            'difficulty' => $this->difficulty,
            'skills' => $this->skills,
            'number_of_questions' => $this->number_of_questions,
            'status' => $this->status,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Progress information
            'progress' => [
                'questions_answered' => $this->whenLoaded('answers', function() {
                    return $this->answers->count();
                }),
                'questions_total' => $this->whenLoaded('questions', function() {
                    return $this->questions->count();
                }),
                'answers_processed' => $this->whenLoaded('answers', function() {
                    return $this->answers->where('status', 'evaluated')->count();
                }),
            ],

            // Relationships
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'has_final_report' => $this->whenLoaded('finalReport', function() {
                return !is_null($this->finalReport);
            }),

            // Summary data
            'cheating_summary' => $this->when($request->user()->can('viewDetailed', $this), function() {
                return [
                    'total_violations' => $this->antiCheatLogs()->count(),
                    'severity_score' => $this->calculateCheatingSeverityScore(),
                ];
            }),
        ];
    }
}
