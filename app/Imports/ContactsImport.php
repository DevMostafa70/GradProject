<?php

namespace App\Imports;

use App\Jobs\SendInvitationEmailJob;
use App\Models\Candidate;
use App\Models\CompanyJob;
use App\Models\EmailInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactsImport implements ToCollection, WithHeadingRow, WithChunkReading, ShouldQueue
{
    protected $job;

    public function __construct(CompanyJob $job)
    {
        $this->job = $job;
    }

    public function collection(Collection $rows)
    {
        Log::info('ContactsImport: Total rows received = ' . $rows->count());

        foreach ($rows as $index => $row) {
            $email = trim($row['email']);
            $name = trim($row['name'] ?? $email);
            $phone = $row['phone'] ?? null;

            Log::info("Processing row {$index}: Email={$email}, Name={$name}");

            if (empty($email)) {
                Log::warning("Row {$index}: Email is empty, skipping");
                continue;
            }

            // 1. التحقق من وجود مرشح مسبق لهذه الوظيفة
            $existingCandidate = Candidate::where('email', $email)
                ->where('company_job_id', $this->job->id)
                ->first();

            if ($existingCandidate) {
                Log::info("Candidate already exists for job {$this->job->id}: {$email}");
                continue;
            }

            // 2. التحقق من وجود دعوة مسبقة
            $existingInvitation = EmailInvitation::where('email', $email)
                ->where('company_job_id', $this->job->id)
                ->exists();

            if ($existingInvitation) {
                Log::info("Invitation already exists for: {$email}");
                continue;
            }

            // 3. إنشاء مرشح جديد في جدول candidates
            try {
                $candidate = Candidate::create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'company_job_id' => $this->job->id,
                    'invitation_token' => Str::random(64),
                    'status' => 'pending',
                    'invited_at' => now(),
                ]);
                Log::info("✅ Candidate created successfully: ID {$candidate->id}, Email: {$email}");
            } catch (\Exception $e) {
                Log::error("❌ Failed to create candidate: " . $e->getMessage());
                continue;
            }

            // 4. إنشاء دعوة في جدول email_invitations
            try {
                $invitation = EmailInvitation::create([
                    'email' => $email,
                    'name' => $name,
                    'company_job_id' => $this->job->id,
                    'status' => 'pending',
                ]);
                Log::info("✅ Invitation created successfully: ID {$invitation->id}");

                // 5. ✅ إرسال الإيميل مع تمرير التوكن الصحيح
                SendInvitationEmailJob::dispatch($invitation, $this->job, $candidate->invitation_token);
            } catch (\Exception $e) {
                Log::error("❌ Failed to create invitation: " . $e->getMessage());
            }
        }
    }

    public function chunkSize(): int
    {
        return 100;
    }
}
