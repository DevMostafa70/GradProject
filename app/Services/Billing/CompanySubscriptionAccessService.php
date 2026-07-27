<?php

namespace App\Services\Billing;

use App\Enums\BillingStatus;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CompanySubscriptionAccessService
{
    /**
     * Resolve the company that owns the authenticated company account.
     */
    public function resolveCompany(mixed $actor): ?Company
    {
        if ($actor instanceof Company) {
            return $actor;
        }

        if ($actor instanceof User && $actor->isCompanyEmployee()) {
            return $actor->company;
        }

        return null;
    }

    /**
     * Return one normalized subscription-access snapshot for owners and employees.
     *
     * The local company billing status remains the primary fast signal. The latest
     * Cashier subscription row is used as an additional expiry safeguard so an
     * ended subscription cannot continue to unlock protected company operations.
     */
    public function snapshot(Company $company, bool $canManageBilling = false): array
    {
        $company->loadMissing('selectedPlan');

        $companyStatus = $this->enumValue($company->status);
        $billingStatus = $this->enumValue($company->billing_status) ?: BillingStatus::None->value;
        $hasSelectedPlan = $company->selected_plan_id !== null && $company->selectedPlan !== null;
        $companyApproved = $company->isApproved();
        $graceEndsAt = $company->billing_grace_ends_at;
        $inPaymentGracePeriod = $billingStatus === BillingStatus::PastDue->value
            && $graceEndsAt !== null
            && now()->lt($graceEndsAt);

        $subscription = null;

        if (
            Schema::hasTable('subscriptions')
            && Schema::hasColumn('subscriptions', 'company_id')
        ) {
            $subscription = DB::table('subscriptions')
                ->where('company_id', $company->getKey())
                ->where('type', 'default')
                ->latest('id')
                ->first();
        }

        $stripeStatus = $subscription?->stripe_status;
        $subscriptionEndsAt = $this->parseDate($subscription?->ends_at);
        $subscriptionTrialEndsAt = $this->parseDate($subscription?->trial_ends_at);
        $subscriptionHasEnded = $subscriptionEndsAt !== null && $subscriptionEndsAt->isPast();
        $subscriptionStillOnGrace = $subscriptionEndsAt !== null && $subscriptionEndsAt->isFuture();

        $activeCompanyStatus = in_array($billingStatus, [
            BillingStatus::Active->value,
            BillingStatus::Trialing->value,
        ], true);

        $activeStripeStatus = $stripeStatus !== null && in_array($stripeStatus, [
            'active',
            'trialing',
        ], true);

        // A cancelled subscription may remain usable until its recorded ends_at.
        $cancelledButNotEnded = $stripeStatus === 'canceled' && $subscriptionStillOnGrace;
        $stripePaymentProblem = in_array($stripeStatus, [
            'past_due',
            'unpaid',
            'incomplete',
            'incomplete_expired',
            'paused',
        ], true);
        $stripeCancelledWithoutGrace = $stripeStatus === 'canceled' && ! $cancelledButNotEnded;
        $localHardLocked = $billingStatus === BillingStatus::Restricted->value
            || ($billingStatus === BillingStatus::Cancelled->value && ! $cancelledButNotEnded);

        $hasPaidAccess = $hasSelectedPlan
            && ! $subscriptionHasEnded
            && ! $localHardLocked
            && ! $stripeCancelledWithoutGrace
            && (! $stripePaymentProblem || $inPaymentGracePeriod)
            && (
                $activeCompanyStatus
                || $activeStripeStatus
                || $inPaymentGracePeriod
                || $cancelledButNotEnded
            );

        $hasFullAccess = $companyApproved && $hasPaidAccess;
        $reason = $this->reason(
            companyApproved: $companyApproved,
            hasSelectedPlan: $hasSelectedPlan,
            hasPaidAccess: $hasPaidAccess,
            billingStatus: $billingStatus,
            stripeStatus: $stripeStatus,
            subscriptionHasEnded: $subscriptionHasEnded,
            inPaymentGracePeriod: $inPaymentGracePeriod,
        );

        return [
            'company_id' => $company->getKey(),
            'company_approved' => $companyApproved,
            'company_status' => $companyStatus,
            'has_selected_plan' => $hasSelectedPlan,
            'has_paid_access' => $hasPaidAccess,
            'has_full_access' => $hasFullAccess,
            'is_locked' => ! $hasFullAccess,
            'reason' => $reason,
            'billing_status' => $billingStatus,
            'stripe_status' => $stripeStatus,
            'in_payment_grace_period' => $inPaymentGracePeriod,
            'can_manage_billing' => $canManageBilling,
            'plans_url' => '/company/dashboard/plans',
            'public_plans_url' => '/plans',
            'grace_ends_at' => $graceEndsAt?->toISOString(),
            'subscription_ends_at' => $subscriptionEndsAt?->toISOString(),
            'subscription_trial_ends_at' => $subscriptionTrialEndsAt?->toISOString(),
            'selected_plan' => $company->selectedPlan ? [
                'id' => $company->selectedPlan->id,
                'name' => $company->selectedPlan->name,
                'slug' => $company->selectedPlan->slug,
            ] : null,
        ];
    }

    private function reason(
        bool $companyApproved,
        bool $hasSelectedPlan,
        bool $hasPaidAccess,
        string $billingStatus,
        ?string $stripeStatus,
        bool $subscriptionHasEnded,
        bool $inPaymentGracePeriod,
    ): ?string {
        if (! $companyApproved) {
            return 'company_not_approved';
        }

        if (! $hasSelectedPlan) {
            return 'no_plan';
        }

        if ($hasPaidAccess) {
            return $inPaymentGracePeriod ? 'payment_grace_period' : null;
        }

        if ($subscriptionHasEnded || in_array($billingStatus, [
            BillingStatus::Cancelled->value,
            BillingStatus::Restricted->value,
        ], true) || $stripeStatus === 'canceled') {
            return 'subscription_expired';
        }

        if ($billingStatus === BillingStatus::CheckoutPending->value) {
            return 'checkout_pending';
        }

        if ($billingStatus === BillingStatus::PastDue->value || in_array($stripeStatus, [
            'past_due',
            'unpaid',
            'incomplete',
            'incomplete_expired',
        ], true)) {
            return 'payment_required';
        }

        return 'subscription_inactive';
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) ($value ?? '');
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
