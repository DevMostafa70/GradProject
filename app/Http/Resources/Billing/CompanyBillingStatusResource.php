<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompanyBillingStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->company_name,
                'email' => $company->email,
                'status' => $company->status?->value ?? $company->status,
                'is_approved' => $company->isApproved(),
            ],
            'billing' => [
                'status' => $company->billing_status instanceof \App\Enums\BillingStatus
                    ? $company->billing_status->value
                    : $company->billing_status,
                'has_paid_access' => $company->hasPaidAccess(),
                'billing_grace_ends_at' => $company->billing_grace_ends_at?->toISOString(),
                'billing_locked_at' => $company->billing_locked_at?->toISOString(),
                'trial_ends_at' => $company->trial_ends_at?->toISOString(),
                'payment_method' => [
                    'type' => $company->pm_type,
                    'last_four' => $company->pm_last_four,
                ],
            ],
            'selected_plan' => $company->selectedPlan
                ? new PlanResource($company->selectedPlan)
                : null,
        ];
    }
}
