<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(CandidateQuestionHistory::class, 'question_bank_id');
    }

    /**
     * Get all questions as collection
     */
    public function getQuestionsCollection(): \Illuminate\Support\Collection
    {
        return collect($this->questions);
    }

    /**
     * Get questions by difficulty
     */
    public function getQuestionsByDifficulty(?string $difficulty = null): \Illuminate\Support\Collection
    {
        $questions = $this->getQuestionsCollection();

        if ($difficulty) {
            return $questions->where('difficulty', $difficulty);
        }

        return $questions;
    }

    /**
     * Get random questions
     */
    public function getRandomQuestions(int $count, ?array $excludeIds = [], ?array $difficultyDistribution = null): array
    {
        $availableQuestions = $this->getQuestionsCollection();

        // Exclude already used questions
        if (!empty($excludeIds)) {
            $availableQuestions = $availableQuestions->reject(function ($q, $index) use ($excludeIds) {
                return in_array($index, $excludeIds);
            });
        }

        // If difficulty distribution is specified
        if ($difficultyDistribution && is_array($difficultyDistribution)) {
            return $this->getQuestionsByDifficultyDistribution($difficultyDistribution, $excludeIds);
        }

        // Simple random selection
        if ($availableQuestions->count() >= $count) {
            return $availableQuestions->random($count)->toArray();
        }

        // Not enough questions, fallback to all available + repeat from beginning
        $selected = $availableQuestions->toArray();
        $remaining = $count - count($selected);

        if ($remaining > 0) {
            $allQuestions = $this->getQuestionsCollection();
            $additional = $allQuestions->random($remaining)->toArray();
            $selected = array_merge($selected, $additional);
        }

        return $selected;
    }

    /**
     * Get questions based on difficulty distribution
     */
    private function getQuestionsByDifficultyDistribution(array $distribution, array $excludeIds = []): array
    {
        $selected = [];
        $usedIndices = $excludeIds;

        foreach ($distribution as $difficulty => $requiredCount) {
            $questions = $this->getQuestionsByDifficulty($difficulty);

            // Filter out used questions
            $available = $questions->reject(function ($q, $index) use ($usedIndices) {
                return in_array($index, $usedIndices);
            });

            $availableCount = $available->count();

            if ($availableCount >= $requiredCount) {
                $randomKeys = $available->random($requiredCount)->keys()->toArray();
                foreach ($randomKeys as $key) {
                    $selected[] = $questions[$key];
                    $usedIndices[] = $key;
                }
            } else {
                // Take all available from this difficulty
                foreach ($available as $index => $question) {
                    $selected[] = $question;
                    $usedIndices[] = $index;
                }

                // Fill remaining from any difficulty
                $remaining = $requiredCount - $availableCount;
                if ($remaining > 0) {
                    $allAvailable = $this->getQuestionsCollection()->reject(function ($q, $index) use ($usedIndices) {
                        return in_array($index, $usedIndices);
                    });

                    if ($allAvailable->count() >= $remaining) {
                        $additional = $allAvailable->random($remaining);
                        foreach ($additional as $index => $question) {
                            $selected[] = $question;
                            $usedIndices[] = $index;
                        }
                    }
                }
            }
        }

        return $selected;
    }
}
