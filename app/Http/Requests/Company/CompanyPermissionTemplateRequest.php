<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Support\CompanyEmployeePermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CompanyPermissionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Company;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->getKey() ?? 0;
        $templateId = $this->route('template')?->getKey();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('company_permission_templates', 'name')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at'))
                    ->ignore($templateId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'string',
                Rule::in(CompanyEmployeePermissionCatalog::ASSIGNABLE),
                Rule::exists('permissions', 'name')
                    ->where(fn ($query) => $query->where('guard_name', 'user')),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم القالب مطلوب',
            'name.unique' => 'هذا الاسم مستخدم بالفعل في شركتك',
            'permissions.required' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.min' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.*.in' => 'تتضمن القائمة صلاحية خاصة بمالك الشركة أو غير قابلة للإسناد',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة ضمن guard المستخدم',
        ];
    }
}
