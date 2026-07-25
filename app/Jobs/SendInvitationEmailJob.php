<?php

namespace App\Jobs;

use App\Mail\JobInvitationMail;
use App\Models\EmailInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendInvitationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $invitationId)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('company-invitation:' . $this->invitationId))->expireAfter(180)];
    }

    public function handle(): void
    {
        $invitation = EmailInvitation::query()
            ->with(['job.company', 'candidate'])
            ->findOrFail($this->invitationId);

        if ($invitation->isCancelled() || (
            $invitation->status === EmailInvitation::DELIVERY_SENT
            && $invitation->token_ciphertext === null
        )) {
            return;
        }

        if ($invitation->token_ciphertext === null) {
            throw new RuntimeException('Invitation token payload is missing. Rotate the invitation before sending.');
        }

        $invitation->forceFill([
            'send_attempts' => (int) $invitation->send_attempts + 1,
        ])->save();

        $rawToken = Crypt::decryptString($invitation->token_ciphertext);
        $sentAt = now();
        $expiresAt = $invitation->job->invitationExpiresAt($sentAt);

        // The validity window starts at the successful delivery attempt, not at import time.
        $invitation->setAttribute('sent_at', $sentAt);
        $invitation->setAttribute('expires_at', $expiresAt);

        Mail::to($invitation->email)->send(
            new JobInvitationMail($invitation, $invitation->job, $rawToken)
        );

        $invitation->markAsSent($sentAt, $expiresAt);

        Log::info('Company candidate invitation sent.', [
            'invitation_id' => $invitation->id,
            'job_id' => $invitation->company_job_id,
            'candidate_id' => $invitation->candidate_id,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $invitation = EmailInvitation::query()->find($this->invitationId);

        if ($invitation !== null) {
            $invitation->markAsFailed($exception?->getMessage());
        }

        Log::error('Company candidate invitation failed permanently.', [
            'invitation_id' => $this->invitationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
