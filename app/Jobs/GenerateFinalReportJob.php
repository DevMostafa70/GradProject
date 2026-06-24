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

    public $tries = 2;
    public $backoff = [60, 120];
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
        // ✅ شرط 1: التأكد من أن جميع الإجابات قد اكتملت
        if (!$this->interview->hasAllAnswersProcessed()) {
            Log::info('⏳ Not all answers processed yet, releasing job back to queue', [
                'interview_id' => $this->interview->id,
                'processed' => $this->interview->answers()->where('status', 'evaluated')->count(),
                'total' => $this->interview->questions()->count()
            ]);

            // أعد الـ job إلى queue بعد 5 ثواني
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

        Log::info('📊 Cheating calculated', [
            'interview_id' => $this->interview->id,
            'severity_score' => $cheatingSeverityScore,
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

        // 🔍 DEBUG: Check what we're about to save
        Log::info('📝 Attempting to save report with data:', [
            'interview_id' => $this->interview->id,
            'overall_score' => $reportData['overall_score'] ?? null,
            'adjusted_score' => $reportData['adjusted_score'] ?? null,
            'technical_score' => $reportData['technical_score'] ?? null,
            'communication_score' => $reportData['communication_score'] ?? null,
            'problem_solving_score' => $reportData['problem_solving_score'] ?? null
        ]);

        // Create or update final report
        try {
            $finalReport = FinalReport::updateOrCreate(
                ['interview_id' => $this->interview->id],
                [
                    'overall_score' => $reportData['overall_score'],
                    'adjusted_score' => $reportData['adjusted_score'],
                    'cheating_severity_score' => $cheatingSeverityScore,
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
                    'ai_raw_response' => $reportData['ai_raw_response'] ?? null,
                    'generated_at' => now(),
                ]
            );

            // Update interview status
            $this->interview->update([
                'status' => Interview::STATUS_COMPLETED_WITH_REPORT
            ]);

            // Broadcast WebSocket event
            broadcast(new FinalReportReady($this->interview, $finalReport))->toOthers();

            Log::info('Final report generated successfully', [
                'interview_id' => $this->interview->id,
                'overall_score' => $reportData['overall_score'],
                'adjusted_score' => $reportData['adjusted_score'],
                'cheating_severity' => $cheatingSeverityScore
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate final report', [
                'interview_id' => $this->interview->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // Update interview status
        $this->interview->update([
            'status' => Interview::STATUS_COMPLETED_WITH_REPORT
        ]);

        Log::info('✅ Interview status updated', [
            'interview_id' => $this->interview->id,
            'new_status' => Interview::STATUS_COMPLETED_WITH_REPORT
        ]);

        // Broadcast WebSocket event
        broadcast(new FinalReportReady($this->interview, $finalReport));

        Log::info('🎉 Final report generated successfully', [
            'interview_id' => $this->interview->id,
            'overall_score' => $reportData['overall_score'],
            'adjusted_score' => $reportData['adjusted_score'],
            'cheating_severity' => $cheatingSeverityScore
        ]);

    } catch (\Exception $e) {
        Log::error('💥 Failed to generate final report', [
            'interview_id' => $this->interview->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Mark interview as failed
        $this->interview->update([
            'status' => Interview::STATUS_FAILED,
            'metadata' => array_merge(
                $this->interview->metadata ?? [],
                ['report_generation_error' => $e->getMessage()]
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
        Log::error('GenerateFinalReportJob failed', [
            'interview_id' => $this->interview->id,
            'error' => $exception->getMessage()
        ]);

        // Notify admin or implement retry logic
    }
}
