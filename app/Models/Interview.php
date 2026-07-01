<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use App\Enums\CheatingRiskLevel;


class Interview extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'candidate_id',
        'position',
        'experience_level',
        'difficulty',
        'skills',
        'number_of_questions',
        'status',
        'started_at',
        'completed_at',
        'metadata',
        // 🔹 NEW: Session Management
        'session_token',
        'expires_at',
        'last_activity_at',
        'current_question_id',
        'answered_questions_count',

        'active_session_id',
        'session_initialized_at',
        'device_fingerprint',
    ];

    protected $casts = [
        'skills' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        // 🔹 NEW
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'answered_questions_count' => 'integer',

        'session_initialized_at' => 'datetime',

    ];

    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PROCESSING_FINAL = 'processing_final';
    const STATUS_COMPLETED_WITH_REPORT = 'completed_with_report';
    const STATUS_FAILED = 'failed';
    const STATUS_EXPIRED = 'expired'; // 🔹 NEW

    // ==================== العلاقات ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('order');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function antiCheatLogs(): HasMany
    {
        return $this->hasMany(AntiCheatLog::class);
    }

    public function finalReport(): HasOne
    {
        return $this->hasOne(FinalReport::class);
    }

    public function currentQuestion()
    {
        return $this->belongsTo(Question::class, 'current_question_id');
    }

    // ==================== دوال الجلسة ====================

    /**
     * Generate a unique session token for the interview
     */
    public function generateSessionToken(): string
    {
        $token = Str::random(64);
        $this->session_token = $token;
        return $token;
    }

    /**
     * Set session expiration time (default: 60 minutes from now)
     */
    public function setExpiration(int $minutes = 60): self
    {
        $this->expires_at = now()->addMinutes($minutes);
        return $this;
    }

    /**
     * Check if the interview session is still valid (not expired)
     */
    public function isSessionValid(): bool
    {
        // If there's no expiration set, consider it valid
        if (!$this->expires_at) {
            return true;
        }

        // Check if session is expired
        if (now()->greaterThan($this->expires_at)) {
            return false;
        }

        return true;
    }

    /**
     * Check if session is expired
     */
    public function isSessionExpired(): bool
    {
        return !$this->isSessionValid();
    }

    /**
     * Update last activity timestamp
     */
    public function updateActivity(): self
    {
        $this->last_activity_at = now();
        return $this;
    }

    /**
     * Update current question and increment answered count
     */
    public function updateProgress(Question $question): self
    {
        $this->current_question_id = $question->id;
        $this->answered_questions_count = $this->answers()->count();
        $this->updateActivity();
        return $this;
    }

    /**
     * Get the next unanswered question
     */
    public function getNextQuestion(): ?Question
    {
        return $this->questions()
            ->where('status', Question::STATUS_PENDING)
            ->orderBy('order')
            ->first();
    }

    /**
     * Get session status as array
     */
    public function getSessionStatus(): array
    {
        $nextQuestion = $this->getNextQuestion();
        $totalQuestions = $this->questions()->count();
        $answeredCount = $this->answers()->count();

        return [
            'interview_id' => $this->id,
            'status' => $this->status,
            'session_token' => $this->session_token,
            'is_valid' => $this->isSessionValid(),
            'is_expired' => $this->isSessionExpired(),
            'expires_at' => $this->expires_at?->toISOString(),
            'expires_in_minutes' => $this->expires_at ? max(0, now()->diffInMinutes($this->expires_at)) : null,
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'total_questions' => $totalQuestions,
            'answered_count' => $answeredCount,
            'remaining_count' => max(0, $totalQuestions - $answeredCount),
            'current_question' => $nextQuestion ? [
                'id' => $nextQuestion->id,
                'order' => $nextQuestion->order,
                'text' => $nextQuestion->question_text,
                'type' => $nextQuestion->type,
            ] : null,
            'all_answered' => $answeredCount >= $totalQuestions,
            'progress_percentage' => $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100, 1) : 0,
        ];
    }

    // ==================== دوال أخرى ====================

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function hasAllAnswersProcessed(): bool
    {
        $totalQuestions = $this->questions()->count();
        $processedAnswers = $this->answers()->where('status', 'evaluated')->count();

        return $totalQuestions === $processedAnswers;
    }

    public function calculateCheatingSeverityScore(): float
    {
        $logs = $this->antiCheatLogs()->get();

        if ($logs->isEmpty()) {
            return 0;
        }

        $severityScore = 0;
        $weights = [
            'multiple_faces' => 5.0,
            'looking_away' => 2.0,
            'tab_switch' => 3.0,
            'window_blur' => 2.5,
            'suspicious_movement' => 2.0,
            'audio_anomaly' => 1.5,
            'device_change' => 4.0,
            'browser_console' => 3.5,
            'copy_paste_attempt' => 4.5,
            'screen_capture' => 5.0,
        ];

        foreach ($logs as $log) {
            $baseWeight = $weights[$log->violation_type] ?? 1.0;
            $severityScore += $baseWeight * $log->confidence_score * ($log->duration_seconds / 60);
        }

        return min(10, $severityScore / 10);
    }

    public function getViolationSummary(): array
    {
        return [
            'total_violations' => $this->antiCheatLogs()->count(),
            'by_type' => $this->antiCheatLogs()
                ->selectRaw('violation_type, COUNT(*) as count, AVG(confidence_score) as avg_confidence, SUM(duration_seconds) as total_duration')
                ->groupBy('violation_type')
                ->get()
                ->toArray(),
            'severity_score' => $this->calculateCheatingSeverityScore(),
        ];
    }

    // ==================== Company Relations ====================

    public function jobCandidate()
    {
        return $this->hasOne(CompanyJobCandidate::class);
    }

    public function isCompanyInterview(): bool
    {
        return $this->jobCandidate !== null;
    }

    // ==================== Resume Data ====================

    /**
     * Get complete resume data for an interview
     * Returns all data needed to resume an interrupted interview
     */
    public function getResumeData(): array
    {
        // Load all necessary relationships
        $this->loadMissing(['questions', 'answers.evaluation', 'answers.audioAnalysis']);

        $totalQuestions = $this->questions()->count();
        $answeredCount = $this->answers()->count();
        $nextQuestion = $this->getNextQuestion();

        return [
            'interview' => [
                'id' => $this->id,
                'position' => $this->position,
                'experience_level' => $this->experience_level,
                'difficulty' => $this->difficulty,
                'skills' => $this->skills,
                'number_of_questions' => $this->number_of_questions,
                'status' => $this->status,
                'started_at' => $this->started_at?->toISOString(),
                'completed_at' => $this->completed_at?->toISOString(),
                'created_at' => $this->created_at?->toISOString(),
            ],
            'session' => [
                'session_token' => $this->session_token,
                'expires_at' => $this->expires_at?->toISOString(),
                'expires_in_minutes' => $this->expires_at ? max(0, now()->diffInMinutes($this->expires_at)) : null,
                'is_valid' => $this->isSessionValid(),
                'is_expired' => $this->isSessionExpired(),
                'last_activity_at' => $this->last_activity_at?->toISOString(),
            ],
            'progress' => [
                'total_questions' => $totalQuestions,
                'answered_count' => $answeredCount,
                'remaining_count' => max(0, $totalQuestions - $answeredCount),
                'progress_percentage' => $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100, 1) : 0,
                'all_answered' => $answeredCount >= $totalQuestions,
            ],
            'current_question' => $nextQuestion ? [
                'id' => $nextQuestion->id,
                'order' => $nextQuestion->order,
                'text' => $nextQuestion->question_text,
                'type' => $nextQuestion->type,
            ] : null,
            'questions' => $this->questions->map(function ($question) {
                $answer = $this->answers->firstWhere('question_id', $question->id);

                return [
                    'id' => $question->id,
                    'order' => $question->order,
                    'text' => $question->question_text,
                    'type' => $question->type,
                    'expected_skills' => $question->expected_skills,
                    'evaluation_criteria' => $question->evaluation_criteria,
                    'status' => $question->status,
                    'answered_at' => $question->answered_at?->toISOString(),
                    'evaluated_at' => $question->evaluated_at?->toISOString(),
                    'answer' => $answer ? [
                        'id' => $answer->id,
                        'transcription' => $answer->status === 'evaluated' ? $answer->transcription : null,
                        'duration_seconds' => $answer->duration_seconds,
                        'status' => $answer->status,
                        'submitted_at' => $answer->submitted_at?->toISOString(),
                        'processed_at' => $answer->processed_at?->toISOString(),
                        'evaluation' => $answer->evaluation ? [
                            'id' => $answer->evaluation->id,
                            'score' => $answer->evaluation->score,
                            'adjusted_score' => $answer->evaluation->adjusted_score,
                            'criteria_scores' => $answer->evaluation->criteria_scores,
                            'strengths' => $answer->evaluation->strengths,
                            'weaknesses' => $answer->evaluation->weaknesses,
                            'detailed_feedback' => $answer->evaluation->detailed_feedback,
                            'clarity_score' => $answer->evaluation->clarity_score,
                            'relevance_score' => $answer->evaluation->relevance_score,
                            'depth_score' => $answer->evaluation->depth_score,
                            'confidence_score' => $answer->evaluation->confidence_score,
                            'cheating_penalty' => $answer->evaluation->cheating_penalty,
                        ] : null,
                        'audio_analysis' => $answer->audioAnalysis ? [
                            'speaking_rate' => $answer->audioAnalysis->speaking_rate,
                            'filler_word_count' => $answer->audioAnalysis->filler_word_count,
                            'confidence_level' => $answer->audioAnalysis->confidence_level,
                            'voice_stability' => $answer->audioAnalysis->voice_stability,
                        ] : null,
                    ] : null,
                ];
            }),
        ];
    }


        // ==================== Tab Lock ====================

    /**
     * The maximum duration a session can be considered active (minutes)
     * Default: 10 minutes of inactivity before auto-unlock
     */
    protected int $maxSessionInactivityMinutes = 10;

    /**
     * Lock the interview for a specific session
     */
    public function lock(string $sessionId, ?string $deviceFingerprint = null): self
    {
        $this->active_session_id = $sessionId;
        $this->session_initialized_at = now();
        if ($deviceFingerprint) {
            $this->device_fingerprint = $deviceFingerprint;
        }
        $this->save();
        return $this;
    }

    /**
     * Unlock the interview (release the lock)
     */
    public function unlock(): self
    {
        $this->active_session_id = null;
        $this->session_initialized_at = null;
        $this->save();
        return $this;
    }

    /**
     * Check if the interview is currently locked
     */
    public function isLocked(): bool
    {
        return !empty($this->active_session_id);
    }

    /**
     * Check if the current session owns the lock
     */
    public function isLockedBySession(string $sessionId): bool
    {
        return $this->active_session_id === $sessionId;
    }

    /**
     * Check if the lock is expired (session took too long)
     */
    public function isLockExpired(): bool
    {
        if (!$this->session_initialized_at) {
            return true;
        }

        return now()->diffInMinutes($this->session_initialized_at) > $this->maxSessionInactivityMinutes;
    }

    /**
     * Get the lock status as an array
     */
    public function getLockStatus(?string $currentSessionId = null): array
    {
        if (!$this->isLocked()) {
            return [
                'locked' => false,
                'message' => 'Interview is not locked',
                'can_access' => true,
            ];
        }

        // Check if lock is expired
        if ($this->isLockExpired()) {
            // Auto-unlock expired lock
            $this->unlock();
            return [
                'locked' => false,
                'message' => 'Previous session expired and was unlocked',
                'can_access' => true,
            ];
        }

        // Check if current session owns the lock
        if ($currentSessionId && $this->isLockedBySession($currentSessionId)) {
            return [
                'locked' => true,
                'message' => 'You already have this interview open in another tab/window',
                'can_access' => true,
                'owned_by_current' => true,
                'session_id' => $this->active_session_id,
                'initialized_at' => $this->session_initialized_at?->toISOString(),
            ];
        }

        // Locked by another session
        return [
            'locked' => true,
            'message' => 'This interview is currently open in another tab or window. Please close it and try again.',
            'can_access' => false,
            'owned_by_current' => false,
            'session_id' => $this->active_session_id,
            'initialized_at' => $this->session_initialized_at?->toISOString(),
        ];
    }

    /**
     * Refresh the lock (update the timestamp)
     */
    public function refreshLock(): self
    {
        if ($this->isLocked()) {
            $this->session_initialized_at = now();
            $this->save();
        }
        return $this;
    }

    /**
     * Get the maximum session inactivity time in minutes
     */
    public function getMaxSessionInactivityMinutes(): int
    {
        return $this->maxSessionInactivityMinutes;
    }



// ==================== Cheating Risk Level ====================

/**
 * Get the cheating risk level based on severity score
 */
public function getCheatingRiskLevel(): CheatingRiskLevel
{
    $score = $this->calculateCheatingSeverityScore();
    return CheatingRiskLevel::fromScore($score);
}

/**
 * Get the cheating risk level label
 */
public function getCheatingRiskLevelLabel(): string
{
    return $this->getCheatingRiskLevel()->label();
}

/**
 * Get the cheating risk level color
 */
public function getCheatingRiskLevelColor(): string
{
    return $this->getCheatingRiskLevel()->color();
}

/**
 * Get the cheating risk level description
 */
public function getCheatingRiskLevelDescription(): string
{
    return $this->getCheatingRiskLevel()->description();
}

/**
 * Get the cheating risk level recommendation
 */
public function getCheatingRiskLevelRecommendation(): string
{
    return $this->getCheatingRiskLevel()->recommendation();
}

/**
 * Get cheating risk data as array
 */
public function getCheatingRiskData(): array
{
    $riskLevel = $this->getCheatingRiskLevel();
    $violationSummary = $this->getViolationSummary();

    return [
        'severity_score' => $this->calculateCheatingSeverityScore(),
        'risk_level' => $riskLevel->value,
        'risk_label' => $riskLevel->label(),
        'risk_color' => $riskLevel->color(),
        'risk_description' => $riskLevel->description(),
        'recommendation' => $riskLevel->recommendation(),
        'penalty_multiplier' => $riskLevel->penaltyMultiplier(),
        'total_violations' => $violationSummary['total_violations'],
        'violations_by_type' => $violationSummary['by_type'] ?? [],
    ];
}
}
