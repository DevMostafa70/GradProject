<?php

namespace App\Services\CompanyInterview;

use App\Imports\CandidateSpreadsheetPreviewImport;
use App\Models\Candidate;
use App\Models\CompanyJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class CandidateSpreadsheetService
{
    public function preview(CompanyJob $job, UploadedFile $file): array
    {
        $sheets = Excel::toArray(new CandidateSpreadsheetPreviewImport(), $file);
        $rows = collect($sheets)->first() ?? [];
        $seenEmails = [];
        $resultRows = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($rows as $index => $rawRow) {
            $row = $this->normalizeRow((array) $rawRow);

            if ($this->isCompletelyEmpty($row)) {
                continue;
            }

            $errors = [];
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                $errors[] = 'Candidate name is required.';
            }

            if ($email === '') {
                $errors[] = 'Candidate email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Candidate email is invalid.';
            }

            if ($email !== '' && isset($seenEmails[$email])) {
                $errors[] = 'Duplicate email inside the uploaded file.';
            }

            if ($email !== '') {
                $seenEmails[$email] = true;

                $existing = Candidate::query()
                    ->where('company_job_id', $job->id)
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->first();

                if ($existing?->status === 'completed') {
                    $errors[] = 'This candidate already completed the interview for this job.';
                } elseif ($existing?->status === 'in_progress') {
                    $errors[] = 'This candidate already has an interview in progress for this job.';
                } elseif ($existing !== null) {
                    $errors[] = 'This candidate already has an invitation for this job.';
                }
            }

            if ($job->max_candidates !== null) {
                $remainingCapacity = max(
                    0,
                    (int) $job->max_candidates - $job->candidates()->count()
                );

                if ($remainingCapacity <= $validCount) {
                    $errors[] = 'The job candidate limit would be exceeded.';
                }
            }

            $valid = $errors === [];
            $valid ? $validCount++ : $invalidCount++;

            $resultRows[] = [
                'row_number' => $index + 2,
                'valid' => $valid,
                'name' => $name,
                'email' => $email,
                'phone' => $this->nullableString($row['phone'] ?? null),
                'candidate_reference' => $this->nullableString($row['candidate_reference'] ?? null),
                'notes' => $this->nullableString($row['notes'] ?? null),
                'errors' => $errors,
            ];
        }

        return [
            'summary' => [
                'total_rows' => count($resultRows),
                'valid_rows' => $validCount,
                'invalid_rows' => $invalidCount,
                'will_send_invitations' => $validCount,
            ],
            'rows' => $resultRows,
            'expected_columns' => [
                'name',
                'email',
                'phone (optional)',
                'candidate_reference (optional)',
                'notes (optional)',
            ],
        ];
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalizedKey = Str::of((string) $key)
                ->lower()
                ->replace([' ', '-'], '_')
                ->trim('_')
                ->toString();

            $normalized[$normalizedKey] = is_string($value) ? trim($value) : $value;
        }

        return [
            'name' => Arr::first([
                $normalized['name'] ?? null,
                $normalized['candidate_name'] ?? null,
                $normalized['full_name'] ?? null,
                $normalized['الاسم'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            'email' => Arr::first([
                $normalized['email'] ?? null,
                $normalized['email_address'] ?? null,
                $normalized['البريد_الالكتروني'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            'phone' => Arr::first([
                $normalized['phone'] ?? null,
                $normalized['mobile'] ?? null,
                $normalized['رقم_الهاتف'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            'candidate_reference' => Arr::first([
                $normalized['candidate_reference'] ?? null,
                $normalized['reference'] ?? null,
                $normalized['candidate_id'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            'notes' => Arr::first([
                $normalized['notes'] ?? null,
                $normalized['note'] ?? null,
                $normalized['ملاحظات'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function isCompletelyEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
