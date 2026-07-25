<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CompanyInterviewAccessException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSingleAnswerJob;
use App\Models\Answer;
use App\Models\AntiCheatLog;
use App\Models\CandidateIdentityEvidence;
use App\Models\CandidateIdentityVerification;
use App\Models\CandidateInterviewSnapshotRequest;
use App\Models\EmailInvitation;
use App\Models\Interview;
use App\Models\Question;
use App\Services\CompanyInterview\CompanyInterviewQuestionService;
use App\Services\CompanyInterview\CompanyInterviewSessionService;
use App\Services\CompanyInterview\IdentityEvidenceService;
use App\Services\CompanyInterview\SnapshotRequestService;
use App\Services\FinalReportCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PublicCompanyInterviewController extends Controller
{
    public function __construct(
        private readonly CompanyInterviewSessionService $sessionService,
        private readonly CompanyInterviewQuestionService $questionService,
        private readonly IdentityEvidenceService $evidenceService,
        private readonly SnapshotRequestService $snapshotService,
        private readonly FinalReportCoordinator $reportCoordinator,
    ) {}

    public function showInvitation(string $token): JsonResponse
    {
        return $this->respond(function () use ($token): array {
            $invitation = $this->sessionService->resolveInvitation($token, true);
            $job = $invitation->job;

            if ($invitation->isClaimed()) {
                throw new CompanyInterviewAccessException(
                    'This invitation was already used. Continue from the saved interview session.',
                    409,
                    ['already_claimed' => true]
                );
            }

            return [
                'candidate' => [
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                ],
                'job' => [
                    'id' => $job->id,
                    'title' => $job->titleForLocale(),
                    'description' => $job->descriptionForLocale(),
                    'required_skills' => $job->required_skills,
                    'difficulty' => $job->difficulty,
                    'number_of_questions' => $job->number_of_questions,
                    'locale' => $job->normalizedInterviewLocale(),
                    'instructions' => $job->instructionsForLocale(),
                ],
                'invitation' => [
                    'expires_at' => $invitation->expires_at?->toISOString(),
                    'valid_hours' => $job->invitation_valid_hours,
                    'one_time_link' => true,
                ],
                'identity_requirements' => $this->identityRequirements($job),
                'session_policy' => [
                    'max_resumes' => (int) $job->max_resume_count,
                    'same_device_only' => true,
                    'concurrent_tabs_allowed' => false,
                ],
            ];
        });
    }

    public function claim(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'browser_secret' => ['required', 'string', 'min:32', 'max:255'],
            'device_fingerprint' => ['required', 'string', 'min:16', 'max:1000'],
            'session_instance_id' => ['required', 'string', 'min:8', 'max:64'],
            'consent_accepted' => ['accepted'],
        ]);

        return $this->respond(function () use ($token, $validated, $request): array {
            $invitation = $this->sessionService->resolveInvitation($token);
            $claimed = $this->sessionService->claim($invitation, $validated, $request);
            /** @var Interview $interview */
            $interview = $claimed['interview'];
            /** @var CandidateIdentityVerification $verification */
            $verification = $claimed['verification'];

            return [
                'session_token' => $claimed['session_token'],
                'interview_id' => $interview->id,
                'locale' => $interview->locale,
                'status' => $interview->status,
                'identity_status' => $verification->status,
                'max_resumes' => (int) $interview->max_resume_count,
                'resume_count' => (int) $interview->resume_count,
                'expires_at' => $interview->expires_at?->toISOString(),
            ];
        }, 201);
    }

    public function resume(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $result = $this->sessionService->resume($request);
            /** @var Interview $interview */
            $interview = $result['interview'];

            return array_merge(
                $this->sessionState($interview),
                ['resume_counted' => $result['resume_counted']]
            );
        });
    }

    public function heartbeat(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $interview = $this->sessionService->authenticate($request);
            $this->sessionService->heartbeat($interview, $request);

            $snapshotRequest = $interview->status === Interview::STATUS_IN_PROGRESS
                ? $this->snapshotService->issueDueRequest($interview)
                : null;

            return [
                'server_time' => now()->toISOString(),
                'interview_status' => $interview->status,
                'resume_count' => (int) $interview->resume_count,
                'max_resume_count' => (int) $interview->max_resume_count,
                'snapshot_request' => $snapshotRequest,
            ];
        });
    }

    public function state(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $interview = $this->sessionService->authenticate($request);

            return $this->sessionState($interview);
        });
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $maxKb = (int) config('company_interviews.identity.max_document_kb', 10240);
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', "max:{$maxKb}"],
            'document_type' => ['required', 'in:national_id,passport,driver_license,other'],
            'side' => ['required', 'in:front,back'],
        ]);

        return $this->respond(function () use ($request, $validated): array {
            $interview = $this->sessionService->authenticate($request);
            $this->assertIdentityCollectionOpen($interview);
            [$verification, $job] = $this->verificationContext($interview);
            $type = $validated['side'] === 'front'
                ? CandidateIdentityEvidence::TYPE_DOCUMENT_FRONT
                : CandidateIdentityEvidence::TYPE_DOCUMENT_BACK;

            $evidence = $this->evidenceService->replaceSingleEvidence(
                $verification,
                $interview,
                $validated['document'],
                $type,
                ['document_type' => $validated['document_type']]
            );

            $verification->forceFill(['document_type' => $validated['document_type']])->save();
            $verification->refreshSubmissionStatus($job);
            $this->syncIdentityStatus($verification);

            return [
                'evidence_id' => $evidence->id,
                'type' => $evidence->type,
                'identity_status' => $verification->fresh()->status,
            ];
        }, 201);
    }

    public function identityStatus(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $interview = $this->sessionService->authenticate($request);
            [$verification, $job] = $this->verificationContext($interview);

            return $this->identityState($verification, $job);
        });
    }

    public function start(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $interview = $this->sessionService->authenticate($request);
            [$verification, $job] = $this->verificationContext($interview);

            if ($interview->status === Interview::STATUS_IN_PROGRESS) {
                return $this->sessionState($interview);
            }

            if ($interview->status !== Interview::STATUS_PENDING) {
                throw new CompanyInterviewAccessException('This interview cannot be started in its current state.', 409);
            }

            if (!$verification->hasRequiredEvidence($job)) {
                throw new CompanyInterviewAccessException(
                    'Complete the required identity document before starting.',
                    422,
                    $this->identityState($verification, $job)
                );
            }

            $questions = DB::transaction(function () use ($job, $interview, $verification): array {
                $questions = $this->questionService->createQuestions($job, $interview);
                $sessionExpiresAt = now()->addMinutes((int) $job->interview_duration_minutes);

                if ($interview->email_invitation_id) {
                    EmailInvitation::query()
                        ->whereKey($interview->email_invitation_id)
                        ->update([
                            'lifecycle_status' => EmailInvitation::LIFECYCLE_IN_PROGRESS,
                            'updated_at' => now(),
                        ]);
                }

                $interview->forceFill([
                    'status' => Interview::STATUS_IN_PROGRESS,
                    'started_at' => $interview->started_at ?? now(),
                    'expires_at' => $sessionExpiresAt,
                    'last_activity_at' => now(),
                    'last_heartbeat_at' => now(),
                ])->save();

                $interview->candidate?->forceFill([
                    'status' => 'in_progress',
                    'started_at' => $interview->candidate?->started_at ?? now(),
                    'session_expires_at' => $sessionExpiresAt,
                ])->save();

                $verification->jobCandidate?->forceFill([
                    'status' => 'in_progress',
                    'started_at' => $verification->jobCandidate?->started_at ?? now(),
                    'identity_status' => $verification->status,
                ])->save();

                return $questions;
            });

            $estimatedSeconds = max(
                120,
                collect($questions)->sum(fn(Question $question): int => (int) ($question->time_allocation_seconds ?: 60))
            );

            $this->snapshotService->schedule(
                $interview,
                (int) $job->random_snapshot_count,
                $estimatedSeconds
            );

            return $this->sessionState($interview->fresh());
        });
    }

    public function uploadSnapshot(Request $request): JsonResponse
    {
        $maxKb = (int) config('company_interviews.identity.max_image_kb', 5120);
        $validated = $request->validate([
            'snapshot' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', "max:{$maxKb}"],
            'snapshot_request_token' => ['required', 'string', 'min:20', 'max:255'],
            'question_id' => ['nullable', 'integer', 'exists:questions,id'],
            'capture_metadata' => ['nullable', 'array'],
        ]);

        return $this->respond(function () use ($request, $validated): array {
            $interview = $this->sessionService->authenticate($request);
            $this->assertInterviewInProgress($interview);
            [$verification] = $this->verificationContext($interview);
            $snapshotRequest = $this->snapshotService->consume(
                $interview,
                $validated['snapshot_request_token']
            );

            if (!empty($validated['question_id'])) {
                $belongs = Question::query()
                    ->where('id', $validated['question_id'])
                    ->where('interview_id', $interview->id)
                    ->exists();
                abort_unless($belongs, 422, 'Question does not belong to this interview.');
            }

            try {
                $evidence = $this->evidenceService->store(
                    $verification,
                    $interview,
                    $validated['snapshot'],
                    CandidateIdentityEvidence::TYPE_INTERVIEW_SNAPSHOT,
                    $validated['question_id'] ?? null,
                    $validated['capture_metadata'] ?? []
                );
            } catch (Throwable $exception) {
                $this->snapshotService->markCaptureFailed($snapshotRequest, $exception->getMessage());
                throw $exception;
            }

            $this->snapshotService->markCaptured($snapshotRequest);
            $interview->increment('captured_snapshot_count');

            return [
                'evidence_id' => $evidence->id,
                'captured_snapshot_count' => (int) $interview->fresh()->captured_snapshot_count,
            ];
        }, 201);
    }

    public function violation(Request $request): JsonResponse
    {
        $allowedTypes = array_values(array_unique(array_merge(
            AntiCheatLog::allowedTypes(),
            ['face_missing', 'multiple_tab']
        )));

        $validated = $request->validate([
            'event_key' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:' . implode(',', $allowedTypes)],
            'question_id' => ['nullable', 'integer', 'exists:questions,id'],
            'duration_seconds' => ['nullable', 'numeric', 'min:0', 'max:3600'],
            'confidence_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'source' => ['nullable', 'in:' . implode(',', AntiCheatLog::allowedSources())],
            'metadata' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return $this->respond(function () use ($request, $validated): array {
            $interview = $this->sessionService->authenticate($request);
            $this->assertInterviewInProgress($interview);

            if (!empty($validated['question_id'])) {
                $belongs = Question::query()
                    ->where('id', $validated['question_id'])
                    ->where('interview_id', $interview->id)
                    ->exists();
                abort_unless($belongs, 422, 'Question does not belong to this interview.');
            }

            $serverEventKey = $interview->id . ':' . substr(hash('sha256', $validated['event_key']), 0, 64);

            $log = AntiCheatLog::query()->firstOrCreate(
                ['event_key' => $serverEventKey],
                [
                    'interview_id' => $interview->id,
                    'question_id' => $validated['question_id'] ?? null,
                    'violation_type' => $validated['type'],
                    'violation_timestamp' => $validated['occurred_at'] ?? now(),
                    'duration_seconds' => $validated['duration_seconds'] ?? 0,
                    'confidence_score' => $validated['confidence_score'] ?? 1,
                    'metadata' => $validated['metadata'] ?? [],
                    'severity_weight' => $this->severityWeight($validated['type']),
                    'source' => $validated['source'] ?? AntiCheatLog::SOURCE_BROWSER_SECURITY,
                ]
            );

            return ['violation_id' => $log->id, 'recorded' => $log->wasRecentlyCreated];
        }, 201);
    }

    public function submitAnswer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'audio_file' => ['required', 'file', 'mimes:webm,mp3,wav,m4a,ogg', 'max:25600'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:600'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        return $this->respond(function () use ($request, $validated): array {
            $interview = $this->sessionService->authenticate($request);

            $this->assertInterviewInProgress($interview);

            $existing = Answer::query()
                ->where('interview_id', $interview->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing !== null) {
                return [
                    'answer_id' => $existing->id,
                    'status' => $existing->status,
                    'duplicate' => true,
                ];
            }

            $question = Question::query()
                ->where('id', $validated['question_id'])
                ->where('interview_id', $interview->id)
                ->firstOrFail();

            $nextExpected = $this->nextUnansweredQuestion($interview);
            if ($nextExpected === null || $nextExpected->id !== $question->id) {
                throw new CompanyInterviewAccessException('This is not the current interview question.', 409);
            }

            // The current AudioTranscriptionService resolves persisted paths from the public disk.
            $disk = 'public';
            $audioPath = $validated['audio_file']->store("answers/{$interview->id}", $disk);
            abort_if($audioPath === false, 500, 'Failed to store audio answer.');

            try {
                $answer = new Answer();
                $answer->forceFill([
                    'idempotency_key' => $validated['idempotency_key'],
                    'interview_id' => $interview->id,
                    'question_id' => $question->id,
                    'audio_file_path' => $audioPath,
                    'duration_seconds' => $validated['duration_seconds'],
                    'status' => Answer::STATUS_PENDING,
                    'submitted_at' => now(),
                    'processing_metadata' => [
                        'interview_locale' => $interview->locale,
                        'interview_type' => 'company_candidate',
                        'storage_disk' => $disk,
                    ],
                ]);
                $answer->save();
            } catch (Throwable $exception) {
                Storage::disk($disk)->delete($audioPath);
                throw $exception;
            }

            $question->forceFill([
                'status' => 'answered',
                'answered_at' => now(),
            ])->save();

            $interview->forceFill([
                'answered_questions_count' => $interview->answers()->count(),
                'last_activity_at' => now(),
                'current_question_id' => $this->nextUnansweredQuestion($interview)?->id,
            ])->save();

            ProcessSingleAnswerJob::dispatch($answer, $audioPath)
                ->onQueue('answers')
                ->afterCommit();

            $next = $this->nextUnansweredQuestion($interview);
            $total = $interview->questions()->count();
            $answered = $interview->answers()->count();

            return [
                'answer_id' => $answer->id,
                'status' => 'processing',
                'is_last' => $answered >= $total,
                'progress' => ['answered' => $answered, 'total' => $total],
                'next_question' => $next ? $this->questionPayload($next, $interview->locale) : null,
            ];
        }, 201);
    }

    public function complete(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $interview = $this->sessionService->authenticate($request);
            $this->assertInterviewInProgress($interview);
            $total = $interview->questions()->count();
            $answered = $interview->answers()->count();

            if ($total === 0 || $answered < $total) {
                throw new CompanyInterviewAccessException(
                    "Only {$answered}/{$total} questions were answered.",
                    422
                );
            }

            DB::transaction(function () use ($interview): void {
                CandidateInterviewSnapshotRequest::query()
                    ->where('interview_id', $interview->id)
                    ->whereIn('status', [
                        CandidateInterviewSnapshotRequest::STATUS_PENDING,
                        CandidateInterviewSnapshotRequest::STATUS_ISSUED,
                        CandidateInterviewSnapshotRequest::STATUS_PROCESSING,
                    ])
                    ->update([
                        'status' => CandidateInterviewSnapshotRequest::STATUS_MISSED,
                        'request_token_hash' => null,
                        'updated_at' => now(),
                    ]);

                $interview->forceFill([
                    'status' => Interview::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'active_session_id' => null,
                    'session_instance_id' => null,
                ])->save();

                $interview->candidate?->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                ])->save();

                $jobCandidate = $interview->company_job_candidate_id
                    ? \App\Models\CompanyJobCandidate::query()->find($interview->company_job_candidate_id)
                    : null;
                $jobCandidate?->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                ])->save();

                $invitation = $interview->email_invitation_id
                    ? EmailInvitation::query()->find($interview->email_invitation_id)
                    : null;
                $invitation?->forceFill([
                    'lifecycle_status' => EmailInvitation::LIFECYCLE_COMPLETED,
                    'completed_at' => now(),
                    'token_hash' => null,
                    'token_ciphertext' => null,
                ])->save();
            });

            $dispatch = $this->reportCoordinator->dispatchIfReady(
                $interview->id,
                'company_candidate_completed'
            );

            return [
                'interview_id' => $interview->id,
                'status' => 'processing',
                'report_visible_to_candidate' => false,
                'report_dispatch' => $dispatch,
                'message' => 'Your interview was submitted successfully.',
            ];
        });
    }

    public function processingStatus(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $interview = $this->sessionService->authenticate($request, false, true);
            $reportReady = $interview->finalReport()->exists();
            $verification = CandidateIdentityVerification::query()
                ->where('interview_id', $interview->id)
                ->first();

            if (!$reportReady && in_array($interview->status, [
                Interview::STATUS_COMPLETED,
                Interview::STATUS_PROCESSING_FINAL,
                Interview::STATUS_FAILED,
            ], true)) {
                $this->reportCoordinator->dispatchIfReady($interview->id, 'company_candidate_status_check');
            }

            return [
                'interview_status' => $interview->fresh()->status,
                'answers_processed' => $interview->answers()->where('status', Answer::STATUS_EVALUATED)->count(),
                'total_answers' => $interview->answers()->count(),
                'processing_complete' => $reportReady,
                'identity_status' => $verification?->status,
                'report_visible_to_candidate' => false,
                'message' => $reportReady
                    ? 'Your interview has been processed successfully.'
                    : 'Your interview is being processed.',
            ];
        });
    }

    private function verificationContext(Interview $interview): array
    {
        $verification = CandidateIdentityVerification::query()
            ->where('interview_id', $interview->id)
            ->first();

        if ($verification === null) {
            throw new CompanyInterviewAccessException(
                'Identity verification record was not found for this interview.',
                404,
                [
                    'interview_id' => $interview->id,
                ]
            );
        }

        $job = $verification->job()->first();

        if ($job === null) {
            throw new CompanyInterviewAccessException(
                'The company job associated with this interview was not found.',
                404,
                [
                    'interview_id' => $interview->id,
                    'verification_id' => $verification->id,
                    'company_job_id' => $verification->company_job_id,
                ]
            );
        }

        /*
     * مهم: إرجاع Array عادية تحتوي دائمًا على عنصرين.
     * لا تستخدم get() هنا لأنها تعيد Collection.
     */
        return [
            $verification,
            $job,
        ];
    }

    private function syncIdentityStatus(CandidateIdentityVerification $verification): void
    {
        $verification->jobCandidate?->forceFill([
            'identity_status' => $verification->fresh()->status,
        ])->save();
    }

    private function sessionState(Interview $interview): array
    {
        $interview->loadMissing(['questions', 'answers']);
        $job = \App\Models\CompanyJob::query()->find($interview->company_job_id);
        $verification = CandidateIdentityVerification::query()->where('interview_id', $interview->id)->first();
        $next = $this->nextUnansweredQuestion($interview);
        $total = $interview->questions()->count();
        $answered = $interview->answers()->count();

        return [
            'interview' => [
                'id' => $interview->id,
                'status' => $interview->status,
                'locale' => $interview->locale,
                'position' => $interview->position,
                'started_at' => $interview->started_at?->toISOString(),
                'expires_at' => $interview->expires_at?->toISOString(),
            ],
            'job' => $job ? [
                'id' => $job->id,
                'title' => $job->titleForLocale($interview->locale),
                'instructions' => $job->instructionsForLocale($interview->locale),
                'number_of_questions' => (int) $job->number_of_questions,
                'difficulty' => $job->difficulty,
                'interview_duration_minutes' => (int) $job->interview_duration_minutes,
                'random_snapshot_count' => (int) $job->random_snapshot_count,
            ] : null,
            'session' => [
                'resume_count' => (int) $interview->resume_count,
                'max_resume_count' => (int) $interview->max_resume_count,
                'remaining_resumes' => max(0, (int) $interview->max_resume_count - (int) $interview->resume_count),
                'last_heartbeat_at' => $interview->last_heartbeat_at?->toISOString(),
            ],
            'identity' => $verification && $job ? $this->identityState($verification, $job) : null,
            'progress' => [
                'answered' => $answered,
                'total' => $total,
                'percentage' => $total > 0 ? round(($answered / $total) * 100, 1) : 0,
            ],
            'current_question' => $next ? $this->questionPayload($next, $interview->locale) : null,
        ];
    }

    private function identityState(CandidateIdentityVerification $verification, $job): array
    {
        $documentFront = $verification->evidences()
            ->where('type', CandidateIdentityEvidence::TYPE_DOCUMENT_FRONT)
            ->exists();

        $documentBack = $verification->evidences()
            ->where('type', CandidateIdentityEvidence::TYPE_DOCUMENT_BACK)
            ->exists();

        return [
            'status' => $verification->status,
            'document_type' => $verification->document_type,
            'document_front_uploaded' => $documentFront,
            'document_back_uploaded' => $documentBack,
            'document_back_required' => $verification->requiresDocumentBack(),
            'selfie_required' => false,
            'liveness_required' => false,
            'face_monitoring_enabled' => true,
            'random_interview_snapshots' => (int) $job->random_snapshot_count,
            'verification_method' => 'document_and_interview_snapshots',
            'ready_to_start' => $verification->hasRequiredEvidence($job),
            'manual_review_timing' => 'after_interview',
        ];
    }

    private function identityRequirements($job): array
    {
        return [
            'required' => (bool) $job->identity_verification_required,
            'document_required' => (bool) $job->identity_document_required,
            'selfie_required' => false,
            'liveness_required' => false,
            'face_monitoring_enabled' => true,
            'random_interview_snapshots' => (int) $job->random_snapshot_count,
            'verification_method' => 'document_and_interview_snapshots',
            'review_timing' => 'after_interview',
            'evidence_deleted_after_review' => (bool) $job->delete_identity_evidence_after_review,
        ];
    }

    private function nextUnansweredQuestion(Interview $interview): ?Question
    {
        return $interview->questions()
            ->whereNotIn('id', $interview->answers()->select('question_id'))
            ->orderBy('order')
            ->first();
    }

    private function questionPayload(Question $question, string $locale): array
    {
        return [
            'id' => $question->id,
            'order' => $question->order,
            'text' => $this->localizedValue($question->question_text, $locale),
            'type' => $question->type,
            'source' => $question->source,
            'time_allocation_seconds' => (int) $question->time_allocation_seconds,
        ];
    }

    private function localizedValue(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                return $value;
            }
        }

        if (!is_array($value)) {
            return '';
        }

        return (string) (
            $value[$locale]
            ?? $value['en']
            ?? $value['ar']
            ?? collect($value)->first(fn($item) => is_string($item))
            ?? ''
        );
    }

    private function assertIdentityCollectionOpen(Interview $interview): void
    {
        if ($interview->status !== Interview::STATUS_PENDING) {
            throw new CompanyInterviewAccessException(
                'Identity evidence can only be submitted before the interview starts.',
                409
            );
        }
    }

    private function assertInterviewInProgress(Interview $interview): void
    {
        if ($interview->status !== Interview::STATUS_IN_PROGRESS) {
            throw new CompanyInterviewAccessException('Interview is not accepting this action.', 409);
        }
    }

    private function severityWeight(string $type): float
    {
        return match ($type) {
            'multiple_faces', 'screen_capture' => 5.0,
            'copy_paste_attempt' => 4.5,
            'device_change', 'multiple_tab' => 4.0,
            'fullscreen_exit', 'browser_console' => 3.5,
            'tab_switch' => 3.0,
            'window_blur' => 2.5,
            'looking_away', 'face_missing', 'suspicious_movement' => 2.0,
            default => 1.0,
        };
    }

    private function respond(callable $callback, int $successStatus = 200): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $callback(),
            ], $successStatus);
        } catch (CompanyInterviewAccessException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
            ], $exception->status);
        }
    }
}
