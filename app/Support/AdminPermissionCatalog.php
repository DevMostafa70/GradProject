<?php

namespace App\Support;

final class AdminPermissionCatalog
{
    public const ALL = [
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
        'admin.companies.delete',
        'admin.jobs.view',
        'admin.jobs.manage',
        'admin.interviews.view',
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
        'admin.notifications.delete',
        'admin.activity_logs.view',
        'admin.activity_logs.clean',
        'admin.backups.view',
        'admin.backups.create',
        'admin.backups.download',
        'admin.backups.delete',
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

    private const LEGACY_MAP = [
        'manage_users' => [
            'admin.users.view',
            'admin.users.create',
            'admin.users.update',
            'admin.users.delete',
            'admin.users.suspend',
        ],
        'manage_companies' => [
            'admin.companies.view',
            'admin.companies.approve',
            'admin.companies.reject',
            'admin.companies.suspend',
            'admin.companies.update',
            'admin.companies.delete',
        ],
        'manage_admins' => [
            'admin.roles.view',
            'admin.roles.create',
            'admin.roles.update',
            'admin.permissions.view',
            'admin.permissions.update',
        ],
        'manage_settings' => ['admin.settings.manage'],
        'view_reports' => [
            'admin.dashboard.view',
            'admin.jobs.view',
            'admin.interviews.view',
        ],
    ];

    public static function all(): array
    {
        return self::ALL;
    }

    public static function normalizeLegacy(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $permission) {
            if (in_array($permission, self::ALL, true)) {
                $normalized[] = $permission;
                continue;
            }

            foreach (self::LEGACY_MAP[$permission] ?? [] as $mapped) {
                $normalized[] = $mapped;
            }
        }

        return array_values(array_unique($normalized));
    }
}
