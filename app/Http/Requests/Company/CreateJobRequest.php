<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if ($user instanceof \App\Models\Company) {
            return true;
        }

        if ($user instanceof \App\Models\User && $user->isCompanyEmployee()) {
            return $user->hasPermissionTo('company.jobs.create');
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|array',
            'title.en' => 'required|string|max:255',
            'title.ar' => 'required|string|max:255',

            'description' => 'required|array',
            'description.en' => 'required|string',
            'description.ar' => 'required|string',
            'required_skills' => 'required|array|min:1',
            'required_skills.*' => 'string|max:100',
            // 'custom_questions' => 'nullable|array|max:10',
            // 'custom_questions.*.question' => 'required|string|max:500',
            // 'custom_questions.*.type' => 'nullable|in:technical,behavioral,situational',
            'custom_questions.*.question' => 'required',
            'custom_questions.*.question.en' => 'nullable|string|max:500',
            'custom_questions.*.question.ar' => 'nullable|string|max:500',
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
            'title.en.required' => 'English job title is required',
            'title.ar.required' => 'Arabic job title is required',
            'description.en.required' => 'English job description is required',
            'description.ar.required' => 'Arabic job description is required',
            'required_skills.required' => 'At least one skill is required',
            'number_of_questions.min' => 'Minimum 3 questions per interview',
            'number_of_questions.max' => 'Maximum 15 questions per interview',
            'ai_questions_count.max' => 'AI questions cannot exceed 15 per candidate.',
            'company_questions_count.max' => 'Company questions cannot exceed 20 per candidate.',
            'questions_file.mimes' => 'Questions file must be Excel or CSV format.',
            'questions_file.max' => 'Questions file cannot exceed 10MB.',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        $user = auth()->user();
        $permissions = $user ? $user->getAllPermissions()->pluck('name')->toArray() : [];

        // ✅ استخدام HttpResponseException بدلاً من abort
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Unauthorized. You do not have the required permission.',
                'required_permissions' => ['company.jobs.create'],
                'your_permissions' => $permissions,
            ], 403)
        );
    }
}
