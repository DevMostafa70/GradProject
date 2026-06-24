<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
        // $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Get dashboard statistics
     */
    public function dashboard(): JsonResponse
    {
        $stats = $this->adminService->getDashboardStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get admin logs
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = $this->adminService->getRecentLogs($request->get('limit', 50));

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
}
