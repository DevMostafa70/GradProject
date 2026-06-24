<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'stripe_product_id',
        'stripe_price_monthly_id',
        'stripe_price_yearly_id',
        'monthly_amount',
        'yearly_amount',
        'currency',
        'features',
        'limits',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'code' => PlanCode::class,
            'monthly_amount' => 'integer',
            'yearly_amount' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'selected_plan_id');
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) data_get($this->features, $feature, false);
    }

    public function limit(string $key): ?int
    {
        $value = data_get($this->limits, $key);

        return is_numeric($value) ? (int) $value : null;
    }
}
