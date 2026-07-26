<?php

namespace App\Services\CompanyInterview;

use App\Models\CompanyJob;
use App\Models\Interview;
use App\Models\Question;
use App\Services\LLMService;
use App\Services\QuestionBankService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompanyInterviewQuestionService
{
    public function __construct(
        private readonly LLMService $llmService,
        private readonly QuestionBankService $questionBankService,
    ) {
    }

    /**
     * Create the questions for a company-candidate interview.
     *
     * AI questions use the exact same LLMService pipeline as normal-user
     * interviews. Company-bank questions remain available for company_only and
     * mixed jobs, but they are normalized and persisted using the same schema.
     */
    public function createQuestions(CompanyJob $job, Interview $interview): array
    {
        if ($interview->questions()->exists()) {
            return $interview->questions()->orderBy('order')->get()->all();
        }

        $locale = $interview->normalizedLocale();
        $requiredCount = max(
            1,
            (int) ($interview->number_of_questions ?: $job->getTotalQuestionsPerCandidate())
        );

        $questions = match ($job->questions_source) {
            'ai_only' => $this->aiQuestions(
                $job,
                $interview,
                $requiredCount,
                $locale
            ),
            'company_only' => $this->companyQuestions(
                $job,
                $interview,
                $requiredCount
            ),
            default => $this->mixedQuestions(
                $job,
                $interview,
                $requiredCount,
                $locale
            ),
        };

        $questions = $this->deduplicateQuestions(
            array_values(array_filter(
                $questions,
                fn (array $question): bool => $this->hasQuestionText(
                    $question['question_text'] ?? null,
                    $locale
                )
            )),
            $locale
        );

        if ($job->question_order === 'random') {
            shuffle($questions);
        }

        $questions = array_slice($questions, 0, $requiredCount);

        if (count($questions) < $requiredCount) {
            if ($job->questions_source === 'company_only') {
                throw new RuntimeException(
                    'The question bank does not contain enough valid questions for this interview.'
                );
            }

            $missingCount = $requiredCount - count($questions);
            $excludedQuestions = array_map(
                fn (array $question): string => $this->questionText(
                    $question['question_text'] ?? '',
                    $locale
                ),
                $questions
            );

            $questions = array_merge(
                $questions,
                $this->aiQuestions(
                    $job,
                    $interview,
                    $missingCount,
                    $locale,
                    $excludedQuestions
                )
            );

            $questions = $this->deduplicateQuestions($questions, $locale);
        }

        if (count($questions) < $requiredCount) {
            throw new RuntimeException(
                'Unable to prepare the required number of interview questions.'
            );
        }

        $questions = array_slice($questions, 0, $requiredCount);

        // The OpenAI call has already completed. Persist only the database
        // changes inside a short transaction so no database lock is held while
        // waiting for the external AI service.
        return DB::transaction(function () use (
            $interview,
            $job,
            $questions,
            $locale
        ): array {
            $lockedInterview = Interview::query()
                ->lockForUpdate()
                ->findOrFail($interview->id);

            if ($lockedInterview->questions()->exists()) {
                return $lockedInterview->questions()
                    ->orderBy('order')
                    ->get()
                    ->all();
            }

            $created = [];

            foreach ($questions as $index => $data) {
                $question = new Question();
                $question->forceFill([
                    'interview_id' => $lockedInterview->id,
                    // questions.job_id belongs to the legacy jobs table. The
                    // company job is linked through interviews.company_job_id.
                    'job_id' => null,
                    'question_text' => $this->localizedText(
                        $data['question_text'] ?? '',
                        $locale
                    ),
                    // Keep the same timing range used by normal-user interviews.
                    'time_allocation_seconds' => max(
                        45,
                        min(600, (int) ($data['time_allocation_seconds'] ?? 120))
                    ),
                    'type' => $this->normalizeType($data['type'] ?? 'general'),
                    'expected_skills' => is_array($data['expected_skills'] ?? null)
                        ? array_values($data['expected_skills'])
                        : array_values($job->required_skills ?? []),
                    'evaluation_criteria' => is_array($data['evaluation_criteria'] ?? null)
                        && !empty($data['evaluation_criteria'])
                            ? $data['evaluation_criteria']
                            : ['clarity', 'depth', 'relevance'],
                    'source' => ($data['source'] ?? 'system') === 'company'
                        ? 'company'
                        : 'system',
                    'order' => $index + 1,
                    'status' => Question::STATUS_PENDING,
                ]);
                $question->save();
                $created[] = $question;
            }

            $lockedInterview->forceFill([
                'number_of_questions' => count($created),
                'current_question_id' => $created[0]->id ?? null,
            ])->save();

            return $created;
        }, 3);
    }

    private function mixedQuestions(
        CompanyJob $job,
        Interview $interview,
        int $requiredCount,
        string $locale
    ): array {
        $configuredCompanyCount = max(0, (int) $job->company_questions_count);
        $configuredAiCount = max(0, (int) $job->ai_questions_count);

        if ($configuredCompanyCount === 0 && $configuredAiCount === 0) {
            $configuredCompanyCount = min(2, $requiredCount);
            $configuredAiCount = max(0, $requiredCount - $configuredCompanyCount);
        }

        $companyCount = min($configuredCompanyCount, $requiredCount);
        $aiCount = min($configuredAiCount, max(0, $requiredCount - $companyCount));

        // If configured counts do not fill the interview, complete the missing
        // portion with AI questions through the normal-user pipeline.
        if ($companyCount + $aiCount < $requiredCount) {
            $aiCount += $requiredCount - ($companyCount + $aiCount);
        }

        $companyQuestions = $companyCount > 0 && $job->question_bank_id
            ? $this->companyQuestions($job, $interview, $companyCount)
            : [];

        // Generate enough AI questions to cover any missing company-bank
        // questions as well as the configured AI portion.
        $aiCount = max($aiCount, $requiredCount - count($companyQuestions));

        $excludedQuestions = array_map(
            fn (array $question): string => $this->questionText(
                $question['question_text'] ?? '',
                $locale
            ),
            $companyQuestions
        );

        $aiQuestions = $this->aiQuestions(
            $job,
            $interview,
            $aiCount,
            $locale,
            $excludedQuestions
        );

        return $this->interleaveQuestions($aiQuestions, $companyQuestions);
    }

    private function companyQuestions(
        CompanyJob $job,
        Interview $interview,
        int $count
    ): array {
        if ($count <= 0) {
            return [];
        }

        if (!$job->question_bank_id) {
            throw new RuntimeException(
                'A question bank is required for company questions.'
            );
        }

        $selected = $this->questionBankService->selectRandomQuestions(
            $job,
            (int) $interview->candidate_id,
            $count
        );

        return array_map(function (array $question) use ($job): array {
            return [
                'question_text' => $question['question'] ?? '',
                'type' => $question['type'] ?? 'behavioral',
                'source' => 'company',
                'expected_skills' => $question['expected_skills']
                    ?? $job->required_skills,
                'evaluation_criteria' => $question['evaluation_criteria']
                    ?? ['clarity', 'relevance', 'depth'],
                'time_allocation_seconds' => $question['time_allocation_seconds']
                    ?? 120,
            ];
        }, $selected);
    }

    private function aiQuestions(
        CompanyJob $job,
        Interview $interview,
        int $count,
        string $locale,
        array $excludedQuestions = []
    ): array {
        if ($count <= 0) {
            return [];
        }

        return $this->llmService->generateQuestionsForJob(
            $job,
            $interview,
            $count,
            [
                'generation_scope' => 'company_candidate_interview',
                'excluded_questions' => $excludedQuestions,
                'interview_locale' => $locale,
            ]
        );
    }

    private function interleaveQuestions(
        array $aiQuestions,
        array $companyQuestions
    ): array {
        $result = [];
        $maximum = max(count($aiQuestions), count($companyQuestions));

        for ($index = 0; $index < $maximum; $index++) {
            if (isset($aiQuestions[$index])) {
                $result[] = $aiQuestions[$index];
            }

            if (isset($companyQuestions[$index])) {
                $result[] = $companyQuestions[$index];
            }
        }

        return $result;
    }

    private function deduplicateQuestions(array $questions, string $locale): array
    {
        $seen = [];
        $unique = [];

        foreach ($questions as $question) {
            $text = $this->questionText(
                $question['question_text'] ?? '',
                $locale
            );
            $normalized = mb_strtolower(trim($text));
            $normalized = preg_replace(
                '/[^\p{L}\p{N}\s]+/u',
                ' ',
                $normalized
            ) ?? $normalized;
            $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
            $normalized = trim($normalized);

            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[] = $question;
        }

        return $unique;
    }

    private function localizedText(mixed $text, string $locale): array
    {
        if (is_array($text)) {
            return $text;
        }

        return [$locale => trim((string) $text)];
    }

    private function hasQuestionText(mixed $text, string $locale): bool
    {
        return $this->questionText($text, $locale) !== '';
    }

    private function questionText(mixed $text, string $locale): string
    {
        if (is_string($text)) {
            return trim($text);
        }

        if (!is_array($text)) {
            return '';
        }

        $value = $text[$locale]
            ?? $text['en']
            ?? $text['ar']
            ?? collect($text)->first(fn ($item) => is_string($item));

        return is_string($value) ? trim($value) : '';
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        if ($type === 'hr') {
            return 'behavioral';
        }

        return in_array(
            $type,
            ['technical', 'behavioral', 'situational', 'general'],
            true
        ) ? $type : 'general';
    }
}
