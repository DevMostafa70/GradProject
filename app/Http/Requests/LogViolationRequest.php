<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogViolationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interview_id' => ['required', 'integer', 'exists:interviews,id'],
            'violations' => ['required', 'array', 'min:1'],
            'violations.*.type' => [
                'required',
                Rule::in([
                    'multiple_faces',
                    'looking_away',
                    'tab_switch',
                    'window_blur',
                    'suspicious_movement',
                    'audio_anomaly',
                    'device_change',
                    'browser_console',
                    'copy_paste_attempt',
                    'screen_capture'
                ])
            ],
            'violations.*.timestamp' => ['required', 'date'],
            'violations.*.duration' => ['numeric', 'min:0', 'max:300'],
            'violations.*.confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'violations.*.metadata' => ['array'],
            'violations.*.metadata.faces_detected' => ['sometimes', 'integer'],
            'violations.*.metadata.looking_direction' => ['sometimes', 'string'],
            'violations.*.metadata.tab_switched' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'violations.required' => 'At least one violation must be provided.',
            'violations.*.type.in' => 'Invalid violation type provided.',
            'violations.*.confidence.between' => 'Confidence score must be between 0 and 1.',
        ];
    }
}
