<?php

namespace App\Http\Requests\Company;

use App\Models\CompanyJobCandidate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isCompany();
    }
    //ترشيح , رفض  , توظيف
    public function rules(): array
    {
        return [
            'status' => 'required|in:' . implode(',', [
                CompanyJobCandidate::STATUS_SHORTLISTED,
                CompanyJobCandidate::STATUS_REJECTED,
                CompanyJobCandidate::STATUS_HIRED,
            ]),
            'company_notes' => 'nullable|string|max:1000',
        ];
    }
}
