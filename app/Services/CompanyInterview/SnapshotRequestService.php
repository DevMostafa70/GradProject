<?php

namespace App\Services\CompanyInterview;

use App\Models\CandidateInterviewSnapshotRequest;
use App\Models\Interview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SnapshotRequestService
{
    public function schedule(Interview $interview, int $count, int $estimatedDurationSeconds): void
    {
        if ($count <= 0 || CandidateInterviewSnapshotRequest::query()->where('interview_id', $interview->id)->exists()) {
            return;
        }

        /*
         * Do not spread requests across the full theoretical interview duration.
         * Candidates may answer early, so all random snapshots are scheduled in
         * an early, capped window while still being distributed randomly.
         */
        $count = min(max($count, 1), 10);
        $windowStart = 12;
        $estimatedWindow = (int) floor(max($estimatedDurationSeconds, 60) * 0.35);
        $windowEnd = min(120, max(45, $estimatedWindow));
        $range = max($count, $windowEnd - $windowStart + 1);
        $segmentSize = max(1, (int) floor($range / $count));
        $used = [];

        for ($index = 0; $index < $count; $index++) {
            $segmentStart = $windowStart + ($index * $segmentSize);
            $segmentEnd = $index === $count - 1
                ? $windowEnd
                : min($windowEnd, $segmentStart + $segmentSize - 1);

            $segmentStart = min($segmentStart, $windowEnd);
            $segmentEnd = max($segmentStart, $segmentEnd);

            do {
                $offset = random_int($segmentStart, $segmentEnd);
            } while (isset($used[$offset]) && count($used) < $range);

            $used[$offset] = true;

            CandidateInterviewSnapshotRequest::create([
                'interview_id' => $interview->id,
                'status' => CandidateInterviewSnapshotRequest::STATUS_PENDING,
                'due_at' => now()->addSeconds($offset),
                'metadata' => [
                    'sequence' => $index + 1,
                    'scheduled_offset_seconds' => $offset,
                    'schedule_window_seconds' => [$windowStart, $windowEnd],
                ],
            ]);
        }
    }

    public function issueDueRequest(Interview $interview): ?array
    {
        $this->markExpiredIssuedRequests($interview);

        return DB::transaction(function () use ($interview): ?array {
            $request = CandidateInterviewSnapshotRequest::query()
                ->where('interview_id', $interview->id)
                ->where('status', CandidateInterviewSnapshotRequest::STATUS_PENDING)
                ->where('due_at', '<=', now())
                ->orderBy('due_at')
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                return null;
            }

            $rawToken = Str::random(64);
            $ttl = max(30, (int) config('company_interviews.session.snapshot_request_ttl_seconds', 60));

            $request->forceFill([
                'request_token_hash' => hash('sha256', $rawToken),
                'status' => CandidateInterviewSnapshotRequest::STATUS_ISSUED,
                'issued_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ])->save();

            return [
                'request_token' => $rawToken,
                'expires_at' => $request->expires_at?->toISOString(),
                'request_id' => $request->id,
            ];
        }, 3);
    }

    public function consume(Interview $interview, string $rawToken): CandidateInterviewSnapshotRequest
    {
        return DB::transaction(function () use ($interview, $rawToken): CandidateInterviewSnapshotRequest {
            $request = CandidateInterviewSnapshotRequest::query()
                ->where('interview_id', $interview->id)
                ->where('request_token_hash', hash('sha256', $rawToken))
                ->where('status', CandidateInterviewSnapshotRequest::STATUS_ISSUED)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new \RuntimeException('Snapshot request is invalid or was already used.');
            }

            if ($request->expires_at && now()->greaterThan($request->expires_at)) {
                $request->forceFill([
                    'status' => CandidateInterviewSnapshotRequest::STATUS_MISSED,
                    'request_token_hash' => null,
                ])->save();

                throw new \RuntimeException('Snapshot request has expired.');
            }

            $request->forceFill([
                'status' => CandidateInterviewSnapshotRequest::STATUS_PROCESSING,
                'request_token_hash' => null,
            ])->save();

            return $request;
        }, 3);
    }

    public function markCaptured(CandidateInterviewSnapshotRequest $request): void
    {
        $request->forceFill([
            'status' => CandidateInterviewSnapshotRequest::STATUS_CAPTURED,
            'captured_at' => now(),
            'request_token_hash' => null,
        ])->save();
    }

    public function markCaptureFailed(CandidateInterviewSnapshotRequest $request, string $reason): void
    {
        $metadata = is_array($request->metadata) ? $request->metadata : [];
        $metadata['capture_failure_reason'] = $reason;
        $metadata['capture_failed_at'] = now()->toISOString();

        $request->forceFill([
            'status' => CandidateInterviewSnapshotRequest::STATUS_MISSED,
            'request_token_hash' => null,
            'metadata' => $metadata,
        ])->save();
    }

    private function markExpiredIssuedRequests(Interview $interview): void
    {
        CandidateInterviewSnapshotRequest::query()
            ->where('interview_id', $interview->id)
            ->where('status', CandidateInterviewSnapshotRequest::STATUS_ISSUED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => CandidateInterviewSnapshotRequest::STATUS_MISSED,
                'request_token_hash' => null,
                'updated_at' => now(),
            ]);
    }
}
