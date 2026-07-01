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
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'pricing' => [
                'monthly' => $this->monthly_price,      
                'yearly' => $this->yearly_price,        
                'currency' => $this->currency ?? 'usd',
            ],
            'features' => $this->features ?? [],
            'limits' => [
                'interviews' => $this->interviews_limit,
                'jobs' => $this->jobs_limit,
                'candidates' => $this->candidates_limit,
            ],
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
