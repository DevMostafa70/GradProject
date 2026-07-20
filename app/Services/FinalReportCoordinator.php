<?php

namespace App\Services;

use App\Jobs\GenerateFinalReportJob;
use App\Models\Answer;
use App\Models\Interview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalReportCoordinator
{
    private const DISPATCH_STALE_AFTER_MINUTES = 15;

    /**
     * Check whether an interview is ready for final-report generation and,
     * when ready, queue exactly one idempotent generation job.
     *
     * This method is intentionally safe to call from multiple places:
     * - after the interview is completed;
     * - after each answer is evaluated;
     * - from the report-status polling endpoint as a self-healing fallback.
     */
    public function dispatchIfReady(
        int|Interview $interview,
        string $trigger = 'unknown'
    ): array {
        $interviewId = $interview instanceof Interview
            ? (int) $interview->getKey()
            : (int) $interview;

        $decision = DB::transaction(function () use ($interviewId, $trigger): array {
            /** @var Interview|null $lockedInterview */
            $lockedInterview = Interview::query()
                ->lockForUpdate()
                ->find($interviewId);

            if (!$lockedInterview) {
                return [
                    'should_dispatch' => false,
                    'dispatched' => false,
                    'ready' => false,
                    'reason' => 'interview_not_found',
                ];
            }

            if ($lockedInterview->finalReport()->exists()) {
                if ($lockedInterview->status !== Interview::STATUS_COMPLETED_WITH_REPORT) {
                    $lockedInterview->forceFill([
                        'status' => Interview::STATUS_COMPLETED_WITH_REPORT,
                        'report_generation_completed_at' =>
                        $lockedInterview->report_generation_completed_at ?? now(),
                    ])->save();
                }

                return [
                    'should_dispatch' => false,
                    'dispatched' => false,
                    'ready' => true,
                    'reason' => 'report_already_exists',
                    'status' => $lockedInterview->status,
                ];
            }

            $readiness = $this->readinessSnapshot($lockedInterview);

            $completionStates = [
                Interview::STATUS_COMPLETED,
                Interview::STATUS_PROCESSING_FINAL,
                // Allow self-healing after a previous report-generation failure.
                Interview::STATUS_FAILED,
            ];

            if (!in_array($lockedInterview->status, $completionStates, true)) {
                return array_merge($readiness, [
                    'should_dispatch' => false,
                    'dispatched' => false,
                    'ready' => false,
                    'reason' => 'interview_not_completed',
                    'status' => $lockedInterview->status,
                ]);
            }

            if ($readiness['failed_answers'] > 0) {
                return array_merge($readiness, [
                    'should_dispatch' => false,
                    'dispatched' => false,
                    'ready' => false,
                    'reason' => 'answer_processing_failed',
                    'status' => $lockedInterview->status,
                ]);
            }

            if (!$readiness['all_requirements_ready']) {
                return array_merge($readiness, [
                    'should_dispatch' => false,
                    'dispatched' => false,
                    'ready' => false,
                    'reason' => 'waiting_for_answer_processing',
                    'status' => $lockedInterview->status,
                ]);
            }

            $metadata = is_array($lockedInterview->metadata)
                ? $lockedInterview->metadata
                : [];

            $reportMetadata = data_get($metadata, 'final_report', []);
            $dispatchStatus = (string) data_get($reportMetadata, 'dispatch_status', '');
            $dispatchRequestedAt = data_get($reportMetadata, 'dispatch_requested_at');

            $recentDispatchExists = false;

            if (
                in_array($dispatchStatus, ['queued', 'processing', 'retrying'], true)
                && is_string($dispatchRequestedAt)
                && $dispatchRequestedAt !== ''
            ) {
                try {
                    $recentDispatchExists = Carbon::parse($dispatchRequestedAt)
                        ->greaterThan(now()->subMinutes(self::DISPATCH_STALE_AFTER_MINUTES));
                } catch (Throwable) {
                    $recentDispatchExists = false;
                }
            }

            if ($recentDispatchExists) {
                return array_merge($readiness, [
                    'should_dispatch' => false,
                    'dispatched' => false,
                    'ready' => false,
                    'reason' => 'generation_already_queued_or_running',
                    'status' => $lockedInterview->status,
                    'generation_state' => $dispatchStatus,
                ]);
            }

            data_set($metadata, 'final_report.dispatch_status', 'queued');
            data_set($metadata, 'final_report.dispatch_requested_at', now()->toISOString());
            data_set($metadata, 'final_report.dispatch_trigger', $trigger);
            data_set($metadata, 'final_report.last_dispatch_error', null);

            $lockedInterview->forceFill([
                'status' => Interview::STATUS_PROCESSING_FINAL,
                'metadata' => $metadata,
            ])->save();

            return array_merge($readiness, [
                'should_dispatch' => true,
                'dispatched' => false,
                'ready' => false,
                'reason' => 'ready_to_queue',
                'status' => Interview::STATUS_PROCESSING_FINAL,
            ]);
        }, 3);

        if (!($decision['should_dispatch'] ?? false)) {
            unset($decision['should_dispatch']);
            return $decision;
        }

        try {
            GenerateFinalReportJob::dispatch($interviewId)
                ->onQueue('reports');

            Log::info('Final report generation queued by coordinator', [
                'interview_id' => $interviewId,
                'trigger' => $trigger,
                'readiness' => $decision,
            ]);

            $decision['dispatched'] = true;
            $decision['reason'] = 'queued';
        } catch (Throwable $exception) {
            $this->markDispatchFailure($interviewId, $exception);

            Log::error('Failed to queue final report generation', [
                'interview_id' => $interviewId,
                'trigger' => $trigger,
                'error' => $exception->getMessage(),
            ]);

            $decision['dispatched'] = false;
            $decision['reason'] = 'queue_dispatch_failed';
            $decision['error'] = $exception->getMessage();
        }

        unset($decision['should_dispatch']);

        return $decision;
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

        $allRequirementsReady = $totalQuestions > 0
            && $totalAnswers >= $totalQuestions
            && $evaluatedAnswers >= $totalQuestions
            && $evaluationsCount >= $totalQuestions
            && $audioAnalysesCount >= $totalQuestions;

        return [
            'total_questions' => $totalQuestions,
            'total_answers' => $totalAnswers,
            'evaluated_answers' => $evaluatedAnswers,
            'failed_answers' => $failedAnswers,
            'evaluations_count' => $evaluationsCount,
            'audio_analyses_count' => $audioAnalysesCount,
            'all_requirements_ready' => $allRequirementsReady,
        ];
    }

    private function markDispatchFailure(int $interviewId, Throwable $exception): void
    {
        DB::transaction(function () use ($interviewId, $exception): void {
            $interview = Interview::query()
                ->lockForUpdate()
                ->find($interviewId);

            if (!$interview || $interview->finalReport()->exists()) {
                return;
            }

            $metadata = is_array($interview->metadata)
                ? $interview->metadata
                : [];

            data_set($metadata, 'final_report.dispatch_status', 'dispatch_failed');
            data_set($metadata, 'final_report.last_dispatch_error', $exception->getMessage());
            data_set($metadata, 'final_report.dispatch_failed_at', now()->toISOString());

            $interview->forceFill([
                // Return to completed so a later polling/status request can self-heal.
                'status' => Interview::STATUS_COMPLETED,
                'metadata' => $metadata,
            ])->save();
        }, 3);
    }
}
