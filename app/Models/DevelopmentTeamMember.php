<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

final class DevelopmentTeamMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_ar',
        'name_en',
        'role_ar',
        'role_en',
        'bio_ar',
        'bio_en',
        'responsibilities_ar',
        'responsibilities_en',
        'skills',
        'image_path',
        'email',
        'linkedin_url',
        'github_url',
        'portfolio_url',
        'display_order',
        'is_active',
        'is_featured',
        'created_by',
    ];

    protected $casts = [
        'skills' => 'array',
        'display_order' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
    ];

    protected $appends = ['image_url'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return asset("storage/" . $this->image_path);
    }
}
