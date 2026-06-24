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

        // Authorization check
        // Gate::authorize('update', $interview);

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

        // Check question order (must answer in sequence)
        // $previousQuestions = $interview->questions()
        //     ->where('order', '<', $question->order)
        //     ->where('status', '!=', Question::STATUS_EVALUATED)
        //     ->exists();

        // if ($previousQuestions) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Please answer questions in order',
        //     ], 400);
        // }

        try {
            // Store audio file
            $audioFile = $request->file('audio_file');
            $path = $audioFile->store('answers/' . $interview->id, 'public');

            // Create answer record
            $answer = Answer::create([
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'audio_file_path' => $path,
                'duration_seconds' => $request->duration_seconds,
                'status' => Answer::STATUS_PENDING,
                'submitted_at' => now(),
            ]);

            // Update question status
            $question->update([
                'status' => Question::STATUS_ANSWERED,
                'answered_at' => now(),
            ]);

            // Dispatch processing job to queue
            ProcessSingleAnswerJob::dispatch($answer, $path)
                ->onQueue('answers')
                ->afterCommit();

            return response()->json([
                'success' => true,
                'message' => 'Answer submitted successfully and queued for processing',
                'data' => new AnswerResource($answer),
            ], 201);

        } catch (\Exception $e) {
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
}
