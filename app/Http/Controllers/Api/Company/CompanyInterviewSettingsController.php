<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyInterview\UpdateCompanyInterviewSettingsRequest;
use App\Models\CompanyJob;
use App\Support\ResolvesAuthenticatedCompany;
use Illuminate\Http\JsonResponse;

class CompanyInterviewSettingsController extends Controller
{
    use ResolvesAuthenticatedCompany;

    public function show(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        return response()->json([
            'success' => true,
            'data' => $this->payload($job),
        ]);
    }

    public function update(
        UpdateCompanyInterviewSettingsRequest $request,
        CompanyJob $job
    ): JsonResponse {
        $this->authorizeJob($job);

        if ($job->invitations()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Interview settings cannot be changed after candidate invitations are created.',
            ], 409);
        }

        $job->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Interview settings updated.',
            'data' => $this->payload($job->fresh()),
        ]);
    }

    private function payload(CompanyJob $job): array
    {
        return [
            'job_id' => $job->id,
            'interview_locale' => $job->normalizedInterviewLocale(),
            'questions_source' => $job->questions_source,
            'number_of_questions' => (int) $job->number_of_questions,
            'ai_questions_count' => (int) $job->ai_questions_count,
            'company_questions_count' => (int) $job->company_questions_count,
            'difficulty' => $job->difficulty,
            'question_order' => $job->question_order,
            'invitation_valid_hours' => (int) $job->invitation_valid_hours,
            'max_resume_count' => (int) $job->max_resume_count,
            'interview_duration_minutes' => (int) $job->interview_duration_minutes,
            'random_snapshot_count' => (int) $job->random_snapshot_count,
            'liveness_challenge_count' => 0,
            'identity_verification_required' => (bool) $job->identity_verification_required,
            'identity_document_required' => (bool) $job->identity_document_required,
            'liveness_required' => false,
            'delete_identity_evidence_after_review' => (bool) $job->delete_identity_evidence_after_review,
            'interview_instructions' => $job->interview_instructions,
        ];
    }

    private function authorizeJob(CompanyJob $job): void
    {
        abort_unless($job->company_id === $this->authenticatedCompany()->id, 403, 'Unauthorized.');
    }
}
