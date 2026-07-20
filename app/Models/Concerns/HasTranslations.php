<?php

namespace App\Models\Concerns;

trait HasTranslations
{
    public function translate(string $field, ?string $locale = null, mixed $fallback = null): mixed
    {
        $locale = $this->normalizeLocale($locale ?: app()->getLocale());
        $value = $this->getAttribute($field);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        if (! is_array($value)) {
            return $value ?? $fallback;
        }

        return $value[$locale]
            ?? $value[config('app.fallback_locale', 'en')]
            ?? $value['en']
            ?? $value['ar']
            ?? $fallback;
    }

    public function translations(string $field): array
    {
        $value = $this->getAttribute($field);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    public static function localized(array|string|null $value, ?string $fallbackLocale = null): array
    {
        if (is_array($value)) {
            return [
                'en' => $value['en'] ?? $value['ar'] ?? null,
                'ar' => $value['ar'] ?? $value['en'] ?? null,
            ];
        }

        return [
            'en' => $fallbackLocale === 'ar' ? null : $value,
            'ar' => $fallbackLocale === 'ar' ? $value : null,
        ];
    }

    protected function normalizeLocale(string $locale): string
    {
        $locale = strtolower(substr($locale, 0, 2));

        return in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
    }
}