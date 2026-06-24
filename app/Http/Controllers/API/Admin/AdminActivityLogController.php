<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityLogController extends Controller
{
    protected ActivityLogService $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    /**
     * جلب جميع السجلات
     * GET /api/admin/activity-logs
     */
    public function index(Request $request): JsonResponse
    {
        $logs = $this->activityLogService->getLogs(
            $request->get('section'),
            $request->get('status'),
            $request->get('date_from'),
            $request->get('date_to'),
            $request->get('per_page', 20)
        );

        // تنسيق البيانات للعرض
        $logs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'datetime' => $log->created_at->format('Y-m-d H:i:s'),
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'section' => $log->section,
                'section_label' => $log->section_label,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'description' => $log->description,
                'status' => $log->status,
                'status_badge' => $log->status_badge,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * عرض تفاصيل سجل محدد
     * GET /api/admin/activity-logs/{id}
     */
    public function show(int $id): JsonResponse
    {
        $log = $this->activityLogService->getLogDetails($id);

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Log not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $log->id,
                'datetime' => $log->created_at->format('Y-m-d H:i:s'),
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'section' => $log->section,
                'section_label' => $log->section_label,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'description' => $log->description,
                'status' => $log->status,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'details' => $log->details,
                'created_at' => $log->created_at,
            ],
        ]);
    }

    /**
     * إحصائيات السجلات
     * GET /api/admin/activity-logs/stats
     */
    public function stats(): JsonResponse
    {
        $stats = $this->activityLogService->getStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * حذف السجلات القديمة
     * DELETE /api/admin/activity-logs/clean
     */
    public function clean(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        $deleted = $this->activityLogService->cleanOldLogs($days);

        return response()->json([
            'success' => true,
            'message' => "Deleted {$deleted} logs older than {$days} days",
        ]);
    }
}
