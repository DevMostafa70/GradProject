<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if ($user instanceof Company) {
            return true;
        }

        if ($user instanceof User && $user->isCompanyEmployee()) {
            return $user->hasPermissionTo('company.jobs.create');
        }

        return false;
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

        $requestedSource = strtolower(trim((string) $this->input('questions_source', 'mixed')));
        $questionsSource = $sourceAliases[$requestedSource] ?? $requestedSource;

        $locale = strtolower(trim((string) $this->input('interview_locale', 'en')));
        $locale = str_replace('_', '-', $locale);
        $locale = explode('-', $locale)[0] ?: 'en';

        $numberOfQuestions = (int) $this->input('number_of_questions', 0);
        $aiQuestionsCount = $this->input('ai_questions_count');
        $companyQuestionsCount = $this->input('company_questions_count');

        if ($questionsSource === 'ai_only') {
            $aiQuestionsCount = $numberOfQuestions;
            $companyQuestionsCount = 0;
        } elseif ($questionsSource === 'company_only') {
            $aiQuestionsCount = 0;
            $companyQuestionsCount = $numberOfQuestions;
        }

        $this->merge([
            'questions_source' => $questionsSource,
            'interview_locale' => $locale,
            'question_order' => 'random',
            'ai_questions_count' => $aiQuestionsCount,
            'company_questions_count' => $companyQuestionsCount,
            'invitation_valid_hours' => $this->input('invitation_valid_hours', 72),
            'max_resume_count' => 3,
            'identity_verification_required' => true,
            'identity_document_required' => true,
            'liveness_required' => false,
            'delete_identity_evidence_after_review' => true,
            'random_snapshot_count' => $this->input('random_snapshot_count', 3),
            'liveness_challenge_count' => 0,
            'hide_score_from_candidate' => true,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'array'],
            'title.en' => ['nullable', 'required_if:interview_locale,en', 'string', 'max:255'],
            'title.ar' => ['nullable', 'required_if:interview_locale,ar', 'string', 'max:255'],

            'description' => ['required', 'array'],
            'description.en' => ['nullable', 'required_if:interview_locale,en', 'string'],
            'description.ar' => ['nullable', 'required_if:interview_locale,ar', 'string'],

            'required_skills' => ['required', 'array', 'min:1'],
            'required_skills.*' => ['required', 'string', 'max:100'],

            'custom_questions' => ['nullable', 'array', 'max:20'],
            'custom_questions.*.question' => ['required'],
            'custom_questions.*.question.en' => ['nullable', 'string', 'max:1000'],
            'custom_questions.*.question.ar' => ['nullable', 'string', 'max:1000'],
            'custom_questions.*.type' => ['nullable', Rule::in(['technical', 'behavioral', 'situational', 'hr'])],

            'questions_source' => ['required', Rule::in(['ai_only', 'mixed', 'company_only'])],
            'number_of_questions' => ['required', 'integer', 'min:3', 'max:15'],
            'ai_questions_count' => ['required', 'integer', 'min:0', 'max:15'],
            'company_questions_count' => ['required', 'integer', 'min:0', 'max:15'],
            'question_order' => ['required', Rule::in(['random'])],

            'difficulty' => ['required', Rule::in(['easy', 'medium', 'hard'])],
            'difficulty_distribution' => ['nullable', 'array'],
            'difficulty_distribution.easy' => ['nullable', 'integer', 'min:0', 'max:15'],
            'difficulty_distribution.medium' => ['nullable', 'integer', 'min:0', 'max:15'],
            'difficulty_distribution.hard' => ['nullable', 'integer', 'min:0', 'max:15'],

            'interview_locale' => ['required', Rule::in(['ar', 'en'])],
            'interview_instructions' => ['nullable', 'array'],
            'interview_instructions.en' => ['nullable', 'string', 'max:5000'],
            'interview_instructions.ar' => ['nullable', 'string', 'max:5000'],

            'invitation_valid_hours' => ['required', 'integer', Rule::in([24, 48, 72, 120, 168])],
            'max_resume_count' => ['required', 'integer', 'in:3'],
            'interview_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
            'random_snapshot_count' => ['required', 'integer', 'min:1', 'max:8'],
            'liveness_challenge_count' => ['required', 'integer', 'in:0'],

            'identity_verification_required' => ['required', 'boolean'],
            'identity_document_required' => ['required', 'boolean'],
            'liveness_required' => ['required', 'boolean'],
            'delete_identity_evidence_after_review' => ['required', 'boolean'],

            'max_candidates' => ['nullable', 'integer', 'min:1', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'hide_score_from_candidate' => ['required', 'boolean'],
            'questions_file' => ['nullable', 'file', 'mimes:xlsx,csv,xls', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $source = $this->string('questions_source')->toString();
            $total = (int) $this->input('number_of_questions', 0);
            $aiCount = (int) $this->input('ai_questions_count', 0);
            $companyCount = (int) $this->input('company_questions_count', 0);

            if (($aiCount + $companyCount) !== $total) {
                $validator->errors()->add(
                    'number_of_questions',
                    'The AI and company question counts must equal the total number of questions.'
                );
            }

            if ($source === 'ai_only' && ($aiCount !== $total || $companyCount !== 0)) {
                $validator->errors()->add('questions_source', 'AI-only interviews must use AI questions only.');
            }

            if ($source === 'company_only' && ($companyCount !== $total || $aiCount !== 0)) {
                $validator->errors()->add('questions_source', 'Company-only interviews must use company questions only.');
            }

            if ($source === 'mixed' && ($aiCount < 1 || $companyCount < 1)) {
                $validator->errors()->add(
                    'questions_source',
                    'Mixed interviews require at least one AI question and one company question.'
                );
            }

            foreach ((array) $this->input('custom_questions', []) as $index => $customQuestion) {
                $questionValue = is_array($customQuestion)
                    ? ($customQuestion['question'] ?? null)
                    : null;

                $validQuestion = is_string($questionValue) && trim($questionValue) !== '';

                if (is_array($questionValue)) {
                    $validQuestion = collect($questionValue)
                        ->contains(fn ($value) => is_string($value) && trim($value) !== '');
                }

                if (!$validQuestion) {
                    $validator->errors()->add(
                        "custom_questions.{$index}.question",
                        'Each custom question must contain a non-empty text value.'
                    );
                }
            }

            $distribution = $this->input('difficulty_distribution');
            if (is_array($distribution) && $distribution !== []) {
                $distributionTotal = (int) ($distribution['easy'] ?? 0)
                    + (int) ($distribution['medium'] ?? 0)
                    + (int) ($distribution['hard'] ?? 0);

                if ($distributionTotal !== $total) {
                    $validator->errors()->add(
                        'difficulty_distribution',
                        'Difficulty distribution must equal the total number of questions.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'title.en.required' => 'English job title is required.',
            'title.ar.required' => 'Arabic job title is required.',
            'description.en.required' => 'English job description is required.',
            'description.ar.required' => 'Arabic job description is required.',
            'required_skills.required' => 'At least one skill is required.',
            'number_of_questions.min' => 'Minimum 3 questions per interview.',
            'number_of_questions.max' => 'Maximum 15 questions per interview.',
            'questions_file.mimes' => 'Questions file must be an Excel or CSV file.',
            'questions_file.max' => 'Questions file cannot exceed 10 MB.',
            'invitation_valid_hours.in' => 'Invitation validity must be 24, 48, 72, 120, or 168 hours.',
            'interview_locale.in' => 'Interview language must be Arabic or English.',
        ];
    }

    protected function failedAuthorization(): void
    {
        $user = auth()->user();
        $permissions = method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')->values()->all()
            : [];

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
