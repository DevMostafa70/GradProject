<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

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
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * الحصول على الصلاحيات المرتبطة بالأدمن مباشرة من قاعدة البيانات
     */
    public function getAdminPermissionsDirectly(): Collection
    {
        $permissionIds = DB::table('model_has_permissions')
            ->where('model_id', $this->id)
            ->where('model_type', 'App\\Models\\Admin')
            ->pluck('permission_id');

        return Permission::whereIn('id', $permissionIds)
            ->where('guard_name', 'admin')
            ->get();
    }

    /**
     * الحصول على أسماء الصلاحيات المرتبطة بالأدمن
     */
    public function getAdminPermissionNames(): array
    {
        return $this->getAdminPermissionsDirectly()->pluck('name')->toArray();
    }

    /**
     * ✅ الحصول على الصلاحيات حسب الدور أو الصلاحيات المخزنة في قاعدة البيانات
     *
     * @return array قائمة الصلاحيات
     */
    public function getPermissionsByRole(): array
    {
        // ✅ 1. إذا كان super_admin، أعطه كل الصلاحيات
        if ($this->isSuperAdmin()) {
            return $this->getAllAdminPermissions();
        }

        // ✅ 2. إذا كان admin عادي، تحقق من الصلاحيات المخزنة في قاعدة البيانات
        if ($this->role === 'admin') {
            // ✅ جلب الصلاحيات من جدول model_has_permissions
            $permissions = $this->getAdminPermissionsDirectly();

            if ($permissions && $permissions->isNotEmpty()) {
                return $permissions->pluck('name')->toArray();
            }

            // ✅ Fallback: الصلاحيات الافتراضية للأدمن العادي
            return $this->getAdminPermissions();
        }

        return [];
    }

    /**
     * ✅ الحصول على اسم الدور (مع Spatie)
     */
    public function getRoleName(): string
    {
        try {
            $roles = $this->getRoleNames();
            if ($roles && $roles->isNotEmpty()) {
                return $roles->first();
            }
        } catch (\Exception $e) {
            // Fallback: استخدام الطريقة القديمة
        }

        return $this->role ?? 'admin';
    }

    /**
     * ✅ جميع صلاحيات الأدمن (للـ super_admin) - Public للاستخدام
     */
    public function getAllAdminPermissions(): array
    {
        return [
            'admin.dashboard.view',
            'admin.users.view',
            'admin.users.create',
            'admin.users.update',
            'admin.users.delete',
            'admin.users.suspend',
            'admin.companies.view',
            'admin.companies.approve',
            'admin.companies.reject',
            'admin.companies.suspend',
            'admin.companies.update',
            'admin.jobs.view',
            'admin.jobs.manage',
            'admin.skills.view',
            'admin.skills.create',
            'admin.skills.update',
            'admin.skills.delete',
            'admin.categories.view',
            'admin.categories.create',
            'admin.categories.update',
            'admin.categories.delete',
            'admin.notifications.view',
            'admin.notifications.send',
            'admin.activity_logs.view',
            'admin.activity_logs.clean',
            'admin.backups.view',
            'admin.backups.create',
            'admin.backups.download',
            'admin.plans.view',
            'admin.plans.manage',
            'admin.billing.view',
            'admin.billing.manage',
            'admin.roles.view',
            'admin.roles.create',
            'admin.roles.update',
            'admin.roles.delete',
            'admin.permissions.view',
            'admin.permissions.create',
            'admin.permissions.update',
            'admin.permissions.delete',
            'admin.settings.manage',
        ];
    }

    /**
     * ✅ صلاحيات الأدمن العادي (الافتراضية)
     */
    private function getAdminPermissions(): array
    {
        return [
            'admin.dashboard.view',
            'admin.users.view',
            'admin.users.create',
            'admin.users.update',
            'admin.companies.view',
            'admin.companies.approve',
            'admin.companies.reject',
            'admin.jobs.view',
            'admin.skills.view',
            'admin.skills.create',
            'admin.skills.update',
            'admin.skills.delete',
            'admin.categories.view',
            'admin.categories.create',
            'admin.categories.update',
            'admin.categories.delete',
            'admin.notifications.view',
            'admin.notifications.send',
            'admin.activity_logs.view',
            'admin.backups.view',
            'admin.backups.create',
            'admin.backups.download',
            'admin.plans.view',
            'admin.billing.view',
        ];
    }
}
