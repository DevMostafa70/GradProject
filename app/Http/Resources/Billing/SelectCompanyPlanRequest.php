<?php

declare(strict_types=1);

namespace App\Http\Requests\Company\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SelectCompanyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'plan_code' => [
                'required',
                'string',
                Rule::exists('plans', 'code')->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_code.required' => 'Plan code is required.',
            'plan_code.exists' => 'The selected plan is not available.',
        ];
    }

    public function planCode(): string
    {
        return (string) $this->validated('plan_code');
    }
}
