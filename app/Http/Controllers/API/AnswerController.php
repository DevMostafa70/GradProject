<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAnswerRequest;
use App\Http\Resources\AnswerResource;
use App\Jobs\ProcessSingleAnswerJob;
use App\Models\Answer;
use App\Models\AntiCheatLog;
use App\Models\Interview;
use App\Models\Question;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnswerController extends Controller
{
    /**
     * Submit an answer for a question.
     *
     * The answer, question status, interview progress, and anti-cheat
     * violations are committed atomically. Repeated requests are returned as
     * successful duplicates and also repair old inconsistent question states.
     */
    public function store(SubmitAnswerRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $question = Question::findOrFail($validated['question_id']);
        $interview = $question->interview;
        $locale = $interview->normalizedLocale();
        app()->setLocale($locale);

        Gate::authorize('update', $interview);

        if (Answer::existsForQuestion($interview->id, $question->id)) {
            $existingAnswer = Answer::where('interview_id', $interview->id)
                ->where('question_id', $question->id)
                ->firstOrFail();

            Log::warning('Duplicate answer prevented (question already answered)', [
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'existing_answer_id' => $existingAnswer->id,
                'ip' => $request->ip(),
            ]);

            return $this->duplicateResponse(
                $existingAnswer,
                $interview,
                $question,
                $this->message($locale, 'تم إرسال إجابة لهذا السؤال مسبقاً.', 'Answer already submitted for this question')
            );
        }

        $idempotencyKey = $validated['idempotency_key'];
        $existingByIdempotency = Answer::findByIdempotencyKey($idempotencyKey);

        if ($existingByIdempotency) {
            // A key must never be reused for a different interview/question.
            if (
                (int) $existingByIdempotency->interview_id !== (int) $interview->id
                || (int) $existingByIdempotency->question_id !== (int) $question->id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => $this->message($locale, 'مفتاح منع التكرار مرتبط بإجابة أخرى.', 'The idempotency key belongs to another answer.'),
                    'error_code' => 'IDEMPOTENCY_KEY_CONFLICT',
                ], 409);
            }

            Log::warning('Duplicate request prevented by idempotency key', [
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'idempotency_key' => $idempotencyKey,
                'existing_answer_id' => $existingByIdempotency->id,
                'ip' => $request->ip(),
            ]);

            return $this->duplicateResponse(
                $existingByIdempotency,
                $interview,
                $question,
                $this->message($locale, 'تم اكتشاف طلب مكرر.', 'Duplicate request detected')
            );
        }

        if ($interview->isSessionExpired()) {
            return response()->json([
                'success' => false,
                'message' => $this->message($locale, 'انتهت صلاحية جلسة المقابلة. يرجى بدء مقابلة جديدة.', 'Interview session has expired. Please start a new interview.'),
                'error_code' => 'SESSION_EXPIRED',
            ], 410);
        }

        $expectedQuestion = $interview->getNextQuestion();
        if ($expectedQuestion && (int) $expectedQuestion->id !== (int) $question->id) {
            return response()->json([
                'success' => false,
                'message' => $this->message($locale, 'يرجى الإجابة عن الأسئلة بالترتيب.', 'Please answer questions in order.'),
                'current_question' => $this->questionData($expectedQuestion),
            ], 400);
        }

        if ($interview->status !== Interview::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => false,
                'message' => $this->message($locale, 'المقابلة ليست قيد التنفيذ.', 'Interview is not in progress.'),
            ], 400);
        }

        $path = null;
        $committed = false;

        try {
            $audioFile = $request->file('audio_file');
            $path = $this->storeAudioFile($audioFile, $interview->id);

            DB::beginTransaction();

            $answer = Answer::createWithIdempotency([
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'audio_file_path' => $path,
                'duration_seconds' => $validated['duration_seconds'],
                'status' => Answer::STATUS_PENDING,
                'submitted_at' => now(),
            ], $idempotencyKey);

            if (!$answer) {
                DB::rollBack();
                Storage::disk('public')->delete($path);

                $existingAnswer = Answer::where('interview_id', $interview->id)
                    ->where('question_id', $question->id)
                    ->firstOrFail();

                return $this->duplicateResponse(
                    $existingAnswer,
                    $interview,
                    $question,
                    $this->message($locale, 'توجد إجابة محفوظة لهذا السؤال.', 'Answer already exists for this question')
                );
            }

            // Use a direct query so this update does not depend on Question's
            // $fillable array. This fixes projects where status/answered_at were
            // silently ignored by mass assignment.
            $this->markQuestionAsAnswered($question);

            $nextQuestion = $this->synchronizeInterviewProgress($interview);

            $violationsLogged = $this->storeViolations(
                $validated['violations'] ?? [],
                $interview,
                $question,
                $answer,
                $idempotencyKey
            );

            DB::commit();
            $committed = true;

            ProcessSingleAnswerJob::dispatch($answer, $path)
                ->onQueue('answers');

            return response()->json([
                'success' => true,
                'message' => $this->message($locale, 'تم إرسال الإجابة بنجاح وإضافتها إلى قائمة المعالجة.', 'Answer submitted successfully and queued for processing'),
                'data' => new AnswerResource($answer),
                'duplicate' => false,
                'violations_logged' => $violationsLogged,
                'session' => $this->getSessionData($interview, $nextQuestion),
            ], 201);
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if (!$committed && $path) {
                Storage::disk('public')->delete($path);
            }

            Log::error('Failed to submit answer: ' . $exception->getMessage(), [
                'interview_id' => $interview->id,
                'question_id' => $question->id,
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->message($locale, 'تعذر إرسال الإجابة.', 'Failed to submit answer'),
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Store browser MediaRecorder output without relying on PHP's MIME guess.
     * Fileinfo frequently labels valid WebM audio as application/octet-stream.
     * We generate the filename on the server and infer a safe extension from
     * the container signature, original extension, or MIME hints.
     */
    private function storeAudioFile(UploadedFile $file, int $interviewId): string
    {
        $extension = $this->resolveAudioExtension($file);
        $filename = Str::uuid()->toString() . '.' . $extension;

        $path = $file->storeAs(
            'answers/' . $interviewId,
            $filename,
            'public'
        );

        if (!$path) {
            throw new \RuntimeException('Failed to store the uploaded audio recording.');
        }

        Log::info('Interview audio stored', [
            'interview_id' => $interviewId,
            'path' => $path,
            'size_bytes' => $file->getSize(),
            'original_extension' => $file->getClientOriginalExtension(),
            'client_mime' => $file->getClientMimeType(),
            'detected_mime' => $file->getMimeType(),
            'stored_extension' => $extension,
        ]);

        return $path;
    }

    private function resolveAudioExtension(UploadedFile $file): string
    {
        $signatureExtension = $this->extensionFromSignature($file);
        if ($signatureExtension !== null) {
            return $signatureExtension;
        }

        $originalExtension = strtolower($file->getClientOriginalExtension());
        $extensionAliases = [
            'webm' => 'webm',
            'weba' => 'webm',
            'mp3' => 'mp3',
            'mpeg' => 'mp3',
            'wav' => 'wav',
            'wave' => 'wav',
            'm4a' => 'm4a',
            'mp4' => 'mp4',
            'ogg' => 'ogg',
            'oga' => 'ogg',
        ];

        if (isset($extensionAliases[$originalExtension])) {
            return $extensionAliases[$originalExtension];
        }

        $mimeExtension = $this->extensionFromMime($file->getClientMimeType())
            ?? $this->extensionFromMime($file->getMimeType());

        // MediaRecorder uses WebM on Chromium browsers in the common case.
        return $mimeExtension ?? 'webm';
    }

    private function extensionFromMime(?string $mime): ?string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        return match ($mime) {
            'audio/webm', 'video/webm', 'audio/weba' => 'webm',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'video/mp4' => 'mp4',
            'audio/ogg', 'application/ogg' => 'ogg',
            default => null,
        };
    }

    private function extensionFromSignature(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();
        if (!$realPath) {
            return null;
        }

        $handle = @fopen($realPath, 'rb');
        if (!$handle) {
            return null;
        }

        $header = (string) fread($handle, 32);
        fclose($handle);

        if (strlen($header) >= 4 && bin2hex(substr($header, 0, 4)) === '1a45dfa3') {
            return 'webm';
        }

        if (str_starts_with($header, 'OggS')) {
            return 'ogg';
        }

        if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE') {
            return 'wav';
        }

        if (substr($header, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($header, 8, 4));
            return in_array($brand, ['m4a ', 'm4b ', 'm4p '], true) ? 'm4a' : 'mp4';
        }

        if (str_starts_with($header, 'ID3')) {
            return 'mp3';
        }

        if (strlen($header) >= 2) {
            $first = ord($header[0]);
            $second = ord($header[1]);

            if ($first === 0xFF && ($second & 0xE0) === 0xE0) {
                return 'mp3';
            }
        }

        return null;
    }

    public function show(Answer $answer): JsonResponse
    {
        app()->setLocale($answer->interview->normalizedLocale());
        Gate::authorize('view', $answer->interview);

        return response()->json([
            'success' => true,
            'data' => new AnswerResource($answer->load(['evaluation', 'audioAnalysis'])),
        ]);
    }

    private function markQuestionAsAnswered(Question $question): void
    {
        Question::query()
            ->whereKey($question->id)
            ->update([
                'status' => Question::STATUS_ANSWERED,
                'answered_at' => $question->answered_at ?? now(),
                'updated_at' => now(),
            ]);

        $question->refresh();

        if ($question->status !== Question::STATUS_ANSWERED) {
            throw new \RuntimeException(
                "Question {$question->id} status was not changed to answered."
            );
        }
    }

    /**
     * Rebuild progress from answers (the source of truth), not from a possibly
     * stale questions.status value.
     */
    private function synchronizeInterviewProgress(Interview $interview): ?Question
    {
        $answeredQuestionIds = $interview->answers()
            ->pluck('question_id');

        $nextQuestion = $interview->questions()
            ->whereNotIn('id', $answeredQuestionIds)
            ->orderBy('order')
            ->first();

        $interview->answered_questions_count = $answeredQuestionIds->count();
        $interview->current_question_id = $nextQuestion?->id;
        $interview->last_activity_at = now();
        $interview->save();
        $interview->refresh();

        return $nextQuestion;
    }

    private function repairDuplicateState(
        Interview $interview,
        Question $question
    ): ?Question {
        return DB::transaction(function () use ($interview, $question) {
            if ($question->status !== Question::STATUS_ANSWERED) {
                $this->markQuestionAsAnswered($question);
            }

            return $this->synchronizeInterviewProgress($interview);
        });
    }

    private function storeViolations(
        array $violations,
        Interview $interview,
        Question $question,
        Answer $answer,
        string $idempotencyKey
    ): int {
        if ($violations === []) {
            return 0;
        }

        $severityWeights = [
            'multiple_faces' => 5.0,
            'looking_away' => 2.0,
            'tab_switch' => 3.0,
            'window_blur' => 2.5,
            'fullscreen_exit' => 3.5,
            'suspicious_movement' => 2.0,
            'audio_anomaly' => 1.5,
            'device_change' => 4.0,
            'browser_console' => 3.5,
            'copy_paste_attempt' => 4.5,
            'screen_capture' => 5.0,
        ];

        $now = now();
        $rows = [];

        foreach ($violations as $violation) {
            $metadata = array_merge($violation['metadata'] ?? [], [
                'answer_id' => $answer->id,
                'question_id' => $question->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $row = [
                'interview_id' => $interview->id,
                'violation_type' => $violation['type'],
                'violation_timestamp' => Carbon::parse($violation['timestamp']),
                'duration_seconds' => $violation['duration'] ?? 0,
                'confidence_score' => $violation['confidence'],
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'severity_weight' => $severityWeights[$violation['type']] ?? 1.0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('anti_cheat_logs', 'event_key')) {
                $row['event_key'] = $violation['event_key']
                    ?? ($idempotencyKey . ':' . $question->id . ':' . ($violation['type'] ?? 'event') . ':' . md5(json_encode($violation)));
            }
            if (Schema::hasColumn('anti_cheat_logs', 'question_id')) {
                $row['question_id'] = $question->id;
            }
            if (Schema::hasColumn('anti_cheat_logs', 'answer_id')) {
                $row['answer_id'] = $answer->id;
            }
            if (Schema::hasColumn('anti_cheat_logs', 'source')) {
                $row['source'] = $violation['source'] ?? 'media_pipe';
            }

            $rows[] = $row;
        }

        AntiCheatLog::insertOrIgnore($rows);

        return count($rows);
    }

    private function duplicateResponse(
        Answer $answer,
        Interview $interview,
        Question $question,
        string $message
    ): JsonResponse {
        // Repair interviews created before this fix, where an Answer exists but
        // the related question may still be marked pending.
        $nextQuestion = $this->repairDuplicateState($interview, $question);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new AnswerResource($answer),
            'duplicate' => true,
            'session' => $this->getSessionData($interview, $nextQuestion),
        ], 200);
    }

    private function questionData(Question $question): array
    {
        return [
            'id' => $question->id,
            'order' => $question->order,
            'question_text' => $question->textForLocale($question->interview?->locale),
            'text' => $question->textForLocale($question->interview?->locale),
            'type' => $question->type,
            'interview_id' => $question->interview_id,
            'expected_skills' => $question->expected_skills,
            'evaluation_criteria' => $question->evaluation_criteria,
            'status' => $question->status,
            'time_allocation_seconds' => $question->time_allocation_seconds,
        ];
    }

    private function getSessionData(
        Interview $interview,
        ?Question $nextQuestion = null
    ): array {
        $interview->refresh();
        $totalQuestions = $interview->questions()->count();
        $answeredCount = $interview->answers()->count();
        $nextQuestion ??= $interview->getNextQuestion();

        return [
            'locale' => $interview->normalizedLocale(),
            'answered_count' => $answeredCount,
            'total_questions' => $totalQuestions,
            'remaining' => max(0, $totalQuestions - $answeredCount),
            'next_question' => $nextQuestion
                ? $this->questionData($nextQuestion)
                : null,
            'all_answered' => $answeredCount >= $totalQuestions,
            'expires_at' => $interview->expires_at?->toISOString(),
            'expires_in_minutes' => $interview->expires_at
                ? max(0, now()->diffInMinutes($interview->expires_at))
                : null,
        ];
    }
    private function message(string $locale, string $arabic, string $english): string
    {
        return $locale === 'ar' ? $arabic : $english;
    }

}