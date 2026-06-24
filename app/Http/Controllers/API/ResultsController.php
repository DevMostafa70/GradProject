<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\InterviewResultResource;
use App\Http\Resources\InterviewDetailsResource;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResultsController extends Controller
{
    /**
     * Get all interviews results for authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $interviews = $user->interviews()
            ->whereIn('status', ['completed', 'completed_with_report'])
            ->with(['finalReport', 'questions'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => InterviewResultResource::collection($interviews),
            'meta' => [
                'current_page' => $interviews->currentPage(),
                'total' => $interviews->total(),
                'per_page' => $interviews->perPage(),
                'last_page' => $interviews->lastPage(),
            ],
        ]);
    }

    /**
     * Get specific interview details with all answers and evaluations
     */
    public function show(Interview $interview): JsonResponse
    {
        // Check if the interview belongs to authenticated user
        if ($interview->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this interview',
            ], 403);
        }

        // Check if interview is completed
        if (!in_array($interview->status, ['completed', 'completed_with_report'])) {
            return response()->json([
                'success' => false,
                'message' => 'This interview is not completed yet',
            ], 400);
        }

        $interview->load([
            'questions' => function ($query) {
                $query->orderBy('order');
            },
            'answers.evaluation',
            'finalReport',
            'antiCheatLogs',
        ]);

        return response()->json([
            'success' => true,
            'data' => new InterviewDetailsResource($interview),
        ]);
    }

    /**
     * Get interview summary statistics for dashboard
     */
    public function summary(): JsonResponse
    {
        $user = Auth::user();

        $interviews = $user->interviews()
            ->whereIn('status', ['completed', 'completed_with_report'])
            ->with('finalReport')
            ->get();

        $totalInterviews = $interviews->count();

        $averageScore = $interviews->isEmpty() ? null : round(
            $interviews->avg(function ($interview) {
                return $interview->finalReport?->overall_score ?? 0;
            }) * 10,
            2
        );

        $bestScore = $interviews->isEmpty() ? null : round(
            $interviews->max(function ($interview) {
                return $interview->finalReport?->overall_score ?? 0;
            }) * 10,
            2
        );

        $recentInterviews = $interviews->take(5)->map(function ($interview) {
            return [
                'id' => $interview->id,
                'position' => $interview->position,
                'date' => $interview->completed_at ?? $interview->updated_at,
                'score' => $interview->finalReport?->overall_score
                    ? round($interview->finalReport->overall_score * 10, 2)
                    : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'total_interviews' => $totalInterviews,
                'average_score' => $averageScore,
                'best_score' => $bestScore,
                'recent_interviews' => $recentInterviews,
            ],
        ]);
    }

    /**
     * Delete an interview (soft delete or hard delete based on requirements)
     */
    public function destroy(Interview $interview): JsonResponse
    {
        if ($interview->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this interview',
            ], 403);
        }

        $interview->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interview deleted successfully',
        ]);
    }
}
