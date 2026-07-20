<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInterviewRequest;
use App\Http\Resources\InterviewResource;
use App\Http\Resources\FinalReportResource;
use App\Models\Interview;
use App\Models\Question;
use App\Services\LLMService;
use App\Services\FinalReportCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    protected LLMService $llmService;
    protected FinalReportCoordinator $finalReportCoordinator;

    // 🔹 NEW: Default session duration in minutes
    protected int $defaultSessionDuration = 60;

    public function __construct(
        LLMService $llmService,
        FinalReportCoordinator $finalReportCoordinator
    ) {
        $this->llmService = $llmService;
        $this->finalReportCoordinator = $finalReportCoordinator;
    }

    /**
     * Start a new interview
     */
    public function store(StoreInterviewRequest $request)
    {
        try {
            // Get session duration from request or use default
            $sessionDuration = $request->input('session_duration', $this->defaultSessionDuration);

            $locale = $this->normalizeLocale(
                $request->validated('locale') ?? $request->header('Accept-Language') ?? app()->getLocale()
            );

            // Keep Laravel translations aligned with the interview language for this request.
            app()->setLocale($locale);

            // Create interview with session management
            $interview = Interview::create([
                'user_id' => Auth::id(),
                'position' => $request->position,
                'experience_level' => $request->experience_level,
                'difficulty' => $request->difficulty,
                'skills' => $request->skills,
                'number_of_questions' => $request->number_of_questions ?? 5,
                'locale' => $locale,
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
                    'question_text' => \App\Models\Question::localized($questionData['question_text'] ?? null, $interview->locale),

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
            // $interview->lock($sessionId, $deviceFingerprint);

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
                'message' => $this->message($locale, 'تم بدء المقابلة بنجاح.', 'Interview started successfully.'),
                'data' => new InterviewResource($interview),
                "first_question" => [
                    'id' => $firstQuestion->id,
                    'interview_id' => $firstQuestion->interview_id,
                    'question_text' => $firstQuestion->translate('question_text'),
                    'type' => $firstQuestion->type,
                    'expected_skills' => $firstQuestion->expected_skills,
                    'evaluation_criteria' => $firstQuestion->evaluation_criteria,
                    'order' => $firstQuestion->order,
                    'status' => $firstQuestion->status,
                    "time_allocation_seconds" => $firstQuestion->time_allocation_seconds
                ],
                // 🔹 Session info
                'session' => [
                    'token' => $interview->session_token,
                    'expires_at' => $interview->expires_at?->toISOString(),
                    'expires_in_minutes' => $sessionDuration,
                    'current_question_id' => $interview->current_question_id,
                    // 🔹 NEW: Lock info
                    'locked' => false,
                    'session_id' => $interview->active_session_id,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to start interview: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->message($locale ?? app()->getLocale(), 'تعذر بدء المقابلة.', 'Failed to start interview.'),
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
        $this->useInterviewLocale($interview);

        // If interview is already completed, return simple status
        if (in_array($interview->status, [
            Interview::STATUS_COMPLETED,
            Interview::STATUS_COMPLETED_WITH_REPORT,
            Interview::STATUS_FAILED,
        ])) {
            return response()->json([
                'success' => true,
                'data' => $interview->getSessionStatus(),
                'message' => $this->message(app()->getLocale(), 'المقابلة مكتملة بالفعل.', 'Interview is already completed.'),
            ]);
        }

        // Check if session expired
        if ($interview->isSessionExpired()) {
            // Update status to expired
            $interview->update(['status' => Interview::STATUS_EXPIRED]);

            return response()->json([
                'success' => false,
                'message' => $this->message(app()->getLocale(), 'انتهت صلاحية جلسة المقابلة.', 'The interview session has expired.'),
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
                'message' => $this->message(app()->getLocale(), 'رمز جلسة المقابلة غير صالح.', 'The interview session token is invalid.'),
            ], 404);
        }

        $this->useInterviewLocale($interview);

        return $this->sessionStatus($interview);
    }

    /**
     * Get interview details with questions
     */
    public function show(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);
        $this->useInterviewLocale($interview);

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
        $this->useInterviewLocale($interview);

        try {
            $completion = DB::transaction(function () use ($interview): array {
                /** @var Interview $lockedInterview */
                $lockedInterview = Interview::query()
                    ->lockForUpdate()
                    ->findOrFail($interview->id);

                $totalQuestions = $lockedInterview->questions()->count();
                $answeredCount = $lockedInterview->answers()->count();

                if ($answeredCount < $totalQuestions) {
                    return [
                        'completed' => false,
                        'http_status' => 400,
                        'message' => $this->message(app()->getLocale(), "تمت الإجابة عن {$answeredCount} فقط من أصل {$totalQuestions} سؤالاً.", "Only {$answeredCount} of {$totalQuestions} questions were answered."),
                        'total_questions' => $totalQuestions,
                        'answered_count' => $answeredCount,
                    ];
                }

                $alreadyCompleted = in_array($lockedInterview->status, [
                    Interview::STATUS_COMPLETED,
                    Interview::STATUS_PROCESSING_FINAL,
                    Interview::STATUS_COMPLETED_WITH_REPORT,
                    Interview::STATUS_FAILED,
                ], true);

                if (
                    !$alreadyCompleted
                    && $lockedInterview->status !== Interview::STATUS_IN_PROGRESS
                ) {
                    return [
                        'completed' => false,
                        'http_status' => 400,
                        'message' => $this->message(app()->getLocale(), 'لا يمكن إنهاء المقابلة في حالتها الحالية.', 'The interview cannot be completed in its current state.'),
                        'status' => $lockedInterview->status,
                        'total_questions' => $totalQuestions,
                        'answered_count' => $answeredCount,
                    ];
                }

                if (!$alreadyCompleted) {
                    $lockedInterview->forceFill([
                        'status' => Interview::STATUS_COMPLETED,
                        'completed_at' => now(),
                        'last_activity_at' => now(),
                    ])->save();
                }

                $answeredQuestionIds = $lockedInterview->answers()
                    ->pluck('question_id');

                if ($answeredQuestionIds->isNotEmpty()) {
                    Question::query()
                        ->where('interview_id', $lockedInterview->id)
                        ->whereIn('id', $answeredQuestionIds)
                        ->whereNotIn('status', [
                            Question::STATUS_PROCESSING,
                            Question::STATUS_EVALUATED,
                        ])
                        ->update([
                            'status' => Question::STATUS_ANSWERED,
                            'answered_at' => now(),
                        ]);
                }

                return [
                    'completed' => true,
                    'already_completed' => $alreadyCompleted,
                    'http_status' => 200,
                    'message' => $alreadyCompleted
                        ? $this->message(app()->getLocale(), 'تم تسجيل إنهاء المقابلة مسبقاً.', 'Interview completion was already recorded.')
                        : $this->message(app()->getLocale(), 'تم إنهاء المقابلة بنجاح.', 'Interview completed successfully.'),
                    'interview_id' => $lockedInterview->id,
                    'status' => $lockedInterview->fresh()->status,
                    'total_questions' => $totalQuestions,
                    'answered_count' => $answeredCount,
                ];
            }, 3);

            if (!($completion['completed'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $completion['message'],
                    'data' => $completion,
                ], $completion['http_status'] ?? 400);
            }

            $reportGeneration = $this->finalReportCoordinator->dispatchIfReady(
                $interview->id,
                'interview_completed'
            );

            $freshInterview = Interview::query()->findOrFail($interview->id);
            $evaluatedCount = $freshInterview->answers()
                ->where('status', 'evaluated')
                ->count();

            Log::info('Interview completion recorded and final report checked', [
                'interview_id' => $freshInterview->id,
                'completion' => $completion,
                'report_generation' => $reportGeneration,
            ]);

            return response()->json([
                'success' => true,
                'message' => $this->message(app()->getLocale(), 'تم إنهاء المقابلة بنجاح ويجري الآن إعداد التقرير النهائي.', 'The interview was completed successfully and the final report is being prepared.'),
                'data' => [
                    'interview_id' => $freshInterview->id,
                    'status' => $freshInterview->status,
                    'total_questions' => $completion['total_questions'],
                    'answered_count' => $completion['answered_count'],
                    'evaluated_count' => $evaluatedCount,
                    'all_answers_evaluated' =>
                        $evaluatedCount >= $completion['total_questions'],
                    'report_ready' => $freshInterview->finalReport()->exists(),
                    'report_generation' => $reportGeneration,
                    'estimated_time_seconds' =>
                        $evaluatedCount >= $completion['total_questions'] ? 60 : 120,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to complete interview', [
                'interview_id' => $interview->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->message(app()->getLocale(), 'تعذر إنهاء المقابلة: ', 'Failed to complete the interview: ') . $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Check final report generation status
     */
    public function checkFinalStatus(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);
        $this->useInterviewLocale($interview);

        $reportGeneration = $this->finalReportCoordinator->dispatchIfReady(
            $interview->id,
            'status_poll'
        );

        $freshInterview = Interview::query()->findOrFail($interview->id);
        $hasReport = $freshInterview->finalReport()->exists();
        $totalQuestions = $freshInterview->questions()->count();
        $evaluatedAnswers = $freshInterview->answers()
            ->where('status', 'evaluated')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'interview_id' => $freshInterview->id,
                'status' => $freshInterview->status,
                'report_ready' => $hasReport,
                'all_answers_processed' =>
                    $totalQuestions > 0 && $evaluatedAnswers >= $totalQuestions,
                'answers_processed' => $evaluatedAnswers,
                'total_answers' => $freshInterview->answers()->count(),
                'total_questions' => $totalQuestions,
                'report_generation' => $reportGeneration,
            ],
        ]);
    }

    /**
     * Get final report
     */
    public function getFinalReport(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);
        $this->useInterviewLocale($interview);

        $report = $interview->finalReport;

        if (!$report) {
            $reportGeneration = $this->finalReportCoordinator->dispatchIfReady(
                $interview->id,
                'report_fetch'
            );

            return response()->json([
                'success' => false,
                'message' => $this->message(app()->getLocale(), 'التقرير النهائي غير جاهز بعد.', 'The final report is not available yet.'),
                'generation' => $reportGeneration,
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
        $this->useInterviewLocale($interview);

        $reportGeneration = $this->finalReportCoordinator->dispatchIfReady(
            $interview->id,
            'report_ready_poll'
        );

        $freshInterview = Interview::query()
            ->with('finalReport')
            ->findOrFail($interview->id);

        $report = $freshInterview->finalReport;
        $totalQuestions = $freshInterview->questions()->count();
        $answersStatus = $freshInterview->answers()
            ->pluck('status')
            ->values()
            ->all();
        $evaluatedAnswers = $freshInterview->answers()
            ->where('status', 'evaluated')
            ->count();
        $failedAnswers = $freshInterview->answers()
            ->where('status', 'failed')
            ->count();

        return response()->json([
            'success' => true,
            'ready' => $report !== null,
            'data' => $report ? new FinalReportResource($report) : null,
            'status' => $freshInterview->status,
            'generation' => $reportGeneration,
            'progress' => [
                'report_exists' => $report !== null,
                'evaluated_answers' => $evaluatedAnswers,
                'failed_answers' => $failedAnswers,
                'evaluations_count' => $freshInterview->evaluations()->count(),
                'audio_analyses_count' => $freshInterview->answers()
                    ->whereHas('audioAnalysis')
                    ->count(),
                'total_questions' => $totalQuestions,
                'answers_status' => $answersStatus,
            ],
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
        $this->useInterviewLocale($interview);

        // Check if interview is already completed
        if (in_array($interview->status, [
            Interview::STATUS_COMPLETED,
            Interview::STATUS_COMPLETED_WITH_REPORT,
            Interview::STATUS_FAILED,
        ])) {
            return response()->json([
                'success' => true,
                'message' => $this->message(app()->getLocale(), 'المقابلة مكتملة بالفعل.', 'Interview is already completed.'),
                'data' => $interview->getResumeData(),
            ]);
        }

        // Check if session is expired
        if ($interview->isSessionExpired()) {
            // Update status to expired
            $interview->update(['status' => Interview::STATUS_EXPIRED]);

            return response()->json([
                'success' => false,
                'message' => $this->message(app()->getLocale(), 'انتهت صلاحية جلسة المقابلة. يرجى بدء مقابلة جديدة.', 'The interview session has expired. Please start a new interview.'),
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
            'message' => $this->message(app()->getLocale(), 'تم استئناف المقابلة بنجاح.', 'The interview was resumed successfully.'),
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
        $this->useInterviewLocale($interview);

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
        $this->useInterviewLocale($interview);

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
        $this->useInterviewLocale($interview);

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
            'message' => $this->message(app()->getLocale(), 'تم حجز جلسة المقابلة لهذا التبويب بنجاح.', 'The interview session was locked successfully.'),
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
        $this->useInterviewLocale($interview);

        $sessionId = $request->header('X-Session-Id') ?? $request->input('session_id');

        // Check if the current session owns the lock
        if (!$interview->isLockedBySession($sessionId)) {
            return response()->json([
                'success' => false,
                'message' => $this->message(app()->getLocale(), 'هذه الجلسة لا تملك قفل المقابلة.', 'This session does not own the interview lock.'),
            ], 403);
        }

        $interview->unlock();

        return response()->json([
            'success' => true,
            'message' => $this->message(app()->getLocale(), 'تم تحرير قفل المقابلة بنجاح.', 'The interview lock was released successfully.'),
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
        $this->useInterviewLocale($interview);

        $sessionId = $request->header('X-Session-Id') ?? $request->input('session_id');

        // Check if the current session owns the lock
        if (!$interview->isLockedBySession($sessionId)) {
            return response()->json([
                'success' => false,
                'message' => $this->message(app()->getLocale(), 'هذه الجلسة لا تملك قفل المقابلة.', 'This session does not own the interview lock.'),
            ], 403);
        }

        $interview->refreshLock();

        return response()->json([
            'success' => true,
            'message' => $this->message(app()->getLocale(), 'تم تحديث قفل المقابلة بنجاح.', 'The interview lock was refreshed successfully.'),
            'data' => [
                'locked' => true,
                'session_id' => $interview->active_session_id,
                'refreshed_at' => $interview->session_initialized_at?->toISOString(),
            ],
        ]);
    }



    /**
     * Pin Laravel's locale to the language stored on the interview.
     */
    private function useInterviewLocale(Interview $interview): string
    {
        $locale = $this->normalizeLocale($interview->locale);
        app()->setLocale($locale);

        return $locale;
    }

    /**
     * Normalize the locale values supported by the interview flow.
     */
    private function normalizeLocale(?string $locale): string
    {
        $locale = strtolower((string) ($locale ?: app()->getLocale()));
        return str_starts_with($locale, 'ar') ? 'ar' : 'en';
    }

    /**
     * Return an API message in the interview language.
     */
    private function message(string $locale, string $arabic, string $english): string
    {
        return $this->normalizeLocale($locale) === 'ar' ? $arabic : $english;
    }
}