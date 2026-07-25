<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermissionTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'permission_templates';

    protected $fillable = [
        'name',
        'description',
        'permissions',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    // ============================================================
    // ✅ Relationships
    // ============================================================

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class, 'permission_template_id');
    }

    // ============================================================
    // ✅ Scopes
    // ============================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($nested) use ($search) {
            $nested->where('name', 'LIKE', "%{$search}%")
                ->orWhere('description', 'LIKE', "%{$search}%");
        });
    }

    // ============================================================
    // ✅ Helper Methods
    // ============================================================

    public function getPermissionsCountAttribute(): int
    {
        return count($this->permissions ?? []);
    }

    public function getPermissionsListAttribute(): array
    {
        return $this->permissions ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    public function syncPermissions(array $permissions): self
    {
        $this->update(['permissions' => $permissions]);
        return $this;
    }

    public function activate(): self
    {
        $this->update(['is_active' => true]);
        return $this;
    }

    public function deactivate(): self
    {
        $this->update(['is_active' => false]);
        return $this;
    }

    /**
     * تنسيق البيانات للـ API
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'permissions_count' => $this->permissions_count,
            'is_active' => $this->is_active,
            'created_by' => $this->creator?->name,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
