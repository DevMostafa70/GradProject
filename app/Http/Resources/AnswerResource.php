<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'question_id' => $this->question_id,
            'transcription' => $this->when(
                $this->status === 'evaluated',
                $this->transcription
            ),
            'duration_seconds' => $this->duration_seconds,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),

            'evaluation' => $this->when(
                $this->relationLoaded('evaluation'),
                function() {
                    return new EvaluationResource($this->evaluation);
                }
            ),

            'audio_analysis' => $this->when(
                $this->relationLoaded('audioAnalysis'),
                function() {
                    return [
                        'speaking_rate' => $this->audioAnalysis->speaking_rate,
                        'filler_word_count' => $this->audioAnalysis->filler_word_count,
                        'confidence_level' => $this->audioAnalysis->confidence_level,
                    ];
                }
            ),
        ];
    }
}
