<?php
// app/Http/Resources/Company/CompanyPermissionTemplateResource.php

namespace App\Http\Resources\Company;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyPermissionTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'permissions_count' => $this->permissions_count,
            'is_active' => $this->is_active,
            'created_by' => [
                'id' => $this->creator?->id,
                'name' => $this->creator?->company_name,
                'email' => $this->creator?->email,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
