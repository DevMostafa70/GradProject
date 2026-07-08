<?php
// app/Http/Requests/Company/UpdateEmployeeRequest.php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // ✅ التحقق من أن المستخدم هو Company Owner
        try {
            return $user->hasRole('company_owner');
        } catch (\Exception $e) {
            return $user->hasPermissionTo('company.employees.update');
        }
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee');

        return [
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($employeeId),
            ],
            'password' => 'nullable|string|min:8|confirmed',
            'template_id' => 'nullable|exists:company_permission_templates,id',
            'permissions' => 'nullable|array|min:1',
            'permissions.*' => 'string|exists:permissions,name',
            'role' => 'nullable|string|exists:roles,name',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'هذا البريد مستخدم بالفعل',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق',
            'template_id.exists' => 'القالب المحدد غير موجود',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة',
            'role.exists' => 'الدور المحدد غير موجود',
        ];
    }
}
