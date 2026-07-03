<?php

namespace App\Jobs;

use App\Models\Answer;
use App\Models\Interview;
use App\Models\Question;
use App\Models\Evaluation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckPendingEvaluationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $backoff = [60, 120];
    public $timeout = 300;

    /**
     * Execute the job.
     *
     * ✅ التحقق الدوري من المقابلات المكتملة التي لديها أسئلة غير مقيمة
     */
    public function handle(): void
    {
        Log::info('🔄 Running CheckPendingEvaluationsJob');

        try {
            // ✅ جلب المقابلات المكتملة التي لديها أسئلة غير مقيمة منذ أكثر من ساعة
            $interviews = Interview::where('status', Interview::STATUS_COMPLETED)
                ->where('completed_at', '<=', now()->subHour())
                ->whereHas('questions', function ($query) {
                    $query->where('status', '!=', Question::STATUS_EVALUATED);
                })
                ->get();

            if ($interviews->isEmpty()) {
                Log::info('✅ No pending evaluations found');
                return;
            }

            Log::info('📋 Found interviews with pending evaluations', [
                'count' => $interviews->count(),
                'interview_ids' => $interviews->pluck('id')->toArray()
            ]);

            foreach ($interviews as $interview) {
                try {
                    $this->processPendingInterview($interview);
                } catch (\Exception $e) {
                    Log::error('❌ Failed to process pending interview', [
                        'interview_id' => $interview->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('✅ CheckPendingEvaluationsJob completed');

        } catch (\Exception $e) {
            Log::error('💥 CheckPendingEvaluationsJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * معالجة مقابلة معلقة
     */
    private function processPendingInterview(Interview $interview): void
    {
        Log::warning('⚠️ Interview has pending evaluations for more than 1 hour', [
            'interview_id' => $interview->id,
            'completed_at' => $interview->completed_at,
            'hours_elapsed' => now()->diffInHours($interview->completed_at)
        ]);

        // ✅ جلب الأسئلة غير المقيمة
        $pendingQuestions = $interview->questions()
            ->where('status', '!=', Question::STATUS_EVALUATED)
            ->get();

        Log::info('📋 Pending questions found', [
            'interview_id' => $interview->id,
            'count' => $pendingQuestions->count(),
            'question_ids' => $pendingQuestions->pluck('id')->toArray()
        ]);

        foreach ($pendingQuestions as $question) {
            try {
                // ✅ تحديث السؤال إلى EVALUATED بقيمة افتراضية
                $question->update([
                    'status' => Question::STATUS_EVALUATED,
                    'evaluated_at' => now(),
                ]);

                Log::info('✅ Pending question marked as evaluated (timeout)', [
                    'question_id' => $question->id,
                    'interview_id' => $interview->id
                ]);

                // ✅ التحقق من وجود إجابة مرتبطة بهذا السؤال
                $answer = $interview->answers()
                    ->where('question_id', $question->id)
                    ->first();

                if ($answer && $answer->status !== Answer::STATUS_EVALUATED) {
                    // ✅ تحديث الإجابة إلى EVALUATED بقيمة افتراضية
                    $answer->update([
                        'status' => Answer::STATUS_EVALUATED,
                        'processed_at' => now(),
                    ]);

                    Log::info('✅ Pending answer marked as evaluated (timeout)', [
                        'answer_id' => $answer->id,
                        'question_id' => $question->id,
                        'interview_id' => $interview->id
                    ]);

                    // ✅ إنشاء تقييم افتراضي إذا لم يكن موجوداً
                    if (!$answer->evaluation) {
                        Evaluation::create([
                            'answer_id' => $answer->id,
                            'question_id' => $question->id,
                            'interview_id' => $interview->id,
                            'score' => 0,
                            'criteria_scores' => [
                                'clarity' => 0,
                                'depth' => 0,
                                'relevance' => 0,
                                'confidence' => 0,
                            ],
                            'strengths' => 'No answer provided due to timeout',
                            'weaknesses' => 'No answer provided',
                            'detailed_feedback' => 'The candidate did not provide an answer for this question within the time limit.',
                            'clarity_score' => 0,
                            'relevance_score' => 0,
                            'depth_score' => 0,
                            'confidence_score' => 0,
                            'ai_raw_response' => [
                                'note' => 'Timeout evaluation - no answer provided'
                            ],
                        ]);

                        Log::info('✅ Default evaluation created for timeout answer', [
                            'answer_id' => $answer->id,
                            'question_id' => $question->id,
                            'interview_id' => $interview->id
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('❌ Failed to process pending question', [
                    'question_id' => $question->id,
                    'interview_id' => $interview->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // ✅ بعد معالجة جميع الأسئلة المعلقة، نتحقق إذا كل شيء جاهز لتوليد التقرير
        $totalQuestions = $interview->questions()->count();
        $evaluatedQuestions = $interview->questions()
            ->where('status', Question::STATUS_EVALUATED)
            ->count();

        $totalAnswers = $interview->answers()->count();
        $evaluatedAnswers = $interview->answers()
            ->where('status', Answer::STATUS_EVALUATED)
            ->count();

        Log::info('📊 Interview status after processing pending questions', [
            'interview_id' => $interview->id,
            'total_questions' => $totalQuestions,
            'evaluated_questions' => $evaluatedQuestions,
            'total_answers' => $totalAnswers,
            'evaluated_answers' => $evaluatedAnswers
        ]);

        // ✅ إذا كانت جميع الأسئلة مقيمة، نولد التقرير
        if ($totalQuestions === $evaluatedQuestions && $totalAnswers === $evaluatedAnswers) {
            Log::info('🎯 All questions evaluated after timeout, generating report', [
                'interview_id' => $interview->id
            ]);

            $interview->update([
                'status' => Interview::STATUS_PROCESSING_FINAL,
            ]);

            GenerateFinalReportJob::dispatch($interview)
                ->onQueue('reports')
                ->delay(now()->addSeconds(5));

            Log::info('✅ Final report queued after timeout recovery', [
                'interview_id' => $interview->id
            ]);
        } else {
            Log::warning('⚠️ Still have pending items after processing', [
                'interview_id' => $interview->id,
                'pending_questions' => $totalQuestions - $evaluatedQuestions,
                'pending_answers' => $totalAnswers - $evaluatedAnswers
            ]);
        }
    }
}
