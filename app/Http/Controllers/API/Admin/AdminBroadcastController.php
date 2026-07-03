<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\BroadcastNotification;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBroadcastController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    /**
     * Send broadcast notification
     * POST /api/admin/broadcast/send
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target_type' => 'required|in:all,companies,candidates,users',
            'send_email' => 'nullable|boolean',
        ]);

        $result = $this->adminService->sendBroadcastNotification(
            $request->title,
            $request->message,
            $request->target_type,
            $request->send_email ?? false
        );

        return response()->json([
            'success' => true,
            'message' => 'Broadcast sent successfully',
            'data' => $result,
        ]);
    }

    /**
     * Get all broadcast notifications
     * GET /api/admin/broadcast
     */
    public function index(Request $request): JsonResponse
    {
        $broadcasts = BroadcastNotification::with('admin')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $broadcasts,
        ]);
    }

    /**
     * Get broadcast details
     * GET /api/admin/broadcast/{id}
     */
    public function show(BroadcastNotification $broadcast): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $broadcast->load('admin'),
        ]);
    }

    /**
     * Delete a specific broadcast notification
     * DELETE /api/admin/broadcast/{id}
     */
    public function destroy(BroadcastNotification $broadcast): JsonResponse
    {
        try {
            $broadcastTitle = $broadcast->title;
            $broadcastId = $broadcast->id;

            // تسجيل النشاط
            \App\Models\AdminLog::log('delete_broadcast', 'broadcast', $broadcastId, [
                'broadcast_title' => $broadcastTitle,
                'broadcast_message' => $broadcast->message,
                'target_type' => $broadcast->target_type,
            ]);

            $broadcast->delete();

            return response()->json([
                'success' => true,
                'message' => "Broadcast '{$broadcastTitle}' deleted successfully",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete broadcast: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete all broadcast notifications
     * DELETE /api/admin/broadcast
     */
    public function destroyAll(Request $request): JsonResponse
    {
        try {
            // التحقق من وجود تأكيد
            if (!$request->has('confirm') || $request->confirm !== true) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please confirm deletion by setting confirm=true in the request body.',
                ], 422);
            }

            $total = BroadcastNotification::count();

            if ($total === 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'No broadcasts to delete.',
                    'data' => [
                        'deleted_count' => 0,
                    ],
                ]);
            }

            // تسجيل النشاط قبل الحذف
            \App\Models\AdminLog::log('delete_all_broadcasts', 'broadcast', null, [
                'total_deleted' => $total,
                'deleted_by' => auth()->user()->name ?? 'System',
            ]);

            BroadcastNotification::truncate();

            return response()->json([
                'success' => true,
                'message' => "All {$total} broadcasts deleted successfully",
                'data' => [
                    'deleted_count' => $total,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete broadcasts: ' . $e->getMessage(),
            ], 500);
        }
    }
}
