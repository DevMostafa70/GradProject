<?php

namespace App\Support;

final class CompanyEmployeePermissionCatalog
{
    /** @var array<int, string> */
    public const ASSIGNABLE = [
        'company.dashboard.view',
        'company.jobs.view',
        'company.jobs.create',
        'company.jobs.update',
        'company.jobs.delete',
        'company.jobs.close',
        'company.candidates.view',
        'company.candidates.invite',
        'company.candidates.update',
        'company.interviews.view',
        'company.results.view',
        'company.results.export',
        'company.question_bank.view',
        'company.question_bank.create',
        'company.question_bank.update',
        'company.question_bank.delete',
        'company.usage.view',
        'company.notifications.view',
    ];

    /** @var array<string, array<int, string>> */
    public const DEPENDENCIES = [
        'company.jobs.update' => ['company.jobs.view'],
        'company.jobs.delete' => ['company.jobs.view'],
        'company.jobs.close' => ['company.jobs.view'],
        'company.candidates.update' => ['company.candidates.view'],
        'company.results.export' => ['company.results.view'],
        'company.question_bank.create' => ['company.question_bank.view'],
        'company.question_bank.update' => ['company.question_bank.view'],
        'company.question_bank.delete' => ['company.question_bank.view'],
    ];

    /** @var array<int, string> */
    public const OWNER_ONLY = [
        'company.profile.view',
        'company.profile.update',
        'company.billing.view',
        'company.billing.select_plan',
        'company.billing.checkout',
        'company.billing.portal',
        'company.employees.view',
        'company.employees.create',
        'company.employees.update',
        'company.employees.delete',
    ];

    /** @var array<int, string> */
    public const ROLES = [
        'company_hr',
        'company_interviewer',
        'company_recruiter',
        'company_question_bank_manager',
        'company_viewer',
        'company_employee',
    ];

    /** @return array<string, array<int, string>> */
    public static function rolePermissions(): array
    {
        return [
            'company_hr' => [
                'company.dashboard.view',
                'company.candidates.view',
                'company.candidates.invite',
                'company.candidates.update',
                'company.interviews.view',
                'company.results.view',
                'company.notifications.view',
            ],
            'company_interviewer' => [
                'company.dashboard.view',
                'company.jobs.view',
                'company.candidates.view',
                'company.interviews.view',
                'company.results.view',
                'company.notifications.view',
            ],
            'company_recruiter' => [
                'company.dashboard.view',
                'company.jobs.view',
                'company.candidates.view',
                'company.candidates.invite',
                'company.candidates.update',
                'company.interviews.view',
                'company.results.view',
                'company.notifications.view',
            ],
            'company_question_bank_manager' => [
                'company.dashboard.view',
                'company.jobs.view',
                'company.question_bank.view',
                'company.question_bank.create',
                'company.question_bank.update',
                'company.question_bank.delete',
                'company.notifications.view',
            ],
            'company_viewer' => [
                'company.dashboard.view',
                'company.jobs.view',
                'company.candidates.view',
                'company.interviews.view',
                'company.results.view',
                'company.question_bank.view',
                'company.notifications.view',
            ],
            'company_employee' => [],
        ];
    }

    /** @return array<int, string> */
    public static function sanitize(array $permissions): array
    {
        $normalized = array_values(array_intersect(
            array_values(array_unique(array_filter(
                $permissions,
                static fn (mixed $permission): bool => is_string($permission) && $permission !== ''
            ))),
            self::ASSIGNABLE
        ));

        do {
            $before = count($normalized);
            foreach ($normalized as $permission) {
                foreach (self::DEPENDENCIES[$permission] ?? [] as $dependency) {
                    if (! in_array($dependency, $normalized, true)) {
                        $normalized[] = $dependency;
                    }
                }
            }
        } while (count($normalized) !== $before);

        return array_values(array_intersect(self::ASSIGNABLE, $normalized));
    }
}
