<?php

namespace App\Jobs;

use App\Events\FinalReportReady;
use App\Models\Answer;
use App\Models\FinalReport;
use App\Models\Interview;
use App\Services\LLMService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateFinalReportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 180, 300, 600, 900];
    public int $timeout = 600;
    public int $uniqueFor = 900;

    private const GENERATION_LOCK_STALE_AFTER_MINUTES = 15;

    public function __construct(
        protected int $interviewId
    ) {
        $this->onQueue('reports');
    }

    public function uniqueId(): string
    {
        return 'final-report:' . $this->interviewId;
    }

    public function handle(LLMService $llmService): void
    {
        $lockToken = null;
        $locale = 'en';

        try {
            $lockToken = $this->claimGenerationLock();

            if ($lockToken === null) {
                return;
            }

            $interview = Interview::query()->findOrFail($this->interviewId);
            $locale = $interview->normalizedLocale();
            app()->setLocale($locale);

            $interview->load([
                'questions',
                'answers.question',
                'answers.audioAnalysis',
                'answers.evaluation',
                'evaluations',
                'antiCheatLogs',
            ]);

            Log::info('Generating final report', [
                'interview_id' => $interview->id,
                'queue_attempt' => $this->attempts(),
                'locale' => $locale,
                'questions_count' => $interview->questions->count(),
                'answers_count' => $interview->answers->count(),
            ]);

            $answers = $interview->answers;
            $evaluations = $interview->evaluations;

            $violationSummary = $interview->getViolationSummary();
            $cheatingSeverityScore = $interview->calculateCheatingSeverityScore();
            $riskData = $interview->getCheatingRiskData();

            $violationsByType = [];

            foreach ($violationSummary['by_type'] ?? [] as $violation) {
                $type = $violation['violation_type'] ?? 'unknown';

                $violationsByType[$type] = [
                    'count' => (int) ($violation['count'] ?? 0),
                    'total_duration' => (float) ($violation['total_duration'] ?? 0),
                    'avg_confidence' => (float) ($violation['avg_confidence'] ?? 0),
                ];
            }

            $reportData = $llmService->generateFinalReport(
                $interview,
                $answers,
                $evaluations,
                $violationSummary,
                $cheatingSeverityScore
            );

            $this->validateReportData($reportData);

            $finalReport = $this->persistCompletedReport(
                $lockToken,
                $reportData,
                $riskData,
                $violationSummary,
                $violationsByType,
                $cheatingSeverityScore
            );

            // Broadcast immediately. The polling endpoint remains a fallback.
            broadcast(new FinalReportReady(
                Interview::query()->findOrFail($this->interviewId),
                $finalReport
            ));

            Log::info('Final report generated successfully', [
                'interview_id' => $this->interviewId,
                'report_id' => $finalReport->id,
                'overall_score' => $finalReport->overall_score,
                'adjusted_score' => $finalReport->adjusted_score,
                'queue_attempt' => $this->attempts(),
                'locale' => $locale,
            ]);
        } catch (Throwable $exception) {
            $this->releaseGenerationLockAfterError($lockToken, $exception);

            Log::error('Failed to generate final report', [
                'interview_id' => $this->interviewId,
                'queue_attempt' => $this->attempts(),
                'locale' => $locale,
                'max_attempts' => $this->tries,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Atomically claim the database-backed generation lock.
     *
     * The unique queue lock prevents normal duplicate jobs. The token stored
     * in metadata provides a second layer of protection across workers and
     * protects report persistence from stale/duplicate jobs.
     */
    private function claimGenerationLock(): ?string
    {
        return DB::transaction(function (): ?string {
            /** @var Interview|null $interview */
            $interview = Interview::query()
                ->lockForUpdate()
                ->find($this->interviewId);

            if (!$interview) {
                Log::warning('Final report job skipped: interview not found', [
                    'interview_id' => $this->interviewId,
                ]);

                return null;
            }

            if ($interview->finalReport()->exists()) {
                $this->markExistingReportAsCompleted($interview);

                Log::info('Final report job skipped: report already exists', [
                    'interview_id' => $this->interviewId,
                ]);

                return null;
            }

            $readiness = $this->readinessSnapshot($interview);

            if (!$readiness['all_requirements_ready']) {
                $metadata = is_array($interview->metadata)
                    ? $interview->metadata
                    : [];

                data_set($metadata, 'final_report.dispatch_status', 'waiting');
                data_set($metadata, 'final_report.waiting_snapshot', $readiness);
                data_set($metadata, 'final_report.waiting_checked_at', now()->toISOString());

                $interview->forceFill([
                    'status' => Interview::STATUS_COMPLETED,
                    'metadata' => $metadata,
                    'report_generation_started_at' => null,
                ])->save();

                Log::info('Final report job skipped: requirements are not ready', [
                    'interview_id' => $this->interviewId,
                    'readiness' => $readiness,
                ]);

                return null;
            }

            $metadata = is_array($interview->metadata)
                ? $interview->metadata
                : [];

            $existingToken = data_get($metadata, 'final_report.lock_token');
            $existingLockAt = data_get($metadata, 'final_report.lock_acquired_at');
            $lockIsFresh = false;

            if (is_string($existingToken) && $existingToken !== '' && is_string($existingLockAt)) {
                try {
                    $lockIsFresh = Carbon::parse($existingLockAt)
                        ->greaterThan(now()->subMinutes(self::GENERATION_LOCK_STALE_AFTER_MINUTES));
                } catch (Throwable) {
                    $lockIsFresh = false;
                }
            }

            if ($lockIsFresh) {
                Log::info('Final report job skipped: another worker owns generation lock', [
                    'interview_id' => $this->interviewId,
                    'lock_acquired_at' => $existingLockAt,
                ]);

                return null;
            }

            $lockToken = (string) Str::uuid();
            $attempts = ((int) $interview->report_generation_attempts) + 1;

            data_set($metadata, 'final_report.dispatch_status', 'processing');
            data_set($metadata, 'final_report.lock_token', $lockToken);
            data_set($metadata, 'final_report.lock_acquired_at', now()->toISOString());
            data_set($metadata, 'final_report.worker_attempt', $this->attempts());
            data_set($metadata, 'final_report.last_error', null);

            $interview->forceFill([
                'status' => Interview::STATUS_PROCESSING_FINAL,
                'metadata' => $metadata,
                'report_generation_started_at' => now(),
                'report_generation_completed_at' => null,
                'report_generation_attempts' => $attempts,
            ])->save();

            $locale = $interview->normalizedLocale();

            Log::info('Final report generation lock acquired', [
                'interview_id' => $this->interviewId,
                'database_attempts' => $attempts,
                'queue_attempt' => $this->attempts(),
                'locale' => $locale,
            ]);

            return $lockToken;
        }, 3);
    }

    private function persistCompletedReport(
        string $lockToken,
        array $reportData,
        array $riskData,
        array $violationSummary,
        array $violationsByType,
        float $cheatingSeverityScore
    ): FinalReport {
        return DB::transaction(function () use (
            $lockToken,
            $reportData,
            $riskData,
            $violationSummary,
            $violationsByType,
            $cheatingSeverityScore
        ): FinalReport {
            /** @var Interview $interview */
            $interview = Interview::query()
                ->lockForUpdate()
                ->findOrFail($this->interviewId);

            $existingReport = $interview->finalReport()->first();

            if ($existingReport) {
                $this->markExistingReportAsCompleted($interview);
                return $existingReport;
            }

            $metadata = is_array($interview->metadata)
                ? $interview->metadata
                : [];

            $currentToken = data_get($metadata, 'final_report.lock_token');

            if (!is_string($currentToken) || !hash_equals($currentToken, $lockToken)) {
                throw new \RuntimeException(
                    'Final report generation lock was lost before persistence.'
                );
            }

            $finalReport = FinalReport::query()->updateOrCreate(
                ['interview_id' => $interview->id],
                [
                    'overall_score' => $reportData['overall_score'],
                    'adjusted_score' => $reportData['adjusted_score'],
                    'cheating_severity_score' => $cheatingSeverityScore,
                    'cheating_risk_level' => $riskData['risk_level'] ?? 'unknown',
                    'cheating_risk_description' => $riskData['risk_description'] ?? null,
                    'cheating_recommendation' => $riskData['recommendation'] ?? null,
                    'violation_count_by_type' => $violationsByType,
                    'total_violations' => (int) ($violationSummary['total_violations'] ?? 0),
                    'violation_summary' => $violationSummary,
                    'skill_breakdown' => $reportData['skill_breakdown'],
                    'question_evaluations' => $reportData['question_evaluations'],
                    'executive_summary' => $reportData['executive_summary'],
                    'strengths_analysis' => $reportData['strengths_analysis'],
                    'improvement_areas' => $reportData['improvement_areas'],
                    'hiring_recommendation' => $reportData['hiring_recommendation'],
                    'technical_score' => $reportData['technical_score'],
                    'communication_score' => $reportData['communication_score'],
                    'problem_solving_score' => $reportData['problem_solving_score'],
                    'educational_summary' => $reportData['educational_summary'] ?? null,
                    'key_strengths' => $reportData['key_strengths'] ?? null,
                    'key_weaknesses' => $reportData['key_weaknesses'] ?? null,
                    'improvement_plan' => $reportData['improvement_plan'] ?? null,
                    'learning_resources' => $reportData['learning_resources'] ?? null,
                    'key_takeaways' => $reportData['key_takeaways'] ?? null,
                    'next_steps' => $reportData['next_steps'] ?? null,
                    'ai_raw_response' => $reportData['ai_raw_response'] ?? null,
                    'generated_at' => now(),
                ]
            );

            data_set($metadata, 'final_report.dispatch_status', 'completed');
            data_set($metadata, 'final_report.completed_at', now()->toISOString());
            data_set($metadata, 'final_report.report_id', $finalReport->id);
            data_forget($metadata, 'final_report.lock_token');
            data_forget($metadata, 'final_report.lock_acquired_at');
            data_forget($metadata, 'final_report.last_error');

            $interview->forceFill([
                'status' => Interview::STATUS_COMPLETED_WITH_REPORT,
                'metadata' => $metadata,
                'report_generation_completed_at' => now(),
            ])->save();

            return $finalReport->fresh();
        }, 3);
    }

    private function releaseGenerationLockAfterError(
        ?string $lockToken,
        Throwable $exception
    ): void {
        if ($lockToken === null) {
            return;
        }

        DB::transaction(function () use ($lockToken, $exception): void {
            $interview = Interview::query()
                ->lockForUpdate()
                ->find($this->interviewId);

            if (!$interview || $interview->finalReport()->exists()) {
                return;
            }

            $metadata = is_array($interview->metadata)
                ? $interview->metadata
                : [];

            $currentToken = data_get($metadata, 'final_report.lock_token');

            if (!is_string($currentToken) || !hash_equals($currentToken, $lockToken)) {
                return;
            }

            $willRetry = $this->attempts() < $this->tries;

            data_set(
                $metadata,
                'final_report.dispatch_status',
                $willRetry ? 'retrying' : 'failed'
            );
            data_set($metadata, 'final_report.last_error', $exception->getMessage());
            data_set($metadata, 'final_report.last_failed_at', now()->toISOString());
            data_set($metadata, 'final_report.failed_queue_attempt', $this->attempts());
            data_forget($metadata, 'final_report.lock_token');
            data_forget($metadata, 'final_report.lock_acquired_at');

            $interview->forceFill([
                'status' => $willRetry
                    ? Interview::STATUS_PROCESSING_FINAL
                    : Interview::STATUS_FAILED,
                'metadata' => $metadata,
                'report_generation_started_at' => null,
            ])->save();
        }, 3);
    }

    private function readinessSnapshot(Interview $interview): array
    {
        $totalQuestions = $interview->questions()->count();
        $totalAnswers = $interview->answers()->count();
        $evaluatedAnswers = $interview->answers()
            ->where('status', Answer::STATUS_EVALUATED)
            ->count();
        $failedAnswers = $interview->answers()
            ->where('status', Answer::STATUS_FAILED)
            ->count();
        $evaluationsCount = $interview->evaluations()->count();
        $audioAnalysesCount = $interview->answers()
            ->whereHas('audioAnalysis')
            ->count();

        return [
            'total_questions' => $totalQuestions,
            'total_answers' => $totalAnswers,
            'evaluated_answers' => $evaluatedAnswers,
            'failed_answers' => $failedAnswers,
            'evaluations_count' => $evaluationsCount,
            'audio_analyses_count' => $audioAnalysesCount,
            'all_requirements_ready' => $totalQuestions > 0
                && $failedAnswers === 0
                && $totalAnswers >= $totalQuestions
                && $evaluatedAnswers >= $totalQuestions
                && $evaluationsCount >= $totalQuestions
                && $audioAnalysesCount >= $totalQuestions,
        ];
    }

    private function validateReportData(array $reportData): void
    {
        $requiredKeys = [
            'overall_score',
            'adjusted_score',
            'skill_breakdown',
            'question_evaluations',
            'executive_summary',
            'strengths_analysis',
            'improvement_areas',
            'hiring_recommendation',
            'technical_score',
            'communication_score',
            'problem_solving_score',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $reportData)) {
                throw new \UnexpectedValueException(
                    "Final report data is missing required key: {$key}"
                );
            }
        }
    }

    private function markExistingReportAsCompleted(Interview $interview): void
    {
        $metadata = is_array($interview->metadata)
            ? $interview->metadata
            : [];

        data_set($metadata, 'final_report.dispatch_status', 'completed');
        data_set(
            $metadata,
            'final_report.completed_at',
            data_get($metadata, 'final_report.completed_at', now()->toISOString())
        );
        data_forget($metadata, 'final_report.lock_token');
        data_forget($metadata, 'final_report.lock_acquired_at');

        $interview->forceFill([
            'status' => Interview::STATUS_COMPLETED_WITH_REPORT,
            'metadata' => $metadata,
            'report_generation_completed_at' =>
                $interview->report_generation_completed_at ?? now(),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $interview = Interview::query()
                ->lockForUpdate()
                ->find($this->interviewId);

            if (!$interview || $interview->finalReport()->exists()) {
                return;
            }

            $metadata = is_array($interview->metadata)
                ? $interview->metadata
                : [];

            data_set($metadata, 'final_report.dispatch_status', 'failed');
            data_set($metadata, 'final_report.permanent_error', $exception->getMessage());
            data_set($metadata, 'final_report.permanently_failed_at', now()->toISOString());
            data_forget($metadata, 'final_report.lock_token');
            data_forget($metadata, 'final_report.lock_acquired_at');

            $interview->forceFill([
                'status' => Interview::STATUS_FAILED,
                'metadata' => $metadata,
                'report_generation_started_at' => null,
            ])->save();
        }, 3);

        Log::error('GenerateFinalReportJob failed permanently', [
            'interview_id' => $this->interviewId,
            'error' => $exception->getMessage(),
        ]);
    }
}