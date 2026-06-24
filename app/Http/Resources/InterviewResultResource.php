<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResultResource extends JsonResource
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
            'score' => $this->whenLoaded('finalReport', function () {
                return $this->finalReport
                    ? round($this->finalReport->overall_score * 10, 2)
                    : null;
            }),
            'adjusted_score' => $this->whenLoaded('finalReport', function () {
                return $this->finalReport
                    ? round($this->finalReport->adjusted_score * 10, 2)
                    : null;
            }),
            'hiring_recommendation' => $this->whenLoaded('finalReport', function () {
                return $this->finalReport?->hiring_recommendation;
            }),
            'completed_at' => $this->completed_at ?? $this->updated_at,
            'created_at' => $this->created_at,
        ];
    }
}
