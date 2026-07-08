<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Company\Billing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BillingStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        $plan = $company->selectedPlan;

        // جلب معلومات الاشتراك من Stripe إذا كان موجوداً
        $subscription = null;
        if ($company->stripe_id) {
            try {
                $stripeSubscription = $company->subscription('default');
                if ($stripeSubscription) {
                    $subscription = [
                        'status' => $stripeSubscription->stripe_status,
                        'current_period_start' => $stripeSubscription->current_period_start?->toISOString(),
                        'current_period_end' => $stripeSubscription->current_period_end?->toISOString(),
                        'canceled_at' => $stripeSubscription->canceled_at?->toISOString(),
                        'ends_at' => $stripeSubscription->ends_at?->toISOString(),
                    ];
                }
            } catch (\Exception $e) {
                // تجاهل أخطاء Stripe
            }
        }

        // ✅ جلب Limits من الخطة
        $limits = [];
        if ($plan) {
            $limits = [
                'interviews' => $plan->interviews_limit ?? 0,
                'jobs' => $plan->jobs_limit ?? 0,
                'candidates' => $plan->candidates_limit ?? 0,
                'employees' => $plan->max_employees ?? 1,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->company_name,
                    'email' => $company->email,
                    'status' => $company->status,
                    'is_approved' => $company->isApproved(),
                ],
                'billing' => [
                    'status' => $company->billing_status?->value ?? 'none',
                    'has_paid_access' => $company->hasPaidAccess(),
                    'grace_ends_at' => $company->billing_grace_ends_at?->toISOString(),
                    'locked_at' => $company->billing_locked_at?->toISOString(),
                    'trial_ends_at' => $company->trial_ends_at?->toISOString(),
                    'payment_method' => [
                        'type' => $company->pm_type,
                        'last_four' => $company->pm_last_four,
                    ],
                ],
                'selected_plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'pricing' => [
                        'monthly' => $plan->monthly_price ? (float) $plan->monthly_price : 0.00,
                        'yearly' => $plan->yearly_price ? (float) $plan->yearly_price : 0.00,
                        'currency' => $plan->currency ?? 'usd',
                    ],
                    'features' => $plan->features ?? [],
                    'limits' => $limits,
                ] : null,
                'stripe_subscription' => $subscription,
            ],
        ]);
    }
}
