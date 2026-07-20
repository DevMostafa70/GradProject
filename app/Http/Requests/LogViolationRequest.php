<?php

namespace App\Http\Requests;

use App\Models\AntiCheatLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogViolationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * دعم بيانات النظام الجديدة والقديمة.
     *
     * النظام الجديد:
     * type, timestamp, duration, confidence
     *
     * النظام القديم:
     * violation_type, violation_timestamp,
     * duration_seconds, confidence_score
     */
    protected function prepareForValidation(): void
    {
        $violations = $this->input('violations', []);

        if (is_string($violations)) {
            $decoded = json_decode($violations, true);

            $violations = is_array($decoded)
                ? $decoded
                : [];
        }

        if (!is_array($violations)) {
            $violations = [];
        }

        $normalizedViolations = array_values(
            array_filter(
                array_map(function ($violation): ?array {
                    if (!is_array($violation)) {
                        return null;
                    }

                    $metadata = $violation['metadata'] ?? [];

                    if (!is_array($metadata)) {
                        $metadata = [];
                    }

                    return [
                        'event_key' =>
                            $violation['event_key']
                            ?? $metadata['event_key']
                            ?? null,

                        'type' =>
                            $violation['type']
                            ?? $violation['violation_type']
                            ?? null,

                        'timestamp' =>
                            $violation['timestamp']
                            ?? $violation['violation_timestamp']
                            ?? now()->toISOString(),

                        'duration' =>
                            $violation['duration']
                            ?? $violation['duration_seconds']
                            ?? 0,

                        'confidence' =>
                            $violation['confidence']
                            ?? $violation['confidence_score']
                            ?? 1,

                        'question_id' =>
                            $violation['question_id']
                            ?? $metadata['question_id']
                            ?? null,

                        'answer_id' =>
                            $violation['answer_id']
                            ?? $metadata['answer_id']
                            ?? null,

                        'source' =>
                            $violation['source']
                            ?? $metadata['source']
                            ?? AntiCheatLog::SOURCE_BROWSER_SECURITY,

                        'metadata' => $metadata,
                    ];
                }, $violations)
            )
        );

        $this->merge([
            'interview_id' => $this->input('interview_id'),
            'violations' => $normalizedViolations,
        ]);
    }

    public function rules(): array
    {
        return [
            'interview_id' => [
                'required',
                'integer',
                'exists:interviews,id',
            ],

            'violations' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],

            'violations.*.event_key' => [
                'nullable',
                'string',
                'max:100',
            ],

            'violations.*.type' => [
                'required',
                Rule::in(AntiCheatLog::allowedTypes()),
            ],

            'violations.*.timestamp' => [
                'required',
                'date',
            ],

            'violations.*.duration' => [
                'nullable',
                'numeric',
                'min:0',
                'max:300',
            ],

            'violations.*.confidence' => [
                'required',
                'numeric',
                'between:0,1',
            ],

            'violations.*.question_id' => [
                'nullable',
                'integer',
                'exists:questions,id',
            ],

            'violations.*.answer_id' => [
                'nullable',
                'integer',
                'exists:answers,id',
            ],

            'violations.*.source' => [
                'nullable',
                Rule::in(AntiCheatLog::allowedSources()),
            ],

            'violations.*.metadata' => [
                'nullable',
                'array',
            ],

            'violations.*.metadata.faces_detected' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'violations.*.metadata.looking_direction' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'violations.*.metadata.tab_switched' => [
                'sometimes',
                'boolean',
            ],

            'violations.*.metadata.action' => [
                'sometimes',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'violations.required' =>
                'At least one violation must be provided.',

            'violations.*.type.required' =>
                'The violation type is required.',

            'violations.*.type.in' =>
                'Invalid violation type provided.',

            'violations.*.confidence.between' =>
                'Confidence score must be between 0 and 1.',
        ];
    }
}