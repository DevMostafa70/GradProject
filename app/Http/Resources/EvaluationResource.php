<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'answer_id' => $this->answer_id,
            'question_id' => $this->question_id,
            'score' => $this->score,
            'adjusted_score' => $this->adjusted_score,
            'criteria_scores' => $this->criteria_scores,
            'strengths' => $this->strengths,
            'weaknesses' => $this->weaknesses,
            'detailed_feedback' => $this->detailed_feedback,
            'clarity_score' => $this->clarity_score,
            'relevance_score' => $this->relevance_score,
            'depth_score' => $this->depth_score,
            'confidence_score' => $this->confidence_score,
            'cheating_penalty' => $this->cheating_penalty,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
