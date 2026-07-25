<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Models\CompanyJobCandidate;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if ($user instanceof Company) {
            return true;
        }

        return $user instanceof User
            && $user->isCompanyEmployee()
            && $user->hasPermissionTo('company.candidates.update');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:' . implode(',', [
                CompanyJobCandidate::STATUS_SHORTLISTED,
                CompanyJobCandidate::STATUS_REJECTED,
                CompanyJobCandidate::STATUS_HIRED,
            ])],
            'company_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
