<?php
// app/Http/Requests/Company/CreateEmployeeRequest.php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEmployeeRequest extends FormRequest
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
            return $user->hasPermissionTo('company.employees.create');
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'template_id' => 'nullable|exists:company_permission_templates,id',
            'permissions' => 'nullable|array|min:1',
            'permissions.*' => 'string|exists:permissions,name',
            'role' => 'nullable|string|exists:roles,name',
        ];
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
            'template_id.exists' => 'القالب المحدد غير موجود',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة',
            'role.exists' => 'الدور المحدد غير موجود',
        ];
    }
}
