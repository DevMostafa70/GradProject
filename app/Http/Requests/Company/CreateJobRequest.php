<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class CreateJobRequest extends FormRequest
{
    public function authorize(): bool
{
    $company = auth()->user();

    if (!$company) {
        return false;
    }

    // التحقق من أن الكائن هو من نوع Company
    return $company instanceof \App\Models\Company;
}

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'required_skills' => 'required|array|min:1',
            'required_skills.*' => 'string|max:100',
            'custom_questions' => 'nullable|array|max:10',
            'custom_questions.*.question' => 'required|string|max:500',
            'custom_questions.*.type' => 'nullable|in:technical,behavioral,situational',
            'questions_source' => 'nullable|in:ai_only,mixed,company_only',
            'number_of_questions' => 'required|integer|min:3|max:15',
            'difficulty' => 'required|in:easy,medium,hard',
            'max_candidates' => 'nullable|integer|min:1|max:500',
            'expires_at' => 'nullable|date|after:today',
            'hide_score_from_candidate' => 'boolean',
            'ai_questions_count' => 'nullable|integer|min:0|max:15',
            'company_questions_count' => 'nullable|integer|min:0|max:20',
            'difficulty_distribution' => 'nullable|array',
            'difficulty_distribution.easy' => 'nullable|integer|min:0',
            'difficulty_distribution.medium' => 'nullable|integer|min:0',
            'difficulty_distribution.hard' => 'nullable|integer|min:0',
            'questions_file' => 'nullable|file|mimes:xlsx,csv,xls|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Job title is required',
            'description.required' => 'Job description is required',
            'required_skills.required' => 'At least one skill is required',
            'number_of_questions.min' => 'Minimum 3 questions per interview',
            'number_of_questions.max' => 'Maximum 15 questions per interview',
            'ai_questions_count.max' => 'AI questions cannot exceed 15 per candidate.',
            'company_questions_count.max' => 'Company questions cannot exceed 20 per candidate.',
            'questions_file.mimes' => 'Questions file must be Excel or CSV format.',
            'questions_file.max' => 'Questions file cannot exceed 10MB.',
        ];
    }
}
