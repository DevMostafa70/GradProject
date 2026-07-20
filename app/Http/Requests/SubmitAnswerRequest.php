<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SubmitAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $violations = $this->input('violations');

        if (is_string($violations)) {
            $decoded = json_decode($violations, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge(['violations' => $decoded]);
            }
        }

        $locale = strtolower(substr((string) ($this->input('locale') ?: $this->header('Accept-Language', app()->getLocale())), 0, 2));
        $this->merge([
            'locale' => in_array($locale, ['ar', 'en'], true) ? $locale : 'en',
        ]);
    }

    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'audio_file' => [
                'bail',
                'required',
                'file',
                'max:102400',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $arabic = $this->input('locale') === 'ar' || app()->getLocale() === 'ar';

                    if (!$value instanceof UploadedFile || !$value->isValid()) {
                        $fail($arabic
                            ? 'ملف تسجيل الصوت المرفوع غير صالح.'
                            : 'The uploaded audio recording is invalid.');
                        return;
                    }

                    $size = $value->getSize();
                    if ($size === false || $size <= 0) {
                        $fail($arabic
                            ? 'التسجيل الصوتي فارغ. يرجى تسجيل الإجابة مرة أخرى.'
                            : 'The audio recording is empty. Please record the answer again.');
                    }
                },
            ],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:600'],
            'idempotency_key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-zA-Z0-9\-_]+$/',
            ],
            'violations' => ['present', 'array', 'max:50'],
            'violations.*.event_key' => ['nullable', 'string', 'max:100'],
            'violations.*.type' => [
                'required',
                'string',
                Rule::in([
                    'multiple_faces',
                    'looking_away',
                    'tab_switch',
                    'window_blur',
                    'fullscreen_exit',
                    'suspicious_movement',
                    'audio_anomaly',
                    'device_change',
                    'browser_console',
                    'copy_paste_attempt',
                    'screen_capture',
                ]),
            ],
            'violations.*.timestamp' => ['required', 'date'],
            'violations.*.duration' => ['nullable', 'numeric', 'min:0', 'max:600'],
            'violations.*.confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'violations.*.question_id' => ['nullable', 'integer'],
            'violations.*.answer_id' => ['nullable', 'integer'],
            'violations.*.source' => ['nullable', 'string', 'max:50'],
            'violations.*.metadata' => ['nullable', 'array'],
            'violations.*.metadata.faces_detected' => ['sometimes', 'integer', 'min:0'],
            'violations.*.metadata.looking_direction' => ['sometimes', 'string', 'max:50'],
            'violations.*.metadata.tab_switched' => ['sometimes', 'boolean'],
            'violations.*.metadata.question_id' => ['sometimes', 'integer'],
        ];
    }

    public function messages(): array
    {
        $arabic = $this->input('locale') === 'ar' || app()->getLocale() === 'ar';

        return $arabic
            ? [
                'audio_file.required' => 'تسجيل الصوت مطلوب.',
                'audio_file.file' => 'تعذر رفع التسجيل الصوتي كملف.',
                'audio_file.max' => 'يجب ألا يتجاوز حجم ملف الصوت 100 ميجابايت.',
                'duration_seconds.max' => 'يجب ألا تتجاوز مدة الإجابة 10 دقائق.',
                'idempotency_key.required' => 'مفتاح منع التكرار مطلوب.',
                'idempotency_key.regex' => 'مفتاح منع التكرار يمكن أن يحتوي فقط على أحرف وأرقام وشرطات.',
                'violations.present' => 'يجب تضمين حقل المخالفات في الطلب.',
                'violations.array' => 'يجب أن يكون حقل المخالفات مصفوفة.',
                'violations.*.type.in' => 'يوجد نوع مخالفة غير صالح.',
                'locale.in' => 'لغة الإجابة يجب أن تكون العربية أو الإنجليزية.',
            ]
            : [
                'audio_file.required' => 'Audio recording is required.',
                'audio_file.file' => 'The audio recording could not be uploaded as a file.',
                'audio_file.max' => 'The audio file size must not exceed 100MB.',
                'duration_seconds.max' => 'The answer duration cannot exceed 10 minutes.',
                'idempotency_key.required' => 'The idempotency key is required.',
                'idempotency_key.regex' => 'The idempotency key may contain only letters, numbers, dashes, and underscores.',
                'violations.present' => 'The violations field must be included in the request.',
                'violations.array' => 'The violations field must be an array.',
                'violations.*.type.in' => 'One or more violation types are invalid.',
                'locale.in' => 'The answer language must be Arabic or English.',
            ];
    }
}
