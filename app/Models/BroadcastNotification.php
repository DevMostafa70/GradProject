<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastNotification extends Model
{
    use HasFactory;

    protected $table = 'broadcast_notifications';

    protected $fillable = [
        'admin_id',
        'title',
        'message',
        'target_type',
        'sent_via_email',
        'sent_at',
        'sent_count',
    ];

    protected $casts = [
        'sent_via_email' => 'boolean',
        'sent_at' => 'datetime',
        'sent_count' => 'integer',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
