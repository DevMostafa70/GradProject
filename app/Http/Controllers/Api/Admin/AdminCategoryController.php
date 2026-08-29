<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobCategory;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCategoryController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
        // $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Get all job categories
     */
    public function index(Request $request): JsonResponse
    {
        $categories = $this->adminService->getJobCategories($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Create a new job category
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|array',
            'name.en' => 'required|string|max:255',
            'name.ar' => 'required|string|max:255',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        $category = $this->adminService->createJobCategory($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category,
        ], 201);
    }

    /**
     * Update a job category
     */
    public function update(Request $request, JobCategory $category): JsonResponse
    {
        $request->validate([
            'name' => 'required|array',
            'name.en' => 'required|string|max:255',
            'name.ar' => 'required|string|max:255',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $category = $this->adminService->updateJobCategory($category, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category,
        ]);
    }

    /**
     * Delete a job category
     */
    public function destroy(JobCategory $category): JsonResponse
    {
        $this->adminService->deleteJobCategory($category);

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Reorder categories
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:job_categories,id',
            'categories.*.sort_order' => 'required|integer',
        ]);

        foreach ($request->categories as $item) {
            JobCategory::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Categories reordered successfully',
        ]);
    }

    /**
     * Toggle category status (activate/deactivate)
     */
    public function toggle(JobCategory $category): JsonResponse
    {
        $category->update([
            'is_active' => !$category->is_active
        ]);

        $status = $category->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'success' => true,
            'message' => "Category '{$category->name_ar}' has been {$status}",
            'data' => [
                'id' => $category->id,
                'is_active' => $category->is_active,
            ],
        ]);
    }
}
