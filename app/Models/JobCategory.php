<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasTranslations;

class JobCategory extends Model
{
    use HasFactory, HasTranslations;

    protected $table = 'job_categories';

    protected $fillable = [
        'name',
        'icon',
        'color',
        'sort_order',
        'is_active',
        'job_count',
    ];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'job_count' => 'integer',
    ];

    public function incrementJobCount(): void
    {
        $this->increment('job_count');
    }

    public function decrementJobCount(): void
    {
        $this->decrement('job_count');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc');
    }
}
