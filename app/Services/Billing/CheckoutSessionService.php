<?php

namespace App\Services\Billing;

use App\Enums\BillingStatus;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;
use Stripe\Checkout\Session;

class CheckoutSessionService
{
    public function createForCompany(Company $company, string $billingInterval = 'monthly'): Session
    {
        $company->loadMissing('selectedPlan');

        if (! $company->selectedPlan instanceof Plan) {
            throw ValidationException::withMessages([
                'plan' => 'You must select a plan before starting checkout.',
            ]);
        }

        $priceId = $this->resolveStripePriceId(
            plan: $company->selectedPlan,
            billingInterval: $billingInterval,
        );

        if (! $priceId) {
            throw ValidationException::withMessages([
                'plan' => 'Stripe price ID is not configured for the selected plan.',
            ]);
        }

        $company->createOrGetStripeCustomer();

        $company->forceFill([
            'billing_status' => BillingStatus::CheckoutPending,
        ])->save();

        return $company->stripe()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $company->stripe_id,
            'client_reference_id' => (string) $company->id,
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $this->frontendUrl('/company/billing/success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->frontendUrl('/company/billing/cancel'),
            'allow_promotion_codes' => true,
            'metadata' => [
                'company_id' => (string) $company->id,
                'plan_id' => (string) $company->selectedPlan->id,
                'plan_code' => $company->selectedPlan->code instanceof \BackedEnum
                    ? $company->selectedPlan->code->value
                    : (string) $company->selectedPlan->code,
                'billing_interval' => $billingInterval,
            ],
            'subscription_data' => [
                'metadata' => [
                    'company_id' => (string) $company->id,
                    'plan_id' => (string) $company->selectedPlan->id,
                    'billing_interval' => $billingInterval,
                ],
            ],
        ]);
    }

    private function resolveStripePriceId(Plan $plan, string $billingInterval): ?string
    {
        return match ($billingInterval) {
            'yearly' => $plan->stripe_price_yearly_id,
            default => $plan->stripe_price_monthly_id,
        };
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('interview_ai.frontend_url'), '/') . $path;
    }
}
