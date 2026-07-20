<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AntiCheatLog extends Model
{
    use HasFactory;

    public const TYPE_MULTIPLE_FACES = 'multiple_faces';
    public const TYPE_LOOKING_AWAY = 'looking_away';
    public const TYPE_TAB_SWITCH = 'tab_switch';
    public const TYPE_WINDOW_BLUR = 'window_blur';
    public const TYPE_FULLSCREEN_EXIT = 'fullscreen_exit';
    public const TYPE_SUSPICIOUS_MOVEMENT = 'suspicious_movement';
    public const TYPE_AUDIO_ANOMALY = 'audio_anomaly';
    public const TYPE_DEVICE_CHANGE = 'device_change';
    public const TYPE_BROWSER_CONSOLE = 'browser_console';
    public const TYPE_COPY_PASTE_ATTEMPT = 'copy_paste_attempt';
    public const TYPE_SCREEN_CAPTURE = 'screen_capture';
    public const TYPE_PROMPT_INJECTION_ATTEMPT = 'prompt_injection_attempt';

    public const SOURCE_BROWSER_SECURITY = 'browser_security';
    public const SOURCE_MEDIAPIPE = 'mediapipe';
    public const SOURCE_AUDIO_ANALYSIS = 'audio_analysis';
    public const SOURCE_SERVER = 'server';

    protected $fillable = [
        'interview_id',
        'question_id',
        'answer_id',
        'event_key',
        'violation_type',
        'violation_timestamp',
        'duration_seconds',
        'confidence_score',
        'metadata',
        'severity_weight',
        'source',
    ];

    protected $casts = [
        'metadata' => 'array',
        'violation_timestamp' => 'datetime',
        'duration_seconds' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'severity_weight' => 'decimal:2',
    ];

    public static function allowedTypes(): array
    {
        return [
            self::TYPE_MULTIPLE_FACES,
            self::TYPE_LOOKING_AWAY,
            self::TYPE_TAB_SWITCH,
            self::TYPE_WINDOW_BLUR,
            self::TYPE_FULLSCREEN_EXIT,
            self::TYPE_SUSPICIOUS_MOVEMENT,
            self::TYPE_AUDIO_ANOMALY,
            self::TYPE_DEVICE_CHANGE,
            self::TYPE_BROWSER_CONSOLE,
            self::TYPE_COPY_PASTE_ATTEMPT,
            self::TYPE_SCREEN_CAPTURE,
            self::TYPE_PROMPT_INJECTION_ATTEMPT,
        ];
    }

    public static function allowedSources(): array
    {
        return [
            self::SOURCE_BROWSER_SECURITY,
            self::SOURCE_MEDIAPIPE,
            self::SOURCE_AUDIO_ANALYSIS,
            self::SOURCE_SERVER,
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }
}
