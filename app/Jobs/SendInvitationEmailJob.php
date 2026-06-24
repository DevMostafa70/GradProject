<?php

namespace App\Jobs;

use App\Models\CompanyJob;
use App\Models\EmailInvitation;
use App\Mail\JobInvitationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInvitationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected EmailInvitation $invitation;
    protected CompanyJob $companyJob;
    protected string $invitationToken; // ✅ جديد

    public function __construct(EmailInvitation $invitation, CompanyJob $companyJob, string $invitationToken)
    {
        $this->invitation = $invitation;
        $this->companyJob = $companyJob;
        $this->invitationToken = $invitationToken; // ✅ حفظ التوكن
    }

    public function handle(): void
    {
        try {
            Mail::to($this->invitation->email)
                ->send(new JobInvitationMail($this->invitation, $this->companyJob, $this->invitationToken));

            $this->invitation->markAsSent();
        } catch (\Exception $e) {
            $this->invitation->markAsFailed();
            Log::error('Failed to send invitation email', [
                'email' => $this->invitation->email,
                'job_id' => $this->companyJob->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
