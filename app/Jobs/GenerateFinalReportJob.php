<?php

namespace App\Jobs;

use App\Models\Interview;
use App\Models\FinalReport;
use App\Services\LLMService;
use App\Events\FinalReportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateFinalReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3; // 🔹 زيادة عدد المحاولات
    public $backoff = [60, 120, 300]; // 🔹 زيادة وقت الانتظار بين المحاولات
    public $timeout = 600;

    protected Interview $interview;

    /**
     * Create a new job instance.
     */
    public function __construct(Interview $interview)
    {
        $this->interview = $interview;
        $this->onQueue('reports');
    }

    /**
     * Execute the job.
     */
    public function handle(LLMService $llmService): void
    {
        try {
            // ============================================================
            // 🔹 NEW: Check if report is already generated
            // ============================================================
            if ($this->interview->isReportGenerated()) {
                Log::info('⏭️ Report already generated, skipping job', [
                    'interview_id' => $this->interview->id,
                    'report_exists' => $this->interview->finalReport()->exists(),
                    'completed_at' => $this->interview->report_generation_completed_at,
                ]);
                return;
            }

            // ============================================================
            // 🔹 NEW: Acquire report generation lock
            // ============================================================
            if (!$this->interview->acquireReportLock()) {
                Log::info('⏳ Report generation already in progress, releasing job', [
                    'interview_id' => $this->interview->id,
                    'started_at' => $this->interview->report_generation_started_at,
                    'attempts' => $this->interview->report_generation_attempts,
                ]);

                // If lock is held by another process, release this job to retry later
                $this->release(30);
                return;
            }

            Log::info('🔒 Report generation lock acquired', [
                'interview_id' => $this->interview->id,
                'attempts' => $this->interview->report_generation_attempts,
            ]);

            // ✅ شرط 1: التأكد من أن جميع الإجابات قد اكتملت
            if (!$this->interview->hasAllAnswersProcessed()) {
                Log::info('⏳ Not all answers processed yet, releasing job back to queue', [
                    'interview_id' => $this->interview->id,
                    'processed' => $this->interview->answers()->where('status', 'evaluated')->count(),
                    'total' => $this->interview->questions()->count()
                ]);

                // 🔹 NEW: Release lock and retry
                $this->interview->resetReportLock();
                $this->release(5);
                return;
            }

            // ✅ شرط 2: التأكد من وجود تقييمات (evaluations) لكل الإجابات
            $totalQuestions = $this->interview->questions()->count();
            $evaluationsCount = $this->interview->evaluations()->count();

            if ($evaluationsCount < $totalQuestions) {
                Log::info('⏳ Not all evaluations ready yet, releasing job back to queue', [
                    'interview_id' => $this->interview->id,
                    'evaluations_count' => $evaluationsCount,
                    'total_questions' => $totalQuestions
                ]);

                // 🔹 NEW: Release lock and retry
                $this->interview->resetReportLock();
                $this->release(3);
                return;
            }

            // ✅ شرط 3: التأكد من وجود تحليل صوتي (audio analysis) لكل الإجابات
            $answersWithAudioAnalysis = $this->interview->answers()
                ->whereHas('audioAnalysis')
                ->count();

            if ($answersWithAudioAnalysis < $totalQuestions) {
                Log::info('⏳ Not all audio analysis ready yet, releasing job back to queue', [
                    'interview_id' => $this->interview->id,
                    'with_audio_analysis' => $answersWithAudioAnalysis,
                    'total_answers' => $totalQuestions
                ]);

                // 🔹 NEW: Release lock and retry
                $this->interview->resetReportLock();
                $this->release(3);
                return;
            }

            Log::info('🎯 Generating final report', [
                'interview_id' => $this->interview->id
            ]);

            // Load all necessary relationships
            $this->interview->load([
                'questions',
                'answers.audioAnalysis',
                'answers.evaluation',
                'antiCheatLogs'
            ]);

            Log::info('✅ Data loaded', [
                'interview_id' => $this->interview->id,
                'questions_count' => $this->interview->questions->count(),
                'answers_count' => $this->interview->answers->count()
            ]);

            // Collect all data
            $answers = $this->interview->answers()->with(['question', 'evaluation', 'audioAnalysis'])->get();
            $evaluations = $this->interview->evaluations;

            // Calculate cheating severity
            $violationSummary = $this->interview->getViolationSummary();
            $cheatingSeverityScore = $this->interview->calculateCheatingSeverityScore();

            // ============================================================
            // 🔹 NEW: Calculate cheating risk data
            // ============================================================
            $riskData = $this->interview->getCheatingRiskData();

            // Get violations by type for detailed reporting
            $violationsByType = [];
            foreach ($violationSummary['by_type'] ?? [] as $violation) {
                $violationsByType[$violation['violation_type']] = [
                    'count' => $violation['count'],
                    'total_duration' => $violation['total_duration'],
                    'avg_confidence' => $violation['avg_confidence'],
                ];
            }

            Log::info('📊 Cheating calculated', [
                'interview_id' => $this->interview->id,
                'severity_score' => $cheatingSeverityScore,
                'risk_level' => $riskData['risk_level'],
                'risk_label' => $riskData['risk_label'],
                'total_violations' => $violationSummary['total_violations']
            ]);

            // Generate report using AI
            Log::info('🤖 Calling LLMService to generate report...');

            $reportData = $llmService->generateFinalReport(
                $this->interview,
                $answers,
                $evaluations,
                $violationSummary,
                $cheatingSeverityScore
            );

            Log::info('✅ LLMService returned report data', [
                'interview_id' => $this->interview->id,
                'data_keys' => array_keys($reportData)
            ]);

            // ============================================================
            // 🔹 NEW: Double-check that report wasn't created during generation
            // ============================================================
            if ($this->interview->isReportGenerated()) {
                Log::info('⏭️ Report was generated during processing, skipping save', [
                    'interview_id' => $this->interview->id,
                ]);
                $this->interview->releaseReportLock();
                return;
            }

            // 🔍 DEBUG: Check what we're about to save
            Log::info('📝 Attempting to save report with data:', [
                'interview_id' => $this->interview->id,
                'overall_score' => $reportData['overall_score'] ?? null,
                'adjusted_score' => $reportData['adjusted_score'] ?? null,
                'technical_score' => $reportData['technical_score'] ?? null,
                'communication_score' => $reportData['communication_score'] ?? null,
                'problem_solving_score' => $reportData['problem_solving_score'] ?? null,
                'risk_level' => $riskData['risk_level'],
                'risk_description' => $riskData['risk_description'],
            ]);

            // Create or update final report
            $finalReport = FinalReport::updateOrCreate(
                ['interview_id' => $this->interview->id],
                [
                    'overall_score' => $reportData['overall_score'],
                    'adjusted_score' => $reportData['adjusted_score'],
                    'cheating_severity_score' => $cheatingSeverityScore,
                    // 🔹 Cheating Risk Level fields
                    'cheating_risk_level' => $riskData['risk_level'],
                    'cheating_risk_description' => $riskData['risk_description'],
                    'cheating_recommendation' => $riskData['recommendation'],
                    'violation_count_by_type' => $violationsByType,
                    'total_violations' => $violationSummary['total_violations'],
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
                    // 🔹 Educational Fields
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

            // ============================================================
            // 🔹 NEW: Release the lock and mark as completed
            // ============================================================
            $this->interview->releaseReportLock();

            Log::info('✅ Final report saved to database', [
                'interview_id' => $this->interview->id,
                'report_id' => $finalReport->id,
                'risk_level' => $finalReport->cheating_risk_level,
            ]);

            // Update interview status
            $this->interview->update([
                'status' => Interview::STATUS_COMPLETED_WITH_REPORT
            ]);

            Log::info('✅ Interview status updated', [
                'interview_id' => $this->interview->id,
                'new_status' => Interview::STATUS_COMPLETED_WITH_REPORT
            ]);

            // Broadcast WebSocket event
            broadcast(new FinalReportReady($this->interview, $finalReport))->toOthers();

            Log::info('🎉 Final report generated successfully', [
                'interview_id' => $this->interview->id,
                'overall_score' => $reportData['overall_score'],
                'adjusted_score' => $reportData['adjusted_score'],
                'cheating_severity' => $cheatingSeverityScore,
                'risk_level' => $riskData['risk_level'],
                'risk_label' => $riskData['risk_label'],
                'attempts' => $this->interview->report_generation_attempts,
            ]);
        } catch (\Exception $e) {
            Log::error('💥 Failed to generate final report', [
                'interview_id' => $this->interview->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempts' => $this->interview->report_generation_attempts ?? 0,
            ]);

            // ============================================================
            // 🔹 NEW: Reset lock on failure (allow retry)
            // ============================================================
            $this->interview->resetReportLock();

            // Mark interview as failed
            $this->interview->update([
                'status' => Interview::STATUS_FAILED,
                'metadata' => array_merge(
                    $this->interview->metadata ?? [],
                    [
                        'report_generation_error' => $e->getMessage(),
                        'failed_at' => now()->toISOString(),
                        'attempts' => $this->interview->report_generation_attempts ?? 0,
                    ]
                )
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateFinalReportJob failed permanently', [
            'interview_id' => $this->interview->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->interview->report_generation_attempts ?? 0,
        ]);

        // ============================================================
        // 🔹 NEW: Ensure lock is released on permanent failure
        // ============================================================
        $this->interview->resetReportLock();

        // Update interview status
        $this->interview->update([
            'status' => Interview::STATUS_FAILED,
            'metadata' => array_merge(
                $this->interview->metadata ?? [],
                [
                    'report_generation_failed_permanently' => true,
                    'error' => $exception->getMessage(),
                    'failed_at' => now()->toISOString(),
                ]
            ),
        ]);
    }
}
