<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'monthly_price',
        'yearly_price',
        'currency',
        'stripe_product_id',
        'stripe_price_monthly_id',
        'stripe_price_yearly_id',
        'interviews_limit',
        'jobs_limit',
        'candidates_limit',
        'features',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'interviews_limit' => 'integer',
            'jobs_limit' => 'integer',
            'candidates_limit' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'selected_plan_id');
    }

    public function getStripePriceId(string $interval = 'monthly'): ?string
    {
        return $interval === 'yearly'
            ? $this->stripe_price_yearly_id
            : $this->stripe_price_monthly_id;
    }

    public function limit(string $key): ?int
    {
        return match ($key) {
            'jobs' => $this->jobs_limit,
            'candidates' => $this->candidates_limit,
            'interviews' => $this->interviews_limit,
            default => null,
        };
    }
}
