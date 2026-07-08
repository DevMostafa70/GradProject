<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateJobRequest;
use App\Http\Requests\Company\UpdateCandidateStatusRequest;
use App\Models\Company;
use App\Models\User;
use App\Models\CompanyJob;
use App\Models\CompanyJobCandidate;
use App\Services\CompanyJobService;
use App\Imports\ContactsImport;
use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class CompanyJobController extends Controller
{
    protected CompanyJobService $jobService;

    public function __construct(CompanyJobService $jobService)
    {
        $this->jobService = $jobService;
    }

    /**
     * Get authenticated company or employee's company
     */
    private function getCompany()
    {
        $user = auth()->user();

        if (!$user) {
            throw new \Exception('Company not found or unauthorized');
        }

        // ✅ إذا كان المستخدم Company Owner
        if ($user instanceof \App\Models\Company) {
            return $user;
        }

        // ✅ إذا كان المستخدم موظف (User model مع is_company_employee = true)
        if ($user instanceof \App\Models\User && $user->isCompanyEmployee()) {
            $company = $user->company;
            if ($company) {
                return $company;
            }
        }

        throw new \Exception('Company not found or unauthorized');
    }

    /**
     * Create a new job posting
     */
    public function store(CreateJobRequest $request): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if (!$company) {
                Log::warning('Company not found in store method');
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found. Please login again.',
                ], 404);
            }

            Log::info('Creating job for company ID: ' . $company->id);
            Log::info('Job data:', $request->validated());

            $job = $this->jobService->createJob($company, $request->validated());

            Log::info('Job created successfully with ID: ' . $job->id);

            return response()->json([
                'success' => true,
                'message' => 'Job created successfully',
                'data' => [
                    'job' => [
                        'id' => $job->id,
                        'title' => $job->title,
                        'unique_token' => $job->unique_token,
                        'shareable_link' => $job->getShareableLink(),
                        'expires_at' => $job->expires_at,
                        'max_candidates' => $job->max_candidates,
                    ],
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating job: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create job: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all jobs for the company
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $company = $this->getCompany();

            $jobs = $company->jobs()
                ->withCount('candidates')
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 10));

            return response()->json([
                'success' => true,
                'data' => $jobs->map(function ($job) {
                    return [
                        'id' => $job->id,
                        'title' => $job->title,
                        'status' => $job->status,
                        'shareable_link' => $job->getShareableLink(),
                        'candidates_count' => $job->candidates_count,
                        'completed_candidates' => $job->candidates()
                            ->whereNotNull('final_score')
                            ->count(),
                        'expires_at' => $job->expires_at,
                        'created_at' => $job->created_at,
                    ];
                }),
                'meta' => [
                    'current_page' => $jobs->currentPage(),
                    'total' => $jobs->total(),
                    'per_page' => $jobs->perPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get job details
     */
    public function show(CompanyJob $job): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $job->id,
                    'title' => $job->title,
                    'description' => $job->description,
                    'required_skills' => $job->required_skills,
                    'custom_questions' => $job->custom_questions,
                    'difficulty' => $job->difficulty,
                    'max_candidates' => $job->max_candidates,
                    'expires_at' => $job->expires_at,
                    'status' => $job->status,
                    'shareable_link' => $job->getShareableLink(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Close a job posting
     */
    public function close(CompanyJob $job): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $job->update(['status' => 'closed']);

            return response()->json([
                'success' => true,
                'message' => 'Job closed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

/**
 * Get job statistics
 */
public function stats(CompanyJob $job): JsonResponse
{
    try {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // ✅ جلب المرشحين من جدول candidates مباشرة
        $candidates = Candidate::where('company_job_id', $job->id)->get();

        $stats = [
            'total_candidates' => $candidates->count(),
            'completed_interviews' => $candidates->whereNotNull('final_score')->count(),
            'average_score' => $candidates->avg('final_score') ? round($candidates->avg('final_score'), 2) : 0,
            'highest_score' => $candidates->max('final_score') ? round($candidates->max('final_score'), 2) : 0,
            'status_breakdown' => [
                'pending' => $candidates->where('status', 'pending')->count(),
                'in_progress' => $candidates->where('status', 'in_progress')->count(),
                'completed' => $candidates->where('status', 'completed')->count(),
                'shortlisted' => $candidates->where('status', 'shortlisted')->count(),
                'rejected' => $candidates->where('status', 'rejected')->count(),
                'hired' => $candidates->where('status', 'hired')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Get ranked candidates for a job
 */
public function candidates(CompanyJob $job): JsonResponse
{
    try {
        $company = $this->getCompany();

        if ($job->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // ✅ جلب المرشحين من جدول candidates مباشرة
        $candidates = Candidate::where('company_job_id', $job->id)
            ->with(['interview', 'interview.finalReport'])
            ->orderBy('created_at', 'desc')
            ->get();

        // ✅ تنسيق البيانات
        $formattedCandidates = $candidates->map(function ($candidate) {
            $interview = $candidate->interview;
            $finalReport = $interview?->finalReport;

            return [
                'id' => $candidate->id,
                'candidate_id' => $candidate->id,
                'name' => $candidate->name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'status' => $candidate->status,
                'final_score' => $candidate->final_score,
                'source' => null,
                'company_notes' => null,
                'invited_at' => $candidate->invited_at?->toISOString(),
                'started_at' => $candidate->started_at?->toISOString(),
                'completed_at' => $candidate->completed_at?->toISOString(),
                'interview_id' => $interview?->id,
                'strengths' => $finalReport?->strengths_analysis,
                'weaknesses' => $finalReport?->improvement_areas,
                'recommendation' => $finalReport?->hiring_recommendation,
                'created_at' => $candidate->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'job_title' => $job->title,
                'total_candidates' => $candidates->count(),
                'candidates' => $formattedCandidates,
            ],
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}
    /**
     * Get candidate details with full interview results
     */
    public function candidateDetails(CompanyJob $job, CompanyJobCandidate $jobCandidate): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($jobCandidate->company_job_id !== $job->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Candidate not found for this job',
                ], 404);
            }

            $interview = $jobCandidate->interview;
            $finalReport = $interview?->finalReport;

            $questions = [];
            if ($interview) {
                $questions = $interview->questions()
                    ->with(['answers.evaluation'])
                    ->orderBy('order')
                    ->get()
                    ->map(function ($question) {
                        $answer = $question->answers->first();
                        $evaluation = $answer?->evaluation;

                        return [
                            'id' => $question->id,
                            'text' => $question->question_text,
                            'type' => $question->type,
                            'source' => $question->source,
                            'answer_transcript' => $answer?->transcription,
                            'score' => $evaluation ? round($evaluation->score * 10, 2) : null,
                            'strengths' => $evaluation?->strengths,
                            'weaknesses' => $evaluation?->weaknesses,
                            'feedback' => $evaluation?->detailed_feedback,
                        ];
                    });
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'candidate' => [
                        'id' => $jobCandidate->candidate->id,
                        'name' => $jobCandidate->candidate->name,
                        'email' => $jobCandidate->candidate->email,
                    ],
                    'job_candidate' => [
                        'status' => $jobCandidate->status,
                        'final_score' => $jobCandidate->final_score,
                        'source' => $jobCandidate->source,
                        'company_notes' => $jobCandidate->company_notes,
                        'completed_at' => $jobCandidate->completed_at,
                    ],
                    'report' => $finalReport ? [
                        'executive_summary' => $finalReport->executive_summary,
                        'strengths_analysis' => $finalReport->strengths_analysis,
                        'improvement_areas' => $finalReport->improvement_areas,
                        'hiring_recommendation' => $finalReport->hiring_recommendation,
                        'technical_score' => $finalReport->technical_score ? round($finalReport->technical_score * 10, 2) : null,
                        'communication_score' => $finalReport->communication_score ? round($finalReport->communication_score * 10, 2) : null,
                        'problem_solving_score' => $finalReport->problem_solving_score ? round($finalReport->problem_solving_score * 10, 2) : null,
                    ] : null,
                    'questions' => $questions,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update candidate status
     */
    public function updateCandidateStatus(
        CompanyJob $job,
        CompanyJobCandidate $jobCandidate,
        UpdateCandidateStatusRequest $request
    ): JsonResponse {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($jobCandidate->company_job_id !== $job->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Candidate not found for this job',
                ], 404);
            }

            $jobCandidate->updateStatus(
                $request->status,
                $request->company_notes
            );

            return response()->json([
                'success' => true,
                'message' => 'Candidate status updated successfully',
                'data' => [
                    'status' => $jobCandidate->status,
                    'company_notes' => $jobCandidate->company_notes,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk invite candidates via Excel file
     */
    public function inviteBulk(Request $request, CompanyJob $job): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $request->validate([
                'excel_file' => 'required|file|mimes:xlsx,csv,xls|max:10240',
            ]);

            Excel::queueImport(new ContactsImport($job), $request->file('excel_file'));

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully. Invitations are being processed and will be sent shortly.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get bulk invitation stats for a job
     */
    public function invitationStats(CompanyJob $job): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $stats = [
                'total' => $job->invitations()->count(),
                'sent' => $job->invitations()->where('status', 'sent')->count(),
                'pending' => $job->invitations()->where('status', 'pending')->count(),
                'failed' => $job->invitations()->where('status', 'failed')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get invitations list for a job
     */
    public function invitations(CompanyJob $job, Request $request): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $invitations = $job->invitations()
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $invitations,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload questions file for a job
     */
    public function uploadQuestions(Request $request, CompanyJob $job): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $request->validate([
                'questions_file' => 'required|file|mimes:xlsx,csv,xls|max:10240',
            ]);

            $questionBankService = new \App\Services\QuestionBankService();
            $result = $questionBankService->uploadQuestions($job, $request->file('questions_file'));

            return response()->json([
                'success' => true,
                'message' => 'Questions uploaded successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get question statistics for a job
     */
    public function questionStats(CompanyJob $job): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $questionBankService = new \App\Services\QuestionBankService();
            $stats = $questionBankService->getQuestionStats($job);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get question bank for a job
     */
    public function getQuestionBank(CompanyJob $job): JsonResponse
    {
        try {
            $company = $this->getCompany();

            if ($job->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $questionBank = $job->questionBank;

            if (!$questionBank) {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'No question bank found for this job',
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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
