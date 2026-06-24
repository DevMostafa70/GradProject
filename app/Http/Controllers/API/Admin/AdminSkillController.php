<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSkillController extends Controller
{
    protected AdminService $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
        // $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Get all skills
     */
    public function index(Request $request): JsonResponse
    {
        $skills = $this->adminService->getSkills($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $skills,
        ]);
    }

    /**
     * Create a new skill
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:skills',
            'category' => 'nullable|string',
        ]);

        $skill = $this->adminService->createSkill($request->only(['name', 'category']));

        return response()->json([
            'success' => true,
            'message' => 'Skill created successfully',
            'data' => $skill,
        ], 201);
    }

    /**
     * Update a skill
     */
    public function update(Request $request, Skill $skill): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:skills,name,' . $skill->id,
            'category' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $skill = $this->adminService->updateSkill($skill, $request->only(['name', 'category', 'is_active']));

        return response()->json([
            'success' => true,
            'message' => 'Skill updated successfully',
            'data' => $skill,
        ]);
    }

    /**
     * Delete a skill
     */
    public function destroy(Skill $skill): JsonResponse
    {
        $this->adminService->deleteSkill($skill);

        return response()->json([
            'success' => true,
            'message' => 'Skill deleted successfully',
        ]);
    }

    /**
     * Toggle skill status
     */
    public function toggle(Skill $skill): JsonResponse
    {
        $skill->update(['is_active' => !$skill->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Skill status updated',
            'data' => ['is_active' => $skill->is_active],
        ]);
    }
}
