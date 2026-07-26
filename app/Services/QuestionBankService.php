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
        $requiredCount = max(1, $job->getTotalQuestionsPerCandidate());
        $source = $job->questions_source ?? 'mixed';
        $allQuestions = [];

        $companyCount = match ($source) {
            'ai_only' => 0,
            'company_only' => $requiredCount,
            default => min(
                max(0, (int) $job->company_questions_count),
                $requiredCount
            ),
        };

        if ($companyCount > 0 && $job->question_bank_id) {
            $companyQuestions = $this->selectRandomQuestions(
                $job,
                $candidateId,
                $companyCount
            );

            foreach ($companyQuestions as $index => $question) {
                $allQuestions[] = [
                    'question_text' => $question['question'] ?? '',
                    'type' => $question['type'] ?? 'behavioral',
                    'source' => 'company',
                    'expected_skills' => $question['expected_skills'] ?? $job->required_skills,
                    'evaluation_criteria' => $question['evaluation_criteria'] ?? ['clarity', 'relevance', 'depth'],
                    'time_allocation_seconds' => $question['time_allocation_seconds'] ?? 120,
                    'question_bank_index' => $index,
                ];
            }
        }

        $aiCount = $source === 'company_only'
            ? 0
            : max(0, $requiredCount - count($allQuestions));

        if ($aiCount > 0) {
            $aiQuestions = $llmService->generateQuestionsForJob(
                $job,
                null,
                $aiCount,
                [
                    'generation_scope' => 'legacy_company_question_bank_flow',
                    'excluded_questions' => array_values(array_filter(array_map(
                        fn (array $question): string => trim((string) ($question['question_text'] ?? '')),
                        $allQuestions
                    ))),
                ]
            );

            foreach ($aiQuestions as $question) {
                $allQuestions[] = [
                    'question_text' => $question['question_text'],
                    'type' => $question['type'] ?? 'general',
                    'source' => 'system',
                    'expected_skills' => $question['expected_skills'] ?? $job->required_skills,
                    'evaluation_criteria' => $question['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                    'time_allocation_seconds' => $question['time_allocation_seconds'] ?? 120,
                ];
            }
        }

        if ($job->question_order === 'random') {
            shuffle($allQuestions);
        }

        return array_slice($allQuestions, 0, $requiredCount);
    }

    /**
     * Generate AI questions based on job requirements
     */
    private function generateAIQuestions(CompanyJob $job, LLMService $llmService): array
    {
        $count = max(1, (int) $job->ai_questions_count);

        return $llmService->generateQuestionsForJob(
            $job,
            null,
            $count,
            [
                'generation_scope' => 'legacy_company_question_bank_flow',
            ]
        );
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
