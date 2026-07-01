<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Company\Billing;

use App\Enums\BillingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\Billing\SelectCompanyPlanRequest;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

final class SelectPlanController extends Controller
{
    public function store(SelectCompanyPlanRequest $request): JsonResponse
    {
        /** @var \App\Models\Company $company */
        $company = $request->user();

        // ✅ التأكد من أن المستخدم شركة
        if (!$company || !($company instanceof \App\Models\Company)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Company access required.',
            ], 403);
        }

        // البحث عن الخطة
        $plan = Plan::where('slug', $request->planCode())
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid plan selected.',
            ], 422);
        }

        // تحديث الشركة بالخطة المختارة
        $company->update([
            'selected_plan_id' => $plan->id,
            'billing_status' => BillingStatus::CheckoutPending,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plan selected successfully. Please proceed to checkout.',
            'data' => [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->company_name,
                ],
                'selected_plan' => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                ],
                'billing_status' => $company->billing_status->value,
            ],
        ]);
    }
}
