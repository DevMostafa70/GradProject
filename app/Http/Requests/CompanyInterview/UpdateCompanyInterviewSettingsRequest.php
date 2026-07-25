<?php

namespace App\Http\Requests\CompanyInterview;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompanyInterviewSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        $sourceAliases = [
            'ai' => 'ai_only',
            'ai_only' => 'ai_only',
            'company' => 'company_only',
            'company_only' => 'company_only',
            'mixed' => 'mixed',
        ];

        $source = strtolower(trim((string) $this->input('questions_source', 'mixed')));
        $source = $sourceAliases[$source] ?? $source;
        $total = (int) $this->input('number_of_questions', 0);

        $aiCount = $this->input('ai_questions_count');
        $companyCount = $this->input('company_questions_count');

        if ($source === 'ai_only') {
            $aiCount = $total;
            $companyCount = 0;
        } elseif ($source === 'company_only') {
            $aiCount = 0;
            $companyCount = $total;
        }

        $locale = strtolower(str_replace('_', '-', (string) $this->input('interview_locale', 'en')));
        $locale = explode('-', $locale)[0] ?: 'en';

        $this->merge([
            'questions_source' => $source,
            'interview_locale' => $locale,
            'ai_questions_count' => $aiCount,
            'company_questions_count' => $companyCount,
            'question_order' => 'random',
            'max_resume_count' => 3,
            'identity_verification_required' => true,
            'identity_document_required' => true,
            'liveness_required' => false,
            'liveness_challenge_count' => 0,
            'delete_identity_evidence_after_review' => true,
        ]);
    }

    public function rules(): array
    {
        return [
            'interview_locale' => ['required', Rule::in(['ar', 'en'])],
            'questions_source' => ['required', Rule::in(['ai_only', 'mixed', 'company_only'])],
            'number_of_questions' => ['required', 'integer', 'min:3', 'max:15'],
            'ai_questions_count' => ['required', 'integer', 'min:0', 'max:15'],
            'company_questions_count' => ['required', 'integer', 'min:0', 'max:15'],
            'difficulty' => ['required', Rule::in(['easy', 'medium', 'hard'])],
            'question_order' => ['required', Rule::in(['random'])],
            'invitation_valid_hours' => ['required', 'integer', Rule::in([24, 48, 72, 120, 168])],
            'max_resume_count' => ['required', 'integer', 'in:3'],
            'interview_duration_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'random_snapshot_count' => ['required', 'integer', 'min:1', 'max:8'],
            'liveness_challenge_count' => ['required', 'integer', 'in:0'],
            'identity_verification_required' => ['required', 'accepted'],
            'identity_document_required' => ['required', 'accepted'],
            'liveness_required' => ['required', 'boolean'],
            'delete_identity_evidence_after_review' => ['required', 'accepted'],
            'interview_instructions' => ['nullable', 'array'],
            'interview_instructions.ar' => ['nullable', 'string', 'max:5000'],
            'interview_instructions.en' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $source = (string) $this->input('questions_source');
            $total = (int) $this->input('number_of_questions', 0);
            $ai = (int) $this->input('ai_questions_count', 0);
            $company = (int) $this->input('company_questions_count', 0);

            if (($ai + $company) !== $total) {
                $validator->errors()->add(
                    'number_of_questions',
                    'AI and company question counts must equal the total number of questions.'
                );
            }

            if ($source === 'mixed' && ($ai < 1 || $company < 1)) {
                $validator->errors()->add(
                    'questions_source',
                    'Mixed interviews require at least one AI question and one company question.'
                );
            }
        });
    }
}
