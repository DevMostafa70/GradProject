<?php

namespace App\Jobs;

use App\Models\Answer;
use App\Models\Question;
use App\Models\Evaluation;
use App\Models\AudioAnalysis;
use App\Models\Interview;
use App\Models\AntiCheatLog;
use App\Services\AudioTranscriptionService;
use App\Services\LLMService;
use App\Services\FinalReportCoordinator;
use App\Services\PromptInjectionGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Logger;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Storage;

class ProcessSingleAnswerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 300;

    protected Answer $answer;
    protected string $audioFilePath;

    /**
     * Create a new job instance.
     */
    public function __construct(Answer $answer, string $audioFilePath)
    {
        $this->answer = $answer;
        $this->audioFilePath = $audioFilePath;
        $this->onQueue('answers');
    }

    /**
     * Execute the job.
     */
    public function handle(
        AudioTranscriptionService $transcriptionService,
        LLMService $llmService,
        FinalReportCoordinator $finalReportCoordinator
    ): void {
        try {
            $this->answer->refresh();

            // Queue retries are idempotent. If the answer was already fully
            // evaluated, only re-run the final-report readiness check.
            if (
                $this->answer->status === Answer::STATUS_EVALUATED
                && $this->answer->evaluation()->exists()
            ) {
                $reportGeneration = $finalReportCoordinator->dispatchIfReady(
                    $this->answer->interview_id,
                    'answer_job_idempotent_recheck'
                );

                Log::info('Answer already evaluated; final report readiness rechecked', [
                    'answer_id' => $this->answer->id,
                    'interview_id' => $this->answer->interview_id,
                    'report_generation' => $reportGeneration,
                ]);

                return;
            }
            Log::info('Processing answer with real Whisper transcription', [
                'answer_id' => $this->answer->id,
                'interview_id' => $this->answer->interview_id,
                'audio_file' => $this->audioFilePath
            ]);

            // Mark as processing
            $this->answer->update(['status' => Answer::STATUS_PROCESSING]);
            $this->answer->question->update(['status' => Question::STATUS_PROCESSING]);

            // Step 1: Real transcription using Whisper API
            Log::info('Starting Whisper transcription for answer', [
                'answer_id' => $this->answer->id
            ]);

            $transcriptionResult = $transcriptionService->transcribe($this->audioFilePath);

            if (!$transcriptionResult['success']) {
                Log::error('Transcription failed for answer', [
                    'answer_id' => $this->answer->id,
                    'error' => $transcriptionResult['error'] ?? 'Unknown error'
                ]);

                // Still mark as failed but don't throw, let the job continue with fallback
                $this->answer->update([
                    'transcription' => 'Transcription failed',
                    'processing_metadata' => [
                        'transcription_error' => $transcriptionResult['error'] ?? 'Unknown error',
                        'failed_at' => now()->toISOString(),
                    ]
                ]);
            } else {
                // Step 2: Update answer with real transcription
                $this->answer->update([
                    'transcription' => $transcriptionResult['transcript'],
                    'processing_metadata' => [
                        'transcription_confidence' => $transcriptionResult['confidence'],
                        'transcription_model' => $transcriptionResult['model_used'] ?? 'whisper-1',
                        'word_count' => $transcriptionResult['word_count'],
                        'transcribed_at' => now()->toISOString(),
                    ]
                ]);

                Log::info('Transcription completed successfully', [
                    'answer_id' => $this->answer->id,
                    'word_count' => $transcriptionResult['word_count'],
                    'confidence' => $transcriptionResult['confidence']
                ]);
            }

            // Step 3: Analyze audio characteristics
            $audioAnalysis = $transcriptionService->analyzeAudio($this->audioFilePath, $this->answer);

            // Calculate REAL speaking rate using Whisper transcript duration
            $wordCount = $transcriptionResult['word_count'] ?? 0;

            $duration =
                $transcriptionResult['duration']
                ?? data_get($audioAnalysis, 'full_analysis_data.duration')
                ?? $audioAnalysis['duration']
                ?? $this->answer->duration_seconds
                ?? 60;

            $realSpeakingRate = $duration > 0
                ? round(($wordCount / $duration) * 60, 2)
                : 0;

            $audioAnalysis['speaking_rate'] = $realSpeakingRate;

            Log::info('Real Speaking Rate Calculated', [
                'word_count' => $wordCount,
                'duration' => $duration,
                'speaking_rate' => $realSpeakingRate,
            ]);

            Log::info('Audio Analysis', $audioAnalysis);

            AudioAnalysis::updateOrCreate(
                ['answer_id' => $this->answer->id],
                array_merge($audioAnalysis, [
                    'interview_id' => $this->answer->interview_id,
                ])
            );

            // ============================================================
            // 🔹 Prompt Injection Protection
            // ============================================================
            $transcript = $this->answer->transcription ?? '';
            $safeTranscript = $transcript;
            // $useFallbackEvaluation = false;
            // $fallbackEvaluation = null;
            // $detection = null;

            // if (!empty($transcript)) {
            //     $guard = new PromptInjectionGuard();
            //     $processed = $guard->process($transcript);

            //     // If prompt injection was detected
            //     if ($processed['detection']['detected']) {
            //         Log::warning('Prompt injection detected in answer', [
            //             'answer_id' => $this->answer->id,
            //             'risk_level' => $processed['detection']['risk_level'],
            //             'risk_score' => $processed['detection']['risk_score'],
            //         ]);

            //         // Log the violation
            //         $this->logPromptInjection(
            //             $this->answer->interview,
            //             $processed['detection']
            //         );

            //         // If risk is critical or high, use fallback evaluation
            //         if (in_array($processed['detection']['risk_level'], ['critical', 'high'])) {
            //             $useFallbackEvaluation = true;
            //             $fallbackEvaluation = $guard->getFallbackEvaluation(
            //                 $transcript,
            //                 $processed['sanitized'],
            //                 $processed['detection']
            //             );
            //             $safeTranscript = $processed['sanitized'];
            //             $detection = $processed['detection'];

            //             Log::warning('Using fallback evaluation due to high-risk prompt injection', [
            //                 'answer_id' => $this->answer->id,
            //                 'risk_level' => $processed['detection']['risk_level'],
            //             ]);
            //         } else {
            //             // Medium or low risk: use sanitized transcript
            //             $safeTranscript = $processed['sanitized'];
            //             Log::info('Using sanitized transcript for evaluation', [
            //                 'answer_id' => $this->answer->id,
            //                 'risk_level' => $processed['detection']['risk_level'],
            //             ]);
            //         }
            //     }
            // }

            // ============================================================
            // Step 4: Evaluate answer using AI with real transcription
            // ============================================================
            $evaluation =  $this->evaluateAnswer($audioAnalysis, $safeTranscript);

            // Step 5: Create evaluation record
            Evaluation::updateOrCreate(
                ['answer_id' => $this->answer->id],
                [
                    'question_id' => $this->answer->question_id,
                    'interview_id' => $this->answer->interview_id,
                    'score' => $evaluation['score'],
                    'criteria_scores' => $evaluation['criteria_scores'],
                    'strengths' => $evaluation['strengths'],
                    'weaknesses' => $evaluation['weaknesses'],
                    'detailed_feedback' => $evaluation['detailed_feedback'],
                    'clarity_score' => $evaluation['clarity_score'],
                    'relevance_score' => $evaluation['relevance_score'],
                    'depth_score' => $evaluation['depth_score'],
                    'confidence_score' => $evaluation['confidence_score'],
                    'ai_raw_response' => $evaluation['raw_response'],
                ]
            );

            // 🔹 If we used sanitized transcript, update the answer
            // if ($safeTranscript !== $transcript && !$useFallbackEvaluation) {
            //     $this->answer->update([
            //         'transcription' => $safeTranscript,
            //         'processing_metadata' => array_merge(
            //             $this->answer->processing_metadata ?? [],
            //             [
            //                 'sanitized_at' => now()->toISOString(),
            //                 'original_length' => strlen($transcript),
            //                 'sanitized_length' => strlen($safeTranscript),
            //             ]
            //         ),
            //     ]);
            // }

            // Step 6: Mark as completed
            $this->answer->update([
                'status' => Answer::STATUS_EVALUATED,
                'processed_at' => now(),
            ]);

            $this->answer->question->update([
                'status' => Question::STATUS_EVALUATED,
                'evaluated_at' => now(),
            ]);

            // Step 7: Race-condition-safe final report check.
            // This works whether the interview completion request happened
            // before or after the last answer finished evaluation.
            $reportGeneration = $finalReportCoordinator->dispatchIfReady(
                $this->answer->interview_id,
                'answer_evaluated'
            );

            Log::info('Final report readiness checked after answer evaluation', [
                'answer_id' => $this->answer->id,
                'interview_id' => $this->answer->interview_id,
                'report_generation' => $reportGeneration,
            ]);

            // ============================================================
            // 🔹 NEW: Clean up audio file with privacy logging
            // ============================================================
            if (Storage::disk('public')->delete($this->audioFilePath)) {
                // 🔹 NEW: Record deletion timestamp for privacy
                $this->answer->update([
                    'audio_deleted_at' => now(),
                ]);

                Log::info('Audio file deleted after processing (privacy)', [
                    'answer_id' => $this->answer->id,
                    'interview_id' => $this->answer->interview_id,
                    'file_path' => $this->audioFilePath,
                ]);
            } else {
                Log::warning('Audio file could not be deleted (may not exist)', [
                    'answer_id' => $this->answer->id,
                    'interview_id' => $this->answer->interview_id,
                    'file_path' => $this->audioFilePath,
                ]);
            }

            Log::info('Answer processed successfully with real transcription', [
                'answer_id' => $this->answer->id,
                'score' => $evaluation['score'],
                'transcription_length' => strlen($this->answer->transcription),
                // 'prompt_injection_handled' => $useFallbackEvaluation,
                'audio_deleted_at' => $this->answer->audio_deleted_at,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process answer', [
                'answer_id' => $this->answer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->answer->update([
                'status' => Answer::STATUS_FAILED,
                'processing_metadata' => [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toISOString(),
                ]
            ]);

            $this->answer->question->update(['status' => Question::STATUS_PENDING]);

            throw $e;
        }
    }

    /**
     * Evaluate answer using OpenAI with real transcription
     * 🔹 MODIFIED: Accept $safeTranscript parameter
     */
    private function evaluateAnswer($audioAnalysis, string $safeTranscript): array
    {
        $question = $this->answer->question;
        $interview = $question->interview;

        $cheatingContext = '';
        $cheatingPenalty = 0;

        $violations = $interview->antiCheatLogs()
            ->where('violation_timestamp', '<=', $this->answer->submitted_at)
            ->get();

        if ($violations->isNotEmpty()) {
            $cheatingContext = "\n\nCheating violations detected:\n";

            foreach ($violations as $violation) {
                $cheatingContext .= "- {$violation->violation_type} (confidence: {$violation->confidence_score})\n";

                $cheatingPenalty +=
                    $violation->severity_weight *
                    $violation->confidence_score *
                    0.1;
            }

            $cheatingContext .= "\nApply a penalty of {$cheatingPenalty} points.";
        }

        // 🔹 Use safe transcript instead of raw transcript
        $transcript = $safeTranscript ?? $this->answer->transcription ?? '';

        $prompt = <<<EOT
Evaluate this interview answer for a {$interview->experience_level} {$interview->position} position.

Question: {$question->question_text}
Question Type: {$question->type}

Candidate's Answer:
{$transcript}

Audio Metrics:
- Speaking Rate: {$audioAnalysis['speaking_rate']} words/minute
- Filler Words: {$audioAnalysis['filler_word_count']}
- Voice Stability: {$audioAnalysis['voice_stability']}
- Confidence Level: {$audioAnalysis['confidence_level']}
{$cheatingContext}

You MUST respond with ONLY a valid JSON object. No markdown. No explanation.

Use exactly this JSON structure:
{
    "score": 7.5,
    "strengths": "What was good about the answer",
    "weaknesses": "Areas for improvement",
    "detailed_feedback": "Comprehensive evaluation",
    "clarity_score": 0.8,
    "relevance_score": 0.7,
    "depth_score": 0.6,
    "confidence_score": 0.9
}

Rules:
- score must be between 0 and 10
- clarity_score, relevance_score, depth_score, confidence_score must be between 0 and 1
- Evaluate the actual transcribed answer content
- Consider audio quality metrics
- Apply cheating penalties if violations were detected
EOT;

        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info('AI Evaluation Attempt', [
                    'answer_id' => $this->answer->id,
                    'attempt' => $attempt,
                ]);

                $response = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an expert technical interviewer. Respond ONLY with valid JSON.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0,
                    'max_tokens' => 1000,
                ]);

                $content = $response->choices[0]->message->content ?? '';

                $content = trim($content);
                $content = preg_replace('/^```json\s*/', '', $content);
                $content = preg_replace('/^```\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);

                $evaluation = json_decode($content, true);

                if (
                    !is_array($evaluation) ||
                    !isset($evaluation['score'])
                ) {
                    throw new \Exception('Invalid evaluation JSON response');
                }

                $finalScore = ($evaluation['score'] ?? 7.0) - $cheatingPenalty;
                $finalScore = max(0, min(10, $finalScore));

                return [
                    'score' => $finalScore,
                    'criteria_scores' => [
                        'clarity' => $evaluation['clarity_score'] ?? 0.7,
                        'depth' => $evaluation['depth_score'] ?? 0.7,
                        'relevance' => $evaluation['relevance_score'] ?? 0.7,
                        'confidence' => $evaluation['confidence_score'] ?? 0.7,
                    ],
                    'strengths' => $evaluation['strengths'] ?? 'Good response.',
                    'weaknesses' => $evaluation['weaknesses'] ?? 'Could be more detailed.',
                    'detailed_feedback' => $evaluation['detailed_feedback'] ?? 'Acceptable answer.',
                    'clarity_score' => $evaluation['clarity_score'] ?? 0.7,
                    'relevance_score' => $evaluation['relevance_score'] ?? 0.7,
                    'depth_score' => $evaluation['depth_score'] ?? 0.7,
                    'confidence_score' => $evaluation['confidence_score'] ?? 0.7,
                    'raw_response' => $evaluation,
                ];

            } catch (\Throwable $e) {
                Log::warning('AI Evaluation Failed', [
                    'answer_id' => $this->answer->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    sleep(2);
                    continue;
                }
            }
        }

        Log::error('Fallback Evaluation Used After All Retries Failed', [
            'answer_id' => $this->answer->id,
        ]);

        $wordCount = str_word_count($transcript);
        $baseScore = $wordCount > 100 ? 8.0 : ($wordCount > 50 ? 7.0 : 6.0);

        return [
            'score' => max(0, $baseScore - $cheatingPenalty),
            'criteria_scores' => [
                'clarity' => 0.7,
                'depth' => 0.7,
                'relevance' => 0.7,
                'confidence' => 0.7,
            ],
            'strengths' => 'Demonstrated understanding of the topic.',
            'weaknesses' => 'Could provide more specific examples.',
            'detailed_feedback' => "The answer contained {$wordCount} words and addressed the question.",
            'clarity_score' => 0.7,
            'relevance_score' => 0.7,
            'depth_score' => 0.7,
            'confidence_score' => 0.7,
            'raw_response' => [
                'note' => 'Fallback evaluation used after 3 failed attempts',
            ],
        ];
    }

    /**
     * 🔹 Create fallback evaluation for prompt injection
     */
    private function createFallbackEvaluation(array $fallbackEvaluation): array
    {
        return [
            'score' => $fallbackEvaluation['score'],
            'criteria_scores' => [
                'clarity' => $fallbackEvaluation['clarity_score'],
                'depth' => $fallbackEvaluation['depth_score'],
                'relevance' => $fallbackEvaluation['relevance_score'],
                'confidence' => $fallbackEvaluation['confidence_score'],
            ],
            'strengths' => is_array($fallbackEvaluation['strengths'])
                ? implode(' ', $fallbackEvaluation['strengths'])
                : $fallbackEvaluation['strengths'],
            'weaknesses' => is_array($fallbackEvaluation['weaknesses'])
                ? implode(' ', $fallbackEvaluation['weaknesses'])
                : $fallbackEvaluation['weaknesses'],
            'detailed_feedback' => $fallbackEvaluation['detailed_feedback'],
            'clarity_score' => $fallbackEvaluation['clarity_score'],
            'relevance_score' => $fallbackEvaluation['relevance_score'],
            'depth_score' => $fallbackEvaluation['depth_score'],
            'confidence_score' => $fallbackEvaluation['confidence_score'],
            'raw_response' => [
                'prompt_injection_detected' => true,
                'risk_level' => $fallbackEvaluation['risk_level'] ?? 'medium',
                'note' => 'Fallback evaluation due to prompt injection detection',
            ],
        ];
    }

    /**
     * 🔹 Log prompt injection attempt
     */
    private function logPromptInjection(Interview $interview, array $detection): void
    {
        try {
            AntiCheatLog::create([
                'interview_id' => $interview->id,
                'violation_type' => 'prompt_injection_attempt',
                'violation_timestamp' => now(),
                'duration_seconds' => 0,
                'confidence_score' => min(1.0, ($detection['risk_score'] ?? 5) / 10),
                'metadata' => [
                    'risk_level' => $detection['risk_level'] ?? 'unknown',
                    'risk_score' => $detection['risk_score'] ?? 0,
                    'detections' => $detection['detections'] ?? [],
                    'suspicious_words' => $detection['suspicious_words'] ?? [],
                    'answer_id' => $this->answer->id,
                ],
                'severity_weight' => ($detection['risk_score'] ?? 5) / 2,
            ]);

            Log::info('Prompt injection logged in anti_cheat_logs', [
                'interview_id' => $interview->id,
                'answer_id' => $this->answer->id,
                'risk_level' => $detection['risk_level'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log prompt injection', [
                'interview_id' => $interview->id,
                'error' => $e->getMessage(),
            ]);
        }
    }


}