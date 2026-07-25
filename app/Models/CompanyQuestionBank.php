<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CompanyQuestionBank extends Model
{
    use HasFactory;

    protected $table = 'company_question_banks';

    protected $fillable = [
        'company_job_id',
        'questions',
        'total_questions',
    ];

    protected $casts = [
        'questions' => 'array',
        'total_questions' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(
            CandidateQuestionHistory::class,
            'question_bank_id'
        );
    }

    /**
     * Return the stored questions while preserving their original indexes.
     *
     * Preserving indexes is important because candidate question history stores
     * the question-bank index and uses it to prevent repeating questions.
     */
    public function getQuestionsCollection(): Collection
    {
        $questions = is_array($this->questions)
            ? $this->questions
            : [];

        return collect($questions)->filter(
            static fn (mixed $question): bool => is_array($question)
        );
    }

    /**
     * Return questions matching a difficulty while preserving original indexes.
     */
    public function getQuestionsByDifficulty(?string $difficulty = null): Collection
    {
        $questions = $this->getQuestionsCollection();

        $difficulty = $this->normalizeDifficulty($difficulty);

        if ($difficulty === null) {
            return $questions;
        }

        return $questions->filter(function (array $question) use ($difficulty): bool {
            $questionDifficulty = $this->normalizeDifficulty(
                $question['difficulty'] ?? 'medium'
            );

            return $questionDifficulty === $difficulty;
        });
    }

    /**
     * Select random questions without repeating excluded question-bank indexes.
     *
     * If fewer questions are available than requested, this method returns only
     * the available questions. The caller can then use AI fallback questions or
     * return a clear validation error for company-only interviews.
     */
    public function getRandomQuestions(
        int $count,
        ?array $excludeIds = [],
        ?array $difficultyDistribution = null
    ): array {
        $count = max(0, $count);

        if ($count === 0) {
            return [];
        }

        $excludedIndexes = $this->normalizeExcludedIndexes($excludeIds ?? []);

        $availableQuestions = $this->getQuestionsCollection()
            ->reject(
                static fn (array $question, int|string $index): bool =>
                    isset($excludedIndexes[(string) $index])
            );

        if ($availableQuestions->isEmpty()) {
            return [];
        }

        if (is_array($difficultyDistribution) && $difficultyDistribution !== []) {
            return $this->getQuestionsByDifficultyDistribution(
                $availableQuestions,
                $difficultyDistribution,
                $count
            );
        }

        return $this->takeRandom($availableQuestions, $count)
            ->values()
            ->all();
    }

    /**
     * Select questions using a difficulty distribution.
     *
     * The configured distribution can describe a larger interview than the
     * requested company-question count. Therefore, it is converted into
     * proportional allocations whose total is exactly the requested count.
     */
    private function getQuestionsByDifficultyDistribution(
        Collection $availableQuestions,
        array $distribution,
        int $requestedCount
    ): array {
        $allocations = $this->buildDifficultyAllocations(
            $distribution,
            $requestedCount
        );

        if ($allocations === []) {
            return $this->takeRandom($availableQuestions, $requestedCount)
                ->values()
                ->all();
        }

        $remainingPool = $availableQuestions;
        $selected = collect();

        foreach ($allocations as $difficulty => $requiredCount) {
            if ($requiredCount <= 0 || $remainingPool->isEmpty()) {
                continue;
            }

            $difficultyPool = $remainingPool->filter(function (array $question) use ($difficulty): bool {
                $questionDifficulty = $this->normalizeDifficulty(
                    $question['difficulty'] ?? 'medium'
                );

                return $questionDifficulty === $difficulty;
            });

            $picked = $this->takeRandom($difficultyPool, $requiredCount);

            foreach ($picked as $index => $question) {
                $selected->push($question);
                $remainingPool->forget($index);
            }
        }

        /*
         * If one difficulty did not contain enough questions, fill the missing
         * positions from the remaining questions of any difficulty.
         */
        $missingCount = $requestedCount - $selected->count();

        if ($missingCount > 0 && $remainingPool->isNotEmpty()) {
            $additional = $this->takeRandom($remainingPool, $missingCount);

            foreach ($additional as $question) {
                $selected->push($question);
            }
        }

        return $selected
            ->take($requestedCount)
            ->values()
            ->all();
    }

    /**
     * Convert difficulty weights/counts into allocations that add up to the
     * exact number of requested questions.
     */
    private function buildDifficultyAllocations(
        array $distribution,
        int $requestedCount
    ): array {
        $requestedCount = max(0, $requestedCount);

        if ($requestedCount === 0) {
            return [];
        }

        $weights = [];

        foreach ($distribution as $difficulty => $weight) {
            $normalizedDifficulty = $this->normalizeDifficulty(
                is_string($difficulty) ? $difficulty : null
            );

            $numericWeight = is_numeric($weight)
                ? max(0.0, (float) $weight)
                : 0.0;

            if ($normalizedDifficulty !== null && $numericWeight > 0) {
                $weights[$normalizedDifficulty] =
                    ($weights[$normalizedDifficulty] ?? 0.0) + $numericWeight;
            }
        }

        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            return [];
        }

        $allocations = [];
        $fractions = [];
        $allocatedCount = 0;

        foreach ($weights as $difficulty => $weight) {
            $exactAllocation = ($weight / $totalWeight) * $requestedCount;
            $baseAllocation = (int) floor($exactAllocation);

            $allocations[$difficulty] = $baseAllocation;
            $fractions[$difficulty] = $exactAllocation - $baseAllocation;
            $allocatedCount += $baseAllocation;
        }

        $remainingCount = $requestedCount - $allocatedCount;

        if ($remainingCount > 0) {
            arsort($fractions, SORT_NUMERIC);

            foreach (array_keys($fractions) as $difficulty) {
                if ($remainingCount <= 0) {
                    break;
                }

                $allocations[$difficulty]++;
                $remainingCount--;
            }
        }

        return $allocations;
    }

    /**
     * Return up to $count random items while preserving original collection keys.
     */
    private function takeRandom(Collection $questions, int $count): Collection
    {
        $takeCount = min(max(0, $count), $questions->count());

        if ($takeCount === 0) {
            return collect();
        }

        return $questions->random($takeCount);
    }

    /**
     * Create a lookup table for excluded question-bank indexes.
     */
    private function normalizeExcludedIndexes(array $excludeIds): array
    {
        $lookup = [];

        foreach ($excludeIds as $index) {
            if (is_int($index) || is_string($index)) {
                $lookup[(string) $index] = true;
            }
        }

        return $lookup;
    }

    private function normalizeDifficulty(?string $difficulty): ?string
    {
        if ($difficulty === null) {
            return null;
        }

        $difficulty = strtolower(trim($difficulty));

        return in_array($difficulty, ['easy', 'medium', 'hard'], true)
            ? $difficulty
            : null;
    }
}