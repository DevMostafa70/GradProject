<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get dashboard statistics
     */
    public function stats(): JsonResponse
    {
        // ✅ التصحيح: استخدام auth()->user() بدلاً من Auth::guard('candidate')
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $stats = $this->dashboardService->getStats($user);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get progress data for chart
     */
    public function progress(Request $request): JsonResponse
    {
        // ✅ التصحيح: استخدام auth()->user()
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $period = $request->get('period', 'month');

        $progress = $this->dashboardService->getProgressData($user, $period);

        return response()->json([
            'success' => true,
            'data' => $progress,
        ]);
    }

    /**
     * Get user weaknesses
     */
    public function weaknesses(Request $request): JsonResponse
    {
        // ✅ التصحيح: استخدام auth()->user()
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $limit = $request->get('limit', 5);

        $weaknesses = $this->dashboardService->getWeaknesses($user, $limit);

        return response()->json([
            'success' => true,
            'data' => $weaknesses,
        ]);
    }

    /**
     * Get daily practice questions
     */
    public function dailyQuestions(Request $request): JsonResponse
    {
        // ✅ التصحيح: استخدام auth()->user()
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $count = $request->get('count', 3);

        $questions = $this->dashboardService->getDailyQuestions($user, $count);

        return response()->json([
            'success' => true,
            'data' => $questions,
        ]);
    }

    /**
     * Get all dashboard data in one request
     */
    public function index(Request $request): JsonResponse
    {
        // ✅ التصحيح: استخدام auth()->user()
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $stats = $this->dashboardService->getStats($user);
        $progress = $this->dashboardService->getProgressData($user, $request->get('period', 'month'));
        $weaknesses = $this->dashboardService->getWeaknesses($user, $request->get('weakness_limit', 5));

        // Only generate daily questions if requested
        $dailyQuestions = null;
        if ($request->boolean('include_questions', false)) {
            $dailyQuestions = $this->dashboardService->getDailyQuestions($user, 3);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'progress' => $progress,
                'weaknesses' => $weaknesses,
                'daily_questions' => $dailyQuestions,
            ],
        ]);
    }
}
