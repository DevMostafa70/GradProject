<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DevelopmentTeamMemberRequest;
use App\Http\Resources\DevelopmentTeamMemberResource;
use App\Models\DevelopmentTeamMember;
use App\Models\DevelopmentTeamSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class AdminDevelopmentTeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DevelopmentTeamMember::query()
            ->with('creator:id,name,email')
            ->orderBy('display_order')
            ->orderBy('id');

        if (($validated['status'] ?? 'all') === 'active') {
            $query->where('is_active', true);
        } elseif (($validated['status'] ?? 'all') === 'inactive') {
            $query->where('is_active', false);
        }

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('role_ar', 'like', "%{$search}%")
                    ->orWhere('role_en', 'like', "%{$search}%");
            });
        }

        $members = $query->paginate((int) ($validated['per_page'] ?? 50));

        $settings = DevelopmentTeamSetting::current();

        return response()->json([
            'success' => true,
            'data' => DevelopmentTeamMemberResource::collection($members->getCollection()),
            'settings' => [
                'is_enabled' => $settings->is_enabled,
                'eyebrow_ar' => $settings->eyebrow_ar,
                'eyebrow_en' => $settings->eyebrow_en,
                'title_ar' => $settings->title_ar,
                'title_en' => $settings->title_en,
                'description_ar' => $settings->description_ar,
                'description_en' => $settings->description_en,
            ],
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
            ],
        ]);
    }


    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'eyebrow_ar' => ['required', 'string', 'max:120'],
            'eyebrow_en' => ['required', 'string', 'max:120'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
        ]);

        $settings = DevelopmentTeamSetting::current();
        $settings->fill($validated);
        $settings->updated_by = $request->user()?->id;
        $settings->save();

        return response()->json([
            'success' => true,
            'message' => 'Development team section settings updated successfully.',
            'data' => [
                'is_enabled' => $settings->is_enabled,
                'eyebrow_ar' => $settings->eyebrow_ar,
                'eyebrow_en' => $settings->eyebrow_en,
                'title_ar' => $settings->title_ar,
                'title_en' => $settings->title_en,
                'description_ar' => $settings->description_ar,
                'description_en' => $settings->description_en,
            ],
        ]);
    }

    public function store(DevelopmentTeamMemberRequest $request): JsonResponse
    {
        $imagePath = $request->file('image')->store('development-team', 'public');

        try {
            $data = $request->safe()->except('image');
            $data['image_path'] = $imagePath;
            $data['created_by'] = $request->user()?->id;
            $data['display_order'] = $data['display_order']
                ?? ((int) DevelopmentTeamMember::query()->max('display_order') + 1);

            $member = DevelopmentTeamMember::query()->create($data);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($imagePath);
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'Development team member created successfully.',
            'data' => new DevelopmentTeamMemberResource($member),
        ], 201);
    }

    public function update(
        DevelopmentTeamMemberRequest $request,
        DevelopmentTeamMember $member
    ): JsonResponse {
        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            $newImagePath = $request->file('image')->store('development-team', 'public');
            $oldImagePath = $member->image_path;
            $data['image_path'] = $newImagePath;

            try {
                $member->fill($data)->save();
            } catch (\Throwable $exception) {
                Storage::disk('public')->delete($newImagePath);
                throw $exception;
            }

            if ($oldImagePath) {
                Storage::disk('public')->delete($oldImagePath);
            }
        } else {
            $member->fill($data)->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Development team member updated successfully.',
            'data' => new DevelopmentTeamMemberResource($member->fresh()),
        ]);
    }

    public function toggle(DevelopmentTeamMember $member): JsonResponse
    {
        $member->update(['is_active' => ! $member->is_active]);

        return response()->json([
            'success' => true,
            'message' => $member->is_active
                ? 'Member is now visible on the landing page.'
                : 'Member was hidden from the landing page.',
            'data' => new DevelopmentTeamMemberResource($member->fresh()),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'members' => ['required', 'array', 'min:1', 'max:200'],
            'members.*.id' => ['required', 'integer', 'exists:development_team_members,id', 'distinct'],
            'members.*.display_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['members'] as $item) {
                DevelopmentTeamMember::query()
                    ->whereKey($item['id'])
                    ->update(['display_order' => $item['display_order']]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Development team order updated successfully.',
        ]);
    }

    public function destroy(DevelopmentTeamMember $member): JsonResponse
    {
        $imagePath = $member->image_path;
        $member->delete();

        if ($imagePath) {
            Storage::disk('public')->delete($imagePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Development team member deleted successfully.',
        ]);
    }
}
