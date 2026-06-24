<?php

namespace App\Listeners\Billing;

use App\Enums\BillingStatus;
use App\Enums\StripeWebhookProcessingStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\StripeWebhookEvent;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

class HandleStripeWebhookReceived
{
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;

        $stripeEventId = (string) data_get($payload, 'id');
        $eventType = (string) data_get($payload, 'type');

        if ($stripeEventId === '') {
            return;
        }

        $webhookEvent = StripeWebhookEvent::query()->firstOrCreate(
            ['stripe_event_id' => $stripeEventId],
            [
                'event_type' => $eventType,
                'payload' => $payload,
                'processing_status' => StripeWebhookProcessingStatus::Pending,
            ]
        );

        if ($webhookEvent->processing_status === StripeWebhookProcessingStatus::Processed) {
            return;
        }

        try {
            DB::transaction(function () use ($payload, $eventType, $webhookEvent): void {
                $webhookEvent->forceFill([
                    'processing_status' => StripeWebhookProcessingStatus::Processing,
                    'payload' => $payload,
                    'error_message' => null,
                ])->save();

                match ($eventType) {
                    'checkout.session.completed' => $this->handleCheckoutCompleted($payload),
                    'customer.subscription.created',
                    'customer.subscription.updated',
                    'customer.subscription.deleted' => $this->handleSubscriptionChanged($payload),
                    'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($payload),
                    'invoice.payment_failed' => $this->handleInvoicePaymentFailed($payload),
                    default => null,
                };

                $webhookEvent->markAsProcessed();
            });
        } catch (Throwable $exception) {
            $webhookEvent->markAsFailed($exception->getMessage());

            report($exception);
        }
    }

    private function handleCheckoutCompleted(array $payload): void
    {
        $session = data_get($payload, 'data.object', []);

        $companyId = data_get($session, 'metadata.company_id');

        if (! $companyId) {
            return;
        }

        $company = Company::query()->find($companyId);

        if (! $company) {
            return;
        }

        $company->forceFill([
            'billing_status' => BillingStatus::CheckoutPending,
        ])->save();
    }

    private function handleSubscriptionChanged(array $payload): void
    {
        $subscription = data_get($payload, 'data.object', []);

        $customerId = data_get($subscription, 'customer');

        if (! $customerId) {
            return;
        }

        $company = Company::query()
            ->where('stripe_id', $customerId)
            ->first();

        if (! $company) {
            return;
        }

        $stripeStatus = (string) data_get($subscription, 'status');
        $stripePriceId = data_get($subscription, 'items.data.0.price.id');

        $plan = $stripePriceId
            ? Plan::query()
                ->where('stripe_price_monthly_id', $stripePriceId)
                ->orWhere('stripe_price_yearly_id', $stripePriceId)
                ->first()
            : null;

        $company->forceFill([
            'selected_plan_id' => $plan?->id ?? $company->selected_plan_id,
            'billing_status' => $this->mapStripeSubscriptionStatus($stripeStatus),
            'billing_locked_at' => $stripeStatus === 'canceled' ? now() : null,
            'billing_grace_ends_at' => in_array($stripeStatus, ['past_due', 'unpaid'], true)
                ? now()->addDays((int) config('interview_ai.billing_grace_days', 3))
                : null,
        ])->save();

        $company->updateDefaultPaymentMethodFromStripe();
    }

    private function handleInvoicePaymentSucceeded(array $payload): void
    {
        $invoice = data_get($payload, 'data.object', []);
        $customerId = data_get($invoice, 'customer');

        if (! $customerId) {
            return;
        }

        $company = Company::query()
            ->where('stripe_id', $customerId)
            ->first();

        if (! $company) {
            return;
        }

        $company->forceFill([
            'billing_status' => BillingStatus::Active,
            'billing_grace_ends_at' => null,
            'billing_locked_at' => null,
        ])->save();

        $company->updateDefaultPaymentMethodFromStripe();
    }

    private function handleInvoicePaymentFailed(array $payload): void
    {
        $invoice = data_get($payload, 'data.object', []);
        $customerId = data_get($invoice, 'customer');

        if (! $customerId) {
            return;
        }

        $company = Company::query()
            ->where('stripe_id', $customerId)
            ->first();

        if (! $company) {
            return;
        }

        $company->forceFill([
            'billing_status' => BillingStatus::PastDue,
            'billing_grace_ends_at' => now()->addDays((int) config('interview_ai.billing_grace_days', 3)),
        ])->save();
    }

    private function mapStripeSubscriptionStatus(string $stripeStatus): BillingStatus
    {
        return match ($stripeStatus) {
            'active' => BillingStatus::Active,
            'trialing' => BillingStatus::Trialing,
            'past_due', 'unpaid', 'incomplete' => BillingStatus::PastDue,
            'canceled' => BillingStatus::Cancelled,
            'incomplete_expired' => BillingStatus::Restricted,
            default => BillingStatus::CheckoutPending,
        };
    }
}
