<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CompanyActivityLogService
{
    public function success(
        Company $company,
        string $section,
        string $action,
        string $description,
        array $details = []
    ): ?ActivityLog {
        return $this->write($company, $section, $action, $description, 'success', $details);
    }

    public function warning(
        Company $company,
        string $section,
        string $action,
        string $description,
        array $details = []
    ): ?ActivityLog {
        return $this->write($company, $section, $action, $description, 'warning', $details);
    }

    public function failed(
        Company $company,
        string $section,
        string $action,
        string $description,
        array $details = []
    ): ?ActivityLog {
        return $this->write($company, $section, $action, $description, 'failed', $details);
    }

    private function write(
        Company $company,
        string $section,
        string $action,
        string $description,
        string $status,
        array $details
    ): ?ActivityLog {
        try {
            return ActivityLog::create([
                // activity_logs.user_id historically points to admins. Keep it
                // null for company activity and identify the actor in details.
                'user_id' => null,
                'section' => $section,
                'action' => $action,
                'description' => $description,
                'status' => $status,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => array_merge([
                    'actor_type' => 'company',
                    'company_id' => $company->id,
                    'company_name' => $company->company_name,
                    'company_email' => $company->email,
                ], $details),
            ]);
        } catch (Throwable $exception) {
            // Activity logging must never make the business operation fail.
            Log::warning('Unable to write company activity log.', [
                'company_id' => $company->id,
                'section' => $section,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
