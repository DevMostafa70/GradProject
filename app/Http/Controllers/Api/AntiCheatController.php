<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LogViolationRequest;
use App\Models\AntiCheatLog;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AntiCheatController extends Controller
{
    /**
     * Store anti-cheat violations idempotently.
     *
     * The frontend saves each event locally before sending it. Re-sending the
     * same event after a refresh or network interruption updates the same row
     * because event_key is unique.
     */
    public function store(LogViolationRequest $request): JsonResponse
    {
        $interview = Interview::query()->findOrFail($request->integer('interview_id'));

        Gate::authorize('update', $interview);

        $severityWeights = [
            AntiCheatLog::TYPE_MULTIPLE_FACES => 5.0,
            AntiCheatLog::TYPE_LOOKING_AWAY => 2.0,
            AntiCheatLog::TYPE_TAB_SWITCH => 3.0,
            AntiCheatLog::TYPE_WINDOW_BLUR => 2.5,
            AntiCheatLog::TYPE_FULLSCREEN_EXIT => 3.5,
            AntiCheatLog::TYPE_SUSPICIOUS_MOVEMENT => 2.0,
            AntiCheatLog::TYPE_AUDIO_ANOMALY => 1.5,
            AntiCheatLog::TYPE_DEVICE_CHANGE => 4.0,
            AntiCheatLog::TYPE_BROWSER_CONSOLE => 3.5,
            AntiCheatLog::TYPE_COPY_PASTE_ATTEMPT => 4.5,
            AntiCheatLog::TYPE_SCREEN_CAPTURE => 5.0,
            AntiCheatLog::TYPE_PROMPT_INJECTION_ATTEMPT => 5.0,
        ];

        $questionIds = $interview->questions()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $answerIds = $interview->answers()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $result = DB::transaction(function () use (
            $request,
            $interview,
            $severityWeights,
            $questionIds,
            $answerIds
        ): array {
            $created = 0;
            $updated = 0;
            $storedIds = [];

            foreach ($request->validated('violations') as $violationData) {
                $questionId = isset($violationData['question_id'])
                    ? (int) $violationData['question_id']
                    : (int) data_get($violationData, 'metadata.question_id', 0);

                $answerId = isset($violationData['answer_id'])
                    ? (int) $violationData['answer_id']
                    : (int) data_get($violationData, 'metadata.answer_id', 0);

                if ($questionId > 0 && !in_array($questionId, $questionIds, true)) {
                    throw ValidationException::withMessages([
                        'violations' => ['A violation question does not belong to this interview.'],
                    ]);
                }

                if ($answerId > 0 && !in_array($answerId, $answerIds, true)) {
                    throw ValidationException::withMessages([
                        'violations' => ['A violation answer does not belong to this interview.'],
                    ]);
                }

                $eventKey = (string) ($violationData['event_key'] ?? Str::uuid());
                $type = (string) $violationData['type'];

                $attributes = [
                    'event_key' => $eventKey,
                ];

                $values = [
                    'interview_id' => $interview->id,
                    'question_id' => $questionId > 0 ? $questionId : null,
                    'answer_id' => $answerId > 0 ? $answerId : null,
                    'violation_type' => $type,
                    'violation_timestamp' => $violationData['timestamp'],
                    'duration_seconds' => $violationData['duration'] ?? 0,
                    'confidence_score' => $violationData['confidence'],
                    'metadata' => array_merge(
                        $violationData['metadata'] ?? [],
                        [
                            'question_id' => $questionId > 0 ? $questionId : null,
                            'answer_id' => $answerId > 0 ? $answerId : null,
                        ]
                    ),
                    'severity_weight' => $severityWeights[$type] ?? 1.0,
                    'source' => $violationData['source'] ?? AntiCheatLog::SOURCE_BROWSER_SECURITY,
                ];

                $existing = AntiCheatLog::query()->where('event_key', $eventKey)->first();

                if ($existing && (int) $existing->interview_id !== (int) $interview->id) {
                    throw ValidationException::withMessages([
                        'violations' => ['The event_key is already assigned to another interview.'],
                    ]);
                }

                $log = AntiCheatLog::query()->updateOrCreate($attributes, $values);

                $log->wasRecentlyCreated ? $created++ : $updated++;
                $storedIds[] = $log->id;
            }

            return compact('created', 'updated', 'storedIds');
        });

        return response()->json([
            'success' => true,
            'message' => 'Anti-cheat violations synchronized successfully.',
            'data' => [
                'interview_id' => $interview->id,
                'created' => $result['created'],
                'updated' => $result['updated'],
                'violations_processed' => $result['created'] + $result['updated'],
                'ids' => $result['storedIds'],
            ],
        ], $result['created'] > 0 ? 201 : 200);
    }

    public function index(Interview $interview): JsonResponse
    {
        Gate::authorize('view', $interview);

        $violations = $interview->antiCheatLogs()
            ->orderByDesc('violation_timestamp')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'violations' => $violations,
                'summary' => $interview->getViolationSummary(),
                'severity_score' => $interview->calculateCheatingSeverityScore(),
            ],
        ]);
    }
}
