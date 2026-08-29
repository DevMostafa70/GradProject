<?php

namespace App\Http\Controllers\Api\Company\Billing;

use App\Enums\BillingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\Billing\StartCheckoutRequest;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class CheckoutController extends Controller
{
    public function store(StartCheckoutRequest $request): JsonResponse
    {
        /** @var \App\Models\Company $company */
        $company = $request->user();

        // التحقق من وجود خطة مختارة
        if (!$company->selected_plan_id) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a plan first.',
            ], 422);
        }

        $plan = $company->selectedPlan;

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Selected plan not found.',
            ], 404);
        }

        // الحصول على Price ID من Stripe
        $billingInterval = $request->billingInterval(); // monthly أو yearly
        $priceId = $billingInterval === 'yearly'
            ? $plan->stripe_price_yearly_id
            : $plan->stripe_price_monthly_id;

        if (!$priceId) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe price ID not configured for this plan.',
            ], 422);
        }

        // إنشاء عميل Stripe إذا لم يكن موجوداً
        $company->createOrGetStripeCustomer();

        // تحديث حالة الفوترة
        $company->update([
            'billing_status' => BillingStatus::CheckoutPending,
        ]);

        // إنشاء جلسة Checkout
        Stripe::setApiKey(config('cashier.secret'));

        $checkoutSession = Session::create([
            'mode' => 'subscription',
            'customer' => $company->stripe_id,
            'client_reference_id' => (string) $company->id,
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => config('interview_ai.frontend_url') . '/company/dashboard/plans/checkout-success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('interview_ai.frontend_url') . '/company/dashboard/plans/checkout-failed',
            'allow_promotion_codes' => true,
            'metadata' => [
                'company_id' => (string) $company->id,
                'plan_id' => (string) $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => $billingInterval,
            ],
            'subscription_data' => [
                'metadata' => [
                    'company_id' => (string) $company->id,
                    'plan_id' => (string) $plan->id,
                ],
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Checkout session created successfully.',
            'data' => [
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
            ],
        ]);
    }
}
