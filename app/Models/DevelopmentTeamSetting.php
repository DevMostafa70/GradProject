<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DevelopmentTeamSetting extends Model
{
    protected $fillable = [
        'is_enabled',
        'eyebrow_ar',
        'eyebrow_en',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'is_enabled' => true,
            'eyebrow_ar' => 'صُنّاع التجربة',
            'eyebrow_en' => 'Meet the builders',
            'title_ar' => 'الفريق الذي بنى Nervu.AI',
            'title_en' => 'The team behind Nervu.AI',
        ]);
    }
}
