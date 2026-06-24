<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageEventType: string
{
    case JobCreated = 'job_created';
    case CandidateImported = 'candidate_imported';
    case InterviewStarted = 'interview_started';
    case InterviewCompleted = 'interview_completed';
    case FinalReportGenerated = 'final_report_generated';
    case CvReviewGenerated = 'cv_review_generated';
    case EmailInvitationSent = 'email_invitation_sent';
}
