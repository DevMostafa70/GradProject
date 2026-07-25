<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CompanyJob extends Model
{
    use HasFactory, HasTranslations;

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
        'interview_locale',
        'question_order',
        'max_candidates',
        'expires_at',
        'invitation_valid_hours',
        'max_resume_count',
        'interview_duration_minutes',
        'random_snapshot_count',
        'liveness_challenge_count',
        'identity_verification_required',
        'identity_document_required',
        'liveness_required',
        'delete_identity_evidence_after_review',
        'interview_instructions',
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
        'difficulty_distribution' => 'array',
        'interview_instructions' => 'array',
        'title' => 'array',
        'description' => 'array',
        'expires_at' => 'datetime',
        'hide_score_from_candidate' => 'boolean',
        'identity_verification_required' => 'boolean',
        'identity_document_required' => 'boolean',
        'liveness_required' => 'boolean',
        'delete_identity_evidence_after_review' => 'boolean',
        'number_of_questions' => 'integer',
        'ai_questions_count' => 'integer',
        'company_questions_count' => 'integer',
        'max_candidates' => 'integer',
        'invitation_valid_hours' => 'integer',
        'max_resume_count' => 'integer',
        'interview_duration_minutes' => 'integer',
        'random_snapshot_count' => 'integer',
        'liveness_challenge_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (CompanyJob $job): void {
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

    public function candidateProfiles(): HasMany
    {
        return $this->hasMany(Candidate::class, 'company_job_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(EmailInvitation::class, 'company_job_id');
    }

    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(CompanyQuestionBank::class, 'question_bank_id');
    }

    public function questionHistory(): HasMany
    {
        return $this->hasMany(CandidateQuestionHistory::class, 'company_job_id');
    }

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(CandidateIdentityVerification::class, 'company_job_id');
    }

    public function normalizedInterviewLocale(): string
    {
        $locale = strtolower(str_replace('_', '-', (string) ($this->interview_locale ?: 'en')));
        $locale = explode('-', $locale)[0] ?? 'en';

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'en';
    }

    public function titleForLocale(?string $locale = null): string
    {
        return $this->translatedValue($this->title, $locale ?? $this->normalizedInterviewLocale());
    }

    public function descriptionForLocale(?string $locale = null): string
    {
        return $this->translatedValue($this->description, $locale ?? $this->normalizedInterviewLocale());
    }

    public function instructionsForLocale(?string $locale = null): ?string
    {
        if (empty($this->interview_instructions)) {
            return null;
        }

        return $this->translatedValue(
            $this->interview_instructions,
            $locale ?? $this->normalizedInterviewLocale()
        );
    }

    public function invitationExpiresAt(?Carbon $sentAt = null): Carbon
    {
        $sentAt ??= now();

        return $sentAt->copy()->addHours(max(1, (int) $this->invitation_valid_hours));
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            $this->update(['status' => 'expired']);
            return false;
        }

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

    public function hasReachedMaxCandidates(): bool
    {
        return $this->max_candidates !== null
            && $this->candidates()->count() >= (int) $this->max_candidates;
    }

    public function hasCandidateApplied(int $candidateId): bool
    {
        return $this->candidates()->where('candidate_id', $candidateId)->exists();
    }

    public function getCandidatesRanked()
    {
        return $this->candidates()
            ->with(['candidate', 'interview'])
            ->whereNotNull('final_score')
            ->orderByDesc('final_score')
            ->get();
    }

    public function getShareableLink(): string
    {
        return url("/interview/join/{$this->unique_token}");
    }

    public function getDifficultyDistributionArray(): array
    {
        $default = ['easy' => 2, 'medium' => 2, 'hard' => 1];

        return array_merge($default, $this->difficulty_distribution ?? []);
    }

    public function getTotalQuestionsPerCandidate(): int
    {
        return match ($this->questions_source) {
            'ai_only' => max(1, (int) ($this->ai_questions_count ?: $this->number_of_questions)),
            'company_only' => max(1, (int) ($this->company_questions_count ?: $this->number_of_questions)),
            default => max(
                1,
                (int) $this->ai_questions_count + (int) $this->company_questions_count
            ),
        };
    }

    private function translatedValue(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                return $value;
            }
        }

        if (!is_array($value)) {
            return '';
        }

        return (string) (
            $value[$locale]
            ?? $value['en']
            ?? $value['ar']
            ?? collect($value)->first(fn ($item) => is_string($item))
            ?? ''
        );
    }
}
