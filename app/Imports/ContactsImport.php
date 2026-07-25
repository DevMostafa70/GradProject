<?php

namespace App\Imports;

use App\Models\CompanyJob;
use App\Services\CompanyInterview\CandidateInvitationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Throwable;

/**
 * Backward-compatible queued import.
 *
 * The main UI now uses preview + confirm, but this class remains safe for any
 * older code that still queues ContactsImport directly.
 */
class ContactsImport implements ToCollection, WithHeadingRow, WithChunkReading, ShouldQueue
{
    public function __construct(private readonly CompanyJob $job)
    {
    }

    public function collection(Collection $rows): void
    {
        $service = app(CandidateInvitationService::class);

        foreach ($rows as $index => $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $name = trim((string) ($row['name'] ?? $email));

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Log::warning('Skipped invalid candidate import row.', [
                    'job_id' => $this->job->id,
                    'row_number' => $index + 2,
                    'email' => $email,
                ]);
                continue;
            }

            try {
                $service->createAndDispatch($this->job, [
                    'row_number' => $index + 2,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $row['phone'] ?? null,
                    'candidate_reference' => $row['candidate_reference'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Candidate invitation row was not imported.', [
                    'job_id' => $this->job->id,
                    'row_number' => $index + 2,
                    'email' => $email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function chunkSize(): int
    {
        return 100;
    }
}
