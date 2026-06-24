<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CompanyJob extends Model
{
    use HasFactory;

    protected $table = 'company_jobs';

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'required_skills',
        'custom_questions',
        'questions_source',
        'number_of_questions',
        'difficulty',
        'max_candidates',
        'expires_at',
        'hide_score_from_candidate',
        'unique_token',
        'status',

        'question_bank_id',
        'ai_questions_count',
        'company_questions_count',
        'difficulty_distribution',
    ];

    protected $casts = [
        'required_skills' => 'array',
        'custom_questions' => 'array',
        'questions_source' => 'string',
        'expires_at' => 'datetime',
        'hide_score_from_candidate' => 'boolean',

        'difficulty_distribution' => 'array',

    ];


    //Event Listener
    protected static function booted()
    {
        static::creating(function ($job) {
            if (empty($job->unique_token)) {
                $job->unique_token = Str::random(32);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(CompanyJobCandidate::class, 'company_job_id');
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        //تاريخ الانتهاء
        if ($this->expires_at && $this->expires_at->isPast()) {
            $this->update(['status' => 'expired']);
            return false;
        }
        //حد أقصى للمرشحين
        if ($this->max_candidates) {
            $completedCount = $this->candidates()
                ->whereIn('status', ['completed', 'shortlisted', 'hired'])
                ->count();

            if ($completedCount >= $this->max_candidates) {
                $this->update(['status' => 'closed']);
                return false;
            }
        }

        return true;
    }

    //وصل عدد المرشحين إلى الحد الأقصى (كل المرشحين بغض النظر عن حالتهم)
    public function hasReachedMaxCandidates(): bool
    {
        if (!$this->max_candidates) {
            return false;
        }

        $totalCandidates = $this->candidates()->count();
        return $totalCandidates >= $this->max_candidates;
    }
    //إذا كان مرشح محدد قد تقدم بالفعل لهذا العنصر (وظيفة/دورة) أم لا
    public function hasCandidateApplied(int $candidateId): bool
    {
        return $this->candidates()
            ->where('candidate_id', $candidateId)
            ->exists();
    }

    public function getCandidatesRanked()
    {
        return $this->candidates()
            ->with(['candidate', 'interview'])
            ->whereNotNull('final_score')
            ->orderBy('final_score', 'desc')
            ->get();
    }

    public function getShareableLink(): string
    {
        return url("/interview/join/{$this->unique_token}");
    }

    public function invitations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailInvitation::class, 'company_job_id');
    }

    //شركات في بنك الأسئلة2 

    public function questionBank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CompanyQuestionBank::class, 'question_bank_id');
    }

    public function questionHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CandidateQuestionHistory::class, 'company_job_id');
    }

    /**
     * Get difficulty distribution as array
     */
    public function getDifficultyDistributionArray(): array
    {
        $default = ['easy' => 2, 'medium' => 2, 'hard' => 1];

        if (!$this->difficulty_distribution) {
            return $default;
        }

        return array_merge($default, $this->difficulty_distribution);
    }

    /**
     * Get total questions per candidate (AI + Company)
     */
    public function getTotalQuestionsPerCandidate(): int
    {
        return $this->ai_questions_count + $this->company_questions_count;
    }
}
