<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CompanyJob;
use App\Models\Interview;
use App\Models\Question;
use App\Models\Answer;
use App\Jobs\ProcessSingleAnswerJob;
use App\Jobs\GenerateFinalReportJob;
use App\Services\LLMService;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublicInterviewController extends Controller
{
    protected LLMService $llmService;
    protected $sessionDurationHours = 2;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
     * Show job details to candidate
     * GET /interview/join/{token}
     */
    public function showJob($token): JsonResponse
    {
        $candidate = Candidate::where('invitation_token', $token)
            ->with('job.questionBank')
            ->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation link',
            ], 404);
        }

        // منع العودة بعد الإكمال
        if ($candidate->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'You have already completed this interview',
            ], 400);
        }

        // التحقق من الصلاحية العامة
        if ($candidate->expires_at && now()->gt($candidate->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation link has expired',
            ], 410);
        }

        // ✅ إذا كان in_progress، تحقق من صلاحية الجلسة واستئنافها
        if ($candidate->status === 'in_progress') {
            if ($candidate->session_expires_at && now()->gt($candidate->session_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your interview session has expired',
                ], 410);
            }

            // ✅ استئناف المقابلة الموجودة (مش نبدأ من جديد)
            $interview = $candidate->interview;

            if (!$interview) {
                return response()->json([
                    'success' => false,
                    'message' => 'Interview not found',
                ], 404);
            }

            // جلب السؤال الحالي
            $currentQuestion = $interview->questions()
                ->whereNotIn('id', $interview->answers()->pluck('question_id'))
                ->orderBy('order')
                ->first();

            // جلب حالة كل الأسئلة
            $allQuestions = $interview->questions()
                ->orderBy('order')
                ->get()
                ->map(function ($q) use ($interview) {
                    $answer = $interview->answers()->where('question_id', $q->id)->first();
                    return [
                        'id' => $q->id,
                        'order' => $q->order,
                        'text' => $q->question_text,
                        'type' => $q->type,
                        'source' => $q->source ?? 'system',
                        'status' => $answer ? 'answered' : 'pending',
                        'evaluated' => $answer ? $answer->status === 'evaluated' : false,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'candidate' => ['id' => $candidate->id, 'name' => $candidate->name],
                    'job' => [
                        'id' => $candidate->job->id,
                        'title' => $candidate->job->title,
                        'description' => $candidate->job->description,
                    ],
                    'interview_id' => $interview->id,
                    'status' => 'resume', // ✅ بنقول للفرونت "استأنف"
                    'current_question' => $currentQuestion ? [
                        'id' => $currentQuestion->id,
                        'order' => $currentQuestion->order,
                        'text' => $currentQuestion->question_text,
                        'type' => $currentQuestion->type,
                    ] : null,
                    'all_questions' => $allQuestions,
                    'total_questions' => $allQuestions->count(),
                    'answered_questions' => $allQuestions->where('status', 'answered')->count(),
                    'session_expires_at' => $candidate->session_expires_at,
                    'session_remaining_minutes' => max(0, now()->diffInMinutes($candidate->session_expires_at, false)),
                ],
            ]);
        }

        // status = 'pending' (لم يبدأ بعد)
        return response()->json([
            'success' => true,
            'data' => [
                'candidate' => ['id' => $candidate->id, 'name' => $candidate->name],
                'job' => [
                    'id' => $candidate->job->id,
                    'title' => $candidate->job->title,
                    'description' => $candidate->job->description,
                    'number_of_questions' => $candidate->job->number_of_questions,
                    'difficulty' => $candidate->job->difficulty,
                ],
                'status' => 'new', // ✅ بنقول للفرونت "مقابلة جديدة"
            ],
        ]);
    }

    /**
     * Start interview
     * POST /interview/join/{token}/start
     */
    public function start($token): JsonResponse
    {
        $candidate = Candidate::where('invitation_token', $token)
            ->with('job.questionBank')
            ->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation link',
            ], 404);
        }

        if ($candidate->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'You have already completed this interview',
            ], 400);
        }

        // ✅ لو في مقابلة موجودة وصالحة، نرجعها بدل ما نبدأ من جديد
        if ($candidate->status === 'in_progress' &&
            $candidate->session_expires_at &&
            now()->lt($candidate->session_expires_at)) {

            return response()->json([
                'success' => true,
                'message' => 'Resuming existing interview',
                'data' => [
                    'interview_id' => $candidate->interview->id,
                    'resume' => true,
                ],
            ]);
        }

        $job = $candidate->job;

        // إنشاء مقابلة جديدة
        $locale = $job->normalizedInterviewLocale();

        $interview = Interview::create([
            'candidate_id' => $candidate->id,
            'company_job_id' => $job->id,
            'interview_type' => 'company_candidate',
            'position' => $job->titleForLocale($locale),
            'experience_level' => 'mid',
            'difficulty' => $job->difficulty,
            'locale' => $locale,
            'skills' => $job->required_skills,
            'number_of_questions' => $job->getTotalQuestionsPerCandidate(),
            'status' => Interview::STATUS_PENDING,
            'session_token' => Str::random(64),
        ]);

        // ✅ توليد الأسئلة
        $questionsData = $this->generateQuestionsBySource($job, $interview);

        foreach ($questionsData as $index => $q) {
            Question::create([
                'interview_id' => $interview->id,
                'question_text' => Question::localized(
                    $q['question_text'] ?? '',
                    $interview->locale
                ),
                'type' => $q['type'] ?? 'general',
                'expected_skills' => $q['expected_skills'] ?? $job->required_skills,
                'evaluation_criteria' => $q['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                'time_allocation_seconds' => max(45, min(600, (int) ($q['time_allocation_seconds'] ?? 120))),
                'order' => $index + 1,
                'source' => $q['source'] ?? 'system',
                'status' => Question::STATUS_PENDING,
            ]);
        }

        // ✅ تحديث حالة المرشح
        $candidate->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'session_expires_at' => now()->addHours($this->sessionDurationHours),
        ]);

        $interview->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        // 🔥 تسجيل بدء المقابلة
        app(\App\Services\ActivityLogService::class)->success(
            'interviews',
            'start_interview',
            "بدأ المرشح '{$candidate->name}' مقابلة لوظيفة '{$job->title}'",
            [
                'candidate_id' => $candidate->id,
                'candidate_name' => $candidate->name,
                'candidate_email' => $candidate->email,
                'job_id' => $job->id,
                'job_title' => $job->title,
                'company_id' => $job->company_id,
                'company_name' => $job->company->company_name ?? null,
                'interview_id' => $interview->id,
                'session_expires_at' => $candidate->session_expires_at,
                'ip' => request()->ip(),
            ]
        );



        $firstQuestion = $interview->questions()->orderBy('order')->first();

        return response()->json([
            'success' => true,
            'message' => 'Interview started',
            'data' => [
                'interview_id' => $interview->id,
                'session_token' => $interview->session_token,
                'session_expires_at' => $candidate->session_expires_at,
                'first_question' => [
                    'id' => $firstQuestion->id,
                    'order' => $firstQuestion->order,
                    'text' => $firstQuestion->question_text,
                    'type' => $firstQuestion->type,
                ],
                'total_questions' => $interview->questions()->count(),
            ],
        ]);
    }

    /**
     * Submit answer with audio file
     * POST /interview/join/{token}/answer
     */
    public function submitAnswer(Request $request, $token): JsonResponse
    {
        $request->validate([
            'interview_id' => 'required|exists:interviews,id',
            'question_id' => 'required|exists:questions,id',
            'audio_file' => 'required|file|mimes:webm,mp3,wav,m4a|max:25600',
            'duration_seconds' => 'required|integer|min:1|max:600',
            'idempotency_key' => 'nullable|string|max:64', // ✅ منع التكرار
        ]);

        $candidate = Candidate::where('invitation_token', $token)->first();
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Invalid link'], 404);
        }

        // ✅ التحقق من صلاحية الجلسة
        if ($candidate->session_expires_at && now()->gt($candidate->session_expires_at)) {
            return response()->json(['success' => false, 'message' => 'Session expired'], 410);
        }

        $interview = Interview::where('id', $request->interview_id)
            ->where('candidate_id', $candidate->id)
            ->first();

        if (!$interview) {
            return response()->json(['success' => false, 'message' => 'Interview not found'], 404);
        }

        // ✅ منع التكرار باستخدام idempotency_key
        if ($request->idempotency_key) {
            $existing = Answer::where('idempotency_key', $request->idempotency_key)->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Answer already submitted',
                    'data' => ['answer_id' => $existing->id, 'status' => $existing->status],
                ]);
            }
        }

        $question = Question::where('id', $request->question_id)
            ->where('interview_id', $interview->id)
            ->first();

        if (!$question) {
            return response()->json(['success' => false, 'message' => 'Question not found'], 404);
        }

        // ✅ تخزين الملف الصوتي
        $audioPath = $request->file('audio_file')->store(
            'answers/' . $interview->id, 'public'
        );

        // ✅ إنشاء أو تحديث الإجابة
        $answer = Answer::updateOrCreate(
            ['question_id' => $question->id, 'interview_id' => $interview->id],
            [
                'audio_file_path' => $audioPath,
                'duration_seconds' => $request->duration_seconds,
                'status' => 'pending',
                'submitted_at' => now(),
                'idempotency_key' => $request->idempotency_key ?? Str::uuid(),
            ]
        );

        // ✅ تحديث السؤال
        $question->update(['status' => 'answered', 'answered_at' => now()]);

        // ✅ إرسال للـ Queue (مش مباشر!)
        ProcessSingleAnswerJob::dispatch($answer, $audioPath)
            ->onQueue('answers')
            ->afterCommit();

        // ✅ رد فوري
        $answeredCount = $interview->answers()->count();
        $totalQuestions = $interview->questions()->count();
        $isLast = $answeredCount >= $totalQuestions;

        // ✅ السؤال التالي
        $nextQuestion = null;
        if (!$isLast) {
            $nextQuestion = $interview->questions()
                ->whereNotIn('id', $interview->answers()->pluck('question_id'))
                ->orderBy('order')
                ->first();
        }

        return response()->json([
            'success' => true,
            'message' => 'Answer submitted and queued for processing',
            'data' => [
                'answer_id' => $answer->id,
                'status' => 'processing',
                'is_last' => $isLast,
                'progress' => [
                    'answered' => $answeredCount,
                    'total' => $totalQuestions,
                ],
                'next_question' => $nextQuestion ? [
                    'id' => $nextQuestion->id,
                    'order' => $nextQuestion->order,
                    'text' => $nextQuestion->question_text,
                    'type' => $nextQuestion->type,
                ] : null,
            ],
        ], 201);
    }

    /**
     * Complete interview
     * POST /interview/join/{token}/complete
     */
    public function complete(Request $request, $token): JsonResponse
    {
        $request->validate([
            'interview_id' => 'required|exists:interviews,id',
        ]);

        $candidate = Candidate::where('invitation_token', $token)->first();
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Invalid link'], 404);
        }

        if ($candidate->session_expires_at && now()->gt($candidate->session_expires_at)) {
            return response()->json(['success' => false, 'message' => 'Session expired'], 410);
        }

        $interview = Interview::where('id', $request->interview_id)
            ->where('candidate_id', $candidate->id)
            ->first();

        if (!$interview) {
            return response()->json(['success' => false, 'message' => 'Interview not found'], 404);
        }

        // ✅ التحقق: كل الأسئلة اترد عليها؟
        $answeredCount = $interview->answers()->count();
        $totalQuestions = $interview->questions()->count();

        if ($answeredCount < $totalQuestions) {
            return response()->json([
                'success' => false,
                'message' => "Only {$answeredCount}/{$totalQuestions} answered",
            ], 400);
        }

        // ✅ تحديث حالة المقابلة
        $interview->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // ✅ تحديث حالة المرشح
        $candidate->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // ✅ التحقق: هل كل الإجابات تم تقييمها؟
        if ($interview->hasAllAnswersProcessed()) {
            $interview->update(['status' => 'processing_final']);

            // ✅ إرسال للـ Queue (مش مباشر!)
            GenerateFinalReportJob::dispatch($interview)
                ->onQueue('reports')
                ->delay(now()->addSeconds(3));
        }

        return response()->json([
            'success' => true,
            'message' => 'Interview completed. Report is being generated.',
            'data' => [
                'interview_id' => $interview->id,
                'status' => 'generating_report',
                'estimated_time_seconds' => 30,
            ],
        ]);
    }

    /**
     * Check report status (for polling)
     * GET /interview/join/{token}/status
     */
    public function checkStatus($token): JsonResponse
    {
        $candidate = Candidate::where('invitation_token', $token)->first();
        if (!$candidate) {
            return response()->json(['success' => false], 404);
        }

        $interview = $candidate->interview;
        if (!$interview) {
            return response()->json(['success' => false], 404);
        }

        $hasReport = $interview->finalReport()->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'interview_status' => $interview->status,
                'report_ready' => $hasReport,
                'answers_processed' => $interview->answers()->where('status', 'evaluated')->count(),
                'total_answers' => $interview->answers()->count(),
            ],
        ]);
    }

    /**
     * Get final report
     * GET /interview/join/{token}/report
     */
    public function getReport($token): JsonResponse
    {
        $candidate = Candidate::where('invitation_token', $token)->first();
        if (!$candidate) {
            return response()->json(['success' => false], 404);
        }

        $interview = $candidate->interview;
        if (!$interview) {
            return response()->json(['success' => false], 404);
        }

        $report = $interview->finalReport;
        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not yet available',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    // ============ دوال الأسئلة (نفس اللي عندك) ============

    private function generateQuestionsBySource($job, $interview): array
    {
        $sourceType = $job->questions_source ?? 'mixed';
        $requiredCount = max(1, (int) $interview->number_of_questions);

        if ($sourceType === 'ai_only') {
            return $this->llmService->generateQuestionsForJob(
                $job,
                $interview,
                $requiredCount,
                ['generation_scope' => 'legacy_public_company_interview']
            );
        }

        if ($sourceType === 'company_only') {
            return array_slice($this->getCompanyQuestions($job), 0, $requiredCount);
        }

        $companyQuestions = $this->getCompanyQuestions($job);
        $companyCount = min(
            max(0, (int) $job->company_questions_count),
            $requiredCount
        );
        $companyQuestions = array_slice($companyQuestions, 0, $companyCount);

        $aiCount = max(0, $requiredCount - count($companyQuestions));
        $excludedQuestions = array_values(array_filter(array_map(
            fn (array $question): string => trim((string) ($question['question_text'] ?? '')),
            $companyQuestions
        )));

        $aiQuestions = $aiCount > 0
            ? $this->llmService->generateQuestionsForJob(
                $job,
                $interview,
                $aiCount,
                [
                    'generation_scope' => 'legacy_public_company_interview',
                    'excluded_questions' => $excludedQuestions,
                ]
            )
            : [];

        return $this->interleaveQuestions($aiQuestions, $companyQuestions, $job);
    }

    /**
     * جلب الأسئلة من بنك الشركة
     */
    private function getCompanyQuestions($job): array
    {
        $questionBank = $job->questionBank;

        if (!$questionBank || empty($questionBank->questions)) {
            // إذا لم يكن هناك بنك أسئلة، استخدم أسئلة افتراضية
            return $this->getFallbackCompanyQuestions($job);
        }

        $questions = [];
        $bankQuestions = $questionBank->questions;

        // اختيار عدد الأسئلة المطلوبة (company_questions_count)
        $count = $job->company_questions_count ?? 2;
        $selected = array_slice($bankQuestions, 0, $count);

        foreach ($selected as $q) {
            $questions[] = [
                'question_text' => $q['question'] ?? $q,
                'type' => $q['type'] ?? 'behavioral',
                'source' => 'company',
                'expected_skills' => $job->required_skills,
                'evaluation_criteria' => ['clarity', 'relevance', 'depth'],
            ];
        }

        return $questions;
    }

    /**
     * دمج الأسئلة (تناوب بين AI و Company)
     */
    private function interleaveQuestions(array $aiQuestions, array $companyQuestions, $job): array
    {
        $result = [];
        $totalQuestions = max(1, $job->getTotalQuestionsPerCandidate());
        $maximum = max(count($aiQuestions), count($companyQuestions));

        for ($index = 0; $index < $maximum && count($result) < $totalQuestions; $index++) {
            if (isset($aiQuestions[$index]) && count($result) < $totalQuestions) {
                $aiQuestions[$index]['source'] = 'system';
                $result[] = $aiQuestions[$index];
            }

            if (isset($companyQuestions[$index]) && count($result) < $totalQuestions) {
                $companyQuestions[$index]['source'] = 'company';
                $result[] = $companyQuestions[$index];
            }
        }

        return $result;
    }

    /**
     * أسئلة افتراضية للشركة إذا لم يكن هناك بنك أسئلة
     */
    private function getFallbackCompanyQuestions($job): array
    {
        $skills = implode(', ', $job->required_skills ?? ['التقنية']);

        return [
            [
                'question_text' => "لماذا تريد العمل في شركتنا؟",
                'type' => 'behavioral',
                'source' => 'company',
                'expected_skills' => $job->required_skills ?? [],
                'evaluation_criteria' => ['clarity', 'relevance', 'motivation'],
            ],
            [
                'question_text' => "صف تجربتك مع {$skills}",
                'type' => 'technical',
                'source' => 'company',
                'expected_skills' => $job->required_skills ?? [],
                'evaluation_criteria' => ['clarity', 'depth', 'technical_accuracy'],
            ],
            [
                'question_text' => "أين ترى نفسك بعد 3 سنوات؟",
                'type' => 'behavioral',
                'source' => 'company',
                'expected_skills' => [],
                'evaluation_criteria' => ['clarity', 'relevance', 'ambition'],
            ],
        ];
    }

}
