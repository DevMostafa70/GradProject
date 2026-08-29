<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResumeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Interview $interview */
        $interview = $this->resource;

        // Load relationships if not already loaded
        $interview->loadMissing(['questions', 'answers.evaluation', 'answers.audioAnalysis']);

        $totalQuestions = $interview->questions()->count();
        $answeredCount = $interview->answers()->count();
        $nextQuestion = $interview->getNextQuestion();

        return [
            // ==================== Interview Info ====================
            'interview' => [
                'id' => $interview->id,
                'position' => $interview->position,
                'experience_level' => $interview->experience_level,
                'difficulty' => $interview->difficulty,
                'skills' => $interview->skills,
                'number_of_questions' => $interview->number_of_questions,
                'status' => $interview->status,
                'started_at' => $interview->started_at?->toISOString(),
                'completed_at' => $interview->completed_at?->toISOString(),
                'created_at' => $interview->created_at?->toISOString(),
            ],

            // ==================== Session Info ====================
            'session' => [
                'session_token' => $interview->session_token,
                'expires_at' => $interview->expires_at?->toISOString(),
                'expires_in_minutes' => $interview->expires_at ? max(0, now()->diffInMinutes($interview->expires_at)) : null,
                'is_valid' => $interview->isSessionValid(),
                'is_expired' => $interview->isSessionExpired(),
                'last_activity_at' => $interview->last_activity_at?->toISOString(),
            ],

            // ==================== Progress ====================
            'progress' => [
                'total_questions' => $totalQuestions,
                'answered_count' => $answeredCount,
                'remaining_count' => max(0, $totalQuestions - $answeredCount),
                'progress_percentage' => $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100, 1) : 0,
                'all_answered' => $answeredCount >= $totalQuestions,
            ],

            // ==================== Current Question ====================
            'current_question' => $nextQuestion ? [
                'id' => $nextQuestion->id,
                'order' => $nextQuestion->order,
                'text' => $nextQuestion->question_text,
                'type' => $nextQuestion->type,
            ] : null,

            // ==================== All Questions with Answers ====================
            'questions' => $interview->questions->map(function ($question) use ($interview) {
                // Find the answer for this question
                $answer = $interview->answers->firstWhere('question_id', $question->id);

                return [
                    'id' => $question->id,
                    'order' => $question->order,
                    'text' => $question->question_text,
                    'type' => $question->type,
                    'expected_skills' => $question->expected_skills,
                    'evaluation_criteria' => $question->evaluation_criteria,
                    'status' => $question->status,
                    'answered_at' => $question->answered_at?->toISOString(),
                    'evaluated_at' => $question->evaluated_at?->toISOString(),

                    // Answer if exists
                    'answer' => $answer ? [
                        'id' => $answer->id,
                        'transcription' => $answer->status === 'evaluated' ? $answer->transcription : null,
                        'duration_seconds' => $answer->duration_seconds,
                        'status' => $answer->status,
                        'submitted_at' => $answer->submitted_at?->toISOString(),
                        'processed_at' => $answer->processed_at?->toISOString(),

                        // Evaluation if exists
                        'evaluation' => $answer->evaluation ? [
                            'id' => $answer->evaluation->id,
                            'score' => $answer->evaluation->score,
                            'adjusted_score' => $answer->evaluation->adjusted_score,
                            'criteria_scores' => $answer->evaluation->criteria_scores,
                            'strengths' => $answer->evaluation->strengths,
                            'weaknesses' => $answer->evaluation->weaknesses,
                            'detailed_feedback' => $answer->evaluation->detailed_feedback,
                            'clarity_score' => $answer->evaluation->clarity_score,
                            'relevance_score' => $answer->evaluation->relevance_score,
                            'depth_score' => $answer->evaluation->depth_score,
                            'confidence_score' => $answer->evaluation->confidence_score,
                            'cheating_penalty' => $answer->evaluation->cheating_penalty,
                        ] : null,

                        // Audio analysis if exists
                        'audio_analysis' => $answer->audioAnalysis ? [
                            'speaking_rate' => $answer->audioAnalysis->speaking_rate,
                            'filler_word_count' => $answer->audioAnalysis->filler_word_count,
                            'confidence_level' => $answer->audioAnalysis->confidence_level,
                            'voice_stability' => $answer->audioAnalysis->voice_stability,
                        ] : null,
                    ] : null,
                ];
            }),
        ];
    }
}
