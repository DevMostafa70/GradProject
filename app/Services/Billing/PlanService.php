<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

final class PlanService
{
    /**
     * @return Collection<int, Plan>
     */
    public function getActivePlans(): Collection
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findActivePlanByCode(string $code): Plan
    {
        return Plan::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
