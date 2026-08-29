<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateEmployeeRequest;
use App\Http\Requests\Company\UpdateEmployeeRequest;
use App\Http\Resources\Company\EmployeeLimitResource;
use App\Http\Resources\Company\EmployeeResource;
use App\Models\Company;
use App\Models\CompanyPermissionTemplate;
use App\Models\User;
use App\Services\CompanyActivityLogService;
use App\Services\CompanyEmployeeAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class CompanyEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        $employees = $company->employees()
            ->with(['roles', 'permissions', 'companyPermissionTemplate'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'success' => true,
            'data' => EmployeeResource::collection($employees),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'total' => $employees->total(),
                'per_page' => $employees->perPage(),
                'limit_info' => $company->getEmployeeLimitInfo(),
            ],
        ]);
    }

    public function store(
        CreateEmployeeRequest $request,
        CompanyEmployeeAccessService $accessService,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();

        if (! Schema::hasColumn('users', 'company_permission_template_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Database schema is missing users.company_permission_template_id. Run the latest migrations, then retry.',
                'error_code' => 'COMPANY_EMPLOYEE_TEMPLATE_COLUMN_MISSING',
            ], 503);
        }

        if (! $company->canAddEmployee()) {
            $limitInfo = $company->getEmployeeLimitInfo();

            return response()->json([
                'success' => false,
                'message' => "Your plan ({$limitInfo['plan_name']}) allows maximum {$limitInfo['max_employees']} team members. Please upgrade to add more employees.",
                'data' => [
                    'limit_info' => $limitInfo,
                    'upgrade_suggestions' => $this->getUpgradeSuggestions($company),
                ],
            ], 403);
        }

        try {
            [$permissionNames, $template] = $this->resolveRequestedAccess($request, $company);

            $employee = DB::transaction(function () use (
                $request,
                $company,
                $permissionNames,
                $template,
                $accessService
            ): User {
                $employee = User::create([
                    'name' => trim((string) $request->input('name')),
                    'email' => strtolower(trim((string) $request->input('email'))),
                    'password' => Hash::make((string) $request->input('password')),
                    'role' => 'user',
                    'company_id' => $company->id,
                    'company_permission_template_id' => $template?->id,
                    'is_company_employee' => true,
                    'is_active' => true,
                ]);

                $accessService->syncRole(
                    $employee,
                    (string) $request->input('role', 'company_employee')
                );
                $accessService->syncPermissions($employee, $permissionNames);

                $company->syncEmployeeCount();

                return $employee;
            });

            $activityLog->success(
                $company,
                'company_employees',
                'create',
                "Created company employee '{$employee->name}'",
                [
                    'employee_id' => $employee->id,
                    'employee_email' => $employee->email,
                    'permission_template_id' => $template?->id,
                    'permissions' => $permissionNames,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Employee added successfully',
                'data' => [
                    'employee' => new EmployeeResource(
                        $employee->fresh()->load(['roles', 'permissions', 'companyPermissionTemplate'])
                    ),
                    'limit_info' => $company->fresh()->getEmployeeLimitInfo(),
                ],
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => 'INVALID_COMPANY_EMPLOYEE_ACCESS',
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Failed to create company employee.', [
                'company_id' => $company->id,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->serverError('Failed to create employee', $exception);
        }
    }

    public function show(Request $request, User $employee): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($employee, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in your company',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new EmployeeResource(
                $employee->load(['roles', 'permissions', 'companyPermissionTemplate'])
            ),
        ]);
    }

    public function update(
        UpdateEmployeeRequest $request,
        User $employee,
        CompanyEmployeeAccessService $accessService,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($employee, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in your company',
            ], 404);
        }

        if (! Schema::hasColumn('users', 'company_permission_template_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Database schema is missing users.company_permission_template_id. Run the latest migrations, then retry.',
                'error_code' => 'COMPANY_EMPLOYEE_TEMPLATE_COLUMN_MISSING',
            ], 503);
        }

        try {
            $permissionNames = null;
            $template = null;
            $templateId = $employee->company_permission_template_id;

            if ($request->filled('template_id')) {
                $template = CompanyPermissionTemplate::query()
                    ->where('company_id', $company->id)
                    ->where('is_active', true)
                    ->find($request->integer('template_id'));

                if (! $template) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Template not found or inactive for this company',
                    ], 404);
                }

                $permissionNames = (array) $template->permissions;
                $templateId = $template->id;
            } elseif ($request->has('permissions')) {
                $permissionNames = (array) $request->input('permissions', []);
                $templateId = null;
            }

            DB::transaction(function () use (
                $request,
                $employee,
                $accessService,
                $permissionNames,
                $templateId
            ): void {
                $updateData = [];

                if ($request->has('name')) {
                    $updateData['name'] = trim((string) $request->input('name'));
                }

                if ($request->has('email')) {
                    $updateData['email'] = strtolower(trim((string) $request->input('email')));
                }

                if ($request->filled('password')) {
                    $updateData['password'] = Hash::make((string) $request->input('password'));
                }

                if ($request->has('is_active')) {
                    $updateData['is_active'] = $request->boolean('is_active');
                }

                if ($permissionNames !== null) {
                    $updateData['company_permission_template_id'] = $templateId;
                }

                if ($updateData !== []) {
                    $employee->update($updateData);
                }

                if ($permissionNames !== null) {
                    $accessService->syncPermissions($employee, $permissionNames);
                }

                if ($request->filled('role')) {
                    $accessService->syncRole($employee, (string) $request->input('role'));
                }

                if ($request->has('is_active') && ! $request->boolean('is_active')) {
                    $employee->tokens()->delete();
                }
            });

            $activityLog->success(
                $company,
                'company_employees',
                'update',
                "Updated company employee '{$employee->name}'",
                [
                    'employee_id' => $employee->id,
                    'permission_template_id' => $templateId,
                    'permissions_changed' => $permissionNames !== null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Employee updated successfully',
                'data' => new EmployeeResource(
                    $employee->fresh()->load(['roles', 'permissions', 'companyPermissionTemplate'])
                ),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => 'INVALID_COMPANY_EMPLOYEE_ACCESS',
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Failed to update company employee.', [
                'employee_id' => $employee->id,
                'company_id' => $company->id,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->serverError('Failed to update employee', $exception);
        }
    }

    public function destroy(
        Request $request,
        User $employee,
        CompanyEmployeeAccessService $accessService,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($employee, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in your company',
            ], 404);
        }

        try {
            $employeeName = $employee->name;

            DB::transaction(function () use ($employee, $company, $accessService): void {
                $employee->tokens()->delete();
                $accessService->clear($employee);
                $employee->update([
                    'company_id' => null,
                    'company_permission_template_id' => null,
                    'is_company_employee' => false,
                    'is_active' => false,
                ]);
                $company->syncEmployeeCount();
            });

            $activityLog->success(
                $company,
                'company_employees',
                'delete',
                "Removed company employee '{$employeeName}'",
                ['employee_id' => $employee->id]
            );

            return response()->json([
                'success' => true,
                'message' => "Employee '{$employeeName}' removed successfully",
                'data' => [
                    'limit_info' => $company->fresh()->getEmployeeLimitInfo(),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to remove company employee.', [
                'employee_id' => $employee->id,
                'company_id' => $company->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->serverError('Failed to delete employee', $exception);
        }
    }

    public function toggleStatus(
        Request $request,
        User $employee,
        CompanyActivityLogService $activityLog
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->user();

        if (! $this->belongsToCompany($employee, $company)) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in your company',
            ], 404);
        }

        try {
            $employee->update(['is_active' => ! $employee->is_active]);

            if (! $employee->is_active) {
                $employee->tokens()->delete();
            }

            $status = $employee->is_active ? 'activated' : 'deactivated';
            $activityLog->success(
                $company,
                'company_employees',
                'toggle_status',
                "Employee '{$employee->name}' was {$status}",
                ['employee_id' => $employee->id, 'is_active' => $employee->is_active]
            );

            return response()->json([
                'success' => true,
                'message' => "Employee '{$employee->name}' has been {$status}",
                'data' => [
                    'id' => $employee->id,
                    'is_active' => $employee->is_active,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->serverError('Failed to toggle employee status', $exception);
        }
    }

    public function limits(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        return response()->json([
            'success' => true,
            'data' => new EmployeeLimitResource($company->getEmployeeLimitInfo()),
        ]);
    }

    /** @return array{0: array<int, string>, 1: CompanyPermissionTemplate|null} */
    private function resolveRequestedAccess(
        CreateEmployeeRequest $request,
        Company $company
    ): array {
        if ($request->filled('template_id')) {
            $template = CompanyPermissionTemplate::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->find($request->integer('template_id'));

            if (! $template) {
                throw new InvalidArgumentException('Template not found or inactive for this company.');
            }

            return [(array) $template->permissions, $template];
        }

        return [(array) $request->input('permissions', []), null];
    }

    private function belongsToCompany(User $employee, Company $company): bool
    {
        return $employee->isCompanyEmployee()
            && (int) $employee->company_id === (int) $company->id;
    }

    private function serverError(string $prefix, Throwable $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => config('app.debug')
                ? "{$prefix}: {$exception->getMessage()}"
                : "{$prefix}. Check storage/logs/laravel.log for details.",
            'error_code' => 'COMPANY_EMPLOYEE_OPERATION_FAILED',
            'exception' => config('app.debug') ? $exception::class : null,
            'file' => config('app.debug') ? $exception->getFile() : null,
            'line' => config('app.debug') ? $exception->getLine() : null,
        ], 500);
    }

    private function getUpgradeSuggestions(Company $company): array
    {
        $upgradePlans = [
            'starter' => [
                'slug' => 'growth',
                'name' => 'Growth',
                'price' => '$79/month',
                'max_employees' => 5,
            ],
            'growth' => [
                'slug' => 'business',
                'name' => 'Business',
                'price' => '$199/month',
                'max_employees' => 20,
            ],
            'business' => [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'price' => 'Custom',
                'max_employees' => 'Unlimited',
            ],
        ];

        $slug = $company->selectedPlan?->slug;

        return $slug && isset($upgradePlans[$slug]) ? [$upgradePlans[$slug]] : [];
    }
}
