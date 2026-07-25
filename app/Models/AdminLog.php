<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class AdminLog extends Model
{
    use HasFactory;

    protected $table = 'admin_logs';

    protected $fillable = [
        'admin_id',
        'action',
        'target_type',
        'target_id',
        'details',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * Record an administrative action without allowing the audit trail to
     * break the business operation that has already succeeded.
     */
    public static function log(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $details = [],
        ?int $adminId = null,
    ): bool {
        try {
            $authenticated = request()?->user();

            $resolvedAdminId = $adminId;
            if ($resolvedAdminId === null && $authenticated instanceof Admin) {
                $resolvedAdminId = (int) $authenticated->getKey();
            }

            // Do not use auth()->id() here. The default project guard is
            // "web", while admin API requests are authenticated by Sanctum.
            if (! $resolvedAdminId || ! Admin::query()->whereKey($resolvedAdminId)->exists()) {
                Log::warning('Admin activity log skipped: authenticated admin could not be resolved.', [
                    'action' => $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'authenticated_model' => is_object($authenticated) ? $authenticated::class : null,
                    'authenticated_id' => method_exists($authenticated, 'getAuthIdentifier')
                        ? $authenticated->getAuthIdentifier()
                        : null,
                ]);

                return false;
            }

            self::query()->create([
                'admin_id' => $resolvedAdminId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => $details ?? [],
                'ip_address' => mb_substr((string) request()?->ip(), 0, 191),
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 191),
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Admin activity log failed.', [
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
