<?php

namespace App\Services\CompanyInterview;

use App\Exceptions\CompanyInterviewAccessException;
use App\Models\CandidateIdentityVerification;
use App\Models\CandidateInterviewSessionEvent;
use App\Models\EmailInvitation;
use App\Models\Interview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyInterviewSessionService
{
    public function resolveInvitation(string $rawToken, bool $markOpened = false): EmailInvitation
    {
        $invitation = EmailInvitation::query()
            ->with(['job.company', 'candidate', 'jobCandidate.interview'])
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if ($invitation === null) {
            throw new CompanyInterviewAccessException('Invalid invitation link.', 404);
        }

        if ($invitation->isCancelled()) {
            throw new CompanyInterviewAccessException('This invitation has been cancelled.', 410);
        }

        if ($invitation->isExpired()) {
            $invitation->forceFill([
                'lifecycle_status' => EmailInvitation::LIFECYCLE_EXPIRED,
            ])->save();

            throw new CompanyInterviewAccessException('This invitation has expired.', 410);
        }

        if ($markOpened && !$invitation->isClaimed()) {
            $invitation->markOpened();
        }

        return $invitation;
    }

    public function claim(EmailInvitation $invitation, array $data, Request $request): array
    {
        return DB::transaction(function () use ($invitation, $data, $request): array {
            $invitation = EmailInvitation::query()
                ->with(['job', 'candidate', 'jobCandidate'])
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if (!$invitation->canBeClaimed()) {
                if ($invitation->isClaimed()) {
                    throw new CompanyInterviewAccessException(
                        'This invitation was already used. Resume with the saved interview session.',
                        409,
                        ['already_claimed' => true]
                    );
                }

                throw new CompanyInterviewAccessException('This invitation cannot be used.', 410);
            }

            $job = $invitation->job;
            $candidate = $invitation->candidate;
            $jobCandidate = $invitation->jobCandidate;

            if ($candidate === null || $jobCandidate === null) {
                throw new CompanyInterviewAccessException('Invitation data is incomplete.', 409);
            }

            if (!$job->isActive()) {
                throw new CompanyInterviewAccessException('This job is no longer accepting interviews.', 410);
            }

            $sessionToken = Str::random(96);
            $browserSecretHash = hash('sha256', (string) $data['browser_secret']);
            $deviceFingerprintHash = hash('sha256', (string) $data['device_fingerprint']);
            $prestartExpiresAt = $invitation->expires_at
                ?? now()->addMinutes(max(
                    30,
                    (int) config('company_interviews.session.prestart_session_minutes', 180)
                ));

            $interview = new Interview();
            $interview->forceFill([
                'candidate_id' => $candidate->id,
                'company_job_id' => $job->id,
                'company_job_candidate_id' => $jobCandidate->id,
                'email_invitation_id' => $invitation->id,
                'interview_type' => 'company_candidate',
                'position' => $job->titleForLocale(),
                'experience_level' => 'mid',
                'difficulty' => $job->difficulty,
                'locale' => $job->normalizedInterviewLocale(),
                'skills' => $job->required_skills,
                'number_of_questions' => $job->getTotalQuestionsPerCandidate(),
                'status' => Interview::STATUS_PENDING,
                'public_session_token_hash' => hash('sha256', $sessionToken),
                'browser_secret_hash' => $browserSecretHash,
                'device_fingerprint' => $deviceFingerprintHash,
                'session_instance_id' => (string) $data['session_instance_id'],
                'active_session_id' => (string) $data['session_instance_id'],
                'session_initialized_at' => now(),
                'last_activity_at' => now(),
                'last_heartbeat_at' => now(),
                'resume_count' => 0,
                'max_resume_count' => (int) $job->max_resume_count,
                'expires_at' => $prestartExpiresAt,
                'consent_accepted_at' => now(),
                'metadata' => [
                    'source' => 'company_candidate_invitation',
                    'identity_review_mode' => 'post_interview_document_snapshot_review',
                    'identity_report_visibility' => true,
                    'selfie_required' => false,
                    'liveness_required' => false,
                    'face_monitoring_enabled' => true,
                ],
            ]);
            $interview->save();

            $verification = CandidateIdentityVerification::create([
                'company_job_id' => $job->id,
                'candidate_id' => $candidate->id,
                'company_job_candidate_id' => $jobCandidate->id,
                'interview_id' => $interview->id,
                'status' => $job->identity_verification_required
                    ? CandidateIdentityVerification::STATUS_PENDING
                    : CandidateIdentityVerification::STATUS_UNDER_REVIEW,
                /*
                 * Active liveness challenges are disabled. Face monitoring
                 * remains active during the interview for anti-cheat events.
                 */
                'liveness_status' => CandidateIdentityVerification::LIVENESS_PASSED,
                'metadata' => [
                    'review_timing' => 'after_interview',
                    'delete_evidence_after_review' => (bool) $job->delete_identity_evidence_after_review,
                ],
            ]);

            $invitation->markClaimed();

            $candidate->forceFill([
                'status' => 'pending',
                'started_at' => null,
                'session_expires_at' => $prestartExpiresAt,
                'max_resume_count' => (int) $job->max_resume_count,
            ])->save();

            $jobCandidate->forceFill([
                'interview_id' => $interview->id,
                'status' => $jobCandidate::STATUS_PENDING,
                'identity_status' => $verification->status,
            ])->save();

            $this->recordEvent($interview, 'claimed', $request, [
                'invitation_id' => $invitation->id,
            ]);

            return [
                'session_token' => $sessionToken,
                'interview' => $interview,
                'verification' => $verification,
            ];
        });
    }

    public function authenticate(
        Request $request,
        bool $requireCurrentInstance = true,
        bool $allowExpiredCompletedSession = false
    ): Interview
    {
        $sessionToken = (string) ($request->header('X-Interview-Session') ?: $request->input('session_token'));
        $browserSecret = (string) ($request->header('X-Browser-Secret') ?: $request->input('browser_secret'));
        $deviceFingerprint = (string) ($request->header('X-Device-Fingerprint') ?: $request->input('device_fingerprint'));
        $sessionInstanceId = (string) ($request->header('X-Session-Instance') ?: $request->input('session_instance_id'));

        if ($sessionToken === '' || $browserSecret === '' || $deviceFingerprint === '' || $sessionInstanceId === '') {
            throw new CompanyInterviewAccessException('Interview session headers are required.', 401);
        }

        if (strlen($sessionInstanceId) < 8 || strlen($sessionInstanceId) > 64) {
            throw new CompanyInterviewAccessException('Session instance ID must be between 8 and 64 characters.', 422);
        }

        $interview = Interview::query()
            ->with(['candidate', 'questions', 'answers'])
            ->where('public_session_token_hash', hash('sha256', $sessionToken))
            ->where('interview_type', 'company_candidate')
            ->first();

        if ($interview === null) {
            throw new CompanyInterviewAccessException('Invalid interview session.', 401);
        }

        if (!hash_equals((string) $interview->browser_secret_hash, hash('sha256', $browserSecret))) {
            $this->recordEvent($interview, 'browser_secret_mismatch', $request);
            throw new CompanyInterviewAccessException('This interview is linked to another browser.', 403);
        }

        if (!hash_equals((string) $interview->device_fingerprint, hash('sha256', $deviceFingerprint))) {
            $this->recordEvent($interview, 'device_mismatch', $request);
            throw new CompanyInterviewAccessException('This interview cannot be opened on another device.', 403);
        }

        $completedState = in_array($interview->status, [
            Interview::STATUS_COMPLETED,
            Interview::STATUS_PROCESSING_FINAL,
            Interview::STATUS_COMPLETED_WITH_REPORT,
            Interview::STATUS_FAILED,
        ], true);

        if (
            $interview->expires_at
            && now()->greaterThan($interview->expires_at)
            && !($allowExpiredCompletedSession && $completedState)
        ) {
            throw new CompanyInterviewAccessException('The interview session has expired.', 410);
        }

        if ($interview->resume_locked_at !== null) {
            throw new CompanyInterviewAccessException(
                'The resume limit has been reached. Contact the company.',
                423,
                ['resume_count' => (int) $interview->resume_count]
            );
        }

        if ($requireCurrentInstance && $interview->session_instance_id !== $sessionInstanceId) {
            throw new CompanyInterviewAccessException('Resume the session before continuing.', 409);
        }

        return $interview;
    }

    public function resume(Request $request): array
    {
        $interview = $this->authenticate($request, false);

        if (!in_array($interview->status, [
            Interview::STATUS_PENDING,
            Interview::STATUS_IN_PROGRESS,
        ], true)) {
            throw new CompanyInterviewAccessException(
                'A submitted interview cannot be resumed.',
                409,
                ['interview_status' => $interview->status]
            );
        }

        $newSessionInstanceId = (string) ($request->header('X-Session-Instance') ?: $request->input('session_instance_id'));

        return DB::transaction(function () use ($interview, $newSessionInstanceId, $request): array {
            $interview = Interview::query()->lockForUpdate()->findOrFail($interview->id);

            if ($interview->session_instance_id === $newSessionInstanceId) {
                $interview->forceFill([
                    'last_activity_at' => now(),
                    'last_heartbeat_at' => now(),
                ])->save();

                return ['interview' => $interview, 'resume_counted' => false];
            }

            $timeout = max(30, (int) config('company_interviews.session.heartbeat_timeout_seconds', 90));
            $previousSessionIsActive = $interview->last_heartbeat_at !== null
                && $interview->last_heartbeat_at->greaterThan(now()->subSeconds($timeout));

            if ($previousSessionIsActive) {
                $this->recordEvent($interview, 'concurrent_session_blocked', $request, [
                    'attempted_session_instance_id' => $newSessionInstanceId,
                ]);

                throw new CompanyInterviewAccessException(
                    'The interview is already active in another tab or window.',
                    409
                );
            }

            if ((int) $interview->resume_count >= (int) $interview->max_resume_count) {
                $interview->forceFill([
                    'resume_locked_at' => now(),
                    'resume_lock_reason' => 'maximum_resume_count_reached',
                ])->save();

                $this->recordEvent($interview, 'resume_limit_reached', $request);

                throw new CompanyInterviewAccessException(
                    'The maximum number of resumes has been reached.',
                    423,
                    [
                        'resume_count' => (int) $interview->resume_count,
                        'max_resume_count' => (int) $interview->max_resume_count,
                    ]
                );
            }

            $interview->forceFill([
                'session_instance_id' => $newSessionInstanceId,
                'active_session_id' => $newSessionInstanceId,
                'session_initialized_at' => now(),
                'last_activity_at' => now(),
                'last_heartbeat_at' => now(),
                'last_resume_at' => now(),
                'resume_count' => (int) $interview->resume_count + 1,
            ])->save();

            $this->recordEvent($interview, 'resumed', $request, [
                'resume_count' => (int) $interview->resume_count,
            ]);

            return ['interview' => $interview, 'resume_counted' => true];
        });
    }

    public function heartbeat(Interview $interview, Request $request): void
    {
        $interview->forceFill([
            'last_activity_at' => now(),
            'last_heartbeat_at' => now(),
        ])->save();
    }

    public function recordEvent(
        Interview $interview,
        string $eventType,
        Request $request,
        array $metadata = []
    ): void {
        $fingerprint = (string) ($request->header('X-Device-Fingerprint') ?: $request->input('device_fingerprint'));
        $sessionInstanceId = (string) ($request->header('X-Session-Instance') ?: $request->input('session_instance_id'));

        CandidateInterviewSessionEvent::create([
            'interview_id' => $interview->id,
            'session_instance_id' => $sessionInstanceId ?: null,
            'event_type' => $eventType,
            'device_fingerprint_hash' => $fingerprint !== '' ? hash('sha256', $fingerprint) : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }
}
