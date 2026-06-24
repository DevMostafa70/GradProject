<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'question_text' => $this->question_text,
            'type' => $this->type,
            'expected_skills' => $this->expected_skills,
            'evaluation_criteria' => $this->evaluation_criteria,
            'order' => $this->order,
            'status' => $this->status,
            'answered_at' => $this->answered_at?->toISOString(),
            'evaluated_at' => $this->evaluated_at?->toISOString(),

            // Include answer if loaded and user has permission
            'answer' => $this->when(
                $this->relationLoaded('answer') && $request->user()->can('view', $this->answer),
                function() {
                    return new AnswerResource($this->answer);
                }
            ),

            'evaluation' => $this->when(
                $this->relationLoaded('evaluation'),
                function() {
                    return new EvaluationResource($this->evaluation);
                }
            ),
        ];
    }
}
