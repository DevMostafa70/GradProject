<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResource extends JsonResource
{
    /**
     * Transform the interview into an array.
     */
    public function toArray(Request $request): array
    {
        $locale = $this->normalizeLocale(
            $this->resource->locale ?? app()->getLocale()
        );

        $user = $request->user();

        $canViewDetailed = $user
            ? $user->can('viewDetailed', $this->resource)
            : false;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            'position' => $this->position,
            'experience_level' => $this->experience_level,
            'difficulty' => $this->difficulty,
            'skills' => $this->skills,

            'number_of_questions' => $this->number_of_questions,

            /*
             * لغة المقابلة المثبتة.
             * لا تعتمد على لغة الواجهة الحالية بعد إنشاء المقابلة.
             */
            'locale' => $locale,

            'status' => $this->status,

            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            /*
             * Session information
             */
            'session' => [
                'token' => $this->session_token,
                'expires_at' => $this->expires_at?->toISOString(),
                'last_activity_at' => $this->last_activity_at?->toISOString(),
                'current_question_id' => $this->current_question_id,
                'answered_questions_count' => (int) (
                    $this->answered_questions_count ?? 0
                ),
            ],

            /*
             * Progress information
             */
            'progress' => [
                'questions_answered' => $this->relationLoaded('answers')
                    ? $this->answers->count()
                    : $this->answers()->count(),

                'questions_total' => $this->relationLoaded('questions')
                    ? $this->questions->count()
                    : $this->questions()->count(),

                'answers_processed' => $this->relationLoaded('answers')
                    ? $this->answers
                        ->where('status', 'evaluated')
                        ->count()
                    : $this->answers()
                        ->where('status', 'evaluated')
                        ->count(),
            ],

            /*
             * Relationships
             */
            'questions' => QuestionResource::collection(
                $this->whenLoaded('questions')
            ),

            'has_final_report' => $this->relationLoaded('finalReport')
                ? $this->finalReport !== null
                : $this->finalReport()->exists(),

            /*
             * Detailed integrity information.
             */
            'cheating_summary' => $this->when(
                $canViewDetailed,
                function (): array {
                    return [
                        'total_violations' => $this
                            ->antiCheatLogs()
                            ->count(),

                        'severity_score' => $this
                            ->calculateCheatingSeverityScore(),
                    ];
                }
            ),
        ];
    }

    /**
     * Normalize supported interview locales.
     */
    private function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(
            trim((string) $locale)
        );

        /*
         * دعم قيم مثل:
         * ar-PS
         * ar_SA
         * en-US
         * en_GB
         */
        $locale = str_replace('_', '-', $locale);
        $locale = explode('-', $locale)[0] ?? 'en';

        return in_array($locale, ['ar', 'en'], true)
            ? $locale
            : 'en';
    }
}