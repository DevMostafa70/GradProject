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

            // تسجيل في Log النظام أيضاً
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

    /**
     * تسجيل نجاح
     */
    public function success(string $section, string $action, string $description, ?array $details = []): ActivityLog
    {
        return $this->log($section, $action, $description, 'success', $details);
    }

    /**
     * تسجيل فشل
     */
    public function failed(string $section, string $action, string $description, ?array $details = []): ActivityLog
    {
        return $this->log($section, $action, $description, 'failed', $details);
    }

    /**
     * تسجيل تحذير
     */
    public function warning(string $section, string $action, string $description, ?array $details = []): ActivityLog
    {
        return $this->log($section, $action, $description, 'warning', $details);
    }

    /**
     * جلب جميع السجلات مع فلترة
     */
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

    /**
     * جلب سجل محدد مع التفاصيل
     */
    public function getLogDetails(int $id): ?ActivityLog
    {
        return ActivityLog::with('user')->find($id);
    }

    /**
     * جلب إحصائيات سريعة للسجلات
     */
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

    /**
     * حذف السجلات القديمة (أكثر من 30 يوماً)
     */
    public function cleanOldLogs(int $days = 30): int
    {
        return ActivityLog::where('created_at', '<', now()->subDays($days))->delete();
    }
}
