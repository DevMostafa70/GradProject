<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class DevelopmentTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $skills = $this->input('skills');

        if (is_string($skills)) {
            $decoded = json_decode($skills, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $skills = $decoded;
            } else {
                $skills = array_values(array_filter(array_map(
                    static fn (string $skill): string => trim($skill),
                    explode(',', $skills)
                )));
            }
        }

        $this->merge([
            'skills' => is_array($skills) ? array_values($skills) : [],
            'is_active' => $this->boolean('is_active', true),
            'is_featured' => $this->boolean('is_featured', false),
        ]);
    }

    public function rules(): array
    {
        $isCreating = $this->route('member') === null;

        return [
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'role_ar' => ['required', 'string', 'max:160'],
            'role_en' => ['required', 'string', 'max:160'],
            'bio_ar' => ['required', 'string', 'max:1500'],
            'bio_en' => ['required', 'string', 'max:1500'],
            'responsibilities_ar' => ['nullable', 'string', 'max:2000'],
            'responsibilities_en' => ['nullable', 'string', 'max:2000'],
            'skills' => ['nullable', 'array', 'max:12'],
            'skills.*' => ['required', 'string', 'max:60', 'distinct'],
            'image' => [$isCreating ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'linkedin_url' => ['nullable', 'url:http,https', 'max:500'],
            'github_url' => ['nullable', 'url:http,https', 'max:500'],
            'portfolio_url' => ['nullable', 'url:http,https', 'max:500'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'A professional member image is required.',
            'image.max' => 'The image must not exceed 4 MB.',
            'skills.max' => 'A maximum of 12 skills is allowed.',
        ];
    }
}
