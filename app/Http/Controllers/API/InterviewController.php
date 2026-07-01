<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInterviewRequest;
use App\Http\Resources\InterviewResource;
use App\Http\Resources\QuestionResource;
use App\Http\Resources\FinalReportResource;
use App\Jobs\GenerateFinalReportJob;
use App\Models\Interview;
use App\Models\Question;
use App\Models\FinalReport;
use App\Services\LLMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    protected LLMService $llmService;

    // 🔹 NEW: Default session duration in minutes
    protected int $defaultSessionDuration = 60;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
    }

   /**
 * Start a new interview
 */
public function store(StoreInterviewRequest $request)
{
    try {
        // Get session duration from request or use default
        $sessionDuration = $request->input('session_duration', $this->defaultSessionDuration);

        // Create interview with session management
        $interview = Interview::create([
            'user_id' => Auth::id(),
            'position' => $request->position,
            'experience_level' => $request->experience_level,
            'difficulty' => $request->difficulty,
            'skills' => $request->skills,
            'number_of_questions' => $request->number_of_questions ?? 5,
            'status' => Interview::STATUS_PENDING,
            // 🔹 Session Management
            'session_token' => null, // Will be generated after questions are created
            'expires_at' => null, // Will be set after questions are created
            'last_activity_at' => null,
            'current_question_id' => null,
            'answered_questions_count' => 0,
            // 🔹 NEW: Tab Lock columns
            'active_session_id' => null,
            'session_initialized_at' => null,
            'device_fingerprint' => null,
        ]);

        // Generate questions using AI
        $questionsData = $this->llmService->generateQuestions($interview);

        // Save questions
        foreach ($questionsData as $questionData) {
            Question::create(array_merge($questionData, [
                'interview_id' => $interview->id,
                'status' => Question::STATUS_PENDING,
            ]));
        }

        // 🔹 Generate session token and set expiration
        $interview->generateSessionToken();
        $interview->setExpiration($sessionDuration);

        // Set the first question as current
        $firstQuestion = $interview->questions()->orderBy('order')->first();
        if ($firstQuestion) {
            $interview->current_question_id = $firstQuestion->id;
        }

        // 🔹 NEW: Lock the interview for the current session
        $sessionId = $request->input('session_id') ?? $interview->session_token;
        $deviceFingerprint = $request->input('device_fingerprint');
        $interview->lock($sessionId, $deviceFingerprint);

        // Update interview status
        $interview->update([
            'status' => Interview::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Reload with relationships
        $interview->load('questions');

        return response()->json([
            'success' => true,
            'message' => 'Interview started successfully',
            'data' => new InterviewResource($interview),
            // 🔹 Session info
            'session' => [
                'token' => $interview->session_token,
                'expires_at' => $interview->expires_at?->toISOString(),
                'expires_in_minutes' => $sessionDuration,
                'current_question_id' => $interview->current_question_id,
                // 🔹 NEW: Lock info
                'locked' => true,
                'session_id' => $interview->active_session_id,
            ],
        ], 201);

    } catch (\Exception $e) {
        Log::error('Failed to start interview: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to start interview',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    /**
     * 🔹 NEW: Get session status for an interview
     * GET /api/interviews/{interview}/session
     */
    public function sessionStatus(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        // If interview is already completed, return simple status
        if (in_array($interview->status, [
            Interview::STATUS_COMPLETED,
            Interview::STATUS_COMPLETED_WITH_REPORT,
            Interview::STATUS_FAILED,
        ])) {
            return response()->json([
                'success' => true,
                'data' => $interview->getSessionStatus(),
                'message' => 'Interview is already completed',
            ]);
        }

        // Check if session expired
        if ($interview->isSessionExpired()) {
            // Update status to expired
            $interview->update(['status' => Interview::STATUS_EXPIRED]);

            return response()->json([
                'success' => false,
                'message' => 'Interview session has expired',
                'data' => $interview->getSessionStatus(),
            ], 410); // 410 Gone
        }

        // Update last activity
        $interview->updateActivity();

        return response()->json([
            'success' => true,
            'data' => $interview->getSessionStatus(),
        ]);
    }

    /**
     * 🔹 NEW: Resume an interview from session token
     * GET /api/interviews/resume/{token}
     */
    public function resumeByToken(string $token): JsonResponse
    {
        $interview = Interview::where('session_token', $token)
            ->where('user_id', Auth::id())
            ->first();

        if (!$interview) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid session token',
            ], 404);
        }

        return $this->sessionStatus($interview);
    }

    /**
     * Get interview details with questions
     */
    public function show(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        return response()->json([
            'success' => true,
            'data' => new InterviewResource($interview->load(['questions', 'answers.evaluation'])),
        ]);
    }

    /**
     * Complete interview (called after last answer)
     */
    public function complete(Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        if ($interview->status !== Interview::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => false,
                'message' => 'Interview cannot be completed in its current state',
            ], 400);
        }

        // Check if all questions are answered
        $answeredCount = $interview->answers()->count();
        $totalQuestions = $interview->questions()->count();

        if ($answeredCount < $totalQuestions) {
            return response()->json([
                'success' => false,
                'message' => "Only {$answeredCount} of {$totalQuestions} questions answered",
            ], 400);
        }

        try {
            DB::beginTransaction();

            $interview->update([
                'status' => Interview::STATUS_COMPLETED,
                'completed_at' => now(),
                'last_activity_at' => now(), // 🔹 NEW
            ]);

            // Mark pending questions
            $pendingQuestions = $interview->questions()
                ->where('status', Question::STATUS_PENDING)
                ->get();

            foreach ($pendingQuestions as $question) {
                $question->update([
                    'status' => Question::STATUS_ANSWERED,
                    'answer_text' => $question->answer_text ?? 'No answer provided',
                    'answered_at' => now(),
                ]);
            }

            DB::commit();

            $evaluatedCount = $interview->answers()->where('status', 'evaluated')->count();
            $allAnswersProcessed = $interview->hasAllAnswersProcessed();

            Log::info('Interview completed successfully', [
                'interview_id' => $interview->id,
                'total_questions' => $totalQuestions,
                'answered_count' => $answeredCount,
                'evaluated_count' => $evaluatedCount,
                'all_answers_processed' => $allAnswersProcessed,
                'pending_questions_marked' => $pendingQuestions->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Interview completed successfully. Report will be generated automatically after all answers are evaluated.',
                'data' => [
                    'interview_id' => $interview->id,
                    'status' => 'completed',
                    'total_questions' => $totalQuestions,
                    'answered_count' => $answeredCount,
                    'evaluated_count' => $evaluatedCount,
                    'all_answers_evaluated' => $allAnswersProcessed,
                    'pending_questions_marked' => $pendingQuestions->count(),
                    'estimated_time_seconds' => $allAnswersProcessed ? 60 : 120,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to complete interview: ' . $e->getMessage(), [
                'interview_id' => $interview->id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete interview: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check final report generation status
     */
    public function checkFinalStatus(Interview $interview): JsonResponse
    {
        // Gate::authorize('view', $interview);

        $hasReport = $interview->finalReport()->exists();
        $allAnswersProcessed = $interview->hasAllAnswersProcessed();

        return response()->json([
            'success' => true,
            'data' => [
                'interview_id' => $interview->id,
                'status' => $interview->status,
                'report_ready' => $hasReport,
                'all_answers_processed' => $allAnswersProcessed,
                'answers_processed' => $interview->answers()->where('status', 'evaluated')->count(),
                'total_answers' => $interview->answers()->count(),
            ],
        ]);
    }

    /**
     * Get final report
     */
    public function getFinalReport(Interview $interview): JsonResponse
    {
        // Gate::authorize('view', $interview);

        $report = $interview->finalReport;

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Final report not yet available',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new FinalReportResource($report),
        ]);
    }

    /**
     * List user's interviews
     */
    public function index(Request $request): JsonResponse
    {
        $interviews = $request->user()
            ->interviews()
            ->with(['questions', 'finalReport'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => InterviewResource::collection($interviews),
            'meta' => [
                'current_page' => $interviews->currentPage(),
                'total' => $interviews->total(),
                'per_page' => $interviews->perPage(),
            ],
        ]);
    }

    /**
 * Check if report is ready (polling endpoint)
 */
public function checkReportReady(Interview $interview): JsonResponse
{
    Gate::authorize('view', $interview);

    $report = $interview->finalReport;
     $allProcessed = $interview->hasAllAnswersProcessed();
    $evaluationsCount = $interview->evaluations()->count();
    $totalQuestions = $interview->questions()->count();

    return response()->json([
        'success' => true,
        'ready' => $report !== null,
        'data' => $report ? new FinalReportResource($report) : null,
        'status' => $interview->status,
        'debug' => [  // ✅ هذه المعلومات تساعدك تعرف وين المشكلة
            'report_exists' => $report !== null,
            'all_answers_processed' => $allProcessed,
            'evaluations_count' => $evaluationsCount,
            'total_questions' => $totalQuestions,
            'interview_status' => $interview->status,
            'answers_status' => $interview->answers()
                ->select('status')
                ->get()
                ->pluck('status')
                ->toArray()
        ]
    ]);
}


    /**
     * 🔹 NEW: Resume an interview with full data
     * GET /api/interviews/{interview}/resume
     *
     * Returns all data needed to restore the interview state on the frontend
     */
    public function resume(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        // Check if interview is already completed
        if (in_array($interview->status, [
            Interview::STATUS_COMPLETED,
            Interview::STATUS_COMPLETED_WITH_REPORT,
            Interview::STATUS_FAILED,
        ])) {
            return response()->json([
                'success' => true,
                'message' => 'Interview is already completed',
                'data' => $interview->getResumeData(),
            ]);
        }

        // Check if session is expired
        if ($interview->isSessionExpired()) {
            // Update status to expired
            $interview->update(['status' => Interview::STATUS_EXPIRED]);

            return response()->json([
                'success' => false,
                'message' => 'Interview session has expired. Please start a new interview.',
                'data' => $interview->getResumeData(),
            ], 410);
        }

        // Update last activity
        $interview->updateActivity();

        // If interview is in progress but has no session token, generate one
        if (empty($interview->session_token) && $interview->status === Interview::STATUS_IN_PROGRESS) {
            $interview->generateSessionToken();
            $interview->save();
        }

        // If current_question_id is null but there are pending questions, set it
        if (empty($interview->current_question_id) && $interview->status === Interview::STATUS_IN_PROGRESS) {
            $nextQuestion = $interview->getNextQuestion();
            if ($nextQuestion) {
                $interview->current_question_id = $nextQuestion->id;
                $interview->save();
            }
        }

        // Reload with all relationships
        $interview->loadMissing(['questions', 'answers.evaluation', 'answers.audioAnalysis']);

        return response()->json([
            'success' => true,
            'message' => 'Interview resumed successfully',
            'data' => $interview->getResumeData(),
        ]);
    }

    /**
     * 🔹 NEW: Check if an interview can be resumed
     * GET /api/interviews/{interview}/can-resume
     */
    public function canResume(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        // Check if interview is in progress
        if ($interview->status !== Interview::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => true,
                'can_resume' => false,
                'reason' => 'Interview is not in progress',
                'status' => $interview->status,
            ]);
        }

        // Check if session is expired
        if ($interview->isSessionExpired()) {
            return response()->json([
                'success' => true,
                'can_resume' => false,
                'reason' => 'Session has expired',
                'expires_at' => $interview->expires_at?->toISOString(),
            ]);
        }

        // Check if all questions are answered
        if ($interview->answered_questions_count >= $interview->questions()->count()) {
            return response()->json([
                'success' => true,
                'can_resume' => true,
                'reason' => 'All questions answered, ready to complete',
                'all_answered' => true,
            ]);
        }

        return response()->json([
            'success' => true,
            'can_resume' => true,
            'reason' => 'Ready to resume',
            'all_answered' => false,
            'remaining_questions' => max(0, $interview->questions()->count() - $interview->answered_questions_count),
            'expires_in_minutes' => $interview->expires_at ? max(0, now()->diffInMinutes($interview->expires_at)) : null,
        ]);
    }


        /**
     * 🔹 NEW: Get lock status for an interview
     * GET /api/interviews/{interview}/lock-status
     */
    public function lockStatus(Interview $interview, Request $request): JsonResponse
    {
        Gate::authorize('view', $interview);

        $sessionId = $request->header('X-Session-Id') ?? $request->input('session_id');

        $lockStatus = $interview->getLockStatus($sessionId);

        return response()->json([
            'success' => true,
            'data' => $lockStatus,
        ]);
    }

    /**
     * 🔹 NEW: Lock the interview for the current session
     * POST /api/interviews/{interview}/lock
     */
    public function lock(Request $request, Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        $request->validate([
            'session_id' => 'required|string|max:64',
            'device_fingerprint' => 'nullable|string|max:255',
        ]);

        $sessionId = $request->input('session_id');
        $deviceFingerprint = $request->input('device_fingerprint');

        // Check if already locked by another session
        $lockStatus = $interview->getLockStatus($sessionId);

        if ($lockStatus['locked'] && !$lockStatus['owned_by_current']) {
            return response()->json([
                'success' => false,
                'message' => $lockStatus['message'],
                'data' => $lockStatus,
            ], 423);
        }

        // Lock the interview
        $interview->lock($sessionId, $deviceFingerprint);

        return response()->json([
            'success' => true,
            'message' => 'Interview locked successfully',
            'data' => [
                'locked' => true,
                'session_id' => $interview->active_session_id,
                'initialized_at' => $interview->session_initialized_at?->toISOString(),
            ],
        ]);
    }

    /**
     * 🔹 NEW: Unlock the interview
     * POST /api/interviews/{interview}/unlock
     */
    public function unlock(Request $request, Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        $sessionId = $request->header('X-Session-Id') ?? $request->input('session_id');

        // Check if the current session owns the lock
        if (!$interview->isLockedBySession($sessionId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not own the lock for this interview',
            ], 403);
        }

        $interview->unlock();

        return response()->json([
            'success' => true,
            'message' => 'Interview unlocked successfully',
            'data' => [
                'locked' => false,
            ],
        ]);
    }

    /**
     * 🔹 NEW: Refresh the lock (keep alive)
     * POST /api/interviews/{interview}/refresh-lock
     */
    public function refreshLock(Request $request, Interview $interview): JsonResponse
    {
        Gate::authorize('update', $interview);

        $sessionId = $request->header('X-Session-Id') ?? $request->input('session_id');

        // Check if the current session owns the lock
        if (!$interview->isLockedBySession($sessionId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not own the lock for this interview',
            ], 403);
        }

        $interview->refreshLock();

        return response()->json([
            'success' => true,
            'message' => 'Lock refreshed successfully',
            'data' => [
                'locked' => true,
                'session_id' => $interview->active_session_id,
                'refreshed_at' => $interview->session_initialized_at?->toISOString(),
            ],
        ]);
    }

}
