<?php

namespace App\Services\CompanyInterview;

use App\Models\CandidateIdentityEvidence;
use App\Models\CandidateIdentityVerification;
use App\Models\CandidateLivenessChallenge;
use App\Models\CompanyJob;
use App\Models\Interview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class IdentityEvidenceService
{
    public function store(
        CandidateIdentityVerification $verification,
        Interview $interview,
        UploadedFile $file,
        string $type,
        ?int $questionId = null,
        array $metadata = []
    ): CandidateIdentityEvidence {
        $this->assertAllowedType($type);

        $disk = (string) config('company_interviews.identity.disk', 'local');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid()->toString() . '.' . $extension;
        $directory = "company-interviews/{$interview->id}/identity/{$verification->id}";
        $path = $file->storeAs($directory, $filename, $disk);

        if ($path === false) {
            throw new RuntimeException('Failed to store identity evidence.');
        }

        $sha256 = hash_file('sha256', $file->getRealPath()) ?: null;

        return CandidateIdentityEvidence::create([
            'verification_id' => $verification->id,
            'interview_id' => $interview->id,
            'question_id' => $questionId,
            'type' => $type,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'sha256' => $sha256,
            'captured_at' => now(),
            'metadata' => $metadata ?: null,
        ]);
    }

    public function replaceSingleEvidence(
        CandidateIdentityVerification $verification,
        Interview $interview,
        UploadedFile $file,
        string $type,
        array $metadata = []
    ): CandidateIdentityEvidence {
        return DB::transaction(function () use ($verification, $interview, $file, $type, $metadata): CandidateIdentityEvidence {
            $existing = $verification->evidences()
                ->where('type', $type)
                ->whereNull('deleted_at')
                ->get();

            foreach ($existing as $evidence) {
                $this->deleteEvidence($evidence);
            }

            return $this->store($verification, $interview, $file, $type, null, $metadata);
        });
    }

    public function generateLivenessChallenges(
        CandidateIdentityVerification $verification,
        CompanyJob $job
    ): array {
        if ($verification->livenessChallenges()->exists()) {
            return $verification->livenessChallenges()->get()->all();
        }

        $available = collect(config('company_interviews.liveness_challenges', []))
            ->filter(fn ($item) => is_string($item) && $item !== '')
            ->shuffle()
            ->values();

        $count = min(max(1, (int) $job->liveness_challenge_count), $available->count());
        $challenges = [];

        foreach ($available->take($count) as $index => $type) {
            $challenges[] = CandidateLivenessChallenge::create([
                'verification_id' => $verification->id,
                'sequence' => $index + 1,
                'challenge_type' => $type,
                'challenge_payload' => $this->challengePayload($type),
                'status' => CandidateLivenessChallenge::STATUS_PENDING,
            ]);
        }

        $verification->forceFill([
            'liveness_status' => CandidateIdentityVerification::LIVENESS_IN_PROGRESS,
        ])->save();

        return $challenges;
    }

    public function completeLivenessChallenge(
        CandidateIdentityVerification $verification,
        Interview $interview,
        CandidateLivenessChallenge $challenge,
        UploadedFile $file,
        bool $passed,
        float $confidence,
        array $metadata = []
    ): CandidateLivenessChallenge {
        if ($challenge->verification_id !== $verification->id) {
            throw new RuntimeException('Liveness challenge does not belong to this verification.');
        }

        if ($challenge->status === CandidateLivenessChallenge::STATUS_PASSED) {
            return $challenge;
        }

        $evidence = $this->store(
            $verification,
            $interview,
            $file,
            CandidateIdentityEvidence::TYPE_LIVENESS,
            null,
            array_merge($metadata, ['challenge_type' => $challenge->challenge_type])
        );

        $challenge->forceFill([
            'status' => $passed
                ? CandidateLivenessChallenge::STATUS_PASSED
                : CandidateLivenessChallenge::STATUS_FAILED,
            'confidence_score' => max(0, min(100, $confidence)),
            'evidence_id' => $evidence->id,
            'started_at' => $challenge->started_at ?? now(),
            'completed_at' => now(),
            'metadata' => $metadata ?: null,
        ])->save();

        $total = $verification->livenessChallenges()->count();
        $passedCount = $verification->livenessChallenges()
            ->where('status', CandidateLivenessChallenge::STATUS_PASSED)
            ->count();

        $verification->forceFill([
            'liveness_status' => $total > 0 && $passedCount === $total
                ? CandidateIdentityVerification::LIVENESS_PASSED
                : CandidateIdentityVerification::LIVENESS_IN_PROGRESS,
            'liveness_score' => $verification->livenessChallenges()
                ->whereNotNull('confidence_score')
                ->avg('confidence_score'),
        ])->save();

        return $challenge->fresh('evidence');
    }

    public function deleteAllEvidence(CandidateIdentityVerification $verification): int
    {
        $deleted = 0;

        foreach ($verification->evidences()->whereNull('deleted_at')->get() as $evidence) {
            $this->deleteEvidence($evidence);
            $deleted++;
        }

        $verification->forceFill([
            'evidence_deleted_at' => now(),
        ])->save();

        return $deleted;
    }

    public function deleteEvidence(CandidateIdentityEvidence $evidence): bool
    {
        $disk = Storage::disk($evidence->disk);

        if ($evidence->path !== '' && $disk->exists($evidence->path)) {
            if (!$disk->delete($evidence->path)) {
                throw new RuntimeException('Failed to delete identity evidence from private storage.');
            }
        }

        // Keep only the audit row. Remove storage location and file fingerprints.
        $metadata = is_array($evidence->metadata) ? $evidence->metadata : [];
        $metadata['deleted_after_manual_review'] = true;
        $metadata['deleted_at'] = now()->toISOString();

        $evidence->forceFill([
            'path' => '',
            'mime_type' => null,
            'file_size' => null,
            'sha256' => null,
            'metadata' => $metadata,
        ])->save();

        if (!$evidence->trashed()) {
            $evidence->delete();
        }

        return true;
    }

    private function assertAllowedType(string $type): void
    {
        $allowed = [
            CandidateIdentityEvidence::TYPE_DOCUMENT_FRONT,
            CandidateIdentityEvidence::TYPE_DOCUMENT_BACK,
            CandidateIdentityEvidence::TYPE_SELFIE,
            CandidateIdentityEvidence::TYPE_LIVENESS,
            CandidateIdentityEvidence::TYPE_INTERVIEW_SNAPSHOT,
        ];

        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Unsupported identity evidence type.');
        }
    }

    private function challengePayload(string $type): array
    {
        return match ($type) {
            'blink_twice' => ['required_blinks' => 2],
            'turn_left' => ['minimum_yaw_degrees' => 18],
            'turn_right' => ['minimum_yaw_degrees' => -18],
            'look_up' => ['minimum_pitch_degrees' => 12],
            'look_down' => ['minimum_pitch_degrees' => -12],
            default => [],
        };
    }
}
