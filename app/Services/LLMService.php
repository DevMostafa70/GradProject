<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use App\Models\Interview;
use App\Models\CompanyJob;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LLMService
{
    /**
     * Generate interview questions through the single canonical pipeline used
     * by both normal-user interviews and company-candidate interviews.
     */
    public function generateQuestions(
        Interview $interview,
        ?int $questionCount = null,
        array $context = []
    ): array {
        $expectedCount = max(
            1,
            (int) ($questionCount ?? $interview->number_of_questions ?? 5)
        );

        $prompt = $this->buildQuestionGenerationPrompt(
            $interview,
            $expectedCount,
            $context
        );

        $maxAttempts = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $attemptNonce = (string) \Illuminate\Support\Str::uuid();
                $attemptPrompt = $prompt . "\n\nGENERATION ATTEMPT: {$attempt}/{$maxAttempts}\n"
                    . "ATTEMPT NONCE: {$attemptNonce}\n"
                    . "Return a fresh interview composition for this attempt. "
                    . "Do not expose the nonce in any question or user-facing field.";

                Log::info('Generating interview questions through unified pipeline', [
                    'interview_id' => $interview->id,
                    'interview_type' => $interview->interview_type,
                    'company_job_id' => $interview->company_job_id,
                    'position' => $interview->position,
                    'expected_count' => $expectedCount,
                    'attempt' => $attempt,
                    'generation_scope' => $context['generation_scope'] ?? 'standard_interview',
                ]);

                $response = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => implode("\n", [
                                'You are a senior structured-interview assessment designer.',
                                'Generate practical, job-related, fair, varied, and consistently scorable interview questions.',
                                'Use the same quality standard for individual and company interviews.',
                                'Historical and excluded questions are a strict semantic exclusion list.',
                                'Avoid generic textbook questions and superficial paraphrases.',
                                'Return one valid JSON object only.',
                            ]),
                        ],
                        [
                            'role' => 'user',
                            'content' => $attemptPrompt,
                        ],
                    ],
                    'temperature' => 0.85,
                    'presence_penalty' => 0.45,
                    'frequency_penalty' => 0.25,
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 6000,
                ]);

                $choice = $response->choices[0] ?? null;

                if (!$choice) {
                    throw new \UnexpectedValueException('OpenAI returned no completion choice.');
                }

                $finishReason = $choice->finishReason
                    ?? $choice->finish_reason
                    ?? null;

                if ($finishReason === 'length') {
                    throw new \UnexpectedValueException('Question generation response was truncated.');
                }

                $content = trim((string) ($choice->message->content ?? ''));

                if ($content === '') {
                    throw new \UnexpectedValueException('OpenAI returned an empty response.');
                }

                try {
                    $questionsData = json_decode(
                        $content,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (\JsonException $exception) {
                    throw new \UnexpectedValueException(
                        'OpenAI returned invalid JSON: ' . $exception->getMessage(),
                        previous: $exception
                    );
                }

                $formatted = $this->validateAndFormatQuestions(
                    $questionsData,
                    $interview,
                    $expectedCount
                );

                if (count($formatted) !== $expectedCount) {
                    throw new \UnexpectedValueException(sprintf(
                        'Expected %d formatted questions, received %d.',
                        $expectedCount,
                        count($formatted)
                    ));
                }

                Log::info('Interview questions generated successfully', [
                    'interview_id' => $interview->id,
                    'interview_type' => $interview->interview_type,
                    'questions_count' => count($formatted),
                    'attempt' => $attempt,
                    'finish_reason' => $finishReason,
                ]);

                return $formatted;
            } catch (\Throwable $exception) {
                $lastException = $exception;

                Log::warning('Unified question generation attempt failed', [
                    'interview_id' => $interview->id,
                    'interview_type' => $interview->interview_type,
                    'position' => $interview->position,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ]);

                if ($attempt < $maxAttempts) {
                    usleep(500000);
                }
            }
        }

        Log::error('All unified question generation attempts failed', [
            'interview_id' => $interview->id,
            'interview_type' => $interview->interview_type,
            'position' => $interview->position,
            'expected_count' => $expectedCount,
            'error' => $lastException?->getMessage(),
        ]);

        return $this->getFallbackQuestions(
            $interview,
            $expectedCount,
            $context
        );
    }



//new anas & mohammed

    /**
     * Generate questions from custom prompt
     */
    public function generateQuestionsFromPrompt(string $prompt, int $expectedCount, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $language = $this->languageName($locale);
        $prompt = "{$prompt}\n\nLANGUAGE REQUIREMENT: Write every user-facing text value in {$language}. Return valid JSON only.";

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert technical interviewer. Generate relevant practice questions.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $data = json_decode($content, true);

            if (!isset($data['questions']) || count($data['questions']) !== $expectedCount) {
                return $this->getFallbackQuestionsFromPrompt($expectedCount, $locale);
            }

            return $data;
        } catch (\Exception $e) {
            return $this->getFallbackQuestionsFromPrompt($expectedCount, $locale);
        }
    }

    /**
     * Fallback for prompt-based questions
     */
    private function getFallbackQuestionsFromPrompt(int $count, ?string $locale = null): array
    {
        $questions = [];
        $locale = $this->normalizeLocale($locale);
        $templates = $locale === 'ar'
            ? [
                "حدثني عن مشكلة تقنية صعبة حللتها مؤخرًا.",
                "كيف تتابع أحدث التقنيات والتطورات في مجالك؟",
                "صف خبرتك في العمل ضمن فريق.",
                "ما منهجك في كتابة كود نظيف وسهل الصيانة؟",
                "كيف تتعامل مع النقد البنّاء؟",
            ]
            : [
                "Tell me about a challenging technical problem you solved recently.",
                "How do you stay updated with the latest technologies?",
                "Describe your experience working in a team environment.",
                "What's your approach to writing clean, maintainable code?",
                "How do you handle constructive criticism?",
            ];

        for ($i = 0; $i < $count; $i++) {
            $questions[] = [
                'question_text' => $templates[$i % count($templates)],
                'type' => $i % 2 == 0 ? 'behavioral' : 'technical',
                'focus_area' => 'general'
            ];
        }

        return ['questions' => $questions];
    }

    // ==================== NEW: Evaluate answer with source awareness ====================


    //new anas & mohammed 2

    /**
     * Evaluate an answer with awareness of question source
     * تقييم إجابة مع تمييز مصدر السؤال (نظام أو شركة)
     *
     * @param string $question - نص السؤال
     * @param string $answer - إجابة المستخدم
     * @param string $source - مصدر السؤال ('system' أو 'company')
     * @param array $context - سياق إضافي (مثل الوظيفة، المهارات المطلوبة)
     * @return array
     */
    public function evaluateAnswerWithSource(string $question, string $answer, string $source, array $context = []): array
    {
        $locale = $this->normalizeLocale($context['locale'] ?? null);
        $language = $this->languageName($locale);

        // بناء سياق إضافي حسب مصدر السؤال
        $sourceContext = '';
        $evaluationFocus = '';

        if ($source === 'company') {
            $sourceContext = "IMPORTANT: This is a CUSTOM question from the hiring company, NOT a standard technical question.

Focus your evaluation on:
1. Relevance to the company's needs and culture
2. Honesty and authenticity of the answer
3. Practical examples and real-world experience
4. Communication clarity and professionalism

The company wants to know if this candidate would be a good cultural and practical fit.";
            $evaluationFocus = "company_custom_question";
        } else {
            $sourceContext = "IMPORTANT: This is a STANDARD technical/behavioral question from the platform.

Focus your evaluation on:
1. Technical accuracy and depth of knowledge
2. Clarity and structure of the answer
3. Problem-solving approach
4. Use of industry best practices";
            $evaluationFocus = "standard_question";
        }

        // إضافة معلومات السياق إذا وجدت
        $contextInfo = '';
        if (!empty($context)) {
            $contextInfo = "\n\nAdditional Context:\n";
            if (isset($context['position'])) {
                $contextInfo .= "- Position: {$context['position']}\n";
            }
            if (isset($context['skills']) && !empty($context['skills'])) {
                $contextInfo .= "- Required skills: " . implode(', ', $context['skills']) . "\n";
            }
        }

        $prompt = <<<EOT
You are an expert interviewer evaluating a candidate's answer during a job interview.

{$sourceContext}
{$contextInfo}

QUESTION: {$question}

CANDIDATE'S ANSWER: {$answer}

Analyze the answer carefully. Write every user-facing text value in {$language}, while keeping JSON keys exactly as specified. Return a JSON response with the following structure:

{
    "score": 0-10 (overall score for this answer),
    "strengths": ["list of 2-4 specific strengths"],
    "weaknesses": ["list of 2-4 specific weaknesses or areas for improvement"],
    "feedback": "detailed constructive feedback for the candidate (2-3 sentences)",
    "clarity_score": 0-10 (how clear and well-structured the answer was),
    "relevance_score": 0-10 (how relevant the answer was to the question),
    "depth_score": 0-10 (depth of knowledge demonstrated),
    "examples_score": 0-10 (use of real-world examples and practical experience)
}

Be fair, constructive, and specific. Provide actionable feedback that helps the candidate improve.
EOT;

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert interviewer with years of experience evaluating candidates. Provide fair, detailed, and constructive feedback.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.5,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $result = json_decode($content, true);

            // إضافة مصدر السؤال إلى النتيجة
            $result['source'] = $source;
            $result['evaluation_focus'] = $evaluationFocus;

            return $result;
        } catch (\Exception $e) {
            Log::error('LLMService: evaluateAnswerWithSource failed', [
                'error' => $e->getMessage(),
                'question' => substr($question, 0, 100),
                'source' => $source
            ]);

            return [
                'score' => 5,
                'strengths' => [$this->localizedText($locale, 'تم تقديم إجابة.', 'An answer was provided.')],
                'weaknesses' => [$this->localizedText($locale, 'تعذر إجراء التحليل الكامل بسبب مشكلة تقنية.', 'Unable to perform full analysis due to a technical issue.')],
                'feedback' => $this->localizedText($locale, 'يرجى مراجعة هذه الإجابة يدويًا، واجه نظام التقييم الآلي مشكلة تقنية.', 'Please review this answer manually. The automated evaluation system encountered an issue.'),
                'clarity_score' => 5,
                'relevance_score' => 5,
                'depth_score' => 5,
                'examples_score' => 5,
                'source' => $source,
                'evaluation_focus' => $evaluationFocus,
                'is_fallback' => true
            ];
        }
    }


    /**
     * Batch evaluate multiple answers with source awareness
     * تقييم مجموعة من الإجابات دفعة واحدة
     *
     * @param array $answersData كل عنصر يحتوي على ['question' => '', 'answer' => '', 'source' => '']
     * @param array $context سياق إضافي
     * @return array
     */
    public function evaluateBatchAnswers(array $answersData, array $context = []): array
    {
        $results = [];

        foreach ($answersData as $index => $data) {
            $question = $data['question'] ?? '';
            $answer = $data['answer'] ?? '';
            $source = $data['source'] ?? 'system';

            if (empty($answer)) {
                $results[$index] = [
                    'score' => 0,
                    'strengths' => [],
                    'weaknesses' => ['No answer provided'],
                    'feedback' => 'Candidate did not provide an answer to this question.',
                    'clarity_score' => 0,
                    'relevance_score' => 0,
                    'depth_score' => 0,
                    'examples_score' => 0,
                    'source' => $source,
                    'error' => 'empty_answer'
                ];
                continue;
            }

            $results[$index] = $this->evaluateAnswerWithSource($question, $answer, $source, $context);
        }

        return $results;
    }

    // ==================== END NEW METHODS ====================



    /**
     * Generate the AI portion of a company interview through the exact same
     * pipeline, prompt, validation, retries, timing, and fallback used by a
     * normal-user interview.
     */
    public function generateQuestionsForJob(
        CompanyJob $job,
        ?Interview $interview = null,
        ?int $questionCount = null,
        array $context = []
    ): array {
        $locale = $interview?->normalizedLocale()
            ?? $job->normalizedInterviewLocale();

        $count = max(
            1,
            (int) ($questionCount
                ?? $interview?->number_of_questions
                ?? $job->number_of_questions
                ?? 5)
        );

        // Work on a clone so generation overrides never persist accidental
        // changes to an already-created company interview.
        $generationInterview = $interview
            ? clone $interview
            : new Interview();

        $generationInterview->forceFill([
            'interview_type' => $interview?->interview_type ?: 'company_candidate',
            'company_job_id' => $interview?->company_job_id ?: $job->id,
            'candidate_id' => $interview?->candidate_id,
            'position' => $job->titleForLocale($locale),
            'experience_level' => $interview?->experience_level ?: 'mid',
            'difficulty' => (int) $job->difficulty,
            'skills' => array_values($job->required_skills ?? []),
            'number_of_questions' => $count,
            'locale' => $locale,
        ]);

        $company = $job->relationLoaded('company')
            ? $job->company
            : $job->company()->first(['id', 'company_name', 'industry']);

        $companyName = $company?->company_name;

        $companyContext = array_merge([
            'generation_scope' => 'company_candidate_interview',
            'company_job_id' => $job->id,
            'company_name' => $companyName ?: 'Not provided',
            'industry' => $company?->industry ?: 'Not provided',
            'job_description' => $job->descriptionForLocale($locale),
            'job_title' => $job->titleForLocale($locale),
            'questions_source' => $job->questions_source,
        ], $context);

        $questions = $this->generateQuestions(
            $generationInterview,
            $count,
            $companyContext
        );

        return array_map(static function (array $question): array {
            $question['source'] = 'system';
            return $question;
        }, $questions);
    }

    /**
     * Analyze resume using AI
     */
    public function analyzeResume(string $extractedText, ?string $targetPosition = null, ?array $targetSkills = null): array
    {

        //  أضف هذه الأسطر
        Log::info('=== LLMService::analyzeResume CALLED ===');
        Log::info('Text length: ' . strlen($extractedText));
        Log::info('Target position: ' . $targetPosition);
        Log::info('Target skills: ' . json_encode($targetSkills));

        $positionText = $targetPosition
            ? "Target position: {$targetPosition}\n"
            : "No specific target position provided.\n";

        $skillsText = $targetSkills
            ? "Target skills to highlight: " . implode(', ', $targetSkills) . "\n"
            : "No specific target skills provided.\n";

        $prompt = <<<EOT
You are an expert resume reviewer and career coach. Analyze the following resume and provide detailed feedback.

{$positionText}
{$skillsText}

RESUME TEXT:
{$extractedText}

Analyze the resume and return a JSON response with the following structure:

{
    "ats_score": 0-100 (how well this resume would perform with ATS systems),
    "strengths": ["list of 3-5 strengths of this resume"],
    "weaknesses": ["list of 3-5 weaknesses or missing elements"],
    "suggestions": ["list of 3-5 specific suggestions for improvement"],
    "missing_skills": ["skills mentioned in target position but missing from resume"],
    "formatting_issues": ["any formatting or readability issues"],
    "overall_assessment": "brief 1-2 sentence overall evaluation",
    "keyword_optimization": "suggestions for keyword optimization"
}

Be specific, constructive, and actionable.
EOT;

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert resume reviewer. Provide detailed, constructive feedback.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.5,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            return json_decode($content, true);
        } catch (\Exception $e) {
            logger()->error('LLMService: analyzeResume failed', [
                'error' => $e->getMessage(),
                'text' => substr($extractedText, 0, 100)
            ]);
            return $this->getFallbackResumeAnalysis($extractedText);
        }
    }


    /**
     * Generate improved resume content
     */
    public function improveResume(string $extractedText, array $analysis, ?string $targetPosition = null): array
    {
        $positionText = $targetPosition
            ? "Improve this resume for a {$targetPosition} position.\n"
            : "Improve this resume for better ATS compatibility.\n";

        $weaknessesText = isset($analysis['weaknesses'])
            ? "Focus on fixing these issues: " . implode(', ', array_slice($analysis['weaknesses'], 0, 3)) . "\n"
            : "";

        $prompt = <<<EOT
{$positionText}
{$weaknessesText}

ORIGINAL RESUME:
{$extractedText}

Return a JSON response with:
{
    "improved_content": "the improved resume text with better formatting and keyword optimization",
    "changes_summary": "brief summary of key changes made",
    "added_keywords": ["keywords that were added"],
    "sections_to_rework": ["sections that need more work"]
}
EOT;

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert resume writer. Improve resumes for better ATS performance.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.6,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            return json_decode($content, true);
        } catch (\Exception $e) {
            return [
                'improved_content' => $extractedText,
                'changes_summary' => 'Unable to generate improvements at this time',
                'added_keywords' => [],
                'sections_to_rework' => [],
            ];
        }
    }

    /**
     * Fallback analysis if AI fails
     */
    private function getFallbackResumeAnalysis(string $text): array
    {
        $length = strlen($text);
        $hasContact = preg_match('/\b(?:email|phone|\+?\d|\@)\b/i', $text);

        return [
            'ats_score' => $length > 500 ? 65 : 45,
            'strengths' => ['Resume submitted for review'],
            'weaknesses' => ['Unable to perform full AI analysis. Please try again.'],
            'suggestions' => ['Ensure your resume includes clear section headers', 'Add quantifiable achievements', 'Use industry-standard keywords'],
            'missing_skills' => [],
            'formatting_issues' => $hasContact ? [] : ['Missing contact information may be incomplete'],
            'overall_assessment' => 'Manual review recommended due to analysis limitations',
            'keyword_optimization' => 'Review job descriptions and align your resume keywords',
        ];
    }

//--------------------------------------------------



    /**
     * Generate final comprehensive report
     */
    public function generateFinalReport(
        Interview $interview,
        Collection $answers,
        Collection $evaluations,
        array $violationSummary,
        float $cheatingSeverityScore
    ): array {
        $locale = $this->normalizeLocale($interview->locale ?? null);
        $language = $this->languageName($locale);
        app()->setLocale($locale);

        $prompt = $this->buildFinalReportPrompt(
            $interview,
            $answers,
            $evaluations,
            $violationSummary,
            $cheatingSeverityScore
        );

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are an expert hiring manager and interview evaluator. Provide comprehensive, fair, and constructive feedback in {$language}. Describe integrity concerns but do not calculate or apply score penalties; the application applies the penalty deterministically."
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.5,
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 2000,
            ]);

            $content = $response->choices[0]->message->content;
            $report = json_decode($content, true);

            return $this->validateAndEnrichReport($report, $evaluations, $cheatingSeverityScore);
        } catch (\Exception $e) {
            // Fallback report generation
            return $this->generateFallbackReport($interview, $evaluations, $cheatingSeverityScore);
        }
    }

    /**
     * Build the canonical question-generation prompt for every interview type.
     */
    private function buildQuestionGenerationPrompt(
        Interview $interview,
        ?int $questionCount = null,
        array $context = []
    ): string {
        $skills = collect($interview->skills ?? [])
            ->filter(fn ($skill) => is_string($skill) && trim($skill) !== '')
            ->map(fn ($skill) => trim($skill))
            ->unique()
            ->values();

        $skillsList = $skills->isNotEmpty()
            ? $skills->implode(', ')
            : 'No specific skills provided';

        $locale = $this->normalizeLocale($interview->locale ?? null);
        $language = $this->languageName($locale);
        $questionCount = max(1, (int) ($questionCount ?? $interview->number_of_questions ?? 5));
        $targetDifficulty = max(1, min(10, (int) ($interview->difficulty ?? 5)));
        $position = trim((string) ($interview->position ?: 'General Position'));
        $experienceLevel = trim((string) ($interview->experience_level ?: 'mid'));

        $jobDescription = trim((string) (
            $context['job_description']
            ?? data_get($interview->metadata, 'job_description')
            ?? 'Not provided'
        ));

        $companyName = trim((string) ($context['company_name'] ?? 'Not applicable'));
        $generationScope = trim((string) ($context['generation_scope'] ?? 'standard_interview'));

        $historyQuery = Question::query()
            ->with('interview:id,position')
            ->whereHas('interview', function ($query) use ($interview, $position): void {
                if ($interview->id) {
                    $query->where('id', '!=', $interview->id);
                }

                $query->whereRaw('LOWER(position) = ?', [mb_strtolower($position)]);
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Question $question): string => $question->textForLocale($locale))
            ->filter()
            ->values();

        $excludedQuestions = collect($context['excluded_questions'] ?? [])
            ->merge($historyQuery)
            ->filter(fn ($question) => is_string($question) && trim($question) !== '')
            ->map(fn ($question) => trim($question))
            ->unique(fn ($question) => mb_strtolower($question))
            ->take(80)
            ->values();

        $excludedQuestionsJson = $excludedQuestions->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $candidatePoolSize = max($questionCount * 3, $questionCount + 10);

        return <<<EOT
You are a senior structured-interview assessment designer, technical hiring manager, and HR expert.

Create a realistic, job-related, fair, varied, and consistently scorable interview for a global AI-powered interview platform.

The exact same professional quality standard applies whether this is an individual practice interview or a company-candidate interview.

---

# INTERVIEW INPUT

- Generation Scope: {$generationScope}
- Job Position: {$position}
- Company: {$companyName}
- Experience Level: {$experienceLevel}
- Required Skills: {$skillsList}
- Job Description: {$jobDescription}
- Target Difficulty: {$targetDifficulty}/10
- Number of Questions: {$questionCount}
- Question Language: {$language}

---

# HISTORICAL EXCLUSION LIST

The following questions were previously used for the same or a similar position, or are already selected from a company question bank.

Treat them as a semantic exclusion list. Do not repeat them, paraphrase them, reuse the same central scenario, or ask for substantially the same evidence through the same reasoning path.

{$excludedQuestionsJson}

---

# INTERNAL SELECTION PROCESS

Internally generate at least {$candidatePoolSize} candidate questions, score them for job relevance, realism, novelty, difficulty alignment, skill coverage, fairness, and scoreability, then return only the strongest {$questionCount} questions.

Do not expose the candidate pool or internal reasoning.

Reject questions that are generic, textbook-only, predictable, discriminatory, duplicated, or superficial paraphrases.

Avoid standard forms such as:

- Tell me about yourself.
- What are your strengths or weaknesses?
- Why should we hire you?
- Where do you see yourself in five years?
- Tell me about a challenging project.
- How do you keep up with technology?
- Explain or define a technology without a practical context.

A related competency may be assessed only through a specific job scenario with meaningful constraints, decisions, trade-offs, and observable evidence.

---

# QUESTION COMPOSITION

Adapt the distribution to the role, using this default guidance:

- Technical or role-functional: 35-50%
- Behavioral evidence: 20-30%
- Situational judgment: 20-30%
- General job-related: 0-10%

At least half of the questions must be practical, scenario-based, work-sample, debugging, decision, incident, prioritization, or trade-off questions.

Use diverse archetypes. Do not use the same archetype more than twice unless the interview has more than 10 questions.

Cover every required skill when mathematically possible. Critical skills may appear more than once only through different scenarios and evidence requirements.

The average difficulty must remain within ±1 of {$targetDifficulty}, with natural variation across questions.

---

# STRUCTURED EVALUATION

Every question must be consistently scorable and include:

- Clear evaluation criteria
- Strong-answer indicators
- A realistic answer timer
- Skills genuinely assessed by the question
- A real-world context

Evaluate evidence, reasoning, correctness, prioritization, trade-offs, validation, ownership, and communication as relevant.

Do not evaluate accent, appearance, personality style, or speaking speed alone.

---

# ANSWER TIME ALLOCATION

Use realistic time limits:

- Simple job-related question: 45-90 seconds
- Technical explanation: 90-180 seconds
- Behavioral evidence: 120-240 seconds
- Debugging or problem solving: 150-300 seconds
- Situational decision: 150-300 seconds
- Architecture or system design: 300-600 seconds

Different questions should normally have different allocations. Complex questions must receive more time than simple questions.

---

# FAIRNESS AND COMPLIANCE

Every question must relate directly to job performance.

Do not ask about age, gender, religion, nationality, ethnicity, marital status, pregnancy, disability, medical history, political views, financial status, family status, or other protected or irrelevant personal information.

Allow multiple professionally defensible answers when appropriate.

---

# LANGUAGE RULES

Write all user-facing fields in {$language}.
Keep JSON keys and enum values in English.
Do not return bilingual objects.
Keep common technical terms in their recognized professional form when appropriate.

---

# OUTPUT FORMAT

Return ONLY one valid JSON object with exactly this structure:

{
  "questions": [
    {
      "id": "stable-unique-string",
      "order": 1,
      "question_text": "string",
      "type": "technical | behavioral | situational | general",
      "category": "string",
      "difficulty_score": 1,
      "expected_skills": ["string"],
      "evaluation_criteria": ["string"],
      "strong_answer_indicators": ["string"],
      "time_allocation_seconds": 120,
      "skill_tags": ["string"],
      "real_world_context": "string"
    }
  ],
  "metadata": {
    "total_questions": {$questionCount},
    "position": "{$position}",
    "experience_level": "{$experienceLevel}",
    "average_difficulty": 0,
    "question_type_distribution": {
      "technical": 0,
      "behavioral": 0,
      "situational": 0,
      "general": 0
    },
    "skills_covered": ["string"]
  }
}

---

# FINAL VALIDATION

Before returning JSON, verify:

- Exactly {$questionCount} questions exist.
- Every required field exists.
- No duplicate or semantically repetitive questions exist.
- No question overlaps with the historical exclusion list.
- Required skills are covered when possible.
- Types, scenarios, and difficulty are varied.
- Timers are realistic and between 45 and 600 seconds.
- All questions are job-related, fair, and consistently scorable.
- All user-facing text is written in {$language}.
- There is no markdown or text outside the JSON.

If validation fails, silently repair the set before returning it.
EOT;
    }

    /**
     * Build comprehensive prompt for final report
     */
    private function buildFinalReportPrompt(
        Interview $interview,
        Collection $answers,
        Collection $evaluations,
        array $violationSummary,
        float $cheatingSeverityScore
    ): string {
        $locale = $this->normalizeLocale($interview->locale ?? null);
        $language = $this->languageName($locale);
        $answersData = [];
        foreach ($answers as $index => $answer) {
            $question = $answer->question;
            $evaluation = $evaluations->where('answer_id', $answer->id)->first();

            $answersData[] = [
                'question_number' => $index + 1,
                'question' => $question->textForLocale($locale),
                'type' => $question->type,
                'answer_transcript' => $answer->transcription,
                'score' => $evaluation ? $evaluation->score : 0,
                'strengths' => $evaluation ? $evaluation->strengths : '',
                'weaknesses' => $evaluation ? $evaluation->weaknesses : '',
                'audio_metrics' => $answer->audioAnalysis ? [
                    'speaking_rate' => $answer->audioAnalysis->speaking_rate,
                    'filler_words' => $answer->audioAnalysis->filler_word_count,
                    'confidence' => $answer->audioAnalysis->confidence_level,
                ] : null,
            ];
        }

        $violationContext = '';
        if ($cheatingSeverityScore > 0) {
            $violationContext = <<<EOT

CHEATING DETECTION SUMMARY:
Severity Score: {$cheatingSeverityScore}/10
Total Violations: {$violationSummary['total_violations']}
Violation Types:
EOT;

            foreach ($violationSummary['by_type'] as $violation) {
                $violationContext .= "\n- {$violation['violation_type']}: {$violation['count']} occurrences, {$violation['total_duration']}s total duration";
            }

            $violationContext .= "\n\nIMPORTANT: Describe the integrity concerns and their implications, but do not calculate or apply a score penalty. The application calculates the adjusted score deterministically.";
        }

        $answersJson = json_encode($answersData, JSON_PRETTY_PRINT);
        $skillsRequiredJson = json_encode($answers->first()?->question?->expected_skills ?? [], JSON_PRETTY_PRINT);

        return <<<EOT
Generate a comprehensive final interview report based ONLY on the provided interview data.

INTERVIEW DETAILS:
Position: {$interview->position}
Experience Level: {$interview->experience_level}
Number of Questions: {$interview->number_of_questions}

SKILLS REQUIRED:
{$skillsRequiredJson}

ANSWERS AND INDIVIDUAL EVALUATIONS:
{$answersJson}

VIOLATION / CHEATING CONTEXT:
{$violationContext}

You MUST respond with ONLY a valid JSON object.
No markdown.
No explanation.
No extra text before or after the JSON.

Use exactly this JSON structure:
{
    "executive_summary": "Brief 2-3 sentence overview",
    "overall_score": 0,
    "adjusted_score": 0,
    "technical_score": 0,
    "communication_score": 0,
    "problem_solving_score": 0,
    "strengths_analysis": "Detailed analysis of strengths",
    "improvement_areas": "Specific areas for improvement",
    "skill_breakdown": {
        "skill_name": 0
    },
    "question_evaluations_summary": "Overall performance across questions",
    "hiring_recommendation": "One of: Strongly Recommend, Recommend, Consider, Do Not Recommend, with reasoning",

    "educational_summary": "2-3 sentence educational summary of the candidate's performance, focusing on learning outcomes and what was learned from this interview",

    "key_strengths": [
        {
            "area": "The area of strength (e.g., Analytical Thinking)",
            "example": "A specific example from the answer that demonstrates this strength",
            "explanation": "Brief explanation of why this is a strength"
        }
    ],

    "key_weaknesses": [
        {
            "area": "The area of weakness (e.g., Answer Structure)",
            "example": "A specific example from the answer that demonstrates this weakness",
            "explanation": "Brief explanation of why this is a weakness"
        }
    ],

    "improvement_plan": [
        {
            "step": 1,
            "title": "Step title",
            "description": "Detailed description of the step",
            "action_items": [
                "Specific action item 1",
                "Specific action item 2"
            ],
            "estimated_time": "Estimated time (e.g., 2 weeks)"
        }
    ],

    "learning_resources": [
        {
            "topic": "Topic name",
            "resource_type": "course, article, book, video, practice",
            "title": "Resource title",
            "description": "Brief description of the resource",
            "why_recommended": "Why this resource is useful"
        }
    ],

    "key_takeaways": [
        "Key lesson 1",
        "Key lesson 2",
        "Key lesson 3"
    ],

    "next_steps": [
        "Actionable next step 1",
        "Actionable next step 2"
    ]
}

Critical report generation rules:

1. Base the report ONLY on the provided answers and individual evaluations.
2. Do NOT invent performance, skills, strengths, or weaknesses that are not supported by the provided data.
3. Do NOT infer answers from the interview questions.
4. Do NOT give credit for unanswered, empty, silent, irrelevant, or non-meaningful responses.
5. Any question with one or more of the following must be treated as unanswered:
   - empty transcript
   - null transcript
   - whitespace-only transcript
   - transcript with fewer than 5 meaningful words
   - answer marked with score 0
   - answer marked as "No meaningful answer was provided"
   - relevance_score equal to 0
   - depth_score equal to 0
   - speaking_rate equal to 0
6. Unanswered questions must contribute 0 to the relevant final scores.
7. If most answers are unanswered or non-meaningful, the final report must clearly state that the candidate did not provide enough valid answers for a reliable positive evaluation.
8. If all answers are unanswered or non-meaningful, return:
{
    "executive_summary": "The candidate did not provide meaningful answers during the interview. As a result, there is insufficient evidence to support readiness for the position.",
    "overall_score": 0,
    "adjusted_score": 0,
    "technical_score": 0,
    "communication_score": 0,
    "problem_solving_score": 0,
    "strengths_analysis": "No clear strengths could be identified because the candidate did not provide meaningful responses.",
    "improvement_areas": "The candidate needs to provide clear, complete, and relevant answers to interview questions before their technical, communication, and problem-solving abilities can be evaluated.",
    "skill_breakdown": {},
    "question_evaluations_summary": "All questions were unanswered or lacked meaningful content.",
    "hiring_recommendation": "Do Not Recommend: The candidate did not provide sufficient meaningful responses to evaluate their suitability for the position.",
    "educational_summary": "The candidate did not provide meaningful answers during the interview. There is insufficient data to evaluate their performance or provide educational feedback.",
    "key_strengths": [],
    "key_weaknesses": [
        {
            "area": "Lack of meaningful responses",
            "example": "The candidate did not provide meaningful answers to the questions asked",
            "explanation": "Without actual answers, skills cannot be assessed or useful feedback provided"
        }
    ],
    "improvement_plan": [
        {
            "step": 1,
            "title": "Practice answering interview questions",
            "description": "Practice answering common interview questions in your field",
            "action_items": [
                "Research common questions in your field",
                "Write out your answers in advance",
                "Practice saying them out loud"
            ],
            "estimated_time": "2 weeks"
        }
    ],
    "learning_resources": [
        {
            "topic": "Interview Skills",
            "resource_type": "practice",
            "title": "Mock Interview Practice",
            "description": "Conduct mock interviews with friends or use the platform",
            "why_recommended": "Consistent practice improves confidence and performance"
        }
    ],
    "key_takeaways": [
        "Proper interview preparation significantly improves performance",
        "Providing clear and complete answers demonstrates your understanding"
    ],
    "next_steps": [
        "Start practicing with basic interview questions in your field",
        "Use the Nervu.Ai platform to practice more interviews"
    ]
}
9. overall_score must be calculated from the actual individual question scores, not from general impressions.
10. Set adjusted_score equal to overall_score. The application will calculate the final integrity-adjusted score.
11. Describe violations objectively without changing the content score.
12. Do not calculate a cheating penalty.
13. Cheating information must not increase any score.
14. Audio metrics may affect communication_score and confidence-related interpretation only.
15. Audio metrics must not compensate for missing, irrelevant, or weak answer content.
16. A confident voice with an empty or irrelevant answer must still receive a low final score.
17. technical_score must reflect technical correctness and job relevance only.
18. communication_score must reflect clarity, structure, completeness, and delivery quality.
19. problem_solving_score must reflect reasoning, examples, decision-making, and ability to handle the question.
20. skill_breakdown must include only skills supported by the provided answers, evaluations, or required skills list.
21. If there is no evidence for a skill, assign it 0 or omit it.
22. Do not assign high scores based on position title, experience level, or assumed background.
23. Hiring recommendation must follow this logic:
   - Strongly Recommend: adjusted_score >= 8 and no serious violations
   - Recommend: adjusted_score >= 7 and no serious violations
   - Consider: adjusted_score >= 5 and there is partial evidence of suitability
   - Do Not Recommend: adjusted_score < 5, or most answers are unanswered, or serious cheating was detected
24. All numeric scores must be valid numbers between 0 and 10.
25. Do not use strings for numeric scores.

Educational feedback rules:
26. educational_summary MUST be in {$language} and educational in nature.
27. key_strengths and key_weaknesses MUST be based on actual answers (not generic).
28. Each strength/weakness MUST include a real example from the answer.
29. improvement_plan MUST have 3-5 actionable steps with clear action items.
30. learning_resources MUST include 3-5 specific resources (real courses, books, etc.).
31. key_takeaways MUST be 3-5 specific lessons the candidate should learn.
32. next_steps MUST be 2-3 immediate actionable steps.
33. All educational content MUST be constructive and encouraging.
34. Use the actual answer content to personalize feedback.
35. If cheating was detected, include a note about integrity in educational_summary.

Write every user-facing report text value in {$language}. Keep JSON keys unchanged.
Return valid JSON only.
EOT;
    }


    /**
     * Validate and format AI-generated questions for every interview type.
     */
    private function validateAndFormatQuestions(
        array $data,
        Interview $interview,
        ?int $expectedCount = null
    ): array {
        $expectedCount = max(
            1,
            (int) ($expectedCount ?? $interview->number_of_questions ?? 5)
        );

        if (
            !isset($data['questions'])
            || !is_array($data['questions'])
            || count($data['questions']) !== $expectedCount
        ) {
            throw new \UnexpectedValueException(sprintf(
                'Expected exactly %d generated questions.',
                $expectedCount
            ));
        }

        $locale = $this->normalizeLocale($interview->locale ?? null);
        $allowedTypes = ['technical', 'behavioral', 'situational', 'general'];
        $formatted = [];
        $normalizedTexts = [];

        foreach ($data['questions'] as $index => $question) {
            if (!is_array($question)) {
                throw new \UnexpectedValueException('A generated question is not an object.');
            }

            $questionText = $question['question_text'] ?? null;

            if (is_array($questionText)) {
                $questionText = $questionText[$locale]
                    ?? $questionText['en']
                    ?? $questionText['ar']
                    ?? reset($questionText);
            }

            if (!is_string($questionText) || mb_strlen(trim($questionText)) < 15) {
                throw new \UnexpectedValueException(sprintf(
                    'Generated question %d has invalid question_text.',
                    $index + 1
                ));
            }

            $questionText = trim($questionText);
            $normalized = $this->normalizeQuestionText($questionText);

            if (in_array($normalized, $normalizedTexts, true)) {
                throw new \UnexpectedValueException('Generated questions contain an exact duplicate.');
            }

            foreach ($normalizedTexts as $previousText) {
                if ($this->questionTextSimilarity($normalized, $previousText) >= 0.72) {
                    throw new \UnexpectedValueException('Generated questions contain highly similar items.');
                }
            }

            $normalizedTexts[] = $normalized;
            $type = strtolower(trim((string) ($question['type'] ?? 'general')));
            $timeAllocation = (int) ($question['time_allocation_seconds'] ?? 120);

            $formatted[] = [
                'question_text' => $questionText,
                'type' => in_array($type, $allowedTypes, true) ? $type : 'general',
                'expected_skills' => array_values(array_filter(
                    is_array($question['expected_skills'] ?? null)
                        ? $question['expected_skills']
                        : ($interview->skills ?? [])
                )),
                'evaluation_criteria' => is_array($question['evaluation_criteria'] ?? null)
                    && !empty($question['evaluation_criteria'])
                        ? $question['evaluation_criteria']
                        : ['clarity', 'depth', 'relevance'],
                'time_allocation_seconds' => max(45, min(600, $timeAllocation)),
                'order' => $index + 1,
            ];
        }

        return $formatted;
    }

    /**
     * Validate and enrich AI-generated report
     */
    private function validateAndEnrichReport(array $report, Collection $evaluations, float $cheatingSeverityScore): array
    {
        // Calculate average scores from evaluations
        $avgScore = $evaluations->avg('score') ?? 0;

        // Apply cheating penalty to adjusted score
        $penaltyMultiplier = max(0, 1 - ($cheatingSeverityScore / 20)); // Max 50% penalty

        return [
            'executive_summary' => $report['executive_summary'] ?? 'Interview completed successfully.',
            'overall_score' => round($avgScore, 2),
            'adjusted_score' => round($avgScore * $penaltyMultiplier, 2),
            'technical_score' => $report['technical_score'] ?? $avgScore,
            'communication_score' => $report['communication_score'] ?? $avgScore,
            'problem_solving_score' => $report['problem_solving_score'] ?? $avgScore,
            'strengths_analysis' => $report['strengths_analysis'] ?? 'Demonstrated understanding of core concepts.',
            'improvement_areas' => $report['improvement_areas'] ?? 'Continue developing technical depth.',
            'skill_breakdown' => $report['skill_breakdown'] ?? ['general' => $avgScore],
            'question_evaluations' => $evaluations->map(fn($e) => [
                'question_id' => $e->question_id,
                'score' => $e->score,
                'feedback' => $e->detailed_feedback
            ])->toArray(),
            'hiring_recommendation' => $report['hiring_recommendation'] ?? ($avgScore >= 7 ? 'Recommend' : 'Consider'),
            'ai_raw_response' => $report,
        ];
    }

    /**
     * Deterministic fallback shared by normal and company AI interviews.
     */
    private function getFallbackQuestions(
        Interview $interview,
        ?int $questionCount = null,
        array $context = []
    ): array {
        $count = max(
            1,
            (int) ($questionCount ?? $interview->number_of_questions ?? 5)
        );
        $locale = $this->normalizeLocale($interview->locale ?? null);
        $skills = collect($interview->skills ?? [])
            ->filter(fn ($skill) => is_string($skill) && trim($skill) !== '')
            ->map(fn ($skill) => trim($skill))
            ->values();

        if ($skills->isEmpty()) {
            $skills = collect([
                $this->localizedText($locale, 'المهارات الوظيفية', 'role-specific skills'),
            ]);
        }

        $position = trim((string) ($interview->position ?: $this->localizedText(
            $locale,
            'هذه الوظيفة',
            'this role'
        )));

        $templates = $locale === 'ar'
            ? [
                fn ($skill) => "واجه فريقك مشكلة تؤثر في استخدام {$skill}. كيف ستحدد السبب، وترتب خطوات المعالجة، وتتحقق من نجاح الحل؟",
                fn ($skill) => "لديك متطلبات غير مكتملة لمهمة تعتمد على {$skill} وموعد تسليم قريب. ما الأسئلة التي ستطرحها، وكيف ستحدد نطاق العمل؟",
                fn ($skill) => "اشرح قرارًا عمليًا اتخذته عند استخدام {$skill}، وما البدائل التي قارنتها، وكيف قست النتيجة.",
                fn ($skill) => "اكتشفت بعد الإطلاق أن حلًا مبنيًا على {$skill} يسبب مشكلة في الأداء أو الجودة. كيف ستتعامل مع الحادث وتمنع تكراره؟",
                fn ($skill) => "كيف ستشرح مخاطرة أو قرارًا متعلقًا بـ{$skill} لشخص غير تقني يحتاج إلى اتخاذ قرار؟",
                fn ($skill) => "لديك خياران صالحان لتنفيذ جزء مهم باستخدام {$skill}. كيف ستقارن بينهما من حيث الوقت والمخاطر وقابلية الصيانة؟",
                fn ($skill) => "صف موقفًا تحملت فيه مسؤولية نتيجة لم تسر كما خُطط لها في عمل مرتبط بـ{$skill}. ماذا فعلت وماذا تعلمت؟",
                fn ($skill) => "طُلب منك تحسين جزء قائم يعتمد على {$skill} دون إيقاف الخدمة. كيف ستخطط للتغيير وتختبره تدريجيًا؟",
            ]
            : [
                fn ($skill) => "Your team faces a problem affecting the use of {$skill}. How would you isolate the cause, prioritize the response, and verify the fix?",
                fn ($skill) => "You have incomplete requirements for a task involving {$skill} and a near deadline. What would you clarify, and how would you define the scope?",
                fn ($skill) => "Explain a practical decision you made while using {$skill}, the alternatives you considered, and how you measured the result.",
                fn ($skill) => "After release, a solution built with {$skill} causes a performance or quality issue. How would you handle the incident and prevent recurrence?",
                fn ($skill) => "How would you explain a risk or decision involving {$skill} to a non-technical stakeholder who must make a decision?",
                fn ($skill) => "You have two viable approaches for an important task using {$skill}. How would you compare delivery time, risk, and maintainability?",
                fn ($skill) => "Describe a situation where you owned an outcome that did not go as planned in work involving {$skill}. What did you do and learn?",
                fn ($skill) => "You must improve an existing component that relies on {$skill} without interrupting service. How would you plan and validate the change incrementally?",
            ];

        $types = [
            'situational',
            'situational',
            'technical',
            'technical',
            'behavioral',
            'situational',
            'behavioral',
            'technical',
        ];
        $times = [180, 180, 150, 240, 120, 210, 180, 240];
        $questions = [];

        for ($index = 0; $index < $count; $index++) {
            $skill = $skills[$index % $skills->count()];
            $templateIndex = $index % count($templates);

            $questions[] = [
                'question_text' => $templates[$templateIndex]($skill),
                'type' => $types[$templateIndex],
                'expected_skills' => [$skill],
                'evaluation_criteria' => ['clarity', 'depth', 'relevance', 'decision_quality'],
                'time_allocation_seconds' => $times[$templateIndex],
                'order' => $index + 1,
            ];
        }

        return $questions;
    }

    private function normalizeQuestionText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function questionTextSimilarity(string $first, string $second): float
    {
        $firstWords = array_values(array_unique(array_filter(
            preg_split('/\\s+/u', $first) ?: []
        )));
        $secondWords = array_values(array_unique(array_filter(
            preg_split('/\\s+/u', $second) ?: []
        )));

        $union = array_unique(array_merge($firstWords, $secondWords));

        if (count($union) === 0) {
            return 0.0;
        }

        return count(array_intersect($firstWords, $secondWords)) / count($union);
    }

    /**
     * Fallback report generation
     */
    private function generateFallbackReport(
        Interview $interview,
        Collection $evaluations,
        float $cheatingSeverityScore
    ): array {
        $avgScore = $evaluations->avg('score') ?? 0;
        $penaltyMultiplier = max(0, 1 - ($cheatingSeverityScore / 20));

        return [
            'executive_summary' => "The candidate completed the interview for {$interview->position} position.",
            'overall_score' => round($avgScore, 2),
            'adjusted_score' => round($avgScore * $penaltyMultiplier, 2),
            'technical_score' => round($avgScore, 2),
            'communication_score' => round($avgScore, 2),
            'problem_solving_score' => round($avgScore, 2),
            'strengths_analysis' => 'Demonstrated understanding of key concepts.',
            'improvement_areas' => 'Consider deepening technical knowledge.',
            'skill_breakdown' => array_fill_keys($interview->skills, $avgScore),
            'question_evaluations' => $evaluations->map(fn($e) => [
                'question_id' => $e->question_id,
                'score' => $e->score,
                'feedback' => $e->detailed_feedback ?? 'Answer evaluated.'
            ])->toArray(),
            'hiring_recommendation' => $avgScore >= 7 ? 'Recommend' : 'Consider',
            'ai_raw_response' => ['note' => 'Fallback response generated'],
        ];
    }

    /**
     * Normalize supported application locales.
     */
    private function normalizeLocale(?string $locale): string
    {
        $locale = strtolower((string) ($locale ?: app()->getLocale()));
        return str_starts_with($locale, 'ar') ? 'ar' : 'en';
    }

    /**
     * Human-readable language name used in OpenAI prompts.
     */
    private function languageName(string $locale): string
    {
        return $locale === 'ar' ? 'Arabic' : 'English';
    }

    /**
     * Select a fallback message using the interview locale.
     */
    private function localizedText(string $locale, string $arabic, string $english): string
    {
        return $locale === 'ar' ? $arabic : $english;
    }
}
