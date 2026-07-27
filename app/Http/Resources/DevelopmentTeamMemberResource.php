<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DevelopmentTeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'role_ar' => $this->role_ar,
            'role_en' => $this->role_en,
            'bio_ar' => $this->bio_ar,
            'bio_en' => $this->bio_en,
            'responsibilities_ar' => $this->responsibilities_ar,
            'responsibilities_en' => $this->responsibilities_en,
            'skills' => array_values($this->skills ?? []),
            'image_url' => $this->image_url,
            'email' => $this->email,
            'linkedin_url' => $this->linkedin_url,
            'github_url' => $this->github_url,
            'portfolio_url' => $this->portfolio_url,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
