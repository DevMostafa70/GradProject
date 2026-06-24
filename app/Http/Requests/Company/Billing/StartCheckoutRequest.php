<?php

namespace App\Http\Requests\Company\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'billing_interval' => [
                'nullable',
                'string',
                Rule::in(['monthly', 'yearly']),
            ],
        ];
    }

    public function billingInterval(): string
    {
        return (string) ($this->validated('billing_interval') ?? 'monthly');
    }
}
