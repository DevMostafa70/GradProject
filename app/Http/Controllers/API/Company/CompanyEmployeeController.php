<?php

namespace App\Http\Controllers\API\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateEmployeeRequest;
use App\Http\Requests\Company\UpdateEmployeeRequest;
use App\Http\Resources\Company\EmployeeResource;
use App\Http\Resources\Company\EmployeeLimitResource;
use App\Models\Company;
use App\Models\CompanyPermissionTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CompanyEmployeeController extends Controller
{
    protected Company $company;

    // public function __construct()
    // {
    //     $this->middleware(function ($request, $next) {
    //         $this->company = $request->user();
    //         return $next($request);
    //     });
    // }

    /**
     * Get all employees for the company
     * GET /api/company/employees
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        $employees = $company->employees()
            ->with('roles', 'permissions')
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => EmployeeResource::collection($employees),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'total' => $employees->total(),
                'per_page' => $employees->perPage(),
                'limit_info' => $company->getEmployeeLimitInfo(),
            ],
        ]);
    }


/**
 * Create a new employee
 * POST /api/company/employees
 */
public function store(CreateEmployeeRequest $request): JsonResponse
{
    try {
        /** @var Company $company */
        $company = $request->user();

        if (!$company->canAddEmployee()) {
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

        $permissionsToAssign = [];

        if ($request->has('template_id') && $request->template_id) {
            $template = CompanyPermissionTemplate::where('company_id', $company->id)
                ->find($request->template_id);

            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template not found for this company',
                ], 404);
            }

            $permissionsToAssign = $template->permissions;
        } elseif ($request->has('permissions') && is_array($request->permissions)) {
            $permissionsToAssign = $request->permissions;
        }

        // ✅ Create the user (employee)
        $employee = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
            'company_id' => $company->id,
            'is_company_employee' => true,
            'is_active' => true,
        ]);

        // ✅ تحديث الـ Guard
        $employee->setGuardName();
        $employee->refresh();

        // ✅ Assign role if provided (guard = company)
        if ($request->has('role')) {
            $role = Role::where('name', $request->role)
                ->where('guard_name', 'company')
                ->first();

            if ($role) {
                $employee->assignRole($role);
            }
        }

        // ✅ Assign permissions (guard = user) - ✅ استخدم guard = user
        if (!empty($permissionsToAssign)) {
            $permissions = Permission::whereIn('name', $permissionsToAssign)
                ->where('guard_name', 'user')
                ->get();

            if ($permissions->isNotEmpty()) {
                $employee->syncPermissions($permissions);
            }
        }

        $company->incrementEmployeeCount();

        return response()->json([
            'success' => true,
            'message' => 'Employee added successfully',
            'data' => [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'is_active' => $employee->is_active,
                    'is_company_employee' => $employee->is_company_employee,
                    'permissions' => $employee->getAllPermissions()->pluck('name'),
                ],
                'limit_info' => $company->getEmployeeLimitInfo(),
            ],
        ], 201);

    } catch (\Exception $e) {
        Log::error('Failed to create employee: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to create employee: ' . $e->getMessage(),
        ], 500);
    }
}

/**
 * Show a specific employee
 * GET /api/company/employees/{employee}
 */
public function show(Request $request, User $employee): JsonResponse
{
    /** @var Company $company */
    $company = $request->user();

    if ($employee->company_id !== $company->id) {
        return response()->json([
            'success' => false,
            'message' => 'Employee not found in your company',
        ], 404);
    }

    $employee->load('roles', 'permissions');

    return response()->json([
        'success' => true,
        'data' => new EmployeeResource($employee),
    ]);
}

/**
 * Update an employee
 * PUT /api/company/employees/{employee}
 */
public function update(UpdateEmployeeRequest $request, User $employee): JsonResponse
{
    try {
        /** @var Company $company */
        $company = $request->user();

        if ($employee->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in your company',
            ], 404);
        }

        // ✅ Update basic info
        $updateData = [];

        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }

        if ($request->has('email')) {
            $updateData['email'] = $request->email;
        }

        if ($request->has('password') && $request->password) {
            $updateData['password'] = Hash::make($request->password);
        }

        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->is_active;
        }

        if (!empty($updateData)) {
            $employee->update($updateData);
        }

        // ✅ Update permissions if provided
        if ($request->has('template_id') && $request->template_id) {
            $template = CompanyPermissionTemplate::where('company_id', $company->id)
                ->find($request->template_id);

            if ($template) {
                $employee->syncPermissions($template->permissions);
            }
        } elseif ($request->has('permissions') && is_array($request->permissions)) {
            $employee->syncPermissions($request->permissions);
        }

        // ✅ Update role if provided
        if ($request->has('role')) {
            $role = Role::where('name', $request->role)
                ->where('guard_name', 'company')
                ->first();

            if ($role) {
                $employee->syncRoles([$role]);
            }
        }

        // ✅ Log activity
        \App\Models\AdminLog::log('update_company_employee', 'company_employee', $employee->id, [
            'company_id' => $company->id,
            'company_name' => $company->company_name,
            'employee_name' => $employee->name,
            'updated_by' => $company->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully',
            'data' => new EmployeeResource($employee->load('roles', 'permissions')),
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to update employee: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Failed to update employee: ' . $e->getMessage(),
        ], 500);
    }
}

/**
 * Delete an employee
 * DELETE /api/company/employees/{employee}
 */
public function destroy(Request $request, User $employee): JsonResponse
{
    try {
        /** @var Company $company */
        $company = $request->user();

        if ($employee->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in your company',
            ], 404);
        }

        $employeeName = $employee->name;

        $employee->syncPermissions([]);
        $employee->syncRoles([]);

        $employee->update([
            'company_id' => null,
            'is_company_employee' => false,
            'is_active' => false,
        ]);

        $company->decrementEmployeeCount();

        \App\Models\AdminLog::log('delete_company_employee', 'company_employee', $employee->id, [
            'company_id' => $company->id,
            'company_name' => $company->company_name,
            'employee_name' => $employeeName,
            'deleted_by' => $company->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Employee '{$employeeName}' removed successfully",
            'data' => [
                'limit_info' => $company->getEmployeeLimitInfo(),
            ],
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to delete employee: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Failed to delete employee: ' . $e->getMessage(),
        ], 500);
    }
}

/**
 * Toggle employee status (activate/deactivate)
 * POST /api/company/employees/{employee}/toggle
 */
public function toggleStatus(Request $request, User $employee): JsonResponse
{
    try {
        /** @var Company $company */
        $company = $request->user();

        if ($employee->company_id !== $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found in your company',
            ], 404);
        }

        $employee->update([
            'is_active' => !$employee->is_active,
        ]);

        $status = $employee->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'success' => true,
            'message' => "Employee '{$employee->name}' has been {$status}",
            'data' => [
                'id' => $employee->id,
                'is_active' => $employee->is_active,
            ],
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to toggle employee status: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Failed to toggle employee status: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * Get employee limit information
     * GET /api/company/employee-limits
     */
    public function limits(Request $request): JsonResponse  // ✅ أضف $request
    {
        /** @var Company $company */
        $company = $request->user();  // ✅ استخدم $request->user()

        return response()->json([
            'success' => true,
            'data' => new EmployeeLimitResource($company->getEmployeeLimitInfo()),
        ]);
    }

    // ============================================================
    // ✅ Helper Methods
    // ============================================================

    /**
     * Get upgrade suggestions based on current plan
     */
    private function getUpgradeSuggestions(Company $company): array
    {
        $suggestions = [];
        $currentPlanSlug = $company->selectedPlan?->slug;

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

        if ($currentPlanSlug && isset($upgradePlans[$currentPlanSlug])) {
            $suggestions[] = $upgradePlans[$currentPlanSlug];
        }

        return $suggestions;
    }
}
