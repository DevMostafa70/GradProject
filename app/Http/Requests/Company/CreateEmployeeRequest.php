<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Support\CompanyEmployeePermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CreateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Company;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->getKey() ?? 0;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasTemplate = $this->filled('template_id');
            $hasPermissions = is_array($this->input('permissions'))
                && count($this->input('permissions')) > 0;

            if ($hasTemplate === $hasPermissions) {
                $validator->errors()->add(
                    'access',
                    'Choose either one permission template or custom permissions.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم الموظف مطلوب',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.unique' => 'هذا البريد مستخدم بالفعل',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق',
            'template_id.exists' => 'القالب المحدد غير موجود أو غير فعال في شركتك',
            'permissions.*.in' => 'تتضمن القائمة صلاحية لا يمكن إسنادها لموظف شركة',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة ضمن guard المستخدم',
            'role.in' => 'الدور المحدد غير صالح لموظف شركة',
        ];
    }
}
