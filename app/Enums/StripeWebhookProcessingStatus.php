<?php

declare(strict_types=1);

namespace App\Enums;

enum StripeWebhookProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
