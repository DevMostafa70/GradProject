<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AntiCheatLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'violation_type',
        'violation_timestamp',
        'duration_seconds',
        'confidence_score',
        'metadata',
        'severity_weight',
    ];

    protected $casts = [
        'metadata' => 'array',
        'violation_timestamp' => 'datetime',
        'duration_seconds' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'severity_weight' => 'decimal:2',
    ];

    const TYPE_MULTIPLE_FACES = 'multiple_faces';
    const TYPE_LOOKING_AWAY = 'looking_away';
    const TYPE_TAB_SWITCH = 'tab_switch';
    const TYPE_WINDOW_BLUR = 'window_blur';
    const TYPE_SUSPICIOUS_MOVEMENT = 'suspicious_movement';
    const TYPE_AUDIO_ANOMALY = 'audio_anomaly';
    const TYPE_DEVICE_CHANGE = 'device_change';
    const TYPE_BROWSER_CONSOLE = 'browser_console';
    const TYPE_COPY_PASTE_ATTEMPT = 'copy_paste_attempt';
    const TYPE_SCREEN_CAPTURE = 'screen_capture';

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }
}
