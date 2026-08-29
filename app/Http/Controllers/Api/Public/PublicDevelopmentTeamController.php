<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\DevelopmentTeamMemberResource;
use App\Models\DevelopmentTeamMember;
use App\Models\DevelopmentTeamSetting;
use Illuminate\Http\JsonResponse;

final class PublicDevelopmentTeamController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = DevelopmentTeamSetting::current();

        $members = $settings->is_enabled
            ? DevelopmentTeamMember::query()->publiclyVisible()->get()
            : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'section' => [
                    'is_enabled' => $settings->is_enabled,
                    'eyebrow_ar' => $settings->eyebrow_ar,
                    'eyebrow_en' => $settings->eyebrow_en,
                    'title_ar' => $settings->title_ar,
                    'title_en' => $settings->title_en,
                    'description_ar' => $settings->description_ar,
                    'description_en' => $settings->description_en,
                ],
                'members' => DevelopmentTeamMemberResource::collection($members),
            ],
        ]);
    }
}
