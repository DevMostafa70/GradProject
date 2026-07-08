<?php
// app/Http/Controllers/API/Company/CompanyPermissionTemplateController.php

namespace App\Http\Controllers\API\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanyPermissionTemplateRequest;
use App\Http\Resources\Company\CompanyPermissionTemplateResource;
use App\Models\Company;
use App\Models\CompanyPermissionTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

class CompanyPermissionTemplateController extends Controller
{
    // ✅ لا يوجد Constructor

    /**
     * Get all permission templates for the company
     * GET /api/company/permission-templates
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->user();

        $templates = $company->permissionTemplates()
            ->with('creator')
            ->when($request->search, function ($query, $search) {
                return $query->search($search);
            })
            ->when($request->active === 'true', function ($query) {
                return $query->active();
            })
            ->orderBy('name')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => CompanyPermissionTemplateResource::collection($templates),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'total' => $templates->total(),
                'per_page' => $templates->perPage(),
            ],
        ]);
    }

    /**
     * Create a new permission template
     * POST /api/company/permission-templates
     */
  public function store(CompanyPermissionTemplateRequest $request): JsonResponse
{
    try {
        /** @var Company $company */
        $company = $request->user();

        // ✅ Verify permissions exist
        $permissions = Permission::whereIn('name', $request->permissions)
            ->where('guard_name', 'company')
            ->pluck('name')
            ->toArray();

        if (empty($permissions)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid permissions found for guard company',
            ], 422);
        }

       $template = CompanyPermissionTemplate::create([
    'company_id' => $company->id,
    'name' => $request->name,
    'description' => $request->description,
    'permissions' => $permissions,
    'is_active' => $request->is_active ?? true,
    'created_by' => $company->id,  // ✅ الآن صحيح لأن created_by يشير إلى companies
]);


        // ✅ Log activity
        \App\Models\AdminLog::log('create_company_permission_template', 'company_permission_template', $template->id, [
            'company_id' => $company->id,
            'company_name' => $company->company_name,
            'template_name' => $request->name,
            'permissions' => $permissions,
            'created_by' => $company->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permission template created successfully',
            'data' => new CompanyPermissionTemplateResource($template->load('creator')),
        ], 201);

    } catch (\Exception $e) {
        Log::error('Failed to create permission template: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to create permission template: ' . $e->getMessage(),
        ], 500);
    }
}

    /**
     * Show a specific permission template
     * GET /api/company/permission-templates/{template}
     */
    public function show(CompanyPermissionTemplate $template): JsonResponse
    {
        /** @var Company $company */
        $company = request()->user();

        // ✅ Verify template belongs to this company
        if ($template->company_id !== $company->id) {
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

    /**
     * Update a permission template
     * PUT /api/company/permission-templates/{template}
     */
    public function update(CompanyPermissionTemplateRequest $request, CompanyPermissionTemplate $template): JsonResponse
    {
        try {
            /** @var Company $company */
            $company = $request->user();

            // ✅ Verify template belongs to this company
            if ($template->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template not found in your company',
                ], 404);
            }

            // ✅ Verify permissions exist
            $permissions = Permission::whereIn('name', $request->permissions)
                ->where('guard_name', 'company')
                ->pluck('name')
                ->toArray();

            if (empty($permissions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid permissions found for guard company',
                ], 422);
            }

            $oldName = $template->name;
            $oldPermissions = $template->permissions;

            $template->update([
                'name' => $request->name,
                'description' => $request->description,
                'permissions' => $permissions,
                'is_active' => $request->is_active ?? $template->is_active,
            ]);

            // ✅ Log activity
            \App\Models\AdminLog::log('update_company_permission_template', 'company_permission_template', $template->id, [
                'company_id' => $company->id,
                'company_name' => $company->company_name,
                'old_name' => $oldName,
                'new_name' => $request->name,
                'old_permissions' => $oldPermissions,
                'new_permissions' => $permissions,
                'updated_by' => $company->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission template updated successfully',
                'data' => new CompanyPermissionTemplateResource($template->load('creator')),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update permission template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update permission template: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a permission template
     * DELETE /api/company/permission-templates/{template}
     */
    public function destroy(CompanyPermissionTemplate $template): JsonResponse
    {
        try {
            /** @var Company $company */
            $company = request()->user();

            // ✅ Verify template belongs to this company
            if ($template->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template not found in your company',
                ], 404);
            }

            $templateName = $template->name;

            // ✅ Log activity
            \App\Models\AdminLog::log('delete_company_permission_template', 'company_permission_template', $template->id, [
                'company_id' => $company->id,
                'company_name' => $company->company_name,
                'template_name' => $templateName,
                'permissions' => $template->permissions,
                'deleted_by' => $company->email,
            ]);

            $template->delete();

            return response()->json([
                'success' => true,
                'message' => "Permission template '{$templateName}' deleted successfully",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete permission template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete permission template: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle template status (activate/deactivate)
     * POST /api/company/permission-templates/{template}/toggle
     */
    public function toggle(CompanyPermissionTemplate $template): JsonResponse
    {
        try {
            /** @var Company $company */
            $company = request()->user();

            // ✅ Verify template belongs to this company
            if ($template->company_id !== $company->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template not found in your company',
                ], 404);
            }

            $template->update([
                'is_active' => !$template->is_active,
            ]);

            $status = $template->is_active ? 'activated' : 'deactivated';

            // ✅ Log activity
            \App\Models\AdminLog::log('toggle_company_permission_template', 'company_permission_template', $template->id, [
                'company_id' => $company->id,
                'company_name' => $company->company_name,
                'template_name' => $template->name,
                'new_status' => $status,
                'updated_by' => $company->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Permission template '{$template->name}' has been {$status}",
                'data' => [
                    'id' => $template->id,
                    'is_active' => $template->is_active,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle permission template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle permission template: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all available permissions for the company
     * GET /api/company/permission-templates/available-permissions
     */
public function availablePermissions(): JsonResponse
{
    // ✅ جلب الصلاحيات مع guard = user (للموظفين)
    $permissions = Permission::where('guard_name', 'user')
        ->where('name', 'LIKE', 'company.%')
        ->orderBy('name')
        ->get()
        ->groupBy(function ($permission) {
            $parts = explode('.', $permission->name);
            return $parts[1] ?? 'other';
        })
        ->map(function ($group) {
            return $group->pluck('name');
        });

    return response()->json([
        'success' => true,
        'data' => $permissions,
    ]);
}

    /**
     * Get all permissions grouped by module
     * GET /api/company/permission-templates/permissions-by-module
     */
    public function permissionsByModule(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'company')
            ->orderBy('name')
            ->get()
            ->groupBy(function ($permission) {
                $parts = explode('.', $permission->name);
                return $parts[0] ?? 'other';
            })
            ->map(function ($group, $module) {
                return [
                    'module' => $module,
                    'permissions' => $group->map(function ($permission) {
                        return [
                            'name' => $permission->name,
                            'description' => $this->getPermissionDescription($permission->name),
                        ];
                    }),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    // ============================================================
    // ✅ Helper Methods
    // ============================================================

    /**
     * Get description for a permission
     */
    private function getPermissionDescription(string $permission): string
    {
        $descriptions = [
            'company.dashboard.view' => 'عرض لوحة التحكم',
            'company.profile.view' => 'عرض ملف الشركة',
            'company.profile.update' => 'تحديث ملف الشركة',
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
            'company.billing.view' => 'عرض الفواتير',
            'company.billing.select_plan' => 'اختيار خطة',
            'company.billing.checkout' => 'إتمام الدفع',
            'company.billing.portal' => 'بوابة الدفع',
            'company.usage.view' => 'عرض الاستخدام',
            'company.notifications.view' => 'عرض الإشعارات',
            'company.employees.view' => 'عرض الموظفين',
            'company.employees.create' => 'إضافة موظف',
            'company.employees.update' => 'تحديث موظف',
            'company.employees.delete' => 'حذف موظف',
        ];

        return $descriptions[$permission] ?? $permission;
    }
}
