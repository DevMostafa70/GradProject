<?php

namespace App\Jobs;

use App\Models\Answer;
use App\Models\Question;
use App\Models\Evaluation;
use App\Models\AudioAnalysis;
use App\Models\Interview;
use App\Services\AudioTranscriptionService;
use App\Services\LLMService;
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
        LLMService $llmService
    ): void {
        try {
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




            // AudioAnalysis::create(array_merge($audioAnalysis, [
            //     'answer_id' => $this->answer->id,
            //     'interview_id' => $this->answer->interview_id,
            // ]));
            AudioAnalysis::updateOrCreate(
                ['answer_id' => $this->answer->id],
                array_merge($audioAnalysis, [
                    'interview_id' => $this->answer->interview_id,
                ])
            );

            // Step 4: Evaluate answer using AI with real transcription
            $evaluation = $this->evaluateAnswer($audioAnalysis);

            // Step 5: Create evaluation record
            Evaluation::create([
                'answer_id' => $this->answer->id,
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
            ]);

            // Step 6: Mark as completed
            $this->answer->update([
                'status' => Answer::STATUS_EVALUATED,
                'processed_at' => now(),
            ]);

            $this->answer->question->update([
                'status' => Question::STATUS_EVALUATED,
                'evaluated_at' => now(),
            ]);

            // Step 7: Check if interview is complete
            $this->checkInterviewCompletion();

            // Clean up audio file
            Storage::delete($this->audioFilePath);

            Log::info('Answer processed successfully with real transcription', [
                'answer_id' => $this->answer->id,
                'score' => $evaluation['score'],
                'transcription_length' => strlen($this->answer->transcription)
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
 */
private function evaluateAnswer($audioAnalysis): array
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

    $transcript = $this->answer->transcription ?? '';

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
 * Check if all answers are processed and trigger final report generation
 */
private function checkInterviewCompletion(): void
{
    $interview = Interview::find($this->answer->interview_id);

    if (
        $interview &&
        $interview->hasAllAnswersProcessed() &&
        $interview->status === Interview::STATUS_COMPLETED
    ) {
        $interview->update([
            'status' => Interview::STATUS_PROCESSING_FINAL,
        ]);

        GenerateFinalReportJob::dispatch($interview)
            ->onQueue('reports')
            ->delay(now()->addSeconds(5));

        Log::info('All answers processed, queued final report generation', [
            'interview_id' => $interview->id,
        ]);
    }
}
}
