<?php
// app/Http/Requests/Company/CompanyPermissionTemplateRequest.php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyPermissionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // ✅ التحقق من أن المستخدم هو Company Owner
        // Company Owner هو من لديه دور 'company_owner'
        try {
            return $user->hasRole('company_owner');
        } catch (\Exception $e) {
            // Fallback: التحقق من أن لديه صلاحيات الشركة
            return $user->hasPermissionTo('company.employees.create');
        }
    }

    public function rules(): array
    {
        $templateId = $this->route('template')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('company_permission_templates', 'name')
                    ->where('company_id', $this->user()?->id ?? 0)
                    ->ignore($templateId),
            ],
            'description' => 'nullable|string|max:1000',
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'string|exists:permissions,name',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم القالب مطلوب',
            'name.unique' => 'هذا الاسم مستخدم بالفعل في شركتك',
            'permissions.required' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.min' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة',
        ];
    }
}
