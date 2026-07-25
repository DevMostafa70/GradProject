<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermissionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        $templateId = $this->route('template')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('permission_templates', 'name')->ignore($templateId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'string',
                Rule::exists('permissions', 'name')->where('guard_name', 'admin'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم القالب مطلوب',
            'name.unique' => 'هذا الاسم مستخدم بالفعل',
            'permissions.required' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.min' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة ضمن صلاحيات الأدمن',
        ];
    }
}
