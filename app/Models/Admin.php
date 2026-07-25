<?php

namespace App\Models;

use App\Support\AdminPermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $table = 'admins';
    protected $guard_name = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'legacy_permissions',
        'permission_template_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'legacy_permissions' => 'array',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    public function permissionTemplate(): BelongsTo
    {
        return $this->belongsTo(PermissionTemplate::class, 'permission_template_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function getAdminPermissionsDirectly(): Collection
    {
        $permissionIds = DB::table('model_has_permissions')
            ->where('model_id', $this->id)
            ->where('model_type', self::class)
            ->pluck('permission_id');

        return Permission::whereIn('id', $permissionIds)
            ->where('guard_name', 'admin')
            ->get();
    }

    public function getAdminPermissionNames(): array
    {
        return $this->getAdminPermissionsDirectly()->pluck('name')->values()->all();
    }

    /**
     * The direct permission assignment is the source of truth for normal admins.
     * This deliberately avoids role-level permission inheritance so a restricted
     * admin cannot accidentally receive every permission from the generic role.
     */
    public function getPermissionsByRole(): array
    {
        if ($this->isSuperAdmin()) {
            return AdminPermissionCatalog::all();
        }

        $direct = $this->getAdminPermissionNames();
        if ($direct !== []) {
            return $direct;
        }

        // Preserve access for old database rows that stored the obsolete
        // manage_users/manage_companies keys in admins.permissions.
        return AdminPermissionCatalog::normalizeLegacy($this->legacy_permissions ?? []);
    }

    public function getRoleName(): string
    {
        return $this->role ?? 'admin';
    }

    public function getAllAdminPermissions(): array
    {
        return AdminPermissionCatalog::all();
    }
}
