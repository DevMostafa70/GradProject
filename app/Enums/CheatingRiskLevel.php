<?php

namespace App\Enums;

enum CheatingRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Get the label for the risk level
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'منخفض',
            self::Medium => 'متوسط',
            self::High => 'مرتفع',
            self::Critical => 'حرج',
        };
    }

    /**
     * Get the color for the risk level (for UI)
     */
    public function color(): string
    {
        return match ($this) {
            self::Low => '#22c55e',     // Green
            self::Medium => '#eab308',  // Yellow
            self::High => '#f97316',    // Orange
            self::Critical => '#ef4444', // Red
        };
    }

    /**
     * Get the badge class for the risk level
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Low => 'bg-green-100 text-green-800',
            self::Medium => 'bg-yellow-100 text-yellow-800',
            self::High => 'bg-orange-100 text-orange-800',
            self::Critical => 'bg-red-100 text-red-800',
        };
    }

    /**
     * Get the description for the risk level
     */
    public function description(): string
    {
        return match ($this) {
            self::Low => 'لا توجد مخالفات خطيرة. يمكن الوثوق بالنتيجة.',
            self::Medium => 'تم رصد بعض المخالفات البسيطة. النتيجة موثوقة إلى حد كبير.',
            self::High => 'تم رصد مخالفات متعددة. النتيجة قد لا تكون موثوقة تماماً.',
            self::Critical => 'تم رصد مخالفات خطيرة جداً. لا يمكن الوثوق بالنتيجة.',
        };
    }

    /**
     * Get the recommendation based on risk level
     */
    public function recommendation(): string
    {
        return match ($this) {
            self::Low => 'يمكن الاعتماد على النتيجة بشكل كامل.',
            self::Medium => 'يُنصح بمراجعة بعض الإجابات يدوياً.',
            self::High => 'يُنصح بإجراء مقابلة إضافية أو مراجعة شاملة.',
            self::Critical => 'لا يُنصح بالاعتماد على هذه النتيجة. يُفضل إعادة المقابلة.',
        };
    }

    /**
     * Get the severity weight for penalty calculation
     */
    public function penaltyMultiplier(): float
    {
        return match ($this) {
            self::Low => 0.9,
            self::Medium => 0.7,
            self::High => 0.4,
            self::Critical => 0.1,
        };
    }

    /**
     * Create from severity score
     */
    public static function fromScore(float $score): self
    {
        return match (true) {
            $score <= 2 => self::Low,
            $score <= 4 => self::Medium,
            $score <= 7 => self::High,
            default => self::Critical,
        };
    }
}
