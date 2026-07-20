<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $locale = strtolower(substr((string) ($this->input('locale') ?: $this->header('Accept-Language', app()->getLocale())), 0, 2));

        $this->merge([
            'locale' => in_array($locale, ['ar', 'en'], true) ? $locale : 'en',
        ]);
    }

    public function rules(): array
    {
        return [
            'position' => ['required', 'string', 'max:255'],
            'experience_level' => [
                'required',
                Rule::in(['junior', 'mid', 'senior', 'lead', 'executive']),
            ],
            'difficulty' => [
                'required',
                Rule::in(['easy', 'medium', 'hard']),
            ],
            'skills' => ['required', 'array', 'min:1'],
            'skills.*' => ['string', 'max:100'],
            'number_of_questions' => ['nullable', 'integer', 'min:3', 'max:10'],
            'session_duration' => ['nullable', 'integer', 'min:15', 'max:240'],
            'session_id' => ['nullable', 'string', 'max:100'],
            'device_fingerprint' => ['nullable', 'string', 'max:255'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
        ];
    }

    public function messages(): array
    {
        $arabic = $this->input('locale') === 'ar' || app()->getLocale() === 'ar';

        return $arabic
            ? [
                'position.required' => 'المسمى الوظيفي مطلوب.',
                'experience_level.required' => 'مستوى الخبرة مطلوب.',
                'experience_level.in' => 'مستوى الخبرة المحدد غير صالح.',
                'difficulty.required' => 'مستوى الصعوبة مطلوب.',
                'difficulty.in' => 'مستوى الصعوبة المحدد غير صالح.',
                'skills.required' => 'يرجى تحديد مهارة واحدة على الأقل للمقابلة.',
                'skills.min' => 'يرجى تحديد مهارة واحدة على الأقل للمقابلة.',
                'number_of_questions.min' => 'الحد الأدنى هو 3 أسئلة.',
                'number_of_questions.max' => 'الحد الأقصى هو 10 أسئلة.',
                'session_duration.min' => 'يجب ألا تقل مدة الجلسة عن 15 دقيقة.',
                'session_duration.max' => 'يجب ألا تتجاوز مدة الجلسة 240 دقيقة.',
                'locale.in' => 'لغة المقابلة يجب أن تكون العربية أو الإنجليزية.',
            ]
            : [
                'position.required' => 'The position is required.',
                'experience_level.required' => 'The experience level is required.',
                'experience_level.in' => 'The selected experience level is invalid.',
                'difficulty.required' => 'The difficulty level is required.',
                'difficulty.in' => 'The selected difficulty level is invalid.',
                'skills.required' => 'Please specify at least one skill for the interview.',
                'skills.min' => 'Please specify at least one skill for the interview.',
                'number_of_questions.min' => 'A minimum of 3 questions is required.',
                'number_of_questions.max' => 'A maximum of 10 questions is allowed.',
                'session_duration.min' => 'The session duration must be at least 15 minutes.',
                'session_duration.max' => 'The session duration cannot exceed 240 minutes.',
                'locale.in' => 'The interview language must be Arabic or English.',
            ];
    }
}
