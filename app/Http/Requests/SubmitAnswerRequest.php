<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'audio_file' => ['required', 'file', 'mimes:webm,mp3,wav,m4a', 'max:102400'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:600'],
        ];
    }

    public function messages(): array
    {
        return [
            'audio_file.required' => 'Audio recording is required.',
            'audio_file.max' => 'Audio file size must not exceed 100MB.',
            'duration_seconds.max' => 'Answer duration cannot exceed 10 minutes.',
        ];
    }
}
