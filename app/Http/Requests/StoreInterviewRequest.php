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

    public function rules(): array
    {
        return [
            'position' => ['required', 'string', 'max:255'],
            'experience_level' => [
                'required',
                Rule::in(['junior', 'mid', 'senior', 'lead', 'executive'])
            ],
            'difficulty' => [
                'required',
                Rule::in(['easy', 'medium', 'hard'])
            ],
            'skills' => ['required', 'array', 'min:1'],
            'skills.*' => ['string', 'max:100'],
             // 🔹 MODIFIED: Remove 'default' from validation rules
            'number_of_questions' => ['nullable', 'integer', 'min:3', 'max:10'],
            // 🔹 NEW: Session duration in minutes (optional)
            'session_duration' => ['nullable', 'integer', 'min:15', 'max:240'],
        ];
    }

    public function messages(): array
    {
        return [
            'skills.required' => 'Please specify at least one skill for the interview.',
            'skills.min' => 'Please specify at least one skill for the interview.',
            'number_of_questions.min' => 'Minimum 3 questions required.',
            'number_of_questions.max' => 'Maximum 10 questions allowed.',
            'session_duration.min' => 'Session duration must be at least 15 minutes.',
            'session_duration.max' => 'Session duration cannot exceed 240 minutes (4 hours).',

            'session_id' => ['nullable', 'string', 'max:64'],
            'device_fingerprint' => ['nullable', 'string', 'max:255'],

        ];
    }
}
