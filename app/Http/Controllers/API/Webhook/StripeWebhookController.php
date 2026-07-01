<?php

namespace App\Http\Controllers\API\Webhook;

use App\Enums\BillingStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\StripeWebhookEvent;
use Illuminate\Http\Request;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('cashier.webhook.secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            return response()->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        // تخزين الـ Webhook
        $webhookEvent = StripeWebhookEvent::create([
            'stripe_event_id' => $event->id,
            'event_type' => $event->type,
            'payload' => $event->toArray(),
            'processing_status' => 'processing',
        ]);

        // معالجة الحدث
        $this->handleEvent($event, $webhookEvent);

        return response()->json(['status' => 'success']);
    }

    private function handleEvent($event, $webhookEvent)
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event, $webhookEvent);
                break;
            case 'invoice.payment_succeeded':
                $this->handlePaymentSucceeded($event, $webhookEvent);
                break;
            case 'invoice.payment_failed':
                $this->handlePaymentFailed($event, $webhookEvent);
                break;
            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event, $webhookEvent);
                break;
            default:
                $webhookEvent->update(['processing_status' => 'ignored']);
        }
    }

    private function handleCheckoutCompleted($event, $webhookEvent)
    {
        $session = $event->data->object;
        $companyId = $session->client_reference_id;
        $company = Company::find($companyId);

        if ($company) {
            $company->update([
                'billing_status' => BillingStatus::Active,
                'billing_locked_at' => null,
                'billing_grace_ends_at' => null,
            ]);
        }

        $webhookEvent->update(['processing_status' => 'processed', 'company_id' => $companyId]);
    }

    private function handlePaymentSucceeded($event, $webhookEvent)
    {
        $invoice = $event->data->object;
        $customerId = $invoice->customer;
        $company = Company::where('stripe_id', $customerId)->first();

        if ($company) {
            $company->update([
                'billing_status' => BillingStatus::Active,
                'billing_locked_at' => null,
                'billing_grace_ends_at' => null,
            ]);
        }

        $webhookEvent->update(['processing_status' => 'processed']);
    }

    private function handlePaymentFailed($event, $webhookEvent)
    {
        $invoice = $event->data->object;
        $customerId = $invoice->customer;
        $company = Company::where('stripe_id', $customerId)->first();

        if ($company) {
            $company->startGracePeriod();
        }

        $webhookEvent->update(['processing_status' => 'processed']);
    }

    private function handleSubscriptionDeleted($event, $webhookEvent)
    {
        $subscription = $event->data->object;
        $customerId = $subscription->customer;
        $company = Company::where('stripe_id', $customerId)->first();

        if ($company) {
            $company->cancelSubscription();
        }

        $webhookEvent->update(['processing_status' => 'processed']);
    }
}
