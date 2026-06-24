<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StripeWebhookProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StripeWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'company_id',
        'payload',
        'processing_status',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processing_status' => StripeWebhookProcessingStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function markAsProcessed(): void
    {
        $this->forceFill([
            'processing_status' => StripeWebhookProcessingStatus::Processed,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markAsFailed(string $message): void
    {
        $this->forceFill([
            'processing_status' => StripeWebhookProcessingStatus::Failed,
            'error_message' => $message,
        ])->save();
    }
}
