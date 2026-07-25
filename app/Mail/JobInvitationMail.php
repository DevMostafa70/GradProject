<?php

namespace App\Mail;

use App\Models\CompanyJob;
use App\Models\EmailInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JobInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public EmailInvitation $invitation,
        public CompanyJob $job,
        private readonly string $invitationToken,
    ) {
    }

    public function envelope(): Envelope
    {
        $locale = $this->job->normalizedInterviewLocale();
        $jobTitle = $this->job->titleForLocale($locale);

        return new Envelope(
            subject: $locale === 'ar'
                ? "دعوة لمقابلة وظيفية - {$jobTitle}"
                : "Job interview invitation - {$jobTitle}",
        );
    }

    public function content(): Content
    {
        $locale = $this->job->normalizedInterviewLocale();

        return new Content(
            view: 'emails.job-invitation',
            with: [
                'candidateName' => $this->invitation->name,
                'jobTitle' => $this->job->titleForLocale($locale),
                'companyName' => $this->job->company->company_name,
                'invitationLink' => $this->invitationLink(),
                'skills' => $this->job->required_skills,
                'locale' => $locale,
                'expiresAt' => $this->invitation->expires_at,
                'validHours' => $this->job->invitation_valid_hours,
                'identityRequired' => (bool) $this->job->identity_verification_required,
            ],
        );
    }

    private function invitationLink(): string
    {
        $baseUrl = rtrim((string) config('company_interviews.frontend_url'), '/');

        return "{$baseUrl}/company-interview/{$this->invitationToken}";
    }
}
