<?php

namespace App\Enums;

enum BillingStatus: string
{
    case None = 'none';
    case CheckoutPending = 'checkout_pending';
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Restricted = 'restricted';
    case Cancelled = 'cancelled';

    public function allowsPaidAccess(): bool
    {
        return match ($this) {
            self::Active, self::Trialing => true,
            default => false,
        };
    }
}
