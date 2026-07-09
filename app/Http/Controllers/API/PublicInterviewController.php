<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CompanyJob;
use App\Models\Interview;
use App\Models\Question;
use App\Services\LLMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublicInterviewController extends Controller
{
    protected LLMService $llmService;

    // مدة صلاحية الجلسة بالساعات (قابلة للتعديل)
    protected $sessionDurationHours = 2;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
     * Show job details to candidate (via invitation token)
     * GET /interview/join/{token}
     */
    public function showJob($token): JsonResponse
    {
        // البحث عن المرشح عبر التوكن
        $candidate = Candidate::where('invitation_token', $token)
            ->with('job')
            ->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation link',
            ], 404);
        }

        // ❌ 1. منع العودة إذا كان قد أكمل المقابلة (حتى لو الجلسة صالحة)
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

        // ✅ 2. إذا كان in_progress، تحقق من صلاحية الجلسة
        if ($candidate->status === 'in_progress') {
            if ($candidate->session_expires_at && now()->gt($candidate->session_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your interview session has expired. You cannot resume the interview.',
                ], 410);
            }

            // الجلسة لا تزال صالحة، يمكن العودة
            $interview = $candidate->interview;
            $currentQuestion = null;

            if ($interview) {
                $currentQuestion = $interview->questions()
                    ->whereNotIn('id', $interview->answers()->pluck('question_id'))
                    ->orderBy('order')
                    ->first();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'candidate' => [
                        'id' => $candidate->id,
                        'name' => $candidate->name,
                        'email' => $candidate->email,
                    ],
                    'job' => [
                        'id' => $candidate->job->id,
                        'title' => $candidate->job->title,
                        'description' => $candidate->job->description,
                        'required_skills' => $candidate->job->required_skills,
                        'number_of_questions' => $candidate->job->number_of_questions,
                        'difficulty' => $candidate->job->difficulty,
                    ],
                    'invitation_token' => $token,
                    'resume' => true,
                    'session_expires_at' => $candidate->session_expires_at,
                    'session_remaining_minutes' => max(0, now()->diffInMinutes($candidate->session_expires_at, false)),
                    'interview_id' => $interview?->id,
                    'current_question' => $currentQuestion ? [
                        'id' => $currentQuestion->id,
                        'order' => $currentQuestion->order,
                        'text' => $currentQuestion->question_text,
                        'type' => $currentQuestion->type,
                    ] : null,
                    'total_questions' => $interview?->questions()->count() ?? 0,
                    'answered_questions' => $interview?->answers()->count() ?? 0,
                ],
            ]);
        }

        // 3. status = 'pending' (لم يبدأ بعد)
        return response()->json([
            'success' => true,
            'data' => [
                'candidate' => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'email' => $candidate->email,
                ],
                'job' => [
                    'id' => $candidate->job->id,
                    'title' => $candidate->job->title,
                    'description' => $candidate->job->description,
                    'required_skills' => $candidate->job->required_skills,
                    'number_of_questions' => $candidate->job->number_of_questions,
                    'difficulty' => $candidate->job->difficulty,
                ],
                'invitation_token' => $token,
                'resume' => false,
            ],
        ]);
    }

    /**
     * Start interview (generate questions)
     * POST /interview/join/{token}/start
     */
    public function start($token): JsonResponse
    {
        $candidate = Candidate::where('invitation_token', $token)->first();

        if (!$candidate) {
            // 🔥 تسجيل محاولة بدء مقابلة بتوكن غير صالح
            app(\App\Services\ActivityLogService::class)->failed(
                'interviews',
                'start_interview',
                'محاولة بدء مقابلة بتوكن غير صالح',
                [
                    'token' => $token,
                    'ip' => request()->ip(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation link',
            ], 404);
        }

        // منع البدء إذا كان قد أكمل المقابلة
        if ($candidate->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'You have already completed this interview',
            ], 400);
        }

        // منع البدء إذا كان في progress ولديه جلسة صالحة (يجب استئنافها)
        if ($candidate->status === 'in_progress') {
            if ($candidate->session_expires_at && !now()->gt($candidate->session_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an in-progress interview. Please resume it.',
                ], 400);
            }
        }

        // إذا كان in_progress ولكن الجلسة منتهية، نسمح ببدء جديد
        if ($candidate->status === 'in_progress' && $candidate->session_expires_at && now()->gt($candidate->session_expires_at)) {
            // حذف المقابلة القديمة والإجابات
            if ($candidate->interview) {
                $candidate->interview->answers()->delete();
                $candidate->interview->delete();
            }
        }

        $job = $candidate->job;

        // إنشاء مقابلة جديدة
        $interview = Interview::create([
            'candidate_id' => $candidate->id,
            'user_id' => null,
            'position' => $job->title,
            'experience_level' => 'mid',
            'difficulty' => $job->difficulty,
            'skills' => $job->required_skills,
            'number_of_questions' => $job->number_of_questions,
            'status' => 'pending',
        ]);

        // ✅ توليد الأسئلة حسب مصدر السؤال (mixed, ai_only, company_only)
        $questionsData = $this->generateQuestionsBySource($job, $interview);

        // حفظ الأسئلة
        foreach ($questionsData as $index => $questionData) {
            Question::create([
                'interview_id' => $interview->id,
                'question_text' => $questionData['question_text'],
                'type' => $questionData['type'] ?? 'technical',
                'expected_skills' => $questionData['expected_skills'] ?? $job->required_skills,
                'evaluation_criteria' => $questionData['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                'order' => $index + 1,
                'source' => $questionData['source'] ?? 'system',
                'status' => 'pending',
            ]);
        }

        // ✅ تحديث حالة المرشح مع وقت انتهاء الجلسة
        $candidate->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'session_expires_at' => now()->addHours($this->sessionDurationHours),
        ]);

        $interview->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        // جلب السؤال الأول
        $firstQuestion = $interview->questions()->orderBy('order')->first();

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

        return response()->json([
            'success' => true,
            'message' => 'Interview started successfully',
            'data' => [
                'interview_id' => $interview->id,
                'candidate_id' => $candidate->id,
                'session_expires_at' => $candidate->session_expires_at,
                'session_remaining_minutes' => $this->sessionDurationHours * 60,
                'total_questions' => $interview->questions()->count(),
                'current_question' => [
                    'id' => $firstQuestion->id,
                    'order' => $firstQuestion->order,
                    'text' => $firstQuestion->question_text,
                    'type' => $firstQuestion->type,
                    'source' => $firstQuestion->source ?? 'system',
                ],
            ],
        ]);
    }

    /**
     * توليد الأسئلة حسب مصدر السؤال (mixed, ai_only, company_only)
     */
    private function generateQuestionsBySource($job, $interview): array
    {
        $sourceType = $job->questions_source ?? 'mixed';
        $allQuestions = [];

        switch ($sourceType) {
            case 'ai_only':
                // ✅ فقط أسئلة من الذكاء الاصطناعي
                $allQuestions = $this->llmService->generateQuestionsForJob($job, $interview);
                // إضافة مصدر system لكل سؤال
                foreach ($allQuestions as &$q) {
                    $q['source'] = 'system';
                }
                break;

            case 'company_only':
                // ✅ فقط أسئلة من بنك الشركة
                $allQuestions = $this->getCompanyQuestions($job);
                break;

            case 'mixed':
            default:
                // ✅ مزيج من الذكاء الاصطناعي + بنك الشركة
                $aiQuestions = $this->llmService->generateQuestionsForJob($job, $interview);
                $companyQuestions = $this->getCompanyQuestions($job);
                $allQuestions = $this->interleaveQuestions($aiQuestions, $companyQuestions, $job);
                break;
        }

        return $allQuestions;
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
        $totalQuestions = $job->number_of_questions ?? 5;

        // تحديد عدد الأسئلة من كل مصدر
        $aiCount = $job->ai_questions_count ?? 3;
        $companyCount = $job->company_questions_count ?? 2;

        // أخذ العدد المطلوب من كل مصدر
        $aiSelected = array_slice($aiQuestions, 0, $aiCount);
        $companySelected = array_slice($companyQuestions, 0, $companyCount);

        // إضافة مصدر لكل سؤال
        foreach ($aiSelected as &$q) {
            $q['source'] = 'system';
        }
        foreach ($companySelected as &$q) {
            $q['source'] = 'company';
        }

        // دمج بالتناوب
        $maxCount = max(count($aiSelected), count($companySelected));

        for ($i = 0; $i < $maxCount && count($result) < $totalQuestions; $i++) {
            if ($i < count($aiSelected) && count($result) < $totalQuestions) {
                $result[] = $aiSelected[$i];
            }
            if ($i < count($companySelected) && count($result) < $totalQuestions) {
                $result[] = $companySelected[$i];
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

    /**
     * Submit answer for a question
     * POST /interview/join/{token}/answer
     */
    public function submitAnswer(Request $request, $token): JsonResponse
    {
        $request->validate([
            'interview_id' => 'required|exists:interviews,id',
            'question_id' => 'required|exists:questions,id',
            'answer_transcript' => 'required|string',
            'duration_seconds' => 'nullable|integer',
        ]);

        $candidate = Candidate::where('invitation_token', $token)->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation link',
            ], 404);
        }

        // ✅ التحقق من صلاحية الجلسة
        if ($candidate->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'You cannot submit answers. The interview is not in progress.',
            ], 400);
        }

        if ($candidate->session_expires_at && now()->gt($candidate->session_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Your interview session has expired. You cannot submit more answers.',
            ], 410);
        }

        $interview = Interview::where('id', $request->interview_id)
            ->where('candidate_id', $candidate->id)
            ->first();

        if (!$interview) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
            ], 404);
        }

        $question = Question::where('id', $request->question_id)
            ->where('interview_id', $interview->id)
            ->first();

        if (!$question) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
            ], 404);
        }

        // ✅ التحقق من وجود إجابة مسبقة (لتجنب التكرار)
        $existingAnswer = $question->answer;

        if ($existingAnswer) {
            // تحديث الإجابة القديمة
            $existingAnswer->update([
                'transcription' => $request->answer_transcript,
                'duration_seconds' => $request->duration_seconds ?? 0,
                'status' => 'processing',
                'submitted_at' => now(),
            ]);
            $answer = $existingAnswer;
        } else {
            // إنشاء إجابة جديدة
            $answer = $question->answer()->create([
                'interview_id' => $interview->id,
                'transcription' => $request->answer_transcript,
                'duration_seconds' => $request->duration_seconds ?? 0,
                'status' => 'processing',
                'submitted_at' => now(),
            ]);
        }

        // تقييم الإجابة باستخدام LLM
        $evaluation = $this->llmService->evaluateAnswer($question, $request->answer_transcript);

        // ✅ حفظ التقييم مع تحويل المصفوفات إلى JSON
        $answer->update([
            'status' => 'evaluated',
            'processed_at' => now(),
        ]);

        // التحقق من وجود تقييم سابق
        $existingEvaluation = $answer->evaluation;

        $evaluationData = [
            'question_id' => $question->id,
            'interview_id' => $interview->id,
            'score' => $evaluation['score'] ?? 0,
            'strengths' => json_encode($evaluation['strengths'] ?? []),
            'weaknesses' => json_encode($evaluation['weaknesses'] ?? []),
            'detailed_feedback' => $evaluation['feedback'] ?? null,
            'criteria_scores' => json_encode([
                'clarity' => $evaluation['clarity_score'] ?? 5,
                'relevance' => $evaluation['relevance_score'] ?? 5,
                'depth' => $evaluation['depth_score'] ?? 5,
                'confidence' => $evaluation['confidence_score'] ?? 5,
            ]),
            'clarity_score' => $evaluation['clarity_score'] ?? 5,
            'relevance_score' => $evaluation['relevance_score'] ?? 5,
            'depth_score' => $evaluation['depth_score'] ?? 5,
            'confidence_score' => $evaluation['confidence_score'] ?? 5,
        ];

        if ($existingEvaluation) {
            $existingEvaluation->update($evaluationData);
        } else {
            $answer->evaluation()->create($evaluationData);
        }

        // التحقق من وجود أسئلة متبقية
        $answeredCount = $interview->answers()->count();
        $totalQuestions = $interview->questions()->count();
        $isLast = $answeredCount >= $totalQuestions;

        $nextQuestion = null;
        if (!$isLast) {
            $nextQuestion = $interview->questions()
                ->whereNotIn('id', $interview->answers()->pluck('question_id'))
                ->orderBy('order')
                ->first();
        }

        return response()->json([
            'success' => true,
            'message' => 'Answer submitted successfully',
            'data' => [
                'answer_id' => $answer->id,
                'score' => $evaluation['score'] ?? null,
                'feedback' => $evaluation['feedback'] ?? null,
                'is_last' => $isLast,
                'next_question' => $nextQuestion ? [
                    'id' => $nextQuestion->id,
                    'order' => $nextQuestion->order,
                    'text' => $nextQuestion->question_text,
                    'type' => $nextQuestion->type,
                    'source' => $nextQuestion->source ?? 'system',
                ] : null,
            ],
        ]);
    }

    /**
     * Complete interview and generate final report
     * POST /interview/join/{token}/complete
     */
    public function complete(Request $request, $token): JsonResponse
    {
        $request->validate([
            'interview_id' => 'required|exists:interviews,id',
        ]);

        $candidate = Candidate::where('invitation_token', $token)->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired invitation link',
            ], 404);
        }

        // ✅ التحقق من أن الجلسة لا تزال صالحة للإكمال
        if ($candidate->session_expires_at && now()->gt($candidate->session_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Your interview session has expired. You cannot complete the interview.',
            ], 410);
        }

        $interview = Interview::where('id', $request->interview_id)
            ->where('candidate_id', $candidate->id)
            ->first();

        if (!$interview) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
            ], 404);
        }

        // حساب متوسط الدرجات
        $averageScore = $interview->evaluations()->avg('score') ?? 0;

        // تحديث حالة المرشح
        $candidate->update([
            'status' => 'completed',
            'completed_at' => now(),
            'final_score' => $averageScore,
        ]);

        // تحديث حالة المقابلة
        $interview->update([
            'status' => 'completed_with_report',
            'completed_at' => now(),
        ]);

        // تجميع الإجابات والتقييمات للتقرير النهائي
        $answers = $interview->answers()->with('evaluation')->get();
        $evaluations = $interview->evaluations()->get();

        // توليد التقرير النهائي
        $finalReport = $this->llmService->generateFinalReport($interview, $answers, $evaluations);

        $interview->finalReport()->create($finalReport);

        return response()->json([
            'success' => true,
            'message' => 'Interview completed successfully',
            'data' => [
                'candidate_id' => $candidate->id,
                'interview_id' => $interview->id,
                'final_score' => round($averageScore * 10, 2),
                'report' => $finalReport,
            ],
        ]);
    }
}
