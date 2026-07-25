<?php

namespace App\Services\CompanyInterview;

use App\Jobs\SendInvitationEmailJob;
use App\Models\Candidate;
use App\Models\CompanyJob;
use App\Models\CompanyJobCandidate;
use App\Models\EmailInvitation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CandidateInvitationService
{
    public function createAndDispatch(CompanyJob $job, array $row): EmailInvitation
    {
        $invitation = DB::transaction(function () use ($job, $row): EmailInvitation {
            $email = strtolower(trim((string) $row['email']));

            $existing = Candidate::query()
                ->where('company_job_id', $job->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new RuntimeException("Candidate {$email} already exists for this job.");
            }

            $candidate = Candidate::create([
                'name' => trim((string) $row['name']),
                'email' => $email,
                'phone' => $row['phone'] ?? null,
                'company_job_id' => $job->id,
                // Legacy column remains populated, but this value is never sent or accepted.
                'invitation_token' => 'legacy_' . Str::uuid()->toString(),
                'status' => 'pending',
                'max_resume_count' => (int) $job->max_resume_count,
                'invited_at' => null,
                'expires_at' => null,
                'import_metadata' => [
                    'candidate_reference' => $row['candidate_reference'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'source_row' => $row['row_number'] ?? null,
                ],
            ]);

            $jobCandidate = CompanyJobCandidate::create([
                'company_job_id' => $job->id,
                'candidate_id' => $candidate->id,
                'status' => CompanyJobCandidate::STATUS_PENDING,
                'identity_status' => 'pending',
                'source' => 'excel_import',
                'invited_at' => now(),
            ]);

            $invitation = EmailInvitation::create([
                'email' => $candidate->email,
                'name' => $candidate->name,
                'company_job_id' => $job->id,
                'candidate_id' => $candidate->id,
                'company_job_candidate_id' => $jobCandidate->id,
                'status' => EmailInvitation::DELIVERY_PENDING,
                'lifecycle_status' => EmailInvitation::LIFECYCLE_CREATED,
                'expires_at' => null,
                'metadata' => [
                    'locale' => $job->normalizedInterviewLocale(),
                    'source' => 'excel_import',
                ],
            ]);

            $this->rotateToken($invitation, false);

            $candidate->forceFill([
                'email_invitation_id' => $invitation->id,
                'expires_at' => $invitation->expires_at,
            ])->save();

            $jobCandidate->forceFill([
                'email_invitation_id' => $invitation->id,
            ])->save();

            return $invitation;
        });

        SendInvitationEmailJob::dispatch($invitation->id)
            ->onQueue('invitations')
            ->afterCommit();

        return $invitation->fresh(['candidate', 'job']);
    }

    public function rotateAndDispatch(EmailInvitation $invitation): EmailInvitation
    {
        DB::transaction(function () use ($invitation): void {
            $locked = EmailInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->status === EmailInvitation::DELIVERY_PENDING) {
                throw new RuntimeException('This invitation is already queued for delivery.');
            }

            if ($locked->isClaimed() || $locked->jobCandidate?->interview_id !== null) {
                throw new RuntimeException('A claimed invitation cannot be resent. Resume the existing interview session instead.');
            }

            if (in_array($locked->lifecycle_status, [
                EmailInvitation::LIFECYCLE_IN_PROGRESS,
                EmailInvitation::LIFECYCLE_COMPLETED,
            ], true)) {
                throw new RuntimeException('An in-progress or completed invitation cannot be resent.');
            }

            $locked->forceFill([
                'status' => EmailInvitation::DELIVERY_PENDING,
                'lifecycle_status' => EmailInvitation::LIFECYCLE_CREATED,
                'opened_at' => null,
                'claimed_at' => null,
                'cancelled_at' => null,
                'completed_at' => null,
                'expires_at' => null,
                'failure_reason' => null,
            ])->save();

            $this->rotateToken($locked, true);

            $locked->candidate?->forceFill([
                'expires_at' => null,
                'session_expires_at' => null,
                'status' => 'pending',
            ])->save();
        });

        SendInvitationEmailJob::dispatch($invitation->id)
            ->onQueue('invitations')
            ->afterCommit();

        return $invitation->fresh();
    }

    public function cancel(EmailInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $locked = EmailInvitation::query()
                ->with('jobCandidate')
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if ($locked->isClaimed() || $locked->jobCandidate?->interview_id !== null) {
                throw new RuntimeException('A claimed invitation cannot be cancelled. Use an interview revocation workflow instead.');
            }

            $locked->cancel();
        });
    }

    private function rotateToken(EmailInvitation $invitation, bool $save = true): string
    {
        $rawToken = Str::random(96);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $rawToken),
            'token_ciphertext' => Crypt::encryptString($rawToken),
        ]);

        if ($save) {
            $invitation->save();
        } else {
            $invitation->saveQuietly();
        }

        return $rawToken;
    }
}
