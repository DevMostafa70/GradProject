<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', Rule::in(['super_admin', 'admin'])],
            'template_id' => [
                'nullable',
                Rule::exists('permission_templates', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'permissions' => ['nullable', 'array', 'min:1'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'admin'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم الأدمن مطلوب',
            'email.required' => 'البريد الإلكتروني مطلوب',
            'email.unique' => 'هذا البريد مستخدم بالفعل',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق',
            'role.in' => 'الدور يجب أن يكون super_admin أو admin',
            'template_id.exists' => 'القالب المحدد غير موجود أو غير نشط',
            'permissions.min' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة ضمن صلاحيات الأدمن',
        ];
    }
}
