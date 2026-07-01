<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;

class ActivityLogService
{
    /**
     * تسجيل نشاط جديد
     */
    public function log(
        string $section,
        string $action,
        string $description,
        ?string $status = 'success',
        ?array $details = []
    ): ActivityLog {
        try {
            $log = ActivityLog::create([
                'user_id' => auth('admin')->id(),
                'section' => $section,
                'action' => $action,
                'description' => $description,
                'status' => $status,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'details' => $details,
            ]);

            Log::channel('activity')->info("{$section} - {$action}", [
                'user_id' => auth('admin')->id(),
                'status' => $status,
                'description' => $description,
            ]);

            return $log;
        } catch (\Exception $e) {
            Log::error('Failed to log activity: ' . $e->getMessage());
            throw $e;
        }
    }

    public function success(string $section, string $action, string $description, ?array $details = []): ActivityLog
    {
        return $this->log($section, $action, $description, 'success', $details);
    }

    public function failed(string $section, string $action, string $description, ?array $details = []): ActivityLog
    {
        return $this->log($section, $action, $description, 'failed', $details);
    }

    public function warning(string $section, string $action, string $description, ?array $details = []): ActivityLog
    {
        return $this->log($section, $action, $description, 'warning', $details);
    }

    public function getLogs(?string $section = null, ?string $status = null, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 20)
    {
        $query = ActivityLog::with('user')
            ->orderBy('created_at', 'desc');

        if ($section) {
            $query->where('section', $section);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query->paginate($perPage);
    }

    public function getLogDetails(int $id): ?ActivityLog
    {
        return ActivityLog::with('user')->find($id);
    }

    public function getStats(): array
    {
        return [
            'total' => ActivityLog::count(),
            'today' => ActivityLog::whereDate('created_at', today())->count(),
            'success' => ActivityLog::where('status', 'success')->count(),
            'failed' => ActivityLog::where('status', 'failed')->count(),
            'warning' => ActivityLog::where('status', 'warning')->count(),
            'sections' => ActivityLog::select('section')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('section')
                ->get()
                ->pluck('count', 'section')
                ->toArray(),
        ];
    }

    public function cleanOldLogs(int $days = 30): int
    {
        try {
            $cutoffDate = now()->subDays($days);

            $deleted = ActivityLog::where('created_at', '<', $cutoffDate)->delete();

            Log::info('Cleaned old activity logs', [
                'deleted_count' => $deleted,
                'days' => $days,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
            ]);

            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to clean activity logs: ' . $e->getMessage());
            return 0;
        }
    }
}
