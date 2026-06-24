<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;

trait FileValidationTrait
{
    /**
     * التحقق من صحة الملف
     */
    protected function validateFile(UploadedFile $file, array $allowedMimes = ['pdf', 'docx', 'txt'], int $maxSizeKB = 5120): array
    {
        $errors = [];

        // التحقق من النوع
        $extension = $file->getClientOriginalExtension();
        if (!in_array(strtolower($extension), $allowedMimes)) {
            $errors[] = 'الملف يجب أن يكون بصيغة: ' . implode(', ', $allowedMimes);
        }

        // التحقق من الحجم (بالكيلوبايت)
        $sizeKB = $file->getSize() / 1024;
        if ($sizeKB > $maxSizeKB) {
            $errors[] = "حجم الملف ($sizeKB KB) يتجاوز الحد المسموح ($maxSizeKB KB).";
        }

        // التحقق من أن الملف قابل للقراءة
        if (!$file->isReadable()) {
            $errors[] = 'الملف غير قابل للقراءة.';
        }

        return $errors;
    }
}
