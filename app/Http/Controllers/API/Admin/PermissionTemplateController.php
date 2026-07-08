<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\PermissionTemplate;
use App\Http\Requests\Admin\PermissionTemplateRequest;
use App\Http\Resources\PermissionTemplateResource;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

class PermissionTemplateController extends Controller
{
    /**
     * Display a listing of permission templates
     * GET /api/admin/permission-templates
     */
    public function index(Request $request): JsonResponse
    {
        $templates = PermissionTemplate::with('creator')
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
            'data' => PermissionTemplateResource::collection($templates),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'total' => $templates->total(),
                'per_page' => $templates->perPage(),
            ],
        ]);
    }

    /**
     * Store a newly created permission template
     * POST /api/admin/permission-templates
     */
    public function store(PermissionTemplateRequest $request): JsonResponse
    {
        try {
            // ✅ التحقق من وجود الصلاحيات
            $permissions = Permission::whereIn('name', $request->permissions)
                ->where('guard_name', 'admin')
                ->pluck('name')
                ->toArray();

            if (empty($permissions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid permissions found for guard admin',
                ], 422);
            }

            $template = PermissionTemplate::create([
                'name' => $request->name,
                'description' => $request->description,
                'permissions' => $permissions,
                'is_active' => $request->is_active ?? true,
                'created_by' => auth()->id(),
            ]);

            // ✅ تسجيل النشاط
            \App\Models\AdminLog::log('create_permission_template', 'permission_template', $template->id, [
                'template_name' => $request->name,
                'permissions' => $permissions,
                'created_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission template created successfully',
                'data' => new PermissionTemplateResource($template->load('creator')),
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
     * Display the specified permission template
     * GET /api/admin/permission-templates/{template}
     */
    public function show(PermissionTemplate $template): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new PermissionTemplateResource($template->load('creator')),
        ]);
    }

    /**
     * Update the specified permission template
     * PUT /api/admin/permission-templates/{template}
     */
    public function update(PermissionTemplateRequest $request, PermissionTemplate $template): JsonResponse
    {
        try {
            // ✅ التحقق من وجود الصلاحيات
            $permissions = Permission::whereIn('name', $request->permissions)
                ->where('guard_name', 'admin')
                ->pluck('name')
                ->toArray();

            if (empty($permissions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid permissions found for guard admin',
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

            // ✅ تسجيل النشاط
            \App\Models\AdminLog::log('update_permission_template', 'permission_template', $template->id, [
                'old_name' => $oldName,
                'new_name' => $request->name,
                'old_permissions' => $oldPermissions,
                'new_permissions' => $permissions,
                'updated_by' => auth()->user()->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission template updated successfully',
                'data' => new PermissionTemplateResource($template->load('creator')),
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
     * Remove the specified permission template
     * DELETE /api/admin/permission-templates/{template}
     */
    public function destroy(PermissionTemplate $template): JsonResponse
    {
        try {
            $templateName = $template->name;

            // ✅ تسجيل النشاط
            \App\Models\AdminLog::log('delete_permission_template', 'permission_template', $template->id, [
                'template_name' => $templateName,
                'permissions' => $template->permissions,
                'deleted_by' => auth()->user()->name ?? 'System',
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
     * POST /api/admin/permission-templates/{template}/toggle
     */
    public function toggle(PermissionTemplate $template): JsonResponse
    {
        try {
            $template->update([
                'is_active' => !$template->is_active,
            ]);

            $status = $template->is_active ? 'activated' : 'deactivated';

            \App\Models\AdminLog::log('toggle_permission_template', 'permission_template', $template->id, [
                'template_name' => $template->name,
                'new_status' => $status,
                'updated_by' => auth()->user()->name ?? 'System',
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
     * Get all available permissions for templates
     * GET /api/admin/permission-templates/available-permissions
     */
    public function availablePermissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'admin')
            ->orderBy('name')
            ->get()
            ->groupBy(function ($permission) {
                $parts = explode('.', $permission->name);
                return $parts[0] ?? 'other';
            })
            ->map(function ($group) {
                return $group->pluck('name');
            });

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }
}
