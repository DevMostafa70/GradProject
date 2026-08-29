<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateIdentityEvidence;
use App\Models\CandidateIdentityVerification;
use App\Models\CandidateInterviewSnapshotRequest;
use App\Models\CompanyJob;
use App\Models\Interview;
use App\Models\Question;
use App\Support\ResolvesAuthenticatedCompany;
use Illuminate\Http\JsonResponse;

class CompanyCandidateReportController extends Controller
{
    use ResolvesAuthenticatedCompany;

    public function show(CompanyJob $job, Candidate $candidate): JsonResponse
    {
        abort_unless(
            $job->company_id === $this->authenticatedCompany()->id,
            403,
            'Unauthorized.'
        );

        abort_unless(
            $candidate->company_job_id === $job->id,
            404,
            'Candidate not found for this job.'
        );

        return response()->json([
            'success' => true,
            'data' => $this->reportPayload($candidate, $job),
        ]);
    }

    public function adminShow(Candidate $candidate): JsonResponse
    {
        $job = $candidate->job()->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->reportPayload($candidate, $job),
        ]);
    }

    private function reportPayload(Candidate $candidate, CompanyJob $job): array
    {
        $interview = Interview::query()
            ->where('candidate_id', $candidate->id)
            ->where('company_job_id', $job->id)
            ->where('interview_type', 'company_candidate')
            ->with([
                'finalReport',
                'questions.answer.evaluation',
                'antiCheatLogs.question',
                'antiCheatLogs.answer',
            ])
            ->latest('id')
            ->first();

        $verification = CandidateIdentityVerification::query()
            ->where('company_job_id', $job->id)
            ->where('candidate_id', $candidate->id)
            ->first();

        $identity = $this->identityPayload($verification);

        if ($interview === null) {
            return [
                'candidate' => $this->candidatePayload($candidate),
                'job' => $this->jobPayload($job),
                'interview' => null,
                'identity' => $identity,
                'report' => null,
                'questions' => [],
                'violations' => [],
                'snapshots' => [],
                'snapshot_requests' => [],
            ];
        }

        $report = $interview->finalReport;
        $locale = method_exists($interview, 'normalizedLocale')
            ? $interview->normalizedLocale()
            : $job->normalizedInterviewLocale();

        $questions = $interview->questions
            ->sortBy('order')
            ->values()
            ->map(function (Question $question) use ($locale): array {
                $answer = $question->answer;
                $evaluation = $answer?->evaluation;

                return [
                    'id' => $question->id,
                    'order' => (int) $question->order,
                    'text' => $this->localizedValue($question->question_text, $locale),
                    'type' => $question->type,
                    'source' => $question->source,
                    'difficulty' => $question->difficulty,
                    'time_allocation_seconds' => (int) ($question->time_allocation_seconds ?? 0),
                    'answer' => $answer ? [
                        'id' => $answer->id,
                        'transcription' => $answer->transcription,
                        'duration_seconds' => (float) ($answer->duration_seconds ?? 0),
                        'status' => $answer->status,
                        'submitted_at' => $answer->submitted_at?->toISOString(),
                        'processing_metadata' => $answer->processing_metadata,
                    ] : null,
                    'evaluation' => $evaluation ? [
                        'score' => $evaluation->score !== null
                            ? round((float) $evaluation->score * 10, 2)
                            : null,
                        'strengths' => $evaluation->strengths,
                        'weaknesses' => $evaluation->weaknesses,
                        'detailed_feedback' => $evaluation->detailed_feedback,
                    ] : null,
                ];
            });

        $questionLookup = $questions->keyBy('id');

        $violations = $interview->antiCheatLogs
            ->sortBy('violation_timestamp')
            ->values()
            ->map(function ($log) use ($questionLookup): array {
                $question = $questionLookup->get($log->question_id);
                $confidence = (float) ($log->confidence_score ?? 0);
                $severityWeight = (float) ($log->severity_weight ?? 0);

                return [
                    'id' => $log->id,
                    'question_id' => $log->question_id,
                    'answer_id' => $log->answer_id,
                    'type' => $log->violation_type,
                    'violation_type' => $log->violation_type,
                    'occurred_at' => $log->violation_timestamp?->toISOString(),
                    'violation_timestamp' => $log->violation_timestamp?->toISOString(),
                    'created_at' => $log->created_at?->toISOString(),
                    'duration_seconds' => (float) ($log->duration_seconds ?? 0),
                    'confidence_score' => $confidence,
                    'severity_weight' => $severityWeight,
                    'risk_score' => round($confidence * $severityWeight, 2),
                    'source' => $log->source,
                    'metadata' => $log->metadata,
                    'question' => $question ? [
                        'id' => $question['id'],
                        'order' => $question['order'],
                        'text' => $question['text'],
                    ] : null,
                ];
            });

        $snapshotRequests = CandidateInterviewSnapshotRequest::query()
            ->where('interview_id', $interview->id)
            ->with('question')
            ->orderBy('due_at')
            ->get()
            ->map(function (CandidateInterviewSnapshotRequest $request) use ($locale): array {
                return [
                    'id' => $request->id,
                    'status' => $request->status,
                    'question_id' => $request->question_id,
                    'question' => $request->question ? [
                        'id' => $request->question->id,
                        'order' => (int) $request->question->order,
                        'text' => $this->localizedValue(
                            $request->question->question_text,
                            $locale
                        ),
                    ] : null,
                    'due_at' => $request->due_at?->toISOString(),
                    'issued_at' => $request->issued_at?->toISOString(),
                    'expires_at' => $request->expires_at?->toISOString(),
                    'captured_at' => $request->captured_at?->toISOString(),
                    'metadata' => $request->metadata,
                ];
            })
            ->values();

        $snapshotCounts = $snapshotRequests->countBy('status');

        $snapshots = collect();

        if ($verification !== null && $verification->evidence_deleted_at === null) {
            $snapshots = CandidateIdentityEvidence::query()
                ->where('verification_id', $verification->id)
                ->where('interview_id', $interview->id)
                ->where('type', CandidateIdentityEvidence::TYPE_INTERVIEW_SNAPSHOT)
                ->with('question')
                ->orderBy('captured_at')
                ->get()
                ->map(function (CandidateIdentityEvidence $evidence) use ($locale): array {
                    return [
                        'id' => $evidence->id,
                        'evidence_id' => $evidence->id,
                        'type' => $evidence->type,
                        'question_id' => $evidence->question_id,
                        'question' => $evidence->question ? [
                            'id' => $evidence->question->id,
                            'order' => (int) $evidence->question->order,
                            'text' => $this->localizedValue(
                                $evidence->question->question_text,
                                $locale
                            ),
                        ] : null,
                        'mime_type' => $evidence->mime_type,
                        'file_size' => (int) ($evidence->file_size ?? 0),
                        'captured_at' => $evidence->captured_at?->toISOString(),
                        'metadata' => $evidence->metadata,
                    ];
                })
                ->values();
        }

        $answeredQuestions = $questions->filter(
            fn (array $question): bool => $question['answer'] !== null
        );

        $questionScores = $questions
            ->pluck('evaluation.score')
            ->filter(fn ($score): bool => $score !== null)
            ->map(fn ($score): float => (float) $score);

        $startedAt = $interview->started_at;
        $completedAt = $interview->completed_at;

        return [
            'candidate' => $this->candidatePayload($candidate),
            'job' => $this->jobPayload($job),
            'interview' => [
                'id' => $interview->id,
                'status' => $interview->status,
                'locale' => $locale,
                'started_at' => $startedAt?->toISOString(),
                'completed_at' => $completedAt?->toISOString(),
                'duration_seconds' => $startedAt && $completedAt
                    ? max(0, $startedAt->diffInSeconds($completedAt))
                    : null,
                'resume_count' => (int) ($interview->resume_count ?? 0),
                'max_resume_count' => (int) ($interview->max_resume_count ?? 3),
                'questions_total' => $questions->count(),
                'questions_answered' => $answeredQuestions->count(),
                'total_answer_duration_seconds' => round(
                    (float) $answeredQuestions->sum(
                        fn (array $question): float => (float) ($question['answer']['duration_seconds'] ?? 0)
                    ),
                    2
                ),
                'average_question_score' => $questionScores->isNotEmpty()
                    ? round((float) $questionScores->average(), 2)
                    : null,
                'captured_snapshot_count' => (int) ($interview->captured_snapshot_count ?? 0),
                'snapshot_summary' => [
                    'expected' => $snapshotRequests->count(),
                    'captured' => (int) ($snapshotCounts[CandidateInterviewSnapshotRequest::STATUS_CAPTURED] ?? 0),
                    'missed' => (int) ($snapshotCounts[CandidateInterviewSnapshotRequest::STATUS_MISSED] ?? 0),
                    'pending' => (int) ($snapshotCounts[CandidateInterviewSnapshotRequest::STATUS_PENDING] ?? 0),
                    'issued' => (int) ($snapshotCounts[CandidateInterviewSnapshotRequest::STATUS_ISSUED] ?? 0),
                    'processing' => (int) ($snapshotCounts[CandidateInterviewSnapshotRequest::STATUS_PROCESSING] ?? 0),
                    'available_files' => $snapshots->count(),
                ],
            ],
            'identity' => array_merge($identity, [
                'snapshot_files_available' => $snapshots->count(),
            ]),
            'report' => $report ? [
                'id' => $report->id,
                'overall_score' => (float) $report->overall_score,
                'adjusted_score' => (float) $report->adjusted_score,
                'score_out_of_100' => round((float) $report->adjusted_score * 10, 2),
                'technical_score' => $report->technical_score !== null
                    ? (float) $report->technical_score
                    : null,
                'communication_score' => $report->communication_score !== null
                    ? (float) $report->communication_score
                    : null,
                'problem_solving_score' => $report->problem_solving_score !== null
                    ? (float) $report->problem_solving_score
                    : null,
                'executive_summary' => $report->executive_summary,
                'strengths_analysis' => $report->strengths_analysis,
                'improvement_areas' => $report->improvement_areas,
                'hiring_recommendation' => $report->hiring_recommendation,
                'skill_breakdown' => $report->skill_breakdown,
                'question_evaluations' => $report->question_evaluations,
                'educational_summary' => $report->educational_summary,
                'key_strengths' => $report->key_strengths,
                'key_weaknesses' => $report->key_weaknesses,
                'improvement_plan' => $report->improvement_plan,
                'learning_resources' => $report->learning_resources,
                'key_takeaways' => $report->key_takeaways,
                'next_steps' => $report->next_steps,
                'cheating_severity_score' => (float) $report->cheating_severity_score,
                'cheating_risk_level' => $report->cheating_risk_level,
                'cheating_risk_description' => $report->cheating_risk_description,
                'cheating_recommendation' => $report->cheating_recommendation,
                'total_violations' => (int) $report->total_violations,
                'violation_summary' => $report->violation_summary,
                'violation_count_by_type' => $report->violation_count_by_type,
                'generated_at' => $report->generated_at?->toISOString(),
                'identity_warning' => $identity['warning'],
            ] : null,
            'questions' => $questions,
            'violations' => $violations,
            'snapshots' => $snapshots,
            'snapshot_requests' => $snapshotRequests,
        ];
    }

    private function candidatePayload(Candidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'name' => $candidate->name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'status' => $candidate->status,
            'final_score' => $candidate->final_score !== null
                ? (float) $candidate->final_score
                : null,
            'invited_at' => $candidate->invited_at?->toISOString(),
            'started_at' => $candidate->started_at?->toISOString(),
            'completed_at' => $candidate->completed_at?->toISOString(),
        ];
    }

    private function jobPayload(CompanyJob $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->titleForLocale(),
            'locale' => $job->normalizedInterviewLocale(),
            'difficulty' => $job->difficulty,
            'questions_source' => $job->questions_source,
            'number_of_questions' => (int) ($job->number_of_questions ?? 0),
        ];
    }

    private function identityPayload(?CandidateIdentityVerification $verification): array
    {
        $status = $verification?->status
            ?? CandidateIdentityVerification::STATUS_PENDING;

        $warning = match ($status) {
            CandidateIdentityVerification::STATUS_REJECTED => [
                'level' => 'danger',
                'label' => 'Identity Rejected',
                'message' => 'The candidate identity was rejected. The interview report remains available and should be reviewed with caution.',
            ],
            CandidateIdentityVerification::STATUS_APPROVED => [
                'level' => 'success',
                'label' => 'Identity Approved',
                'message' => 'The candidate identity was manually approved.',
            ],
            default => [
                'level' => 'warning',
                'label' => 'Identity Pending',
                'message' => 'The interview report is ready, but identity review has not been completed.',
            ],
        };

        return [
            'status' => $status,
            'label' => $warning['label'],
            'warning' => $warning,
            'reviewed_at' => $verification?->reviewed_at?->toISOString(),
            'review_notes' => $verification?->review_notes,
            'rejection_reason' => $verification?->rejection_reason,
            'evidence_deleted_at' => $verification?->evidence_deleted_at?->toISOString(),
            'evidence_available' => $verification !== null
                && $verification->evidence_deleted_at === null,
        ];
    }

    private function localizedValue(mixed $value, string $locale): string
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
