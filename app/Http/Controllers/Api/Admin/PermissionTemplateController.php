<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PermissionTemplateRequest;
use App\Http\Resources\PermissionTemplateResource;
use App\Models\AdminLog;
use App\Models\PermissionTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

class PermissionTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = PermissionTemplate::with('creator')
            ->withCount('admins')
            ->when($request->search, fn ($query, $search) => $query->search($search))
            ->when(
                in_array($request->active, ['true', 'false'], true),
                fn ($query) => $query->where('is_active', $request->active === 'true')
            )
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

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

    public function store(PermissionTemplateRequest $request): JsonResponse
    {
        $permissions = $this->validatedPermissions($request->validated('permissions'));

        $template = PermissionTemplate::create([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'permissions' => $permissions,
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()->id,
        ]);

        AdminLog::log('create_permission_template', 'permission_template', $template->id, [
            'template_name' => $template->name,
            'permissions' => $permissions,
            'created_by' => $request->user()->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permission template created successfully',
            'data' => new PermissionTemplateResource($template->load('creator')),
        ], 201);
    }

    public function show(PermissionTemplate $template): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new PermissionTemplateResource($template->load('creator')->loadCount('admins')),
        ]);
    }

    public function update(
        PermissionTemplateRequest $request,
        PermissionTemplate $template
    ): JsonResponse {
        $permissions = $this->validatedPermissions($request->validated('permissions'));
        $permissionModels = Permission::where('guard_name', 'admin')
            ->whereIn('name', $permissions)
            ->get();

        try {
            $oldName = $template->name;
            $oldPermissions = $template->permissions ?? [];

            $updatedAdmins = DB::transaction(function () use (
                $template,
                $request,
                $permissions,
                $permissionModels
            ): int {
                $template->update([
                    'name' => $request->validated('name'),
                    'description' => $request->validated('description'),
                    'permissions' => $permissions,
                    'is_active' => $request->has('is_active')
                        ? $request->boolean('is_active')
                        : $template->is_active,
                ]);

                $admins = $template->admins()
                    ->where('role', 'admin')
                    ->get();

                foreach ($admins as $admin) {
                    $admin->syncPermissions($permissionModels);
                    $admin->update(['legacy_permissions' => $permissions]);
                }

                return $admins->count();
            });

            AdminLog::log('update_permission_template', 'permission_template', $template->id, [
                'old_name' => $oldName,
                'new_name' => $template->name,
                'old_permissions' => $oldPermissions,
                'new_permissions' => $permissions,
                'updated_admins' => $updatedAdmins,
                'updated_by' => $request->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission template updated successfully',
                'data' => new PermissionTemplateResource($template->fresh()->load('creator')->loadCount('admins')),
                'meta' => ['updated_admins' => $updatedAdmins],
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update admin permission template', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update permission template.',
            ], 500);
        }
    }

    public function destroy(PermissionTemplate $template): JsonResponse
    {
        try {
            DB::transaction(function () use ($template) {
                // Logical relation only: existing databases may use MyISAM.
                $template->admins()->update(['permission_template_id' => null]);
                $template->delete();
            });

            AdminLog::log('delete_permission_template', 'permission_template', $template->id, [
                'template_name' => $template->name,
                'permissions' => $template->permissions,
                'deleted_by' => request()->user()?->name ?? 'System',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Permission template '{$template->name}' deleted successfully",
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to delete admin permission template', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete permission template.',
            ], 500);
        }
    }

    public function toggle(PermissionTemplate $template): JsonResponse
    {
        $template->update(['is_active' => ! $template->is_active]);

        AdminLog::log('toggle_permission_template', 'permission_template', $template->id, [
            'template_name' => $template->name,
            'new_status' => $template->is_active ? 'activated' : 'deactivated',
            'updated_by' => request()->user()?->name ?? 'System',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Permission template '{$template->name}' status updated",
            'data' => [
                'id' => $template->id,
                'is_active' => $template->is_active,
            ],
        ]);
    }

    public function availablePermissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'admin')
            ->where('name', 'like', 'admin.%')
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($permission) => explode('.', $permission->name)[1] ?? 'other')
            ->map(fn ($group) => $group->pluck('name')->values());

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    private function validatedPermissions(array $requested): array
    {
        return Permission::where('guard_name', 'admin')
            ->whereIn('name', $requested)
            ->pluck('name')
            ->values()
            ->all();
    }
}
