<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyJob;
use App\Models\CompanyJobCandidate;
use App\Models\User;
use App\Models\Interview;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CompanyJobService
{
    protected LLMService $llmService;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
     * Create a new job posting
     * إنشاء وظيفة جديدة
     */
    public function createJob(Company $company, array $data): CompanyJob
    {
        try {
            Log::info('CompanyJobService: Creating job for company ID: ' . $company->id);

            $job = CompanyJob::create([
                'company_id' => $company->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'required_skills' => $data['required_skills'],
                'custom_questions' => $data['custom_questions'] ?? null,
                'questions_source' => $data['questions_source'] ?? 'mixed',
                'number_of_questions' => $data['number_of_questions'],
                'difficulty' => $data['difficulty'],
                'max_candidates' => $data['max_candidates'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'hide_score_from_candidate' => $data['hide_score_from_candidate'] ?? true,
                'status' => 'active',
            ]);

            Log::info('CompanyJobService: Job created with ID: ' . $job->id);

            return $job;
        } catch (\Exception $e) {
            Log::error('CompanyJobService: Error creating job: ' . $e->getMessage());
            Log::error('CompanyJobService: Data received: ' . json_encode($data));
            throw $e;
        }
    }

    /**
     * Get or create candidate user
     * البحث عن مرشح أو إنشائه
     */
    public function getOrCreateCandidate(string $email, string $name, ?string $source = null): User
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(\Illuminate\Support\Str::random(16)),
                'role' => 'candidate',
            ]);
        }

        return $user;
    }

    /**
     * Initialize interview for candidate
     * بدء مقابلة جديدة
     */
    public function initializeInterview(CompanyJob $job, User $candidate, ?string $source = null): array
    {
        return DB::transaction(function () use ($job, $candidate, $source) {
            // Check if candidate already applied
            $existing = CompanyJobCandidate::where('company_job_id', $job->id)
                ->where('candidate_id', $candidate->id)
                ->first();

            if ($existing) {
                if ($existing->status === CompanyJobCandidate::STATUS_COMPLETED) {
                    throw new \Exception('You have already completed this interview');
                }
                if ($existing->status === CompanyJobCandidate::STATUS_IN_PROGRESS) {
                    throw new \Exception('You already have an in-progress interview for this job');
                }
            }

            // Create job candidate record
            $jobCandidate = CompanyJobCandidate::updateOrCreate(
                ['company_job_id' => $job->id, 'candidate_id' => $candidate->id],
                [
                    'source' => $source,
                    'status' => CompanyJobCandidate::STATUS_PENDING,
                    'invited_at' => now(),
                ]
            );

            // Generate questions using QuestionBankService
            $questionBankService = new QuestionBankService();
            $allQuestions = $questionBankService->generateInterviewQuestions($job, $candidate->id, $this->llmService);

            // Create interview
            $interview = Interview::create([
                'user_id' => $candidate->id,
                'position' => $job->translate('title'),
                'experience_level' => 'mid',
                'locale' => app()->getLocale(),
                'difficulty' => $job->difficulty,
                'skills' => $job->required_skills,
                'number_of_questions' => count($allQuestions),
                'status' => Interview::STATUS_PENDING,
            ]);

            // Save questions
            $questionsWithIndices = [];
            foreach ($allQuestions as $index => $questionData) {
                $question = Question::create([
                    'interview_id' => $interview->id,
                    'job_id' => $questionData['source'] === 'company' ? $job->id : null,
                    'question_text' => \App\Models\Question::localized($questionData['question_text'] ?? null, $interview->locale),
                    'type' => $questionData['type'] ?? 'technical',
                    'expected_skills' => $questionData['expected_skills'] ?? [],
                    'evaluation_criteria' => $questionData['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                    'source' => $questionData['source'],
                    'order' => $index + 1,
                    'status' => Question::STATUS_PENDING,
                ]);

                $questionsWithIndices[] = [
                    'question_id' => $question->id,
                    'question_text' => $questionData['question_text'],
                    'type' => $questionData['type'],
                    'source' => $questionData['source'],
                    'question_bank_index' => $questionData['question_bank_index'] ?? null,
                ];
            }

            // Update job candidate with interview
            $jobCandidate->update([
                'interview_id' => $interview->id,
                'status' => CompanyJobCandidate::STATUS_PENDING,
            ]);

            // Store questions metadata for later recording
            $interview->update([
                'metadata' => array_merge($interview->metadata ?? [], [
                    'company_questions' => $questionsWithIndices,
                ]),
            ]);

            return [
                'interview' => $interview,
                'jobCandidate' => $jobCandidate,
            ];
        });
    }

    /**
     * Record questions after interview completion
     */
    public function recordInterviewQuestions(Interview $interview, CompanyJobCandidate $jobCandidate, array $answersWithScores): void
    {
        $job = $jobCandidate->job;
        $questionBankService = new QuestionBankService();

        $questionsData = [];
        foreach ($answersWithScores as $answer) {
            $questionsData[] = [
                'question_text' => $answer['question_text'],
                'type' => $answer['type'],
                'score' => $answer['score'],
                'time_to_answer' => $answer['time_to_answer'] ?? null,
            ];
        }

        $questionBankService->recordAskedQuestions($job, $jobCandidate->candidate_id, $questionsData);
    }

    /**
     * Generate questions based on source type
     * توليد الأسئلة بناءً على نوع المصدر (جديد)
     */
    private function generateQuestionsBySource(CompanyJob $job): array
    {
        $sourceType = $job->questions_source ?? 'mixed';

        switch ($sourceType) {
            case 'ai_only':
                return $this->generateSystemQuestionsOnly($job);

            case 'company_only':
                return $this->generateCompanyQuestionsOnly($job);

            case 'mixed':
            default:
                return $this->generateMixedQuestions($job);
        }
    }

    /**
     * Generate only AI questions (Option 1)
     * فقط أسئلة من الذكاء الاصطناعي
     */
    private function generateSystemQuestionsOnly(CompanyJob $job): array
    {
        $systemQuestions = $this->generateSystemQuestions($job);

        foreach ($systemQuestions as &$q) {
            $q['source'] = 'system';
        }

        return $systemQuestions;
    }

    /**
     * Generate only company questions (Option 3)
     * فقط أسئلة من الشركة
     */
    private function generateCompanyQuestionsOnly(CompanyJob $job): array
    {
        $questions = [];

        if (!empty($job->custom_questions)) {
            foreach ($job->custom_questions as $cq) {
                $questions[] = [
                    'question_text' => $cq['question'] ?? $cq,
                    'type' => $cq['type'] ?? 'behavioral',
                    'expected_skills' => $job->required_skills,
                    'evaluation_criteria' => ['clarity', 'relevance', 'depth'],
                    'source' => 'company',
                ];
            }
        }

        // If no custom questions, add default fallback questions
        if (empty($questions)) {
            $questions = $this->getFallbackCompanyQuestions($job);
        }

        return $questions;
    }

    /**
     * Generate mixed questions (AI + Company) - Option 2
     * أسئلة مختلطة (ذكاء اصطناعي + شركة)
     */
    private function generateMixedQuestions(CompanyJob $job): array
    {
        $systemQuestions = $this->generateSystemQuestions($job);
        $companyQuestions = [];

        if (!empty($job->custom_questions)) {
            foreach ($job->custom_questions as $cq) {
                $companyQuestions[] = [
                    'question_text' => $cq['question'] ?? $cq,
                    'type' => $cq['type'] ?? 'behavioral',
                    'expected_skills' => $job->required_skills,
                    'evaluation_criteria' => ['clarity', 'relevance', 'depth'],
                    'source' => 'company',
                ];
            }
        }

        return $this->interleaveQuestions($systemQuestions, $companyQuestions);
    }

    /**
     * Interleave two arrays of questions (alternate between AI and company)
     * دمج الأسئلة بالتناوب
     */
    private function interleaveQuestions(array $systemQuestions, array $companyQuestions): array
    {
        $result = [];
        $maxCount = max(count($systemQuestions), count($companyQuestions));

        for ($i = 0; $i < $maxCount; $i++) {
            if ($i < count($systemQuestions)) {
                $systemQuestions[$i]['source'] = 'system';
                $result[] = $systemQuestions[$i];
            }
            if ($i < count($companyQuestions)) {
                $companyQuestions[$i]['source'] = 'company';
                $result[] = $companyQuestions[$i];
            }
        }

        return $result;
    }

    /**
     * Generate system questions using LLM
     * توليد أسئلة بالذكاء الاصطناعي
     */
    private function generateSystemQuestions(CompanyJob $job): array
    {
        $skillsList = implode(', ', $job->required_skills);

        $prompt = <<<EOT
Generate {$job->number_of_questions} interview questions for a {$job->title} position.

Required skills: {$skillsList}
Difficulty level: {$job->difficulty}

Format the response as a JSON object with a 'questions' array. Each question should have:
- question_text: The actual question
- type: One of ['technical', 'behavioral', 'situational']
- expected_skills: Array of skills this question evaluates
- evaluation_criteria: Array of key points to evaluate

Return ONLY valid JSON.
EOT;

        try {
            $response = $this->llmService->generateQuestionsFromPrompt($prompt, $job->number_of_questions);
            $questions = [];

            foreach (($response['questions'] ?? []) as $q) {
                $questions[] = [
                    'question_text' => $q['question_text'] ?? '',
                    'type' => $q['type'] ?? 'technical',
                    'expected_skills' => $q['expected_skills'] ?? $job->required_skills,
                    'evaluation_criteria' => $q['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                ];
            }

            return $questions;
        } catch (\Exception $e) {
            return $this->getFallbackSystemQuestions($job);
        }
    }

    /**
     * Fallback system questions if AI fails
     * أسئلة احتياطية للنظام في حال فشل الذكاء الاصطناعي
     */
    private function getFallbackSystemQuestions(CompanyJob $job): array
    {
        $skills = $job->required_skills;
        $skill = $skills[0] ?? 'software development';

        $templates = [
            "Tell me about your experience with {$skill}.",
            "What's the most challenging project you've worked on using {$skill}?",
            "How do you stay updated with the latest developments in {$skill}?",
            "Describe a time you had to debug a complex issue.",
            "How do you approach learning new technologies?",
        ];

        $questions = [];
        for ($i = 0; $i < min($job->number_of_questions, count($templates)); $i++) {
            $questions[] = [
                'question_text' => $templates[$i],
                'type' => $i < 3 ? 'technical' : 'behavioral',
                'expected_skills' => $skills,
                'evaluation_criteria' => ['clarity', 'depth', 'relevance'],
            ];
        }

        return $questions;
    }

    /**
     * Fallback company questions if none provided
     * أسئلة احتياطية للشركة إذا لم ترسل أي أسئلة
     */
    private function getFallbackCompanyQuestions(CompanyJob $job): array
    {
        return [
            [
                'question_text' => "Why are you interested in this position at {$job->company->company_name}?",
                'type' => 'behavioral',
                'expected_skills' => $job->required_skills,
                'evaluation_criteria' => ['clarity', 'relevance', 'motivation'],
                'source' => 'company',
            ],
            [
                'question_text' => "Describe your experience with " . implode(', ', $job->required_skills),
                'type' => 'technical',
                'expected_skills' => $job->required_skills,
                'evaluation_criteria' => ['clarity', 'depth', 'technical_accuracy'],
                'source' => 'company',
            ],
            [
                'question_text' => "Where do you see yourself in 3 years?",
                'type' => 'behavioral',
                'expected_skills' => [],
                'evaluation_criteria' => ['clarity', 'relevance', 'ambition'],
                'source' => 'company',
            ],
        ];
    }

    /**
     * Complete interview and update score
     * إكمال المقابلة وتحديث النتيجة
     */
    public function completeInterview(Interview $interview, CompanyJobCandidate $jobCandidate): void
    {
        $finalReport = $interview->finalReport;
        $score = $finalReport ? round($finalReport->overall_score * 10, 2) : 0;

        $jobCandidate->markAsCompleted($score);

        $interview->update([
            'status' => Interview::STATUS_COMPLETED_WITH_REPORT,
            'completed_at' => now(),
        ]);
    }

    /**
     * Get job candidates ranked by score
     * ترتيب المرشحين حسب الدرجة
     */
    public function getRankedCandidates(CompanyJob $job)
    {
        return $job->candidates()
            ->with(['candidate', 'interview.finalReport'])
            ->whereNotNull('final_score')
            ->orderBy('final_score', 'desc')
            ->get()
            ->map(function ($jobCandidate) {
                $interview = $jobCandidate->interview;
                $finalReport = $interview?->finalReport;

                return [
                    'id' => $jobCandidate->id,
                    'candidate_id' => $jobCandidate->candidate_id,
                    'name' => $jobCandidate->candidate->name,
                    'email' => $jobCandidate->candidate->email,
                    'final_score' => $jobCandidate->final_score,
                    'status' => $jobCandidate->status,
                    'source' => $jobCandidate->source,
                    'company_notes' => $jobCandidate->company_notes,
                    'completed_at' => $jobCandidate->completed_at,
                    'strengths' => $finalReport?->strengths_analysis,
                    'weaknesses' => $finalReport?->improvement_areas,
                    'recommendation' => $finalReport?->hiring_recommendation,
                ];
            });
    }
}
