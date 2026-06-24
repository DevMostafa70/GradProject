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
        // $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Send broadcast notification
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target_type' => 'required|in:all,companies,candidates',
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
     */
    public function show(BroadcastNotification $broadcast): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $broadcast->load('admin'),
        ]);
    }
}
