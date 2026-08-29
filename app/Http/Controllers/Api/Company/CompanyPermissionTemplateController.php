<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanyPermissionTemplateRequest;
use App\Http\Resources\Company\CompanyPermissionTemplateResource;
use App\Models\Company;
use App\Models\CompanyPermissionTemplate;
use App\Services\CompanyActivityLogService;
use App\Services\CompanyEmployeeAccessService;
use App\Support\CompanyEmployeePermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Throwable;

final class CompanyPermissionTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        $templates = $company->permissionTemplates()
            ->with('creator')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->search(trim((string) $request->input('search')));
            })
            ->when($request->input('active') === 'true', fn ($query) => $query->active())
            ->when($request->input('active') === 'false', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'success' => true,
            'data' => CompanyPermissionTemplateResource::collection($templates),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'total' => $templates->total(),
                'per_page' => $templates->perPage(),
            ],
        ]);
    }

    public function store(
        CompanyPermissionTemplateRequest $request,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();
        $permissions = CompanyEmployeePermissionCatalog::sanitize(
            (array) $request->input('permissions', [])
        );

        try {
            $template = CompanyPermissionTemplate::create([
                'company_id' => $company->id,
                'name' => trim((string) $request->input('name')),
                'description' => $request->input('description'),
                'permissions' => $permissions,
                'is_active' => $request->boolean('is_active', true),
                'created_by' => $company->id,
            ]);

            $activityLog->success(
                $company,
                'company_permission_templates',
                'create',
                "Created permission template '{$template->name}'",
                ['template_id' => $template->id, 'permissions' => $permissions]
            );

            return response()->json([
                'success' => true,
                'message' => 'Permission template created successfully',
                'data' => new CompanyPermissionTemplateResource($template->load('creator')),
            ], 201);
        } catch (Throwable $exception) {
            Log::error('Failed to create company permission template.', [
                'company_id' => $company->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->serverError('Failed to create permission template', $exception);
        }
    }

    public function show(Request $request, CompanyPermissionTemplate $template): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($template, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found in your company',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new CompanyPermissionTemplateResource($template->load('creator')),
        ]);
    }

    public function update(
        CompanyPermissionTemplateRequest $request,
        CompanyPermissionTemplate $template,
        CompanyEmployeeAccessService $accessService,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($template, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found in your company',
            ], 404);
        }

        $permissions = CompanyEmployeePermissionCatalog::sanitize(
            (array) $request->input('permissions', [])
        );

        try {
            $oldPermissions = (array) $template->permissions;

            $updatedEmployees = DB::transaction(function () use (
                $request,
                $template,
                $permissions,
                $accessService
            ): int {
                $template->update([
                    'name' => trim((string) $request->input('name')),
                    'description' => $request->input('description'),
                    'permissions' => $permissions,
                    'is_active' => $request->has('is_active')
                        ? $request->boolean('is_active')
                        : $template->is_active,
                ]);

                $employees = $template->employees()
                    ->where('is_company_employee', true)
                    ->whereNotNull('company_id')
                    ->get();

                foreach ($employees as $employee) {
                    $accessService->syncPermissions($employee, $permissions);
                }

                return $employees->count();
            });

            $activityLog->success(
                $company,
                'company_permission_templates',
                'update',
                "Updated permission template '{$template->name}'",
                [
                    'template_id' => $template->id,
                    'old_permissions' => $oldPermissions,
                    'new_permissions' => $permissions,
                    'updated_employees' => $updatedEmployees,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Permission template updated successfully',
                'data' => new CompanyPermissionTemplateResource(
                    $template->fresh()->load('creator')
                ),
                'meta' => ['updated_employees' => $updatedEmployees],
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to update company permission template.', [
                'company_id' => $company->id,
                'template_id' => $template->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->serverError('Failed to update permission template', $exception);
        }
    }

    public function destroy(
        Request $request,
        CompanyPermissionTemplate $template,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($template, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found in your company',
            ], 404);
        }

        try {
            $templateName = $template->name;
            $linkedEmployees = DB::transaction(function () use ($template): int {
                $count = $template->employees()->count();
                $template->employees()->update(['company_permission_template_id' => null]);
                $template->delete();

                return $count;
            });

            $activityLog->success(
                $company,
                'company_permission_templates',
                'delete',
                "Deleted permission template '{$templateName}'",
                ['template_id' => $template->id, 'unlinked_employees' => $linkedEmployees]
            );

            return response()->json([
                'success' => true,
                'message' => "Permission template '{$templateName}' deleted successfully",
                'meta' => ['unlinked_employees' => $linkedEmployees],
            ]);
        } catch (Throwable $exception) {
            return $this->serverError('Failed to delete permission template', $exception);
        }
    }

    public function toggle(
        Request $request,
        CompanyPermissionTemplate $template,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($template, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found in your company',
            ], 404);
        }

        try {
            $template->update(['is_active' => ! $template->is_active]);
            $status = $template->is_active ? 'activated' : 'deactivated';

            $activityLog->success(
                $company,
                'company_permission_templates',
                'toggle_status',
                "Permission template '{$template->name}' was {$status}",
                ['template_id' => $template->id, 'is_active' => $template->is_active]
            );

            return response()->json([
                'success' => true,
                'message' => "Permission template '{$template->name}' has been {$status}",
                'data' => [
                    'id' => $template->id,
                    'is_active' => $template->is_active,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->serverError('Failed to toggle permission template', $exception);
        }
    }

    public function availablePermissions(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('guard_name', CompanyEmployeeAccessService::GUARD)
            ->whereIn('name', CompanyEmployeePermissionCatalog::ASSIGNABLE)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission): string => explode('.', $permission->name)[1] ?? 'other')
            ->map(fn ($group) => $group->pluck('name')->values());

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    public function permissionsByModule(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('guard_name', CompanyEmployeeAccessService::GUARD)
            ->whereIn('name', CompanyEmployeePermissionCatalog::ASSIGNABLE)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission): string => explode('.', $permission->name)[1] ?? 'other')
            ->map(function ($group, string $module): array {
                return [
                    'module' => $module,
                    'permissions' => $group->map(fn (Permission $permission): array => [
                        'name' => $permission->name,
                        'description' => $this->getPermissionDescription($permission->name),
                    ])->values(),
                ];
            })
            ->values();

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    private function belongsToCompany(
        CompanyPermissionTemplate $template,
        Company $company
    ): bool {
        return (int) $template->company_id === (int) $company->id;
    }

    private function serverError(string $prefix, Throwable $exception): JsonResponse
    {
        Log::error($prefix, [
            'error' => $exception->getMessage(),
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => config('app.debug')
                ? "{$prefix}: {$exception->getMessage()}"
                : "{$prefix}. Check storage/logs/laravel.log for details.",
            'exception' => config('app.debug') ? $exception::class : null,
            'file' => config('app.debug') ? $exception->getFile() : null,
            'line' => config('app.debug') ? $exception->getLine() : null,
        ], 500);
    }

    private function getPermissionDescription(string $permission): string
    {
        return [
            'company.dashboard.view' => 'عرض لوحة التحكم',
            'company.jobs.view' => 'عرض الوظائف',
            'company.jobs.create' => 'إنشاء وظيفة',
            'company.jobs.update' => 'تحديث وظيفة',
            'company.jobs.delete' => 'حذف وظيفة',
            'company.jobs.close' => 'إغلاق وظيفة',
            'company.candidates.view' => 'عرض المرشحين',
            'company.candidates.invite' => 'دعوة مرشحين',
            'company.candidates.update' => 'تحديث حالة مرشح',
            'company.interviews.view' => 'عرض المقابلات',
            'company.results.view' => 'عرض النتائج',
            'company.results.export' => 'تصدير النتائج',
            'company.question_bank.view' => 'عرض بنك الأسئلة',
            'company.question_bank.create' => 'إضافة أسئلة',
            'company.question_bank.update' => 'تعديل أسئلة',
            'company.question_bank.delete' => 'حذف أسئلة',
            'company.usage.view' => 'عرض الاستخدام',
            'company.notifications.view' => 'عرض الإشعارات',
        ][$permission] ?? $permission;
    }
}
