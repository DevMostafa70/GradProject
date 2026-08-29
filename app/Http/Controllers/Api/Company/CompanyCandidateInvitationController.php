<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Models\CandidateInterviewSessionEvent;
use App\Models\CompanyJob;
use App\Models\EmailInvitation;
use App\Models\Interview;
use App\Services\CompanyInterview\CandidateInvitationService;
use App\Support\ResolvesAuthenticatedCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyCandidateInvitationController extends Controller
{
    use ResolvesAuthenticatedCompany;

    public function __construct(
        private readonly CandidateInvitationService $invitationService,
    ) {
    }

    public function index(Request $request, CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        $query = $job->invitations()
            ->with(['candidate', 'jobCandidate.interview.finalReport'])
            ->latest('id');

        if ($request->filled('delivery_status')) {
            $query->where('status', $request->string('delivery_status')->toString());
        }

        if ($request->filled('lifecycle_status')) {
            $query->where('lifecycle_status', $request->string('lifecycle_status')->toString());
        }

        $invitations = $query->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'success' => true,
            'data' => $invitations->through(function (EmailInvitation $invitation): array {
                $interview = $invitation->jobCandidate?->interview;

                return [
                    'id' => $invitation->id,
                    'candidate_id' => $invitation->candidate_id,
                    'company_job_candidate_id' => $invitation->company_job_candidate_id,
                    'interview_id' => $interview?->id,
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                    'delivery_status' => $invitation->status,
                    'lifecycle_status' => $invitation->lifecycle_status,
                    'identity_status' => $invitation->jobCandidate?->identity_status,
                    'candidate_status' => $invitation->candidate?->status,
                    'interview_status' => $interview?->status,
                    'report_ready' => $interview?->finalReport !== null,
                    'identity_review_available' => $interview !== null
                        && !in_array($interview->status, [
                            Interview::STATUS_PENDING,
                            Interview::STATUS_IN_PROGRESS,
                        ], true),
                    'send_attempts' => $invitation->send_attempts,
                    'sent_at' => $invitation->sent_at?->toISOString(),
                    'expires_at' => $invitation->expires_at?->toISOString(),
                    'opened_at' => $invitation->opened_at?->toISOString(),
                    'claimed_at' => $invitation->claimed_at?->toISOString(),
                    'completed_at' => $invitation->completed_at?->toISOString(),
                    'failure_reason' => $invitation->failure_reason,
                ];
            }),
        ]);
    }

    public function resend(CompanyJob $job, EmailInvitation $invitation): JsonResponse
    {
        $this->authorizeInvitation($job, $invitation);
        $invitation = $this->invitationService->rotateAndDispatch($invitation);

        return response()->json([
            'success' => true,
            'message' => 'A new invitation token was generated and queued for delivery.',
            'data' => [
                'invitation_id' => $invitation->id,
                'delivery_status' => $invitation->status,
                'lifecycle_status' => $invitation->lifecycle_status,
                'expires_at' => $invitation->expires_at?->toISOString(),
            ],
        ]);
    }

    public function cancel(CompanyJob $job, EmailInvitation $invitation): JsonResponse
    {
        $this->authorizeInvitation($job, $invitation);
        $this->invitationService->cancel($invitation);

        return response()->json([
            'success' => true,
            'message' => 'Invitation cancelled.',
        ]);
    }

    public function extendResumeLimit(Request $request, CompanyJob $job, EmailInvitation $invitation): JsonResponse
    {
        $this->authorizeInvitation($job, $invitation);

        $validated = $request->validate([
            'additional_resumes' => ['required', 'integer', 'min:1', 'max:3'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $interview = $invitation->jobCandidate?->interview;
        abort_if($interview === null, 404, 'Interview not found.');

        DB::transaction(function () use ($interview, $validated, $request): void {
            /** @var Interview $locked */
            $locked = Interview::query()->lockForUpdate()->findOrFail($interview->id);
            $oldLimit = (int) $locked->max_resume_count;
            $newLimit = $oldLimit + (int) $validated['additional_resumes'];

            $locked->forceFill([
                'max_resume_count' => $newLimit,
                'resume_locked_at' => null,
                'resume_lock_reason' => null,
            ])->save();

            CandidateInterviewSessionEvent::create([
                'interview_id' => $locked->id,
                'event_type' => 'resume_limit_extended',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'old_limit' => $oldLimit,
                    'new_limit' => $newLimit,
                    'reason' => $validated['reason'],
                    'performed_by' => auth()->id(),
                ],
                'occurred_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Resume limit extended.',
            'data' => [
                'resume_count' => (int) $interview->fresh()->resume_count,
                'max_resume_count' => (int) $interview->fresh()->max_resume_count,
            ],
        ]);
    }

    private function authorizeJob(CompanyJob $job): void
    {
        abort_unless($job->company_id === $this->authenticatedCompany()->id, 403, 'Unauthorized.');
    }

    private function authorizeInvitation(CompanyJob $job, EmailInvitation $invitation): void
    {
        $this->authorizeJob($job);
        abort_unless($invitation->company_job_id === $job->id, 404, 'Invitation not found for this job.');
    }
}
