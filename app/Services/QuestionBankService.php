<?php

namespace App\Services;

use App\Models\CompanyJob;
use App\Models\CompanyQuestionBank;
use App\Models\CandidateQuestionHistory;
use App\Imports\QuestionsImport;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class QuestionBankService
{
    /**
     * Upload and process question file for a job
     */
    public function uploadQuestions(CompanyJob $job, UploadedFile $file): array
    {
        // Import questions from Excel
        $import = new QuestionsImport();
        Excel::import($import, $file);

        $questions = $import->getQuestions();

        if (empty($questions)) {
            throw new \Exception('No valid questions found in the file.');
        }

        // Create or update question bank
        $questionBank = CompanyQuestionBank::updateOrCreate(
            ['company_job_id' => $job->id],
            [
                'questions' => $questions,
                'total_questions' => count($questions),
            ]
        );

        // Update job with question bank reference
        $job->update(['question_bank_id' => $questionBank->id]);

        return [
            'question_bank_id' => $questionBank->id,
            'total_questions' => count($questions),
            'questions' => $questions,
        ];
    }

    /**
     * Select random questions for a candidate (without repetition)
     */
    public function selectRandomQuestions(CompanyJob $job, int $candidateId, int $requiredCount): array
    {
        $questionBank = $job->questionBank;

        if (!$questionBank) {
            throw new \Exception('No question bank found for this job.');
        }

        // Get previously used question IDs for this candidate
        $usedQuestionIds = CandidateQuestionHistory::getUsedQuestionIds($candidateId, $job->id);

        // Get difficulty distribution if specified
        $difficultyDistribution = $job->getDifficultyDistributionArray();

        // Select random questions
        $selectedQuestions = $questionBank->getRandomQuestions(
            $requiredCount,
            $usedQuestionIds,
            $difficultyDistribution
        );

        return $selectedQuestions;
    }

    /**
     * Generate questions for interview (AI + Company)
     */
    public function generateInterviewQuestions(
        CompanyJob $job,
        int $candidateId,
        LLMService $llmService
    ): array {
        $allQuestions = [];

        // 1. Get company questions from bank
        if ($job->company_questions_count > 0 && $job->question_bank_id) {
            $companyQuestions = $this->selectRandomQuestions(
                $job,
                $candidateId,
                $job->company_questions_count
            );

            foreach ($companyQuestions as $index => $q) {
                $allQuestions[] = [
                    'question_text' => $q['question'],
                    'type' => $q['type'] ?? 'behavioral',
                    'source' => 'company',
                    'expected_skills' => $job->required_skills,
                    'evaluation_criteria' => ['clarity', 'relevance', 'depth'],
                    'question_bank_index' => $index,
                ];
            }
        }

        // 2. Get AI-generated questions
        if ($job->ai_questions_count > 0 && $job->questions_source !== 'company_only') {
            $aiQuestions = $this->generateAIQuestions($job, $llmService);

            foreach ($aiQuestions as $q) {
                $allQuestions[] = [
                    'question_text' => $q['question_text'],
                    'type' => $q['type'] ?? 'technical',
                    'source' => 'system',
                    'expected_skills' => $job->required_skills,
                    'evaluation_criteria' => ['clarity', 'depth', 'relevance'],
                ];
            }
        }

        // Shuffle to mix AI and company questions
        shuffle($allQuestions);

        return $allQuestions;
    }

    /**
     * Generate AI questions based on job requirements
     */
    private function generateAIQuestions(CompanyJob $job, LLMService $llmService): array
    {
        $skillsList = implode(', ', $job->required_skills);

        $prompt = <<<EOT
Generate {$job->ai_questions_count} interview questions for a {$job->title} position.

Required skills: {$skillsList}
Difficulty level: {$job->difficulty}

Format the response as a JSON object with a 'questions' array. Each question should have:
- question_text: The actual question
- type: One of ['technical', 'behavioral', 'situational']

Return ONLY valid JSON.
EOT;

        try {
            $response = $llmService->generateQuestionsFromPrompt($prompt, $job->ai_questions_count);
            return $response['questions'] ?? [];
        } catch (\Exception $e) {
            return $this->getFallbackAIQuestions($job);
        }
    }

    /**
     * Fallback AI questions if generation fails
     */
    private function getFallbackAIQuestions(CompanyJob $job): array
    {
        $skills = $job->required_skills;
        $skill = $skills[0] ?? 'software development';

        $templates = [
            "Tell me about your experience with {$skill}.",
            "What's the most challenging project you've worked on?",
            "How do you stay updated with latest technologies?",
            "Describe your problem-solving approach.",
            "How do you handle technical debt?",
        ];

        $questions = [];
        for ($i = 0; $i < $job->ai_questions_count; $i++) {
            $questions[] = [
                'question_text' => $templates[$i % count($templates)],
                'type' => $i % 2 == 0 ? 'technical' : 'behavioral',
            ];
        }

        return $questions;
    }

    /**
     * Record questions after interview completion
     */
    public function recordAskedQuestions(
        CompanyJob $job,
        int $candidateId,
        array $questionsWithScores
    ): void {
        $questionBank = $job->questionBank;

        if (!$questionBank) {
            return;
        }

        $bankQuestions = $questionBank->getQuestionsCollection();

        foreach ($questionsWithScores as $item) {
            // Find which question in bank matches
            $matchedIndex = null;
            foreach ($bankQuestions as $index => $bankQ) {
                if ($bankQ['question'] === $item['question_text']) {
                    $matchedIndex = $index;
                    break;
                }
            }

            if ($matchedIndex !== null) {
                CandidateQuestionHistory::recordQuestion(
                    $candidateId,
                    $job->id,
                    $matchedIndex,
                    $item['question_text'],
                    $item['type'] ?? null,
                    $bankQuestions[$matchedIndex]['difficulty'] ?? 'medium',
                    $item['score'] ?? null,
                    $item['time_to_answer'] ?? null
                );
            }
        }
    }

    /**
     * Get question statistics for a job
     */
    public function getQuestionStats(CompanyJob $job): array
    {
        $questionBank = $job->questionBank;

        if (!$questionBank) {
            return [
                'total_questions' => 0,
                'used_questions' => 0,
                'unused_questions' => 0,
                'questions_by_difficulty' => [],
                'average_scores' => [],
            ];
        }

        $totalQuestions = $questionBank->total_questions;
        $usedQuestionIds = CandidateQuestionHistory::where('company_job_id', $job->id)
            ->whereNotNull('question_bank_id')
            ->distinct('question_bank_id')
            ->pluck('question_bank_id')
            ->toArray();

        $usedCount = count(array_unique($usedQuestionIds));

        // Calculate average scores per question
        $averageScores = CandidateQuestionHistory::where('company_job_id', $job->id)
            ->whereNotNull('score')
            ->select('question_bank_id', DB::raw('AVG(score) as avg_score'), DB::raw('COUNT(*) as times_used'))
            ->groupBy('question_bank_id')
            ->get()
            ->toArray();

        // Get questions by difficulty
        $questionsByDifficulty = [
            'easy' => 0,
            'medium' => 0,
            'hard' => 0,
        ];

        foreach ($questionBank->questions as $q) {
            $difficulty = $q['difficulty'] ?? 'medium';
            if (isset($questionsByDifficulty[$difficulty])) {
                $questionsByDifficulty[$difficulty]++;
            }
        }

        return [
            'total_questions' => $totalQuestions,
            'used_questions' => $usedCount,
            'unused_questions' => $totalQuestions - $usedCount,
            'questions_by_difficulty' => $questionsByDifficulty,
            'average_scores' => $averageScores,
        ];
    }
}
