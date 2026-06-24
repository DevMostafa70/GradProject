<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateQuestionHistory extends Model
{
    use HasFactory;

    protected $table = 'candidate_question_history';

    protected $fillable = [
        'candidate_id',
        'company_job_id',
        'question_bank_id',
        'question_text',
        'question_type',
        'question_difficulty',
        'score',
        'asked_at',
        'was_answered',
        'was_skipped',
        'time_to_answer',
        'metadata',
    ];

    protected $casts = [
        'asked_at' => 'datetime',
        'was_answered' => 'boolean',
        'was_skipped' => 'boolean',
        'metadata' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(CompanyJob::class, 'company_job_id');
    }

    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(CompanyQuestionBank::class, 'question_bank_id');
    }

    /**
     * Get used question IDs for a candidate on a specific job
     */
    public static function getUsedQuestionIds(int $candidateId, int $companyJobId): array
    {
        return self::where('candidate_id', $candidateId)
            ->where('company_job_id', $companyJobId)
            ->whereNotNull('question_bank_id')
            ->pluck('question_bank_id')
            ->toArray();
    }

    /**
     * Record that a question was asked to a candidate
     */
    public static function recordQuestion(
        int $candidateId,
        int $companyJobId,
        int $questionBankId,
        string $questionText,
        ?string $questionType = null,
        ?string $questionDifficulty = 'medium',
        ?float $score = null,
        ?int $timeToAnswer = null
    ): self {
        return self::create([
            'candidate_id' => $candidateId,
            'company_job_id' => $companyJobId,
            'question_bank_id' => $questionBankId,
            'question_text' => $questionText,
            'question_type' => $questionType,
            'question_difficulty' => $questionDifficulty,
            'score' => $score,
            'asked_at' => now(),
            'was_answered' => !is_null($score),
            'time_to_answer' => $timeToAnswer,
        ]);
    }
}
