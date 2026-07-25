<?php

namespace App\Services\CompanyInterview;

use App\Models\CompanyJob;
use App\Models\Interview;
use App\Models\Question;
use App\Services\LLMService;
use App\Services\QuestionBankService;
use RuntimeException;

class CompanyInterviewQuestionService
{
    public function __construct(
        private readonly LLMService $llmService,
        private readonly QuestionBankService $questionBankService,
    ) {
    }

    public function createQuestions(CompanyJob $job, Interview $interview): array
    {
        if ($interview->questions()->exists()) {
            return $interview->questions()->orderBy('order')->get()->all();
        }

        $locale = $job->normalizedInterviewLocale();
        $questions = match ($job->questions_source) {
            'ai_only' => $this->aiQuestions($job, (int) $job->number_of_questions, $locale),
            'company_only' => $this->companyQuestions($job, $interview, (int) $job->number_of_questions),
            default => $this->mixedQuestions($job, $interview, $locale),
        };

        $questions = array_values(array_filter(
            $questions,
            fn (array $question): bool => $this->hasQuestionText($question['question_text'] ?? null, $locale)
        ));

        if ($job->question_order === 'random') {
            shuffle($questions);
        }

        $requiredCount = max(1, (int) $job->number_of_questions);
        $questions = array_slice($questions, 0, $requiredCount);

        if (count($questions) < $requiredCount) {
            if ($job->questions_source === 'company_only') {
                throw new RuntimeException('The question bank does not contain enough valid questions for this interview.');
            }

            $missing = $requiredCount - count($questions);
            $questions = array_merge($questions, $this->aiQuestions($job, $missing, $locale));
        }

        $questions = array_values(array_filter(
            $questions,
            fn (array $question): bool => $this->hasQuestionText($question['question_text'] ?? null, $locale)
        ));

        if (count($questions) < $requiredCount) {
            throw new RuntimeException('Unable to prepare the required number of interview questions.');
        }

        $questions = array_slice($questions, 0, $requiredCount);

        $created = [];

        foreach ($questions as $index => $data) {
            $question = new Question();
            $question->forceFill([
                'interview_id' => $interview->id,
                // questions.job_id belongs to the legacy jobs table. The company job is linked through interviews.company_job_id.
                'job_id' => null,
                'question_text' => $this->localizedText($data['question_text'] ?? '', $locale),
                'time_allocation_seconds' => max(30, min(300, (int) ($data['time_allocation_seconds'] ?? 60))),
                'type' => $this->normalizeType($data['type'] ?? 'technical'),
                'expected_skills' => $data['expected_skills'] ?? $job->required_skills,
                'evaluation_criteria' => $data['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                'source' => $data['source'] ?? 'system',
                'order' => $index + 1,
                'status' => 'pending',
            ]);
            $question->save();
            $created[] = $question;
        }

        $interview->forceFill([
            'number_of_questions' => count($created),
            'current_question_id' => $created[0]->id ?? null,
        ])->save();

        return $created;
    }

    private function mixedQuestions(CompanyJob $job, Interview $interview, string $locale): array
    {
        $aiCount = max(0, (int) $job->ai_questions_count);
        $companyCount = max(0, (int) $job->company_questions_count);

        return array_merge(
            $this->companyQuestions($job, $interview, $companyCount),
            $this->aiQuestions($job, $aiCount, $locale),
        );
    }

    private function companyQuestions(CompanyJob $job, Interview $interview, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if (!$job->question_bank_id) {
            throw new RuntimeException('A question bank is required for company questions.');
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
                'expected_skills' => $question['expected_skills'] ?? $job->required_skills,
                'evaluation_criteria' => $question['evaluation_criteria'] ?? ['clarity', 'relevance', 'depth'],
                'time_allocation_seconds' => $question['time_allocation_seconds'] ?? 60,
            ];
        }, $selected);
    }

    private function aiQuestions(CompanyJob $job, int $count, string $locale): array
    {
        if ($count <= 0) {
            return [];
        }

        $skills = implode(', ', $job->required_skills ?? []);
        $jobTitle = $job->titleForLocale($locale);
        $jobDescription = $job->descriptionForLocale($locale);
        $language = $locale === 'ar' ? 'Arabic' : 'English';

        $prompt = <<<PROMPT
Generate exactly {$count} interview questions for the following company job.

Job title: {$jobTitle}
Job description: {$jobDescription}
Required skills: {$skills}
Difficulty: {$job->difficulty}
Language: {$language}

Return a JSON object with a "questions" array. Each item must contain:
- question_text
- type: technical, behavioral, situational, or general
- expected_skills: array
- evaluation_criteria: array
- time_allocation_seconds: integer between 30 and 300
PROMPT;

        $response = $this->llmService->generateQuestionsFromPrompt($prompt, $count, $locale);

        return array_map(function (array $question) use ($job): array {
            return [
                'question_text' => $question['question_text'] ?? '',
                'type' => $question['type'] ?? 'technical',
                'source' => 'system',
                'expected_skills' => $question['expected_skills'] ?? $job->required_skills,
                'evaluation_criteria' => $question['evaluation_criteria'] ?? ['clarity', 'depth', 'relevance'],
                'time_allocation_seconds' => $question['time_allocation_seconds'] ?? 60,
            ];
        }, $response['questions'] ?? []);
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
        if (is_string($text)) {
            return trim($text) !== '';
        }

        if (!is_array($text)) {
            return false;
        }

        $value = $text[$locale]
            ?? $text['en']
            ?? $text['ar']
            ?? collect($text)->first(fn ($item) => is_string($item));

        return is_string($value) && trim($value) !== '';
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        if ($type === 'hr') {
            return 'behavioral';
        }

        return in_array($type, ['technical', 'behavioral', 'situational', 'general'], true)
            ? $type
            : 'technical';
    }
}
