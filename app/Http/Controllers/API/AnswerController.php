<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAnswerRequest;
use App\Http\Resources\AnswerResource;
use App\Jobs\ProcessSingleAnswerJob;
use App\Models\Answer;
use App\Models\Interview;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AnswerController extends Controller
{
    public function __construct()
    {
        // $this->middleware('auth:sanctum');
    }

    /**
     * Submit an answer for a question
     */
    public function store(SubmitAnswerRequest $request)
    {
        $question = Question::findOrFail($request->question_id);
        $interview = $question->interview;

        // 🔹 NEW: Check if answer already exists for this question (Database-level check)
        if (Answer::existsForQuestion($interview->id, $question->id)) {
            // Get the existing answer
            $existingAnswer = Answer::where('interview_id', $interview->id)
                ->where('question_id', $question->id)
                ->first();

            Log::warning('Duplicate answer prevented (question already answered)', [
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'existing_answer_id' => $existingAnswer->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Answer already submitted for this question',
                'data' => new AnswerResource($existingAnswer),
                'duplicate' => true,
                'session' => $this->getSessionData($interview),
            ], 200);
        }

        // 🔹 NEW: Check idempotency key to prevent duplicate requests
        $idempotencyKey = $request->input('idempotency_key');
        $existingByIdempotency = Answer::findByIdempotencyKey($idempotencyKey);

        if ($existingByIdempotency) {
            Log::warning('Duplicate request prevented by idempotency key', [
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'idempotency_key' => $idempotencyKey,
                'existing_answer_id' => $existingByIdempotency->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Duplicate request detected',
                'data' => new AnswerResource($existingByIdempotency),
                'duplicate' => true,
                'session' => $this->getSessionData($interview),
            ], 200);
        }

        // 🔹 Check if session is valid
        if ($interview->isSessionExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Interview session has expired. Please start a new interview.',
                'error_code' => 'SESSION_EXPIRED',
            ], 410);
        }

        // 🔹 Check if this is the current question
        if ($interview->current_question_id && $interview->current_question_id != $question->id) {
            // Check if user is trying to answer out of order
            $nextQuestion = $interview->getNextQuestion();
            if ($nextQuestion && $nextQuestion->id != $question->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please answer questions in order.',
                    'current_question' => $nextQuestion ? [
                        'id' => $nextQuestion->id,
                        'order' => $nextQuestion->order,
                        'text' => $nextQuestion->question_text,
                    ] : null,
                ], 400);
            }
        }

        // Validate question is ready for answering
        if ($question->status !== Question::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This question has already been answered',
            ], 400);
        }

        // Check if interview is in progress
        if ($interview->status !== Interview::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => false,
                'message' => 'Interview is not in progress',
            ], 400);
        }

        try {
            // Store audio file
            $audioFile = $request->file('audio_file');
            $path = $audioFile->store('answers/' . $interview->id, 'public');

            // 🔹 NEW: Create answer with idempotency protection
            $answer = Answer::createWithIdempotency([
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'audio_file_path' => $path,
                'duration_seconds' => $request->duration_seconds,
                'status' => Answer::STATUS_PENDING,
                'submitted_at' => now(),
            ], $idempotencyKey);

            // 🔹 NEW: If answer creation returned null (duplicate), return existing
            if (!$answer) {
                $existingAnswer = Answer::where('interview_id', $interview->id)
                    ->where('question_id', $question->id)
                    ->first();

                Log::warning('Duplicate answer prevented during creation', [
                    'interview_id' => $interview->id,
                    'question_id' => $question->id,
                    'existing_answer_id' => $existingAnswer->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Answer already exists for this question',
                    'data' => new AnswerResource($existingAnswer),
                    'duplicate' => true,
                    'session' => $this->getSessionData($interview),
                ], 200);
            }

            // Update question status
            $question->update([
                'status' => Question::STATUS_ANSWERED,
                'answered_at' => now(),
            ]);

            // Update session progress
            $interview->updateProgress($question);

            // Find and set the next question
            $nextQuestion = $interview->getNextQuestion();
            if ($nextQuestion) {
                $interview->current_question_id = $nextQuestion->id;
                $interview->save();
            } else {
                // All questions answered
                $interview->current_question_id = null;
                $interview->save();
            }

            // Dispatch processing job to queue
            ProcessSingleAnswerJob::dispatch($answer, $path)
                ->onQueue('answers')
                ->afterCommit();

            return response()->json([
                'success' => true,
                'message' => 'Answer submitted successfully and queued for processing',
                'data' => new AnswerResource($answer),
                'duplicate' => false,
                'session' => $this->getSessionData($interview, $nextQuestion),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to submit answer: ' . $e->getMessage(), [
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit answer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get answer status
     */
    public function show(Answer $answer): JsonResponse
    {
        Gate::authorize('view', $answer->interview);

        return response()->json([
            'success' => true,
            'data' => new AnswerResource($answer->load(['evaluation', 'audioAnalysis'])),
        ]);
    }

    /**
     * 🔹 NEW: Helper method to get session data
     */
    private function getSessionData(Interview $interview, ?Question $nextQuestion = null): array
    {
        $totalQuestions = $interview->questions()->count();
        $answeredCount = $interview->answers()->count();

        if (!$nextQuestion) {
            $nextQuestion = $interview->getNextQuestion();
        }

        return [
            'answered_count' => $answeredCount,
            'total_questions' => $totalQuestions,
            'remaining' => max(0, $totalQuestions - $answeredCount),
            'next_question' => $nextQuestion ? [
                'id' => $nextQuestion->id,
                'order' => $nextQuestion->order,
                'text' => $nextQuestion->question_text,
                'type' => $nextQuestion->type,
            ] : null,
            'all_answered' => $answeredCount >= $totalQuestions,
            'expires_at' => $interview->expires_at?->toISOString(),
            'expires_in_minutes' => $interview->expires_at ? max(0, now()->diffInMinutes($interview->expires_at)) : null,
        ];
    }
}
