<?php

namespace App\Http\Requests\Company\Billing;

use Illuminate\Foundation\Http\FormRequest;

class SelectCompanyPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // ✅ السماح فقط للشركات (Company model)
        $user = $this->user();

        if (!$user) {
            return false;
        }

        return $user instanceof \App\Models\Company;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'plan_code' => 'required|string|in:starter,growth,business,enterprise',
        ];
    }

    /**
     * Get the plan code from the request.
     */
    public function planCode(): string
    {
        return $this->validated('plan_code');
    }
}
