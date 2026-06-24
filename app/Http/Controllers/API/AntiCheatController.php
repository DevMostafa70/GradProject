<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogViolationRequest;
use App\Models\AntiCheatLog;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;

class AntiCheatController extends Controller
{
    public function __construct()
    {
        // $this->middleware('auth:sanctum');
    }

    /**
     * Store anti-cheat violations (bulk insert)
     */
    public function store(LogViolationRequest $request): JsonResponse
    {
        $interview = Interview::findOrFail($request->interview_id);

        Gate::authorize('update', $interview);

        try {
            $violations = [];
            $severityWeights = [
                'multiple_faces' => 5.0,
                'looking_away' => 2.0,
                'tab_switch' => 3.0,
                'window_blur' => 2.5,
                'suspicious_movement' => 2.0,
                'audio_anomaly' => 1.5,
                'device_change' => 4.0,
                'browser_console' => 3.5,
                'copy_paste_attempt' => 4.5,
                'screen_capture' => 5.0,
            ];

            foreach ($request->violations as $violationData) {
                $violations[] = [
                    'interview_id' => $interview->id,
                    'violation_type' => $violationData['type'],
                    'violation_timestamp' => $violationData['timestamp'],
                    'duration_seconds' => $violationData['duration'] ?? 0,
                    'confidence_score' => $violationData['confidence'],
                    'metadata' => $violationData['metadata'] ?? [],
                    'severity_weight' => $severityWeights[$violationData['type']] ?? 1.0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert for performance
            AntiCheatLog::insert($violations);

            return response()->json([
                'success' => true,
                'message' => count($violations) . ' violation(s) logged successfully',
                'data' => [
                    'violations_logged' => count($violations),
                    'interview_id' => $interview->id,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to log violations',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get violations for an interview
     */
    public function index(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        $violations = $interview->antiCheatLogs()
            ->orderBy('violation_timestamp', 'desc')
            ->get();

        $summary = $interview->getViolationSummary();

        return response()->json([
            'success' => true,
            'data' => [
                'violations' => $violations,
                'summary' => $summary,
                'severity_score' => $interview->calculateCheatingSeverityScore(),
            ],
        ]);
    }
}
