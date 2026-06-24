<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    ];

    protected $casts = [
        'skills' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PROCESSING_FINAL = 'processing_final';
    const STATUS_COMPLETED_WITH_REPORT = 'completed_with_report';
    const STATUS_FAILED = 'failed';

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

        // Normalize to 0-10 scale
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




    //company anas



    public function jobCandidate()
    {
        return $this->hasOne(CompanyJobCandidate::class);
    }

    public function isCompanyInterview(): bool
    {
        return $this->jobCandidate !== null;
    }
}
