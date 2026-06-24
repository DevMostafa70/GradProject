<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UsageEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'event_type',
        'quantity',
        'resource_type',
        'resource_id',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => UsageEventType::class,
            'quantity' => 'integer',
            'resource_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
