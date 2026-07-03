<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'file_path',
        'size',
        'status',
        'type',
        'notes',
        'metadata',
        'completed_at',
        'restored_at',
        'created_by',
        'restored_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'completed_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_RESTORED = 'restored';

    const TYPE_MANUAL = 'manual';
    const TYPE_SCHEDULED = 'scheduled';

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'restored_by');
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $error = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'notes' => $error,
        ]);
    }

    public function markAsRestored(int $adminId): void
    {
        $this->update([
            'status' => self::STATUS_RESTORED,
            'restored_at' => now(),
            'restored_by' => $adminId,
        ]);
    }

    public function getFileSizeAttribute(): string
    {
        if ($this->size < 1024) {
            return $this->size . ' B';
        } elseif ($this->size < 1024 * 1024) {
            return round($this->size / 1024, 2) . ' KB';
        } elseif ($this->size < 1024 * 1024 * 1024) {
            return round($this->size / (1024 * 1024), 2) . ' MB';
        } else {
            return round($this->size / (1024 * 1024 * 1024), 2) . ' GB';
        }
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('admin.backups.download', $this->id);
    }
}
