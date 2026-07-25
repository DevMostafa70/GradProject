<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Support\CompanyEmployeePermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Company;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->getKey() ?? 0;
        $employeeId = $this->route('employee')?->getKey();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($employeeId),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'template_id' => [
                'nullable',
                'integer',
                Rule::exists('company_permission_templates', 'id')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at')),
            ],
            'permissions' => ['nullable', 'array', 'min:1'],
            'permissions.*' => [
                'string',
                Rule::in(CompanyEmployeePermissionCatalog::ASSIGNABLE),
                Rule::exists('permissions', 'name')
                    ->where(fn ($query) => $query->where('guard_name', 'user')),
            ],
            'role' => [
                'nullable',
                'string',
                Rule::in(CompanyEmployeePermissionCatalog::ROLES),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('template_id') && $this->has('permissions')) {
                $validator->errors()->add(
                    'access',
                    'Send either a permission template or custom permissions, not both.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'هذا البريد مستخدم بالفعل',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق',
            'template_id.exists' => 'القالب المحدد غير موجود أو غير فعال في شركتك',
            'permissions.*.in' => 'تتضمن القائمة صلاحية لا يمكن إسنادها لموظف شركة',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة ضمن guard المستخدم',
            'role.in' => 'الدور المحدد غير صالح لموظف شركة',
        ];
    }
}
