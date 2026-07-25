<?php

namespace App\Http\Controllers\API\Company;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateIdentityEvidence;
use App\Models\CandidateIdentityVerification;
use App\Models\CompanyJob;
use App\Services\CompanyInterview\IdentityEvidenceService;
use App\Support\ResolvesAuthenticatedCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyIdentityReviewController extends Controller
{
    use ResolvesAuthenticatedCompany;

    public function __construct(
        private readonly IdentityEvidenceService $evidenceService,
    ) {
    }

    public function show(CompanyJob $job, Candidate $candidate): JsonResponse
    {
        $this->authorizeCandidate($job, $candidate);

        $verification = CandidateIdentityVerification::query()
            ->with([
                'evidences' => fn ($query) => $query->whereIn('type', [
                    CandidateIdentityEvidence::TYPE_DOCUMENT_FRONT,
                    CandidateIdentityEvidence::TYPE_DOCUMENT_BACK,
                    CandidateIdentityEvidence::TYPE_INTERVIEW_SNAPSHOT,
                ]),
            ])
            ->where('company_job_id', $job->id)
            ->where('candidate_id', $candidate->id)
            ->firstOrFail();

        $interview = \App\Models\Interview::query()
            ->where('candidate_id', $candidate->id)
            ->where('company_job_id', $job->id)
            ->where('interview_type', 'company_candidate')
            ->latest('id')
            ->first();
        $reviewAvailable = $interview !== null
            && !in_array($interview->status, [
                \App\Models\Interview::STATUS_PENDING,
                \App\Models\Interview::STATUS_IN_PROGRESS,
            ], true);

        return response()->json([
            'success' => true,
            'data' => [
                'verification' => [
                    'id' => $verification->id,
                    'status' => $verification->status,
                    'document_type' => $verification->document_type,
                    'verification_method' => 'document_and_interview_snapshots',
                    'face_monitoring_enabled' => true,
                    'submitted_at' => $verification->submitted_at?->toISOString(),
                    'reviewed_at' => $verification->reviewed_at?->toISOString(),
                    'review_notes' => $verification->review_notes,
                    'rejection_reason' => $verification->rejection_reason,
                    'evidence_deleted_at' => $verification->evidence_deleted_at?->toISOString(),
                    'review_available' => $reviewAvailable,
                    'review_available_after' => 'interview_submission',
                ],
                'interview' => $interview ? [
                    'id' => $interview->id,
                    'status' => $interview->status,
                    'completed_at' => $interview->completed_at?->toISOString(),
                ] : null,
                'candidate' => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'email' => $candidate->email,
                ],
                'evidences' => $verification->evidences->map(fn (CandidateIdentityEvidence $evidence): array => [
                    'id' => $evidence->id,
                    'type' => $evidence->type,
                    'mime_type' => $evidence->mime_type,
                    'file_size' => $evidence->file_size,
                    'captured_at' => $evidence->captured_at?->toISOString(),
                    'question_id' => $evidence->question_id,
                    'view_url' => url("/api/company/jobs/{$job->id}/candidates/{$candidate->id}/identity/evidences/{$evidence->id}"),
                ])->values(),
            ],
        ]);
    }

    public function evidence(
        CompanyJob $job,
        Candidate $candidate,
        CandidateIdentityEvidence $evidence
    ): StreamedResponse {
        $this->authorizeCandidate($job, $candidate);

        abort_unless(
            in_array($evidence->type, [
                CandidateIdentityEvidence::TYPE_DOCUMENT_FRONT,
                CandidateIdentityEvidence::TYPE_DOCUMENT_BACK,
                CandidateIdentityEvidence::TYPE_INTERVIEW_SNAPSHOT,
            ], true),
            404,
            'Evidence not found.'
        );

        $verification = $evidence->verification;
        abort_unless(
            $verification->company_job_id === $job->id
            && $verification->candidate_id === $candidate->id,
            404,
            'Evidence not found.'
        );

        abort_unless(Storage::disk($evidence->disk)->exists($evidence->path), 404, 'Evidence file is unavailable.');

        return Storage::disk($evidence->disk)->response(
            $evidence->path,
            null,
            [
                'Content-Type' => $evidence->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function review(Request $request, CompanyJob $job, Candidate $candidate): JsonResponse
    {
        $this->authorizeCandidate($job, $candidate);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'rejection_reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000'],
        ]);

        $verification = CandidateIdentityVerification::query()
            ->where('company_job_id', $job->id)
            ->where('candidate_id', $candidate->id)
            ->firstOrFail();

        abort_if($verification->reviewed_at !== null, 409, 'Identity verification has already been reviewed.');

        $interview = \App\Models\Interview::query()
            ->where('candidate_id', $candidate->id)
            ->where('company_job_id', $job->id)
            ->where('interview_type', 'company_candidate')
            ->latest('id')
            ->first();
        abort_if($interview === null, 409, 'The candidate interview has not been created yet.');
        abort_if(
            in_array($interview->status, [
                \App\Models\Interview::STATUS_PENDING,
                \App\Models\Interview::STATUS_IN_PROGRESS,
            ], true),
            409,
            'Identity review is available only after the candidate submits the interview.'
        );

        $reviewer = $this->reviewerIdentity();

        $deletedCount = 0;

        DB::transaction(function () use (
            $verification,
            $validated,
            $reviewer,
            $job,
            &$deletedCount
        ): void {
            $verification->forceFill([
                'status' => $validated['decision'],
                'reviewer_type' => $reviewer['type'],
                'reviewer_id' => $reviewer['id'],
                'review_notes' => $validated['review_notes'] ?? null,
                'rejection_reason' => $validated['decision'] === 'rejected'
                    ? $validated['rejection_reason']
                    : null,
                'reviewed_at' => now(),
            ])->save();

            $verification->jobCandidate?->forceFill([
                'identity_status' => $validated['decision'],
            ])->save();

            if ($job->delete_identity_evidence_after_review) {
                $deletedCount = $this->evidenceService->deleteAllEvidence($verification);
            }
        });

        return response()->json([
            'success' => true,
            'message' => $validated['decision'] === 'approved'
                ? 'Identity approved.'
                : 'Identity rejected. The interview report remains available with an Identity Rejected warning.',
            'data' => [
                'identity_status' => $validated['decision'],
                'reviewed_at' => $verification->fresh()->reviewed_at?->toISOString(),
                'evidence_deleted' => (bool) $job->delete_identity_evidence_after_review,
                'deleted_evidence_count' => $deletedCount,
                'report_visibility' => 'visible_to_authorized_company_users',
            ],
        ]);
    }

    private function authorizeCandidate(CompanyJob $job, Candidate $candidate): void
    {
        abort_unless($job->company_id === $this->authenticatedCompany()->id, 403, 'Unauthorized.');
        abort_unless($candidate->company_job_id === $job->id, 404, 'Candidate not found for this job.');
    }
}
