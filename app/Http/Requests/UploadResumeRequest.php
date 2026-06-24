<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        // الحد الأقصى لحجم الملف (5MB)
        $maxFileSize = config('app.max_resume_size', 5120); // بالكيلوبايت

        return [
            'resume' => [
                'required',
                'file',
                'mimes:pdf,docx,txt',
                'max:' . $maxFileSize,
            ],
            'target_position' => 'nullable|string|max:255',
            'target_skills' => 'nullable|array',
            'target_skills.*' => 'string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'resume.required' => 'يرجى اختيار ملف السيرة الذاتية.',
            'resume.file' => 'الملف غير صحيح.',
            'resume.mimes' => 'الملف يجب أن يكون بصيغة PDF أو DOCX أو TXT فقط.',
            'resume.max' => 'حجم الملف يتجاوز الحد المسموح (5 ميجابايت كحد أقصى).',
            'target_position.string' => 'الوظيفة المستهدفة يجب أن تكون نصاً.',
            'target_position.max' => 'الوظيفة المستهدفة لا تتجاوز 255 حرفاً.',
            'target_skills.array' => 'المهارات يجب أن تكون على شكل قائمة.',
            'target_skills.*.string' => 'المهارة يجب أن تكون نصاً.',
            'target_skills.*.max' => 'المهارة لا تتجاوز 100 حرف.',
        ];
    }
}
