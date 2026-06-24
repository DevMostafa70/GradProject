<?php

namespace App\Services\Billing;

use App\Enums\BillingStatus;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class CompanyBillingService
{
    public function __construct(
        private readonly PlanService $planService,
    ) {
    }

    public function selectPlan(Company $company, string $planCode): Company
    {
        $plan = $this->planService->findActivePlanByCode($planCode);

        return DB::transaction(function () use ($company, $plan): Company {
            $company->forceFill([
                'selected_plan_id' => $plan->id,
                'billing_status' => BillingStatus::CheckoutPending,
                'billing_locked_at' => null,
            ])->save();

            return $company->fresh(['selectedPlan']);
        });
    }

    public function getSelectedPlan(Company $company): ?Plan
    {
        return $company->selectedPlan;
    }

    public function hasSelectedPlan(Company $company): bool
    {
        return $company->selected_plan_id !== null;
    }
}
