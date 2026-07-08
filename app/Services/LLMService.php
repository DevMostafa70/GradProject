<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use App\Models\Interview;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LLMService
{
    /**
     * Generate interview questions based on position, skills, and experience
     */
    public function generateQuestions(Interview $interview)
    {
        $prompt = $this->buildQuestionGenerationPrompt($interview);

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert technical interviewer. Generate relevant interview questions with evaluation criteria.'
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
            $questions = json_decode($content, true);
            return $this->validateAndFormatQuestions($questions, $interview->number_of_questions);
        } catch (\Exception $e) {
            logger($e->getMessage());
            // Fallback questions if AI fails
            return $this->getFallbackQuestions($interview);
        }
    }



//new anas & mohammed

    /**
     * Generate questions from custom prompt
     */
    public function generateQuestionsFromPrompt(string $prompt, int $expectedCount): array
    {
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
                return $this->getFallbackQuestionsFromPrompt($expectedCount);
            }

            return $data;
        } catch (\Exception $e) {
            return $this->getFallbackQuestionsFromPrompt($expectedCount);
        }
    }

    /**
     * Fallback for prompt-based questions
     */
    private function getFallbackQuestionsFromPrompt(int $count): array
    {
        $questions = [];
        $templates = [
            "Tell me about a challenging technical problem you solved recently.",
            "How do you stay updated with the latest technologies?",
            "Describe your experience working in a team environment.",
            "What's your approach to writing clean, maintainable code?",
            "How do you handle constructive criticism?"
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

Analyze the answer carefully and return a JSON response with the following structure:

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
                'strengths' => ['Answer was provided'],
                'weaknesses' => ['Unable to perform full analysis due to technical issue'],
                'feedback' => 'Please review this answer manually. The automated evaluation system encountered an issue.',
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
     * Generate questions for a specific job (for company candidates)
     */
    public function generateQuestionsForJob($job, $interview): array
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
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert technical interviewer. Generate relevant interview questions with evaluation criteria.'
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
            $questionsData = json_decode($content, true);

            return $this->formatQuestionsForJob($questionsData, $job);
        } catch (\Exception $e) {
            Log::error('generateQuestionsForJob failed: ' . $e->getMessage());
            return $this->getFallbackQuestionsForJob($job);
        }
    }

    /**
     * Format questions for job
     */
    private function formatQuestionsForJob(array $data, $job): array
    {
        $formatted = [];

        if (!isset($data['questions']) || empty($data['questions'])) {
            return $this->getFallbackQuestionsForJob($job);
        }

        foreach ($data['questions'] as $index => $question) {
            $formatted[] = [
                'question_text' => $question['question_text'] ?? 'Please describe your experience.',
                'type' => $question['type'] ?? 'technical',
                'expected_skills' => $question['expected_skills'] ?? $job->required_skills,
                'evaluation_criteria' => $question['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                'order' => $index + 1,
            ];
        }

        return $formatted;
    }

    /**
     * Fallback questions for job
     */
    private function getFallbackQuestionsForJob($job): array
    {
        $questions = [];
        $skill = $job->required_skills[0] ?? 'software development';

        $templates = [
            "Tell me about your experience with {$skill}.",
            "What's the most challenging project you've worked on using {$skill}?",
            "How do you stay updated with the latest developments in {$skill}?",
            "Describe a time you had to debug a complex issue.",
            "How do you approach learning new technologies?"
        ];

        for ($i = 0; $i < $job->number_of_questions; $i++) {
            $questions[] = [
                'question_text' => $templates[$i % count($templates)],
                'type' => $i < 2 ? 'technical' : 'behavioral',
                'expected_skills' => $job->required_skills,
                'evaluation_criteria' => ['clarity', 'depth', 'relevance'],
                'order' => $i + 1,
            ];
        }

        return $questions;
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
                        'content' => 'You are an expert hiring manager and interview evaluator. Provide comprehensive, fair, and constructive feedback. IMPORTANT: Apply penalties for any detected cheating behavior.'
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
     * Build prompt for question generation
     */
    private function buildQuestionGenerationPrompt(Interview $interview): string
    {

        $skillsList = implode(', ', $interview->skills);

        return <<<EOT
Generate {$interview->number_of_questions} interview questions for a {$interview->experience_level} level {$interview->position} position.

Required skills: {$skillsList}
Difficulty level: {$interview->difficulty}

Format the response as a JSON object with a 'questions' array. Each question should have:
- question_text: The actual question
- type: One of ['technical', 'behavioral', 'situational', 'general']
- expected_skills: Array of skills this question evaluates
- evaluation_criteria: Array of key points to evaluate in the answer
- order: Question number (1-based)

Ensure a mix of question types and cover the required skills appropriately.
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
        $answersData = [];
        foreach ($answers as $index => $answer) {
            $question = $answer->question;
            $evaluation = $evaluations->where('answer_id', $answer->id)->first();

            $answersData[] = [
                'question_number' => $index + 1,
                'question' => $question->question_text,
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

            $violationContext .= "\n\nIMPORTANT: You MUST apply appropriate score penalties for detected cheating. The severity score of {$cheatingSeverityScore}/10 should result in a proportional score reduction. Candidates who cheat should receive lower overall evaluations, especially in integrity-related areas.";
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
10. adjusted_score must equal overall_score if no cheating or violation was detected.
11. adjusted_score must be lower than overall_score if cheating or violations were detected.
12. The severity of cheating or violations must proportionally reduce the adjusted_score.
13. Cheating penalties must not increase any score.
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
26. educational_summary MUST be in English and educational in nature.
27. key_strengths and key_weaknesses MUST be based on actual answers (not generic).
28. Each strength/weakness MUST include a real example from the answer.
29. improvement_plan MUST have 3-5 actionable steps with clear action items.
30. learning_resources MUST include 3-5 specific resources (real courses, books, etc.).
31. key_takeaways MUST be 3-5 specific lessons the candidate should learn.
32. next_steps MUST be 2-3 immediate actionable steps.
33. All educational content MUST be constructive and encouraging.
34. Use the actual answer content to personalize feedback.
35. If cheating was detected, include a note about integrity in educational_summary.

Return valid JSON only.
EOT;
    }


    /**
     * Validate and format AI-generated questions
     */
private function validateAndFormatQuestions(array $data, int $expectedCount): array
{
    if (!isset($data['questions']) || count($data['questions']) !== $expectedCount) {
        return $this->getFallbackQuestions(Interview::find(1));
    }

    $formatted = [];
    foreach ($data['questions'] as $index => $question) {
        // 🔹 Build the question_text as an array with both languages
        $questionText = [
            'en' => $question['question_text'] ?? 'Please describe your experience with relevant technologies.',
            'ar' => $question['question_text'] ?? 'يرجى وصف خبرتك مع التقنيات ذات الصلة.',
        ];

        $formatted[] = [
            'question_text' => $questionText,  // 🔹 Pass array directly - Eloquent will cast to JSON
            'type' => in_array($question['type'] ?? '', ['technical', 'behavioral', 'situational', 'general'])
                ? $question['type']
                : 'general',
            'expected_skills' => $question['expected_skills'] ?? [],
            'evaluation_criteria' => $question['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
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
     * Fallback questions if AI fails
     */
private function getFallbackQuestions(Interview $interview): array
{
    $questions = [];
    $skill = $interview->skills[0] ?? 'software development';

    $templates = [
        "Tell me about your experience with {$skill}.",
        "What's the most challenging {$skill} project you've worked on?",
        "How do you stay updated with the latest developments in {$skill}?",
        "Describe a time you had to learn a new technology quickly.",
        "How do you approach problem-solving in {$skill}?"
    ];

    foreach (array_slice($templates, 0, $interview->number_of_questions) as $index => $template) {
        $questions[] = [
            'question_text' => [
                'en' => $template,
                'ar' => $template,
            ],
            'type' => $index < 2 ? 'technical' : 'behavioral',
            'expected_skills' => [$skill],
            'evaluation_criteria' => ['clarity', 'depth', 'relevance', 'confidence'],
            'order' => $index + 1,
        ];
    }

    return $questions;
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
}
