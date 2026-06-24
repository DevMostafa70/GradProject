<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobCategory extends Model
{
    use HasFactory;

    protected $table = 'job_categories';

    protected $fillable = [
        'name_ar',
        'name_en',
        'icon',
        'color',
        'sort_order',
        'is_active',
        'job_count',
    ];

    protected $casts = [
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
