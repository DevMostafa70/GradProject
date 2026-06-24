<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code?->value ?? $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'pricing' => [
                'monthly_amount' => $this->monthly_amount,
                'yearly_amount' => $this->yearly_amount,
                'currency' => $this->currency,
            ],
            'features' => $this->features ?? [],
            'limits' => $this->limits ?? [],
            'stripe' => [
                'product_id' => $this->stripe_product_id,
                'monthly_price_id' => $this->stripe_price_monthly_id,
                'yearly_price_id' => $this->stripe_price_yearly_id,
            ],
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
