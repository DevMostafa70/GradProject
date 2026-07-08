<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermissionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ✅ فقط super_admin يمكنه إنشاء/تعديل/حذف القوالب
        $user = $this->user();
        return $user && $user->isSuperAdmin();
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
            'name.unique' => 'هذا الاسم مستخدم بالفعل',
            'permissions.required' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.min' => 'يجب تحديد صلاحية واحدة على الأقل',
            'permissions.*.exists' => 'الصلاحية المحددة غير موجودة',
        ];
    }
}
