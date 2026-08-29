<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateJobRequest;
use App\Http\Requests\Company\UpdateCandidateStatusRequest;
use App\Http\Requests\CompanyInterview\UpdateCompanyInterviewSettingsRequest;
use App\Models\CandidateInterviewSessionEvent;
use App\Models\Company;
use App\Models\CompanyJob;
use App\Models\CompanyJobCandidate;
use App\Models\EmailInvitation;
use App\Models\Interview;
use App\Models\User;
use App\Services\CompanyInterview\CandidateInvitationService;
use App\Services\CompanyEmployeeAccessService;
use App\Services\CompanyInterview\CandidateSpreadsheetService;
use App\Services\CompanyJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompanyJobController extends Controller
{
    public function __construct(
        private readonly CompanyJobService $jobService,
        private readonly CandidateSpreadsheetService $spreadsheetService,
        private readonly CandidateInvitationService $invitationService,
        private readonly CompanyEmployeeAccessService $employeeAccessService,
    ) {
    }

    /**
     * Resolve the company that owns the authenticated account.
     */
    private function getCompany(): Company
    {
        $user = request()->user();

        if ($user instanceof Company) {
            return $user;
        }

        if ($user instanceof User && $user->isCompanyEmployee() && $user->company) {
            return $user->company;
        }

        abort(403, 'Company not found or unauthorized.');
    }

    private function authorizeJob(CompanyJob $job): Company
    {
        $company = $this->getCompany();
        abort_unless((int) $job->company_id === (int) $company->id, 403, 'Unauthorized.');

        return $company;
    }

    private function authorizeInvitation(CompanyJob $job, EmailInvitation $invitation): void
    {
        $this->authorizeJob($job);
        abort_unless((int) $invitation->company_job_id === (int) $job->id, 404, 'Invitation not found for this job.');
    }

    private function actorCan(string $permission): bool
    {
        $actor = request()->user();

        if ($actor instanceof Company) {
            return true;
        }

        return $actor instanceof User
            && $actor->isCompanyEmployee()
            && in_array(
                $permission,
                $this->employeeAccessService->permissionNames($actor),
                true
            );
    }

    /**
     * Create a job using the existing company job flow, enhanced with interview settings.
     */
    public function store(CreateJobRequest $request): JsonResponse
    {
        try {
            $company = $this->getCompany();
            $job = $this->jobService->createJob($company, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Job created successfully.',
                'data' => [
                    'job' => [
                        'id' => $job->id,
                        'title' => $job->titleForLocale(),
                        'unique_token' => $job->unique_token,
                        'shareable_link' => $job->getShareableLink(),
                        'expires_at' => $job->expires_at?->toISOString(),
                        'max_candidates' => $job->max_candidates,
                        'interview_settings' => $this->settingsPayload($job),
                    ],
                ],
            ], 201);
        } catch (Throwable $exception) {
            Log::error('Failed to create company job.', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create job: ' . $exception->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $company = $this->getCompany();

        $jobs = $company->jobs()
            ->withCount(['candidates', 'invitations'])
            ->latest('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 10))));

        return response()->json([
            'success' => true,
            'data' => $jobs->getCollection()->map(fn (CompanyJob $job): array => [
                'id' => $job->id,
                'title' => $job->titleForLocale(),
                'status' => $job->status,
                'shareable_link' => $job->getShareableLink(),
                'candidates_count' => (int) $job->candidates_count,
                'invitations_count' => (int) $job->invitations_count,
                'completed_candidates' => $job->candidates()
                    ->whereIn('status', [
                        CompanyJobCandidate::STATUS_COMPLETED,
                        CompanyJobCandidate::STATUS_SHORTLISTED,
                        CompanyJobCandidate::STATUS_REJECTED,
                        CompanyJobCandidate::STATUS_HIRED,
                    ])
                    ->count(),
                'interview_locale' => $job->normalizedInterviewLocale(),
                'questions_source' => $job->questions_source,
                'expires_at' => $job->expires_at?->toISOString(),
                'created_at' => $job->created_at?->toISOString(),
            ])->values(),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'total' => $jobs->total(),
                'per_page' => $jobs->perPage(),
            ],
        ]);
    }

    public function show(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $job->id,
                'title' => $job->titleForLocale(),
                'title_translations' => $job->title,
                'description' => $job->descriptionForLocale(),
                'description_translations' => $job->description,
                'required_skills' => $job->required_skills,
                'custom_questions' => $job->custom_questions,
                'difficulty' => $job->difficulty,
                'questions_source' => $job->questions_source,
                'number_of_questions' => (int) $job->number_of_questions,
                'ai_questions_count' => (int) $job->ai_questions_count,
                'company_questions_count' => (int) $job->company_questions_count,
                'max_candidates' => $job->max_candidates,
                'expires_at' => $job->expires_at?->toISOString(),
                'status' => $job->status,
                'shareable_link' => $job->getShareableLink(),
                'interview_settings' => $this->settingsPayload($job),
            ],
        ]);
    }

    public function close(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);
        $job->update(['status' => 'closed']);

        return response()->json([
            'success' => true,
            'message' => 'Job closed successfully.',
        ]);
    }

    public function destroy(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);
        $job->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job deleted successfully.',
        ]);
    }

    public function stats(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        $canViewCandidates = $this->actorCan('company.candidates.view');
        $canViewInterviews = $this->actorCan('company.interviews.view');
        $canViewResults = $this->actorCan('company.results.view');

        abort_unless(
            $canViewCandidates || $canViewInterviews || $canViewResults,
            403,
            'You do not have permission to view job statistics.'
        );

        $candidates = $job->candidates()
            ->with(['interview.finalReport', 'identityVerification'])
            ->get();
        $data = [];

        if ($canViewCandidates) {
            $data['total_candidates'] = $candidates->count();
            $data['status_breakdown'] = [
                'pending' => $candidates->where('status', CompanyJobCandidate::STATUS_PENDING)->count(),
                'in_progress' => $candidates->where('status', CompanyJobCandidate::STATUS_IN_PROGRESS)->count(),
                'completed' => $candidates->where('status', CompanyJobCandidate::STATUS_COMPLETED)->count(),
                'shortlisted' => $candidates->where('status', CompanyJobCandidate::STATUS_SHORTLISTED)->count(),
                'rejected' => $candidates->where('status', CompanyJobCandidate::STATUS_REJECTED)->count(),
                'hired' => $candidates->where('status', CompanyJobCandidate::STATUS_HIRED)->count(),
            ];
            $data['identity_breakdown'] = [
                'pending' => $candidates->whereIn('identity_status', ['pending', 'under_review'])->count(),
                'approved' => $candidates->where('identity_status', 'approved')->count(),
                'rejected' => $candidates->where('identity_status', 'rejected')->count(),
            ];
            $data['invitation_breakdown'] = [
                'total' => $job->invitations()->count(),
                'sent' => $job->invitations()->where('status', EmailInvitation::DELIVERY_SENT)->count(),
                'pending' => $job->invitations()->where('status', EmailInvitation::DELIVERY_PENDING)->count(),
                'failed' => $job->invitations()->where('status', EmailInvitation::DELIVERY_FAILED)->count(),
                'expired' => $job->invitations()->where('lifecycle_status', EmailInvitation::LIFECYCLE_EXPIRED)->count(),
            ];
        }

        if ($canViewInterviews || $canViewResults) {
            $data['completed_interviews'] = $candidates
                ->filter(fn (CompanyJobCandidate $item) => $item->interview?->finalReport !== null)
                ->count();
        }

        if ($canViewResults) {
            $scores = $candidates
                ->pluck('final_score')
                ->filter(fn ($score) => $score !== null)
                ->map(fn ($score) => (float) $score);
            $data['average_score'] = $scores->isNotEmpty()
                ? round((float) $scores->avg(), 2)
                : 0;
            $data['highest_score'] = $scores->isNotEmpty()
                ? round((float) $scores->max(), 2)
                : 0;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Existing candidates page API, now sourced from company_job_candidates.
     */
    public function candidates(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        $jobCandidates = $job->candidates()
            ->with([
                'candidate',
                'invitation',
                'interview.finalReport',
                'identityVerification',
            ])
            ->latest('id')
            ->get();

        $formatted = $jobCandidates->map(function (CompanyJobCandidate $jobCandidate): array {
            $candidate = $jobCandidate->candidate;
            $invitation = $jobCandidate->invitation;
            $interview = $jobCandidate->interview;
            $report = $interview?->finalReport;
            $identityStatus = $jobCandidate->identity_status
                ?: $jobCandidate->identityVerification?->status
                ?: 'pending';

            return [
                // Keep id as the pivot id so the existing status-update endpoint remains compatible.
                'id' => $jobCandidate->id,
                'job_candidate_id' => $jobCandidate->id,
                'candidate_id' => $candidate?->id,
                'name' => $candidate?->name,
                'email' => $candidate?->email,
                'phone' => $candidate?->phone,
                'status' => $jobCandidate->status,
                'candidate_status' => $candidate?->status,
                'identity_status' => $identityStatus,
                'final_score' => $jobCandidate->final_score !== null
                    ? (float) $jobCandidate->final_score
                    : ($candidate?->final_score !== null ? (float) $candidate->final_score : null),
                'source' => $jobCandidate->source,
                'company_notes' => $jobCandidate->company_notes,
                'invitation_id' => $invitation?->id,
                'invitation_delivery_status' => $invitation?->status,
                'invitation_lifecycle_status' => $invitation?->lifecycle_status,
                'invitation_expires_at' => $invitation?->expires_at?->toISOString(),
                'interview_id' => $interview?->id,
                'interview_status' => $interview?->status,
                'resume_count' => (int) ($interview?->resume_count ?? 0),
                'max_resume_count' => (int) ($interview?->max_resume_count ?? 3),
                'report_ready' => $report !== null,
                'identity_review_available' => $interview !== null
                    && !in_array($interview->status, [Interview::STATUS_PENDING, Interview::STATUS_IN_PROGRESS], true),
                'strengths' => $report?->strengths_analysis,
                'weaknesses' => $report?->improvement_areas,
                'recommendation' => $report?->hiring_recommendation,
                'invited_at' => $jobCandidate->invited_at?->toISOString(),
                'started_at' => $jobCandidate->started_at?->toISOString(),
                'completed_at' => $jobCandidate->completed_at?->toISOString(),
                'created_at' => $jobCandidate->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'job_id' => $job->id,
                'job_title' => $job->titleForLocale(),
                'total_candidates' => $formatted->count(),
                'candidates' => $formatted->values(),
            ],
        ]);
    }

    public function candidateDetails(CompanyJob $job, CompanyJobCandidate $candidate): JsonResponse
    {
        $this->authorizeJob($job);
        abort_unless((int) $candidate->company_job_id === (int) $job->id, 404, 'Candidate not found for this job.');

        $candidate->load([
            'candidate',
            'invitation',
            'identityVerification',
            'interview.questions.answers.evaluation',
            'interview.finalReport',
        ]);

        $interview = $candidate->interview;
        $report = $interview?->finalReport;
        $canViewResults = $this->actorCan('company.results.view');

        $questions = $canViewResults
            ? $interview?->questions
            ->sortBy('order')
            ->values()
            ->map(function ($question): array {
                $answer = $question->answers->first();
                $evaluation = $answer?->evaluation;

                return [
                    'id' => $question->id,
                    'text' => method_exists($question, 'translate')
                        ? $question->translate('question_text')
                        : $question->question_text,
                    'type' => $question->type,
                    'source' => $question->source,
                    'answer_transcript' => $answer?->transcription,
                    'score' => $evaluation?->score !== null ? round((float) $evaluation->score * 10, 2) : null,
                    'strengths' => $evaluation?->strengths,
                    'weaknesses' => $evaluation?->weaknesses,
                    'feedback' => $evaluation?->detailed_feedback,
                ];
            }) ?? collect()
            : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'candidate' => [
                    'id' => $candidate->candidate?->id,
                    'name' => $candidate->candidate?->name,
                    'email' => $candidate->candidate?->email,
                    'phone' => $candidate->candidate?->phone,
                ],
                'job_candidate' => [
                    'id' => $candidate->id,
                    'status' => $candidate->status,
                    'identity_status' => $candidate->identity_status,
                    'final_score' => $candidate->final_score !== null ? (float) $candidate->final_score : null,
                    'source' => $candidate->source,
                    'company_notes' => $candidate->company_notes,
                    'completed_at' => $candidate->completed_at?->toISOString(),
                ],
                'invitation' => $candidate->invitation ? [
                    'id' => $candidate->invitation->id,
                    'delivery_status' => $candidate->invitation->status,
                    'lifecycle_status' => $candidate->invitation->lifecycle_status,
                    'expires_at' => $candidate->invitation->expires_at?->toISOString(),
                ] : null,
                'interview' => $interview ? [
                    'id' => $interview->id,
                    'status' => $interview->status,
                    'resume_count' => (int) ($interview->resume_count ?? 0),
                    'max_resume_count' => (int) ($interview->max_resume_count ?? 3),
                ] : null,
                'report' => $canViewResults && $report ? [
                    'executive_summary' => $report->executive_summary,
                    'strengths_analysis' => $report->strengths_analysis,
                    'improvement_areas' => $report->improvement_areas,
                    'hiring_recommendation' => $report->hiring_recommendation,
                    'technical_score' => $report->technical_score !== null ? round((float) $report->technical_score * 10, 2) : null,
                    'communication_score' => $report->communication_score !== null ? round((float) $report->communication_score * 10, 2) : null,
                    'problem_solving_score' => $report->problem_solving_score !== null ? round((float) $report->problem_solving_score * 10, 2) : null,
                ] : null,
                'questions' => $questions,
            ],
        ]);
    }

    public function updateCandidateStatus(
        CompanyJob $job,
        CompanyJobCandidate $candidate,
        UpdateCandidateStatusRequest $request
    ): JsonResponse {
        $this->authorizeJob($job);
        abort_unless((int) $candidate->company_job_id === (int) $job->id, 404, 'Candidate not found for this job.');

        $candidate->updateStatus($request->string('status')->toString(), $request->input('company_notes'));

        return response()->json([
            'success' => true,
            'message' => 'Candidate status updated successfully.',
            'data' => [
                'status' => $candidate->status,
                'company_notes' => $candidate->company_notes,
            ],
        ]);
    }

    /**
     * Preview the existing Excel invitation file before importing it.
     */
    public function previewCandidateImport(Request $request, CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);
        $validated = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->spreadsheetService->preview($job, $validated['excel_file']),
        ]);
    }

    /**
     * Existing endpoint kept for compatibility. It now uses the secure invitation flow.
     */
    public function inviteBulk(Request $request, CompanyJob $job): JsonResponse
    {
        return $this->confirmCandidateImport($request, $job);
    }

    public function confirmCandidateImport(Request $request, CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);
        $validated = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $preview = $this->spreadsheetService->preview($job, $validated['excel_file']);
        $imported = [];
        $failed = [];

        foreach ($preview['rows'] as $row) {
            if (!$row['valid']) {
                continue;
            }

            try {
                $invitation = $this->invitationService->createAndDispatch($job, $row);
                $imported[] = [
                    'row_number' => $row['row_number'],
                    'candidate_id' => $invitation->candidate_id,
                    'invitation_id' => $invitation->id,
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                ];
            } catch (Throwable $exception) {
                $failed[] = [
                    'row_number' => $row['row_number'],
                    'email' => $row['email'],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $status = count($imported) > 0 ? 201 : 422;

        return response()->json([
            'success' => count($imported) > 0,
            'message' => count($imported) > 0
                ? 'Candidate import completed. Valid invitations were queued for delivery.'
                : 'No valid candidates were imported.',
            'data' => [
                'preview_summary' => $preview['summary'],
                'imported_count' => count($imported),
                'failed_during_import_count' => count($failed),
                'imported' => $imported,
                'failed_during_import' => $failed,
                'invalid_rows' => array_values(array_filter(
                    $preview['rows'],
                    fn (array $row): bool => !$row['valid']
                )),
            ],
        ], $status);
    }

    public function invitationStats(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $job->invitations()->count(),
                'sent' => $job->invitations()->where('status', EmailInvitation::DELIVERY_SENT)->count(),
                'pending' => $job->invitations()->where('status', EmailInvitation::DELIVERY_PENDING)->count(),
                'failed' => $job->invitations()->where('status', EmailInvitation::DELIVERY_FAILED)->count(),
                'opened' => $job->invitations()->whereNotNull('opened_at')->count(),
                'claimed' => $job->invitations()->whereNotNull('claimed_at')->count(),
                'completed' => $job->invitations()->where('lifecycle_status', EmailInvitation::LIFECYCLE_COMPLETED)->count(),
                'expired' => $job->invitations()->where('lifecycle_status', EmailInvitation::LIFECYCLE_EXPIRED)->count(),
                'cancelled' => $job->invitations()->where('lifecycle_status', EmailInvitation::LIFECYCLE_CANCELLED)->count(),
            ],
        ]);
    }

    public function invitations(CompanyJob $job, Request $request): JsonResponse
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

        $invitations->through(function (EmailInvitation $invitation): array {
            $jobCandidate = $invitation->jobCandidate;
            $interview = $jobCandidate?->interview;

            return [
                'id' => $invitation->id,
                'candidate_id' => $invitation->candidate_id,
                'job_candidate_id' => $invitation->company_job_candidate_id,
                'interview_id' => $interview?->id,
                'name' => $invitation->name,
                'email' => $invitation->email,
                // status is kept for the existing table component.
                'status' => $invitation->status,
                'delivery_status' => $invitation->status,
                'lifecycle_status' => $invitation->lifecycle_status,
                'candidate_status' => $invitation->candidate?->status,
                'employment_status' => $jobCandidate?->status,
                'identity_status' => $jobCandidate?->identity_status ?? 'pending',
                'interview_status' => $interview?->status,
                'report_ready' => $interview?->finalReport !== null,
                'identity_review_available' => $interview !== null
                    && !in_array($interview->status, [Interview::STATUS_PENDING, Interview::STATUS_IN_PROGRESS], true),
                'resume_count' => (int) ($interview?->resume_count ?? 0),
                'max_resume_count' => (int) ($interview?->max_resume_count ?? 3),
                'send_attempts' => (int) $invitation->send_attempts,
                'sent_at' => $invitation->sent_at?->toISOString(),
                'expires_at' => $invitation->expires_at?->toISOString(),
                'opened_at' => $invitation->opened_at?->toISOString(),
                'claimed_at' => $invitation->claimed_at?->toISOString(),
                'completed_at' => $invitation->completed_at?->toISOString(),
                'created_at' => $invitation->created_at?->toISOString(),
                'failure_reason' => $invitation->failure_reason,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $invitations,
        ]);
    }

    public function resendInvitation(CompanyJob $job, EmailInvitation $invitation): JsonResponse
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
            ],
        ]);
    }

    public function cancelInvitation(CompanyJob $job, EmailInvitation $invitation): JsonResponse
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

    public function interviewSettings(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        return response()->json([
            'success' => true,
            'data' => $this->settingsPayload($job),
        ]);
    }

    public function updateInterviewSettings(
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
            'data' => $this->settingsPayload($job->fresh()),
        ]);
    }

    public function uploadQuestions(Request $request, CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);
        $validated = $request->validate([
            'questions_file' => ['required', 'file', 'mimes:xlsx,csv,xls', 'max:10240'],
        ]);

        $service = app(\App\Services\QuestionBankService::class);
        $result = $service->uploadQuestions($job, $validated['questions_file']);

        return response()->json([
            'success' => true,
            'message' => 'Questions uploaded successfully.',
            'data' => $result,
        ]);
    }

    public function questionStats(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);
        $stats = app(\App\Services\QuestionBankService::class)->getQuestionStats($job);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function getQuestionBank(CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);
        $questionBank = $job->questionBank;

        if (!$questionBank) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No question bank found for this job.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $questionBank->id,
                'total_questions' => $questionBank->total_questions,
                'questions' => $questionBank->questions,
            ],
        ]);
    }

    private function settingsPayload(CompanyJob $job): array
    {
        return [
            'job_id' => $job->id,
            'interview_locale' => $job->normalizedInterviewLocale(),
            'questions_source' => $job->questions_source,
            'number_of_questions' => (int) $job->number_of_questions,
            'ai_questions_count' => (int) $job->ai_questions_count,
            'company_questions_count' => (int) $job->company_questions_count,
            'difficulty' => $job->difficulty,
            'question_order' => $job->question_order ?: 'random',
            'invitation_valid_hours' => (int) ($job->invitation_valid_hours ?? 72),
            'max_resume_count' => (int) ($job->max_resume_count ?? 3),
            'interview_duration_minutes' => (int) ($job->interview_duration_minutes ?? 120),
            'random_snapshot_count' => (int) ($job->random_snapshot_count ?? 3),
            'liveness_challenge_count' => 0,
            'identity_verification_required' => (bool) $job->identity_verification_required,
            'identity_document_required' => (bool) $job->identity_document_required,
            'liveness_required' => false,
            'delete_identity_evidence_after_review' => (bool) $job->delete_identity_evidence_after_review,
            'interview_instructions' => $job->interview_instructions ?? [],
        ];
    }
}
